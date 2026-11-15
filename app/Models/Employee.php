<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'joining_date',
        'designation',
        'department',
        'salary',
        'address',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'salary' => 'decimal:2',
        ];
    }

    public function deviceMappings()
    {
        return $this->hasMany(EmployeeDeviceMapping::class);
    }
}
