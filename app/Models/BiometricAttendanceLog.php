<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiometricAttendanceLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'punched_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(
            AttendanceDevice::class,
            'device_id'
        );
    }
}