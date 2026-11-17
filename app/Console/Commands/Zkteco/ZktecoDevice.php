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
        {--model= : Model, e.g. "SenseFace 3A"}
        {--mac= : MAC address, e.g. 00:17:61:11:4f:a1}
        {--ip= : Device IP (informational; the device identifies itself by serial number)}
        {--port= : Device comm/SDK port (informational, usually 4370 — NOT the server port)}
        {--disable : Disable instead of enable}';

    protected $description = 'Register (or enable/disable) a ZKTeco terminal so it may push attendance';

    public function handle(): int
    {
        $serial = strtoupper(trim((string) $this->argument('serial')));

        if (!preg_match('/^[A-Z0-9\-_.]{1,64}$/', $serial)) {
            $this->components->error('Invalid serial number.');

            return self::INVALID;
        }
        if (($mac = $this->option('mac')) && !preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/', $mac)) {
            $this->components->error('Invalid MAC address.');

            return self::INVALID;
        }
        if (($ip = $this->option('ip')) && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->components->error('Invalid IP address.');

            return self::INVALID;
        }
        $port = $this->option('port');
        if ($port !== null && (!ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535)) {
            $this->components->error('Invalid port.');

            return self::INVALID;
        }

        // Serial number is the identity: update the existing row, never create a duplicate.
        $device = AttendanceDevice::query()->firstOrNew(['serial_number' => $serial]);
        $existed = $device->exists;
        $device->name = $this->option('name') ?: ($device->name ?: 'Attendance '.$serial);
        if ($this->option('location')) {
            $device->location = $this->option('location');
        }
        foreach (['model' => $this->option('model'), 'mac_address' => $mac ? strtolower(str_replace('-', ':', $mac)) : null, 'ip_address' => $ip, 'port' => $port !== null ? (int) $port : null] as $column => $value) {
            if ($value !== null && $value !== '') {
                $device->{$column} = $value;
            }
        }
        $device->protocol = $device->protocol ?: 'TA_PUSH';
        $device->is_active = !$this->option('disable');
        $device->save();

        $this->components->info(sprintf(
            '%s %s [%s] is now %s.',
            $existed ? 'Updated' : 'Registered',
            $device->name,
            $device->serial_number,
            $device->is_active ? 'ACTIVE' : 'DISABLED',
        ));

        return self::SUCCESS;
    }
}
