<?php

namespace Tests\Feature\Zkteco;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceCommand;
use App\Models\Employee;
use App\Models\User;
use App\Services\Zkteco\ZkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blade admin pages (Machine Attendance / Attendance Devices) against the real
 * Employee model and its biometric_id column.
 */
class ZktecoAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private const SN = 'VGU56244900309';

    /** Same safety net as ZktecoAdmsTest: never wipe a non-test database. */
    protected function beforeRefreshingDatabase()
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && ! str_contains(strtolower($database), 'test')) {
            $this->markTestSkipped("Refusing to run: RefreshDatabase would wipe [{$connection}:{$database}].");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'zkteco.timezone' => 'Asia/Dhaka',
            'zkteco.allowed_ips' => [],
            'zkteco.log.enabled' => false,
            'zkteco.queue_connection' => 'sync',
            'zkteco.employee.model' => Employee::class,
            'zkteco.employee.pin_column' => 'biometric_id',
            'zkteco.employee.name_column' => 'name',
            'zkteco.employee.active_column' => 'status',
            'zkteco.employee.active_value' => 'active',
        ]);
        ZkLog::reset();

        foreach ([['EMP-1001', '1001', 'Rahim Uddin', 'active'], ['EMP-1002', '1002', 'Karima Begum', 'active'], ['EMP-1003', null, 'No Device', 'active'], ['EMP-1004', '1004', 'Left Already', 'inactive']] as [$code, $bio, $name, $status]) {
            Employee::create([
                'employee_id' => $code, 'biometric_id' => $bio, 'name' => $name, 'email' => strtolower($code).'@example.test',
                'joining_date' => '2026-01-01', 'designation' => 'Nurse', 'department' => 'Ward', 'status' => $status,
            ]);
        }

        AttendanceDevice::create(['serial_number' => self::SN, 'name' => 'Main Entrance', 'is_active' => true]);

        // Admin pages require a logged-in user.
        $this->actingAs(User::factory()->create());
    }

    private function punch(string $pin, string $at): void
    {
        $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=ATTLOG&Stamp=1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "{$pin}\t{$at}\t0\t15\t\t0\t0\n")
            ->assertOk();
    }

    public function test_machine_attendance_tabs_render(): void
    {
        $today = now('Asia/Dhaka')->toDateString();
        $this->punch('1001', "{$today} 08:02:00");
        $this->punch('99999', "{$today} 08:05:00");

        $this->get(route('admin.hr.attendance.machine.index'))
            ->assertOk()
            ->assertSee('Machine Attendance')
            ->assertSee('Rahim Uddin')
            ->assertSee('08:02 AM')
            ->assertSee('Karima Begum')
            ->assertDontSee('No Device')      // no biometric_id -> not on the sheet
            ->assertDontSee('Left Already');  // inactive

        $this->get(route('admin.hr.attendance.machine.index', ['tab' => 'daily', 'status' => 'absent']))
            ->assertOk()->assertSee('Karima Begum')->assertDontSee('Rahim Uddin');

        $this->get(route('admin.hr.attendance.machine.index', ['tab' => 'punches', 'from' => $today, 'to' => $today]))
            ->assertOk()->assertSee('Rahim Uddin')->assertSee('Not linked');

        $this->get(route('admin.hr.attendance.machine.index', ['tab' => 'unmapped']))
            ->assertOk()->assertSee('99999');
    }

    public function test_relink_after_setting_biometric_id(): void
    {
        $this->punch('2002', now('Asia/Dhaka')->format('Y-m-d').' 09:00:00');
        Employee::where('employee_id', 'EMP-1003')->update(['biometric_id' => '2002']);

        $this->post(route('admin.hr.attendance.machine.relink'))->assertRedirect()->assertSessionHas('success');

        $this->get(route('admin.hr.attendance.machine.index', ['tab' => 'unmapped']))
            ->assertOk()->assertSee('Every punch is linked');
    }

    public function test_export_csv(): void
    {
        $today = now('Asia/Dhaka')->toDateString();
        $this->punch('1001', "{$today} 08:02:00");

        $csv = $this->get(route('admin.hr.attendance.machine.export', ['from' => $today, 'to' => $today]))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Rahim Uddin', $csv);
        $this->assertStringContainsString('08:02 AM', $csv);
    }

    public function test_devices_page_and_actions(): void
    {
        $this->get(route('admin.hr.attendance.devices.index'))
            ->assertOk()->assertSee('Attendance Devices')->assertSee(self::SN)->assertSee('Cloud Server Setting');

        $this->post(route('admin.hr.attendance.devices.store'), ['serial_number' => 'abc123', 'name' => 'Back Door', 'is_active' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $device = AttendanceDevice::where('serial_number', 'ABC123')->firstOrFail();

        $this->put(route('admin.hr.attendance.devices.update', $device), ['name' => 'Back Door 2', 'location' => '', 'is_active' => '0'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($device->fresh()->is_active);

        // Disabled, never-connected device: commands refused; listed under "waiting for approval".
        $this->post(route('admin.hr.attendance.devices.command', $device), ['type' => 'info'])->assertSessionHas('error');
        $this->get(route('admin.hr.attendance.devices.index'))->assertOk()->assertSee('waiting for approval')->assertSee('Approve');

        $main = AttendanceDevice::where('serial_number', self::SN)->firstOrFail();
        $this->post(route('admin.hr.attendance.devices.command', $main), ['type' => 'info'])->assertSessionHas('success');
        $this->post(route('admin.hr.attendance.devices.command', $main), ['type' => 'sync_logs', 'from' => '2026-11-01', 'to' => '2026-11-07'])
            ->assertSessionHas('success');
        $this->assertSame(2, AttendanceDeviceCommand::where('attendance_device_id', $main->id)->count());

        $this->post(route('admin.hr.attendance.devices.command', $main), ['type' => 'push_employees'])->assertSessionHas('success');
        $this->get('/iclock/getrequest?SN='.self::SN)->assertOk()->assertSee('1001')->assertSee('1002')->assertDontSee('1004');

        $this->delete(route('admin.hr.attendance.devices.destroy', $device))->assertRedirect();
        $this->assertModelMissing($device);
    }

    public function test_validation_errors_are_shown(): void
    {
        $this->from(route('admin.hr.attendance.devices.index'))
            ->post(route('admin.hr.attendance.devices.store'), ['serial_number' => self::SN, 'name' => '', '_method' => 'POST'])
            ->assertRedirect(route('admin.hr.attendance.devices.index'))
            ->assertSessionHasErrors(['serial_number', 'name']);

        $this->followRedirects($this->from(route('admin.hr.attendance.devices.index'))
            ->post(route('admin.hr.attendance.devices.store'), ['serial_number' => self::SN, 'name' => '']))
            ->assertOk()->assertSee('alert-danger', false);
    }
}
