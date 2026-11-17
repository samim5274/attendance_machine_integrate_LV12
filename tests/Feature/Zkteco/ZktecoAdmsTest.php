<?php

namespace Tests\Feature\Zkteco;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceCommand;
use App\Models\AttendancePunch;
use App\Models\EmployeeDailyAttendance;
use App\Services\Zkteco\AdmsParser;
use App\Services\Zkteco\DailyAttendanceBuilder;
use App\Services\Zkteco\DeviceCommandService;
use App\Services\Zkteco\ZkLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Stand-in for the project's Employee model, so the tests don't depend on its schema. */
class ZkTestEmployee extends Model
{
    protected $table = 'zk_test_employees';

    protected $guarded = [];
}

class ZktecoAdmsTest extends TestCase
{
    use RefreshDatabase;

    private const SN = 'VGU56244900309';

    /**
     * Safety net: RefreshDatabase wipes the database it runs on. Refuse to run unless
     * phpunit.xml points at sqlite :memory: or a database whose name contains "test".
     */
    protected function beforeRefreshingDatabase()
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && !str_contains(strtolower($database), 'test')) {
            $this->markTestSkipped("Refusing to run: RefreshDatabase would wipe [{$connection}:{$database}]. "
                .'Set DB_CONNECTION=sqlite and DB_DATABASE=:memory: (or a *_test database) in phpunit.xml.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('zk_test_employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->nullable();
            $t->timestamps();
        });

        config([
            'app.timezone' => 'Asia/Dhaka',
            'zkteco.timezone' => 'Asia/Dhaka',
            'zkteco.allowed_ips' => [],
            'zkteco.log.enabled' => false,
            'zkteco.employee.model' => ZkTestEmployee::class,
            'zkteco.employee.pin_column' => 'code',
            'zkteco.employee.name_column' => 'name',
            'zkteco.employee.active_column' => null,
        ]);
        date_default_timezone_set('Asia/Dhaka');
        ZkLog::reset();

        ZkTestEmployee::create(['name' => 'Rahim', 'code' => '1001']);
        ZkTestEmployee::create(['name' => 'Karima', 'code' => '1002']);
        ZkTestEmployee::create(['name' => 'Night Nurse', 'code' => '1003']);

        AttendanceDevice::create(['serial_number' => self::SN, 'name' => 'Main Entrance', 'is_active' => true]);
    }

    private function attlog(array $lines, string $stamp = '100'): \Illuminate\Testing\TestResponse
    {
        $body = implode("\n", array_map(fn ($l) => implode("\t", $l), $lines))."\n";

        return $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=ATTLOG&Stamp='.$stamp, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    private function emp(string $code): int
    {
        return (int) ZkTestEmployee::where('code', $code)->value('id');
    }

    public function test_unknown_device_is_rejected_and_recorded_as_pending(): void
    {
        $this->get('/iclock/cdata?SN=NEWDEVICE01&options=all')
            ->assertStatus(401)
            ->assertSee('UNKNOWN DEVICE');

        $this->assertDatabaseHas('attendance_devices', ['serial_number' => 'NEWDEVICE01', 'is_active' => false]);

        // Data from it is not accepted (device keeps it until approved).
        $this->call('POST', '/iclock/cdata?SN=NEWDEVICE01&table=ATTLOG', [], [], [], [], "1001\t2026-11-14 09:00:00\t0\t15\n")->assertStatus(403);
        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_request_without_serial_is_rejected(): void
    {
        $this->get('/iclock/getrequest')->assertStatus(400);
    }

    public function test_handshake_returns_device_options(): void
    {
        $res = $this->get('/iclock/cdata?SN='.self::SN.'&options=all&pushver=2.4.1&language=69')->assertOk();

        $this->assertStringStartsWith('text/plain', $res->headers->get('Content-Type'));
        $text = $res->getContent();
        $this->assertStringContainsString('GET OPTION FROM: '.self::SN, $text);
        $this->assertStringContainsString('TimeZone=6', $text);
        $this->assertStringContainsString('Realtime=1', $text);
        $this->assertStringContainsString("TransFlag=TransData AttLog\tOpLog", $text);
        $this->assertSame('2.4.1', AttendanceDevice::first()->push_version);
    }

    public function test_attlog_is_stored_idempotently_and_bad_lines_are_skipped(): void
    {
        $lines = [
            ['1001', '2026-11-14 08:58:12', '0', '15', '', '0', '0'],
            ['1001', '2026-11-14 08:59:00', '0', '15', '', '0', '0'], // duplicate tap
            ['1001', '2026-11-14 17:05:31', '1', '15', '', '0', '0'],
            ['0', '2026-11-14 09:00:00', '0', '15'],                   // stranger / telemetry
            ['1002', 'not-a-date', '0', '15'],
        ];

        $this->attlog($lines, '555')->assertOk()->assertSee('OK: 5');
        $this->attlog($lines, '555')->assertOk(); // device re-sends after reconnect

        $this->assertSame(3, AttendancePunch::count());
        $this->assertSame('555', AttendanceDevice::first()->attlog_stamp);

        $day = EmployeeDailyAttendance::where('employee_id', $this->emp('1001'))->first();
        $this->assertSame('2026-11-14', $day->attendance_date);
        $this->assertSame('08:58', $day->first_in->format('H:i'));
        $this->assertSame('17:05', $day->last_out->format('H:i'));
        $this->assertSame(2, $day->punch_count); // 08:58 + 08:59 counted once
        $this->assertSame(EmployeeDailyAttendance::PRESENT, $day->status);
        $this->assertSame('Office', $day->shift_name);
        $this->assertSame(0, $day->late_minutes);
    }

    public function test_night_shift_across_midnight_is_one_duty_dated_on_the_in_day(): void
    {
        $this->attlog([
            ['1003', '2026-11-14 19:55:03', '0', '15'],
            ['1003', '2026-11-15 08:02:10', '1', '15'],
            ['1003', '2026-11-15 20:10:00', '0', '15'], // next night
            ['1003', '2026-11-16 08:00:00', '1', '15'],
        ])->assertOk();

        $rows = EmployeeDailyAttendance::where('employee_id', $this->emp('1003'))->orderBy('attendance_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-11-14', $rows[0]->attendance_date);
        $this->assertSame('2026-11-15 08:02', $rows[0]->last_out->format('Y-m-d H:i'));
        $this->assertSame('Night', $rows[0]->shift_name);
        $this->assertSame(727, $rows[0]->worked_minutes);
        $this->assertSame('2026-11-15', $rows[1]->attendance_date);
        $this->assertSame('Night', $rows[1]->shift_name);
        $this->assertSame(0, $rows[1]->late_minutes); // 20:10 is within the 10 min grace
    }

    public function test_out_of_order_uploads_are_recalculated(): void
    {
        // OUT arrives first (e.g. from a second terminal), IN arrives later.
        $this->attlog([['1002', '2026-11-14 17:30:00', '1', '15']])->assertOk();
        $this->attlog([['1002', '2026-11-14 09:24:40', '0', '15']])->assertOk();

        $rows = EmployeeDailyAttendance::where('employee_id', $this->emp('1002'))->get();
        $this->assertCount(1, $rows);
        $this->assertSame('09:24', $rows[0]->first_in->format('H:i'));
        $this->assertSame('17:30', $rows[0]->last_out->format('H:i'));
        $this->assertSame(24, $rows[0]->late_minutes);
        $this->assertSame(485, $rows[0]->worked_minutes);
    }

    public function test_single_punch_is_on_duty_then_incomplete(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00'));
        $this->attlog([['1001', '2026-11-14 09:00:00', '0', '15']])->assertOk();
        $this->assertSame(EmployeeDailyAttendance::ON_DUTY, EmployeeDailyAttendance::first()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
        $this->artisan('zkteco:housekeeping')->assertSuccessful();
        $this->assertSame(EmployeeDailyAttendance::INCOMPLETE, EmployeeDailyAttendance::first()->status);
    }

    public function test_locked_rows_are_never_overwritten(): void
    {
        EmployeeDailyAttendance::create([
            'employee_id' => $this->emp('1001'), 'attendance_date' => '2026-11-14',
            'status' => 'present', 'source' => 'manual', 'remarks' => 'Approved by HR',
        ]);

        $this->attlog([['1001', '2026-11-14 10:30:00', '0', '15']])->assertOk();

        $row = EmployeeDailyAttendance::first();
        $this->assertSame('manual', $row->source);
        $this->assertNull($row->first_in);
    }

    public function test_unknown_pins_are_kept_and_relinked_later(): void
    {
        $this->attlog([['2001', '2026-11-14 09:05:00', '0', '15'], ['2001', '2026-11-14 18:00:00', '1', '15']])->assertOk();
        $this->assertNull(AttendancePunch::first()->employee_id);
        $this->assertSame(0, EmployeeDailyAttendance::count());

        $id = ZkTestEmployee::create(['name' => 'New Staff', 'code' => '2001'])->id;
        $this->artisan('zkteco:rebuild --relink')->assertSuccessful();

        $this->assertSame(2, AttendancePunch::where('employee_id', $id)->count());
        $this->assertSame(1, EmployeeDailyAttendance::where('employee_id', $id)->count());
    }

    public function test_command_round_trip(): void
    {
        $device = AttendanceDevice::first();
        $service = app(DeviceCommandService::class);
        $info = $service->info($device);
        $pushed = $service->pushEmployees($device);
        $this->assertSame(3, $pushed);

        $res = $this->get('/iclock/getrequest?SN='.self::SN.'&INFO=Ver%208.0.4.2,57,0,1234,192.168.10.136,10,7,12,55')->assertOk();
        $this->assertStringContainsString("C:{$info->id}:INFO", $res->getContent());
        $this->assertStringContainsString("DATA UPDATE USERINFO PIN=1001\tName=Rahim", $res->getContent());
        $this->assertSame(AttendanceDeviceCommand::SENT, $info->fresh()->status);
        $this->assertSame(57, $device->fresh()->user_count);
        $this->assertSame(55, $device->fresh()->face_count);

        // Nothing left: plain OK.
        $this->get('/iclock/getrequest?SN='.self::SN)->assertOk()->assertSee('OK');

        $body = "ID={$info->id}&Return=0&CMD=INFO\n~DeviceName=SenseFace 3A\nMAC=00:17:61:11:4f:a1\nID=".($info->id + 1)."&Return=-1002&CMD=DATA\n";
        $this->call('POST', '/iclock/devicecmd?SN='.self::SN, [], [], [], [], $body)->assertOk();

        $this->assertSame(AttendanceDeviceCommand::SUCCESS, $info->fresh()->status);
        $this->assertSame(AttendanceDeviceCommand::FAILED, AttendanceDeviceCommand::find($info->id + 1)->status);
        $this->assertSame('SenseFace 3A', $device->fresh()->model);
        $this->assertSame('00:17:61:11:4f:a1', $device->fresh()->mac_address);
    }

    public function test_operlog_users_are_stored_without_templates(): void
    {
        $body = "USER PIN=1001\tName=Rahim Uddin\tPri=0\tPasswd=\tCard=\tGrp=1\n"
            ."BIODATA Pin=1001\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=9\tMajorVer=39\tTmp=QUJD\n"
            ."OPLOG 4\t0\t2026-11-14 10:00:00\t0\t0\t0\t0\n";

        $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=OPERLOG&Stamp=77', [], [], [], [], $body)
            ->assertOk()->assertSee('OK: 3');

        $this->assertDatabaseHas('attendance_device_users', ['pin' => '1001', 'name' => 'Rahim Uddin', 'has_face' => true]);
        $this->assertSame('77', AttendanceDevice::first()->operlog_stamp);
    }

    public function test_disabled_device_cannot_push(): void
    {
        AttendanceDevice::first()->update(['is_active' => false]);
        $this->attlog([['1001', '2026-11-14 09:00:00', '0', '15']])->assertStatus(403);
        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_ip_allow_list(): void
    {
        config(['zkteco.allowed_ips' => ['192.168.10.0/24']]);
        $this->get('/iclock/getrequest?SN='.self::SN)->assertStatus(403); // test client is 127.0.0.1

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.10.136'])
            ->get('/iclock/getrequest?SN='.self::SN)->assertOk();
    }

    public function test_device_clock_in_dhaka_with_app_in_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        $this->attlog([['1001', '2026-11-15 02:30:00', '0', '15'], ['1001', '2026-11-15 10:00:00', '1', '15']])->assertOk();

        // 02:30 Dhaka == 20:30 UTC previous day; duty date stays the Dhaka date.
        $this->assertSame('2026-11-14 20:30:00', AttendancePunch::orderBy('punched_at')->first()->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15', EmployeeDailyAttendance::first()->attendance_date);
    }

    public function test_parser_handles_space_separated_and_unix_time(): void
    {
        $p = AdmsParser::attLog("1001 2026-11-14 09:00:00 0 1\n1002\t1763089200\t0\t15\n", 'Asia/Dhaka');
        $this->assertCount(2, $p['records']);
        $this->assertSame('2026-11-14 09:00:00', $p['records'][0]['time']);
        $this->assertSame(1, $p['records'][0]['verify']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $p['records'][1]['time']);
    }

    public function test_shift_matching_is_lenient(): void
    {
        $at = fn ($t) => CarbonImmutable::parse('2026-11-14 '.$t, 'Asia/Dhaka');

        $this->assertSame(['Morning', 0], DailyAttendanceBuilder::matchShift($at('08:05')));
        $this->assertSame(['Office', 0], DailyAttendanceBuilder::matchShift($at('08:25'))); // on time for Office
        $this->assertSame(['Office', 30], DailyAttendanceBuilder::matchShift($at('09:30')));
        $this->assertSame(['Night', 0], DailyAttendanceBuilder::matchShift($at('19:45')));
        $this->assertSame([null, 0], DailyAttendanceBuilder::matchShift($at('03:00')));
    }
}
