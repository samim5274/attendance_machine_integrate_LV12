<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDeviceCommand extends Model
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';

    protected $fillable = [
        'attendance_device_id', 'type', 'command', 'status', 'return_code', 'response',
        'requested_by', 'sent_at', 'completed_at',
    ];

    protected $casts = [
        'return_code' => 'integer',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    /** Mark commands that were handed to a device but never acknowledged. */
    public static function expireStale(): int
    {
        $minutes = (int) config('zkteco.devices.command_timeout_minutes', 30);

        return static::query()
            ->whereIn('status', [self::PENDING, self::SENT])
            ->where(function ($q) use ($minutes) {
                $q->where(fn ($q) => $q->where('status', self::SENT)->where('sent_at', '<', now()->subMinutes($minutes)))
                    // pending for a day = device never came online
                    ->orWhere(fn ($q) => $q->where('status', self::PENDING)->where('created_at', '<', now()->subDay()));
            })
            ->update(['status' => self::EXPIRED, 'completed_at' => now()]);
    }
}
