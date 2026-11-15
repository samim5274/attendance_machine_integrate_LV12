<?php

namespace App\Console\Commands\Zkteco;

use App\Models\AttendanceDevice;
use Illuminate\Console\Command;

class ZktecoDevice extends Command
{
    protected $signature = 'zkteco:device
        {serial : Device serial number (Menu > System Info > Device Info)}
        {--name= : Friendly name, e.g. "Main Gate"}
        {--location= : Where it is installed}
        {--disable : Disable instead of enable}';

    protected $description = 'Register (or enable/disable) a ZKTeco terminal so it may push attendance';

    public function handle(): int
    {
        $serial = strtoupper(trim((string) $this->argument('serial')));

        $device = AttendanceDevice::query()->firstOrNew(['serial_number' => $serial]);
        $device->name = $this->option('name') ?: ($device->name ?: 'Attendance '.$serial);
        if ($this->option('location')) {
            $device->location = $this->option('location');
        }
        $device->is_active = !$this->option('disable');
        $device->save();

        $this->components->info(sprintf(
            '%s [%s] is now %s.',
            $device->name,
            $device->serial_number,
            $device->is_active ? 'ACTIVE' : 'DISABLED',
        ));

        return self::SUCCESS;
    }
}
