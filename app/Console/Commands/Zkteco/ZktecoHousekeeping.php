<?php

namespace App\Console\Commands\Zkteco;

use App\Models\AttendanceDeviceCommand;
use App\Models\EmployeeDailyAttendance;
use Illuminate\Console\Command;

class ZktecoHousekeeping extends Command
{
    protected $signature = 'zkteco:housekeeping {--keep-commands=60 : Days of command history to keep}';

    protected $description = 'Expire unanswered device commands, close finished duties, prune old command history';

    public function handle(): int
    {
        $expired = AttendanceDeviceCommand::expireStale();

        // A single punch whose duty window has passed is no longer "on duty" but "incomplete" (no OUT).
        $maxShiftMinutes = (int) round((float) config('zkteco.attendance.max_shift_hours', 16) * 60);
        $closed = EmployeeDailyAttendance::query()
            ->where('status', EmployeeDailyAttendance::ON_DUTY)
            ->where('first_in', '<', now()->subMinutes($maxShiftMinutes)->format('Y-m-d H:i:s'))
            ->where('is_locked', false)
            ->update(['status' => EmployeeDailyAttendance::INCOMPLETE]);

        $pruned = AttendanceDeviceCommand::query()
            ->whereIn('status', [AttendanceDeviceCommand::SUCCESS, AttendanceDeviceCommand::FAILED, AttendanceDeviceCommand::EXPIRED])
            ->where('created_at', '<', now()->subDays((int) $this->option('keep-commands')))
            ->delete();

        $this->components->info("Expired {$expired} command(s), closed {$closed} duty row(s), pruned {$pruned} old command(s).");

        return self::SUCCESS;
    }
}
