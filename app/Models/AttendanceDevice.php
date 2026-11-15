<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDevice extends Model
{
    protected $fillable = [
        'serial_number', 'name', 'model', 'location', 'ip_address', 'mac_address',
        'firmware_version', 'push_version', 'timezone', 'is_active',
        'attlog_stamp', 'operlog_stamp', 'attphoto_stamp',
        'user_count', 'fp_count', 'face_count', 'attlog_count', 'options',
        'last_seen_at', 'last_handshake_at', 'last_punch_at', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'options' => 'array',
        'user_count' => 'integer',
        'fp_count' => 'integer',
        'face_count' => 'integer',
        'attlog_count' => 'integer',
        'last_seen_at' => 'datetime',
        'last_handshake_at' => 'datetime',
        'last_punch_at' => 'datetime',
    ];

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(AttendanceDeviceUser::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(AttendanceDeviceCommand::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isOnline(): bool
    {
        $threshold = (int) config('zkteco.devices.online_threshold_seconds', 120);

        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan(now()->subSeconds($threshold));
    }

    /** Time zone the device clock runs in. */
    public function clockTimezone(): string
    {
        return $this->timezone ?: (string) config('zkteco.timezone', 'Asia/Dhaka');
    }

    /** Merge new key/values into the JSON options column. */
    public function mergeOptions(array $values): void
    {
        $this->options = array_merge($this->options ?? [], $values);
    }
}
