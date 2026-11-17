<?php

namespace App\Events\Zkteco;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after employee_daily_attendances rows were (re)built.
 * Listen to this if you also want to copy the result into another table
 * (for example your existing employee_attendances used by EmployeeAttendanceController).
 */
class DailyAttendanceUpdated
{
    use Dispatchable;

    /** @param list<string> $dates Y-m-d duty dates */
    public function __construct(
        public readonly int $employeeId,
        public readonly array $dates,
    ) {
    }
}
