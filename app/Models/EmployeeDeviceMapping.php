<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDeviceMapping extends Model
{
    protected $guarded = [];

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}