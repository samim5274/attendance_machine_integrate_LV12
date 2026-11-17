<?php

namespace App\Services\Zkteco;

use App\Models\EmployeeDeviceMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps device PINs ("User ID" on the terminal) to employee primary keys,
 * using config('zkteco.employee').
 */
class EmployeeResolver
{
    /** @var array<string, int|null> keyed by "deviceId|pin" */
    private array $cache = [];

    public function available(): bool
    {
        $model = config('zkteco.employee.model');

        return is_string($model) && class_exists($model);
    }

    public function model(): ?Model
    {
        if (!$this->available()) {
            return null;
        }
        $class = config('zkteco.employee.model');

        return new $class();
    }

    public function query(): ?Builder
    {
        return $this->model()?->newQuery();
    }

    public function pinColumn(): string
    {
        return (string) config('zkteco.employee.pin_column', 'employee_code');
    }

    public function nameColumn(): string
    {
        return (string) config('zkteco.employee.name_column', 'name');
    }

    /** Apply the optional "active employees only" filter. */
    public function applyActive(Builder|\Illuminate\Database\Query\Builder $query, string $prefix = ''): void
    {
        $column = config('zkteco.employee.active_column');
        if ($column) {
            $query->where($prefix.$column, config('zkteco.employee.active_value', 1));
        }
    }

    /** Whether the legacy global PIN column (employees.biometric_id) is consulted. */
    public function usesGlobalPin(): bool
    {
        return (bool) config('zkteco.employee.global_pin_fallback', true) && $this->pinColumn() !== '';
    }

    /**
     * Resolve device PINs to employee ids.
     *
     * Order, per PIN:
     *   1. active EmployeeDeviceMapping for (device, PIN)      -> that employee
     *   2. inactive mapping for (device, PIN)                  -> unresolved (deliberately switched off)
     *   3. legacy global column (employees.biometric_id)       -> that employee (if enabled)
     *   4. otherwise unresolved (punch is still stored, employee_id stays NULL)
     *
     * @param  iterable<string>  $pins
     * @return array<string, int>  pin => employee id (unresolved pins omitted)
     */
    public function map(iterable $pins, ?int $deviceId = null): array
    {
        $pins = array_values(array_unique(array_map('strval', is_array($pins) ? $pins : iterator_to_array($pins))));
        $prefix = ($deviceId ?? 0).'|';
        $missing = array_values(array_filter($pins, fn ($p) => !array_key_exists($prefix.$p, $this->cache)));

        foreach (array_chunk($missing, 500) as $chunk) {
            $resolved = [];

            if ($deviceId !== null) {
                $mappings = EmployeeDeviceMapping::query()
                    ->where('attendance_device_id', $deviceId)
                    ->whereIn('device_user_id', $chunk)
                    ->get(['device_user_id', 'employee_id', 'is_active']);

                foreach ($mappings as $m) {
                    // An inactive mapping blocks the fallback on purpose.
                    $resolved[(string) $m->device_user_id] = $m->is_active ? (int) $m->employee_id : null;
                }
            }

            $rest = array_values(array_filter($chunk, fn ($p) => !array_key_exists($p, $resolved)));
            if ($rest !== [] && $this->usesGlobalPin() && ($query = $this->query())) {
                $found = $query->whereIn($this->pinColumn(), $rest)->pluck($this->model()->getKeyName(), $this->pinColumn());
                foreach ($rest as $pin) {
                    $resolved[$pin] = isset($found[$pin]) ? (int) $found[$pin] : null;
                }
            }

            foreach ($chunk as $pin) {
                $this->cache[$prefix.$pin] = $resolved[$pin] ?? null;
            }
        }

        $out = [];
        foreach ($pins as $pin) {
            if (($this->cache[$prefix.$pin] ?? null) !== null) {
                $out[$pin] = $this->cache[$prefix.$pin];
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
