<?php

namespace App\Console\Commands\Zkteco;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use Illuminate\Console\Command;

class ZktecoStatus extends Command
{
    protected $signature = 'zkteco:status';

    protected $description = 'Show ZKTeco terminals, connection state and today\'s punches';

    public function handle(): int
    {
        $devices = AttendanceDevice::query()->orderBy('name')->get();

        if ($devices->isEmpty()) {
            $this->components->warn('No devices yet. Point the terminal at this server or run: php artisan zkteco:device <SERIAL>');

            return self::SUCCESS;
        }

        $today = now()->startOfDay()->format('Y-m-d H:i:s');

        $this->table(
            ['Name', 'Serial', 'State', 'IP', 'Last seen', 'Last punch', 'Today', 'Users', 'Faces', 'Logs on device'],
            $devices->map(fn (AttendanceDevice $d) => [
                $d->name,
                $d->serial_number,
                !$d->is_active ? 'DISABLED/PENDING' : ($d->isOnline() ? 'ONLINE' : 'OFFLINE'),
                $d->ip_address ?? '-',
                $d->last_seen_at?->diffForHumans() ?? 'never',
                $d->last_punch_at?->format('d M H:i') ?? '-',
                AttendancePunch::query()->where('attendance_device_id', $d->id)->where('punched_at', '>=', $today)->count(),
                $d->user_count ?? '-',
                $d->face_count ?? '-',
                $d->attlog_count ?? '-',
            ])->all(),
        );

        $unmapped = AttendancePunch::query()->whereNull('employee_id')->distinct()->count('pin');
        if ($unmapped > 0) {
            $this->components->warn("{$unmapped} device PIN(s) are not linked to any employee. Set the employee code, then run: php artisan zkteco:rebuild --relink");
        }

        return self::SUCCESS;
    }
}
