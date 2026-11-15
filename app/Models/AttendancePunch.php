<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePunch extends Model
{
    public $timestamps = false;

    public const STATUS_LABELS = [
        0 => 'Check In',
        1 => 'Check Out',
        2 => 'Break Out',
        3 => 'Break In',
        4 => 'OT In',
        5 => 'OT Out',
    ];

    public const VERIFY_LABELS = [
        0 => 'Password',
        1 => 'Fingerprint',
        2 => 'Card',
        3 => 'Password',
        4 => 'Card',
        15 => 'Face',
        25 => 'Palm',
    ];

    protected $fillable = [
        'attendance_device_id', 'device_sn', 'pin', 'employee_id', 'punched_at',
        'attendance_date', 'status', 'verify_type', 'work_code', 'raw', 'received_at',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
        'received_at' => 'datetime',
        'status' => 'integer',
        'verify_type' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? 'Punch';
    }

    public function verifyLabel(): string
    {
        return self::VERIFY_LABELS[$this->verify_type] ?? 'Other ('.$this->verify_type.')';
    }
}
