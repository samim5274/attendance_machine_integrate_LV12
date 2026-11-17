<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDevice extends Model
{
    protected $fillable = [
        'serial_number', 'name', 'model', 'location', 'ip_address', 'mac_address', 'port', 'protocol',
        'firmware_version', 'push_version', 'timezone', 'is_active',
        'attlog_stamp', 'operlog_stamp', 'attphoto_stamp',
        'user_count', 'fp_count', 'face_count', 'attlog_count', 'options',
        'last_seen_at', 'last_handshake_at', 'last_punch_at', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'port' => 'integer',
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

    public function mappings(): HasMany
    {
        return $this->hasMany(EmployeeDeviceMapping::class);
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

    /**
     * online   = active and talked to us within the threshold
     * offline  = active but silent (or never connected)
     * pending  = unknown terminal that connected by itself and awaits approval
     * disabled = deliberately switched off by an admin
     */
    public function connectionState(): string
    {
        if ($this->is_active) {
            return $this->isOnline() ? 'online' : 'offline';
        }

        return $this->last_handshake_at === null && !$this->punches()->exists() ? 'pending' : 'disabled';
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
