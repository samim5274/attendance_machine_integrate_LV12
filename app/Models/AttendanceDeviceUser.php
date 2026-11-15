<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDeviceUser extends Model
{
    protected $fillable = [
        'attendance_device_id', 'pin', 'name', 'privilege', 'card', 'has_face', 'fingerprint_count',
    ];

    protected $casts = [
        'privilege' => 'integer',
        'has_face' => 'boolean',
        'fingerprint_count' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}
