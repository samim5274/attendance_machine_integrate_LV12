<?php

namespace App\Listeners;

use App\Events\Zkteco\DailyAttendanceUpdated;
use App\Models\EmployeeAttendance;            // <- your existing model
use App\Models\EmployeeDailyAttendance;

class CopyMachineAttendance
{
    public function handle(DailyAttendanceUpdated $event): void
    {
        EmployeeDailyAttendance::where('employee_id', $event->employeeId)
            ->whereIn('attendance_date', $event->dates)
            ->get()
            ->each(fn ($d) => EmployeeAttendance::updateOrCreate(
                ['employee_id' => $d->employee_id, 'date' => $d->attendance_date],
                ['in_time' => $d->first_in, 'out_time' => $d->last_out, 'status' => 'present', 'source' => 'device'],
            ));
    }
}