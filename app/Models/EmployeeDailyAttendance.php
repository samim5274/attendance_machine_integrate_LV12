<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDailyAttendance extends Model
{
    public const PRESENT = 'present';
    public const ON_DUTY = 'on_duty';      // checked in, duty still running
    public const INCOMPLETE = 'incomplete'; // single punch, duty window passed

    protected $fillable = [
        'employee_id', 'attendance_date', 'first_in', 'last_out', 'worked_minutes', 'punch_count',
        'shift_name', 'late_minutes', 'status', 'first_device_id', 'source', 'is_locked', 'remarks',
    ];

    protected $casts = [
        'first_in' => 'datetime',
        'last_out' => 'datetime',
        'worked_minutes' => 'integer',
        'punch_count' => 'integer',
        'late_minutes' => 'integer',
        'is_locked' => 'boolean',
    ];
}
