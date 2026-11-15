<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\BiometricAttendanceLog;

class ZktecoDailyAttendanceSync
{
    public function sync(
        int $employeeId,
        BiometricAttendanceLog $log
    ): void {

        $date = $log->punched_at->toDateString();

        $attendance = EmployeeAttendance::firstOrNew([
            'employee_id' => $employeeId,
            'attendance_date' => $date,
        ]);

        /*
         * Do not overwrite manually recorded attendance.
         * Assumes recorded_by is nullable.
         */
        if (
            $attendance->exists &&
            !is_null($attendance->recorded_by)
        ) {
            return;
        }

        $field = match ((int) $log->punch_status) {

            0 => 'check_in',

            1 => 'check_out',

            2 => 'break_out',

            3 => 'break_in',

            default => null,
        };

        if (!$field) {
            return;
        }

        $newTime = $log->punched_at->format('H:i:s');

        $currentTime = $attendance->{$field};

        $earliestFields = [
            'check_in',
            'break_out',
        ];

        $latestFields = [
            'check_out',
            'break_in',
        ];

        if (!$currentTime) {

            $attendance->{$field} = $newTime;

        } elseif (
            in_array($field, $earliestFields) &&
            $newTime < $currentTime
        ) {

            $attendance->{$field} = $newTime;

        } elseif (
            in_array($field, $latestFields) &&
            $newTime > $currentTime
        ) {

            $attendance->{$field} = $newTime;
        }

        if (!$attendance->status) {
            $attendance->status = 'Present';
        }

        $attendance->save();
    }
}