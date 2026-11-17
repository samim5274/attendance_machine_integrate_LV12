<?php

namespace Tests\Feature\Zkteco;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceCommand;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\EmployeeDailyAttendance;
use App\Models\EmployeeDeviceMapping;
use App\Models\User;
use App\Services\Zkteco\DeviceCommandService;
use App\Services\Zkteco\ZkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Per-device PIN mapping, device registration, monitoring and admin authorization,
 * against the real Employee model.
 */
class ZktecoMappingTest extends TestCase
{
    use RefreshDatabase;

    private const SN = 'VGU56244900309';

    private AttendanceDevice $device;

    /** Never wipe a non-test database (same guard as the other ZKTeco tests). */
    protected function beforeRefreshingDatabase()
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && !str_contains(strtolower($database), 'test')) {
            $this->markTestSkipped("Refusing to run: RefreshDatabase would wipe [{$connection}:{$database}].");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'app.timezone' => 'Asia/Dhaka',
            'zkteco.timezone' => 'Asia/Dhaka',
            'zkteco.allowed_ips' => [],
            'zkteco.admin_emails' => [],
            'zkteco.log.enabled' => false,
            'zkteco.queue_connection' => 'sync',
            'zkteco.employee.model' => Employee::class,
            'zkteco.employee.pin_column' => 'biometric_id',
            'zkteco.employee.global_pin_fallback' => true,
            'zkteco.employee.name_column' => 'name',
            'zkteco.employee.active_column' => 'status',
            'zkteco.employee.active_value' => 'active',
        ]);
        date_default_timezone_set('Asia/Dhaka');
        ZkLog::reset();

        $this->device = AttendanceDevice::create([
            'serial_number' => self::SN, 'name' => 'Main Entrance', 'model' => 'SenseFace 3A', 'is_active' => true,
        ]);
    }

    private function employee(string $code, string $name, ?string $biometricId = null): Employee
    {
        return Employee::create([
            'employee_id' => $code, 'biometric_id' => $biometricId, 'name' => $name,
            'email' => strtolower($code).'@example.test', 'joining_date' => '2026-01-01',
            'designation' => 'Nurse', 'department' => 'Ward', 'status' => 'active',
        ]);
    }

    private function attlog(string $body, string $sn = self::SN): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', "/iclock/cdata?SN={$sn}&table=ATTLOG&Stamp=9", [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    // ------------------------------------------------------------ registration

    public function test_device_registration_with_full_details_and_duplicate_serial_is_refused(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.hr.attendance.devices.store'), [
            'serial_number' => 'abc-77', 'name' => 'Back Gate', 'model' => 'SenseFace 3A',
            'ip_address' => '192.168.10.140', 'mac_address' => '00-17-61-11-4F-A2', 'port' => 4370,
            'protocol' => 'TA_PUSH', 'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_devices', [
            'serial_number' => 'ABC-77', 'mac_address' => '00:17:61:11:4f:a2', 'port' => 4370, 'protocol' => 'TA_PUSH', 'ip_address' => '192.168.10.140',
        ]);

        // Same serial (any case) again -> validation error, still one row.
        $this->post(route('admin.hr.attendance.devices.store'), ['serial_number' => 'ABC-77', 'name' => 'Copy'])
            ->assertSessionHasErrors('serial_number');
        $this->post(route('admin.hr.attendance.devices.store'), ['serial_number' => 'x', 'name' => 'Bad', 'mac_address' => 'nope', 'port' => 70000, 'ip_address' => '999.1.1.1'])
            ->assertSessionHasErrors(['mac_address', 'port', 'ip_address']);
        $this->assertSame(1, AttendanceDevice::where('serial_number', 'ABC-77')->count());
    }

    public function test_artisan_registration_updates_instead_of_duplicating(): void
    {
        Artisan::call('zkteco:device', ['serial' => self::SN, '--model' => 'SenseFace 3A', '--mac' => '00:17:61:11:4f:a1', '--ip' => '192.168.10.136', '--port' => '4370']);
        Artisan::call('zkteco:device', ['serial' => strtolower(self::SN)]);

        $this->assertSame(1, AttendanceDevice::where('serial_number', self::SN)->count());
        $d = AttendanceDevice::where('serial_number', self::SN)->first();
        $this->assertSame('00:17:61:11:4f:a1', $d->mac_address);
        $this->assertSame(4370, $d->port);
        $this->assertSame('TA_PUSH', $d->protocol);
    }

    // --------------------------------------------------------------- mapping

    public function test_attendance_is_resolved_through_the_device_mapping_not_the_employee_code(): void
    {
        $rahim = $this->employee('EMP-0015', 'Rahim');
        EmployeeDeviceMapping::create(['attendance_device_id' => $this->device->id, 'employee_id' => $rahim->id, 'device_user_id' => '1001', 'is_active' => true]);

        // Two punches in one request.
        $this->attlog("1001\t2026-11-14 08:58:00\t0\t15\t0\t0\n1001\t2026-11-14 17:02:00\t1\t15\t0\t0\n")->assertOk()->assertSee('OK: 2');

        $this->assertSame(2, AttendancePunch::where('employee_id', $rahim->id)->where('pin', '1001')->count());
        $day = EmployeeDailyAttendance::where('employee_id', $rahim->id)->firstOrFail();
        $this->assertSame('08:58', $day->first_in->format('H:i'));
        $this->assertSame('17:02', $day->last_out->format('H:i'));
    }

    public function test_same_pin_on_two_devices_maps_to_different_employees(): void
    {
        $a = $this->employee('EMP-1', 'A');
        $b = $this->employee('EMP-2', 'B');
        $other = AttendanceDevice::create(['serial_number' => 'SECOND01', 'name' => 'Ward', 'is_active' => true]);
        EmployeeDeviceMapping::create(['attendance_device_id' => $this->device->id, 'employee_id' => $a->id, 'device_user_id' => '7']);
        EmployeeDeviceMapping::create(['attendance_device_id' => $other->id, 'employee_id' => $b->id, 'device_user_id' => '7']);
        // Employee A is also enrolled on the second device under another PIN.
        EmployeeDeviceMapping::create(['attendance_device_id' => $other->id, 'employee_id' => $a->id, 'device_user_id' => '70']);

        $this->attlog("7\t2026-11-14 09:00:00\t0\t15\n")->assertOk();
        $this->attlog("7\t2026-11-14 09:01:00\t0\t15\n70\t2026-11-14 18:00:00\t1\t15\n", 'SECOND01')->assertOk();

        $this->assertSame($a->id, AttendancePunch::where('device_sn', self::SN)->value('employee_id'));
        $this->assertSame($b->id, AttendancePunch::where('device_sn', 'SECOND01')->where('pin', '7')->value('employee_id'));
        $this->assertSame($a->id, AttendancePunch::where('device_sn', 'SECOND01')->where('pin', '70')->value('employee_id'));
    }

    public function test_missing_mapping_keeps_the_punch_unresolved_and_creating_the_mapping_links_it(): void
    {
        $this->actingAs($this->admin());
        $emp = $this->employee('EMP-0099', 'Karima');

        $this->attlog("4242\t2026-11-14 08:00:00\t0\t15\n")->assertOk();
        $punch = AttendancePunch::firstOrFail();
        $this->assertNull($punch->employee_id);
        $this->assertSame('4242', $punch->pin);

        $this->get(route('admin.hr.attendance.machine.index', ['tab' => 'unmapped']))->assertOk()->assertSee('4242')->assertSee('Main Entrance');

        $this->post(route('admin.hr.attendance.mappings.store'), [
            'attendance_device_id' => $this->device->id, 'employee_id' => $emp->id, 'device_user_id' => ' 4242 ', 'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, '1 earlier punch'));

        $this->assertSame($emp->id, $punch->fresh()->employee_id);
        $this->assertTrue(EmployeeDailyAttendance::where('employee_id', $emp->id)->exists());
    }

    public function test_inactive_mapping_blocks_the_global_fallback(): void
    {
        $emp = $this->employee('EMP-3', 'Legacy', '555');
        $mapping = EmployeeDeviceMapping::create(['attendance_device_id' => $this->device->id, 'employee_id' => $emp->id, 'device_user_id' => '555', 'is_active' => false]);

        $this->attlog("555\t2026-11-14 08:00:00\t0\t15\n")->assertOk();
        $this->assertNull(AttendancePunch::first()->employee_id);

        // Without any mapping, the legacy biometric_id still works (existing feature preserved).
        $mapping->delete();
        $this->attlog("555\t2026-11-14 09:00:00\t0\t15\n")->assertOk();
        $this->assertSame($emp->id, AttendancePunch::where('punched_at', 'like', '%09:00:00')->value('employee_id'));
    }

    public function test_mapping_validation(): void
    {
        $this->actingAs($this->admin());
        $a = $this->employee('EMP-A', 'A');
        $b = $this->employee('EMP-B', 'B');
        $deleted = $this->employee('EMP-D', 'Gone');
        $deleted->delete();

        $ok = ['attendance_device_id' => $this->device->id, 'employee_id' => $a->id, 'device_user_id' => '1001', 'is_active' => '1'];
        $this->post(route('admin.hr.attendance.mappings.store'), $ok)->assertSessionHasNoErrors();

        // Same PIN on the same device for another employee -> refused.
        $this->post(route('admin.hr.attendance.mappings.store'), ['employee_id' => $b->id] + $ok)->assertSessionHasErrors('device_user_id');
        // Unknown device / employee, deleted employee, bad PINs.
        $this->post(route('admin.hr.attendance.mappings.store'), ['attendance_device_id' => 999] + $ok)->assertSessionHasErrors('attendance_device_id');
        $this->post(route('admin.hr.attendance.mappings.store'), ['employee_id' => 999] + $ok)->assertSessionHasErrors('employee_id');
        $this->post(route('admin.hr.attendance.mappings.store'), ['employee_id' => $deleted->id, 'device_user_id' => '1'] + $ok)->assertSessionHasErrors('employee_id');
        $this->post(route('admin.hr.attendance.mappings.store'), ['device_user_id' => "12\tC:1:REBOOT"] + $ok)->assertSessionHasErrors('device_user_id');
        $this->post(route('admin.hr.attendance.mappings.store'), ['device_user_id' => '0'] + $ok)->assertSessionHasErrors('device_user_id');

        $this->assertSame(1, EmployeeDeviceMapping::count());

        // Edit (keeping its own PIN is fine), deactivate, list/filter, delete.
        $m = EmployeeDeviceMapping::firstOrFail();
        $this->put(route('admin.hr.attendance.mappings.update', $m), ['employee_id' => $b->id, 'is_active' => '0'] + $ok)->assertSessionHasNoErrors();
        $this->assertFalse($m->fresh()->is_active);
        $this->assertSame($b->id, $m->fresh()->employee_id);

        $this->get(route('admin.hr.attendance.mappings.index', ['device_id' => $this->device->id, 'status' => 'inactive', 'search' => 'B']))
            ->assertOk()->assertSee('1001')->assertSee('EMP-B');
        $this->get(route('admin.hr.attendance.mappings.index', ['status' => 'active']))->assertOk()->assertSee('No mappings match');

        $this->delete(route('admin.hr.attendance.mappings.destroy', $m))->assertRedirect();
        $this->assertModelMissing($m);
    }

    public function test_mapped_only_employee_appears_on_the_daily_sheet(): void
    {
        $this->actingAs($this->admin());
        $emp = $this->employee('EMP-0042', 'Mapped Only');
        EmployeeDeviceMapping::create(['attendance_device_id' => $this->device->id, 'employee_id' => $emp->id, 'device_user_id' => '42']);
        $today = now('Asia/Dhaka')->toDateString();
        $this->attlog("42\t{$today} 08:05:00\t0\t15\n")->assertOk();

        $this->get(route('admin.hr.attendance.machine.index', ['date' => $today]))
            ->assertOk()->assertSee('Mapped Only')->assertSee('08:05 AM');
    }

    // ------------------------------------------------------------- commands

    public function test_push_employees_uses_mapped_pins_and_user_commands_are_validated(): void
    {
        $emp = $this->employee('EMP-0015', 'Rahim', '15'); // legacy id 15 must NOT be pushed: mapping wins
        $m = EmployeeDeviceMapping::create(['attendance_device_id' => $this->device->id, 'employee_id' => $emp->id, 'device_user_id' => '1001']);
        $this->employee('EMP-0016', 'Legacy Only', '16');

        $commands = app(DeviceCommandService::class);
        $this->assertSame(2, $commands->pushEmployees($this->device));
        $texts = AttendanceDeviceCommand::pluck('command')->implode("\n");
        $this->assertStringContainsString("PIN=1001\tName=Rahim", $texts);
        $this->assertStringContainsString("PIN=16\tName=Legacy Only", $texts);
        $this->assertStringNotContainsString('PIN=15', $texts);

        $this->expectException(InvalidArgumentException::class);
        $commands->deleteUser($this->device, "\t");
    }

    public function test_mapping_push_and_delete_from_device_go_through_the_queue(): void
    {
        $this->actingAs($this->admin());
        $emp = $this->employee('EMP-0015', 'Rahim');
        $m = EmployeeDeviceMapping::create(['attendance_device_id' => $this->device->id, 'employee_id' => $emp->id, 'device_user_id' => '1001']);

        $this->post(route('admin.hr.attendance.mappings.device', $m), ['action' => 'push'])->assertSessionHas('success');
        $this->post(route('admin.hr.attendance.mappings.device', $m), ['action' => 'remove'])->assertSessionHas('success');
        $this->post(route('admin.hr.attendance.mappings.device', $m), ['action' => 'raw'])->assertSessionHasErrors('action');

        // Poll -> both handed over; queued != done.
        $this->get('/iclock/getrequest?SN='.self::SN)->assertOk()
            ->assertSee("DATA UPDATE USERINFO PIN=1001\tName=Rahim", false)
            ->assertSee('DATA DELETE USERINFO PIN=1001', false);
        $this->assertSame(2, AttendanceDeviceCommand::where('status', AttendanceDeviceCommand::SENT)->count());

        [$push, $del] = AttendanceDeviceCommand::orderBy('id')->get()->all();
        $this->call('POST', '/iclock/devicecmd?SN='.self::SN, [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            "ID={$push->id}&Return=0&CMD=DATA\nID={$del->id}&Return=-1004&CMD=DATA\n")->assertOk()->assertSee('OK');

        $this->assertSame(AttendanceDeviceCommand::SUCCESS, $push->fresh()->status);
        $this->assertSame(AttendanceDeviceCommand::FAILED, $del->fresh()->status);
        $this->assertSame(-1004, $del->fresh()->return_code);
    }

    // ------------------------------------------------------- protocol guards

    public function test_impossible_timestamps_are_not_stored(): void
    {
        $future = now('Asia/Dhaka')->addDays(3)->format('Y-m-d H:i:s');
        $this->attlog("1\t1999-12-31 23:00:00\t0\t15\n2\t{$future}\t0\t15\n3\t2026-02-30 08:00:00\t0\t15\n4\t2026-11-14 08:00:00\t0\t15\n")
            ->assertOk()->assertSee('OK: 4'); // device may advance past the bad lines

        $this->assertSame(['4'], AttendancePunch::pluck('pin')->all());
    }

    public function test_oversized_body_is_refused(): void
    {
        config(['zkteco.max_body_kb' => 1]);
        $this->attlog(str_repeat("1\t2026-11-14 08:00:00\t0\t15\n", 100))->assertStatus(413);
        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_heartbeat_handshake_and_online_offline_state(): void
    {
        $this->assertSame('offline', $this->device->connectionState()); // never seen

        $this->get('/iclock/cdata?SN='.self::SN.'&options=all&pushver=2.4.1')->assertOk()->assertSee('GET OPTION FROM: '.self::SN);
        $this->get('/iclock/getrequest?SN='.self::SN.'&INFO=Ver%208.0.4,3,0,120,192.168.10.136,10,7,12,3')->assertOk()->assertSeeText('OK');

        $d = $this->device->fresh();
        $this->assertNotNull($d->last_handshake_at);
        $this->assertTrue($d->isOnline());
        $this->assertSame('online', $d->connectionState());
        $this->assertSame(3, $d->user_count);
        $this->assertSame(120, $d->attlog_count);

        $this->travel(config('zkteco.devices.online_threshold_seconds') + 5)->seconds();
        $this->assertSame('offline', $d->fresh()->connectionState());

        $d->update(['is_active' => false]);
        $this->assertSame('disabled', $d->fresh()->connectionState());

        $pending = AttendanceDevice::create(['serial_number' => 'NEW1', 'name' => 'x', 'is_active' => false]);
        $this->assertSame('pending', $pending->connectionState());
    }

    public function test_test_connection_reports_offline_honestly(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.hr.attendance.devices.command', $this->device), ['type' => 'test'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'never contacted'));

        $this->get('/iclock/getrequest?SN='.self::SN)->assertOk();
        $this->post(route('admin.hr.attendance.devices.command', $this->device), ['type' => 'test'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Connection OK'));

        $this->get(route('admin.hr.attendance.devices.index', ['history' => $this->device->id]))
            ->assertOk()->assertSee('Command history')->assertSee('Test connection')->assertSee('Logs received');
    }

    // --------------------------------------------------------- authorization

    public function test_admin_pages_require_login_and_permission(): void
    {
        $routes = [
            ['get', route('admin.hr.attendance.devices.index')],
            ['get', route('admin.hr.attendance.mappings.index')],
            ['get', route('admin.hr.attendance.machine.index')],
            ['post', route('admin.hr.attendance.devices.command', $this->device)],
            ['post', route('admin.hr.attendance.mappings.store')],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertStatus(401); // HTTP Basic challenge
        }
        $this->assertSame(0, AttendanceDeviceCommand::count());

        config(['zkteco.admin_emails' => ['boss@example.test']]);
        $this->actingAs(User::factory()->create(['email' => 'clerk@example.test']));
        $this->get(route('admin.hr.attendance.devices.index'))->assertForbidden();
        $this->post(route('admin.hr.attendance.devices.command', $this->device), ['type' => 'reboot'])->assertForbidden();

        $this->actingAs(User::factory()->create(['email' => 'boss@example.test']));
        $this->get(route('admin.hr.attendance.devices.index'))->assertOk();

        // Device endpoints never need a user, only a registered serial.
        auth()->logout();
        $this->get('/iclock/getrequest?SN='.self::SN)->assertOk();
    }
}
