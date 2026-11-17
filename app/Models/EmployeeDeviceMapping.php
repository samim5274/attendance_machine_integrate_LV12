<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * (terminal, PIN) -> employee. The PIN is whatever "User ID" was typed on the
 * terminal; it is never assumed to equal the employee's id or code.
 */
class EmployeeDeviceMapping extends Model
{
    protected $fillable = ['attendance_device_id', 'employee_id', 'device_user_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
