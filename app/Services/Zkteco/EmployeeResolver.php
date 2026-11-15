<?php

namespace App\Services\Zkteco;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps device PINs ("User ID" on the terminal) to employee primary keys,
 * using config('zkteco.employee').
 */
class EmployeeResolver
{
    /** @var array<string, int|null> */
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

    /**
     * @param  iterable<string>  $pins
     * @return array<string, int>  pin => employee id (unknown pins omitted)
     */
    public function map(iterable $pins): array
    {
        $pins = array_values(array_unique(array_map('strval', is_array($pins) ? $pins : iterator_to_array($pins))));
        $missing = array_values(array_filter($pins, fn ($p) => !array_key_exists($p, $this->cache)));

        if ($missing && ($query = $this->query())) {
            $key = $this->model()->getKeyName();
            $column = $this->pinColumn();

            foreach (array_chunk($missing, 500) as $chunk) {
                $found = (clone $query)->whereIn($column, $chunk)->pluck($key, $column);
                foreach ($chunk as $pin) {
                    $this->cache[$pin] = isset($found[$pin]) ? (int) $found[$pin] : null;
                }
            }
        }

        $out = [];
        foreach ($pins as $pin) {
            if (($this->cache[$pin] ?? null) !== null) {
                $out[$pin] = $this->cache[$pin];
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
