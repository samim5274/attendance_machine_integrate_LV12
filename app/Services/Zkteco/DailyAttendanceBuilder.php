<?php

namespace App\Services\Zkteco;

use App\Events\Zkteco\DailyAttendanceUpdated;
use App\Models\AttendancePunch;
use App\Models\EmployeeDailyAttendance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turns raw punches into one row per employee per duty date.
 *
 * Duty ("session") rule — works without a roster and across midnight:
 *   - punches are taken in time order;
 *   - a punch more than max_shift_hours after the FIRST punch of the current
 *     duty starts a new duty;
 *   - the duty is dated on the day of its first punch (night shift 20:00 ->
 *     08:00 belongs to the evening it started);
 *   - first punch = IN, last punch = OUT, repeated punches within
 *     duplicate_punch_minutes are counted once.
 */
class DailyAttendanceBuilder
{
    /**
     * Rebuild an employee's attendance from the duty that contains $from onwards.
     *
     * @return list<string> duty dates (Y-m-d) written
     */
    public function rebuild(int $employeeId, string|CarbonInterface $from): array
    {
        $appTz = (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::parse($from, $appTz);

        $dates = DB::transaction(function () use ($employeeId, $from) {
            $start = $this->dutyStartAtOrBefore($employeeId, $from);

            $punches = AttendancePunch::query()
                ->where('employee_id', $employeeId)
                ->where('punched_at', '>=', $start->format('Y-m-d H:i:s'))
                ->orderBy('punched_at')
                ->orderBy('id')
                ->get(['id', 'punched_at', 'attendance_device_id']);

            if ($punches->isEmpty()) {
                return [];
            }

            $tz = (string) config('zkteco.timezone', 'Asia/Dhaka');
            $sessions = self::sessions(
                $punches->map(fn ($p) => [
                    'id' => $p->id,
                    'at' => $p->punched_at->toImmutable()->setTimezone($tz),
                    'device_id' => $p->attendance_device_id,
                ])->all(),
                (int) round((float) config('zkteco.attendance.max_shift_hours', 16) * 60),
                (int) config('zkteco.attendance.duplicate_punch_minutes', 2),
            );

            $days = self::summariseByDate($sessions, CarbonImmutable::now($tz));
            $this->persist($employeeId, $days, $start->setTimezone($tz)->format('Y-m-d'));

            return array_keys($days);
        });

        if ($dates !== []) {
            event(new DailyAttendanceUpdated($employeeId, $dates));
        }

        return $dates;
    }

    /**
     * Start of the duty that $from may belong to. Always a real duty start, so
     * a partial rebuild never splits a duty in the middle.
     */
    public function dutyStartAtOrBefore(int $employeeId, CarbonImmutable $from): CarbonImmutable
    {
        $maxShiftMinutes = (float) config('zkteco.attendance.max_shift_hours', 16) * 60;

        $previous = AttendancePunch::query()
            ->where('employee_id', $employeeId)
            ->where('punched_at', '<', $from->format('Y-m-d H:i:s'))
            ->whereNotNull('attendance_date')
            ->orderByDesc('punched_at')
            ->first(['punched_at', 'attendance_date']);

        if ($previous === null || $previous->punched_at->diffInMinutes($from, true) > $maxShiftMinutes) {
            return $from;
        }

        $dutyStart = AttendancePunch::query()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', $previous->attendance_date)
            ->min('punched_at');

        return $dutyStart ? CarbonImmutable::parse($dutyStart, $from->getTimezone()) : $from;
    }

    /**
     * @param  list<array{id:int, at:CarbonImmutable, device_id:?int}>  $punches  sorted by time
     * @return list<array{start:CarbonImmutable, end:?CarbonImmutable, count:int, ids:list<int>, device_id:?int}>
     */
    public static function sessions(array $punches, int $maxShiftMinutes, int $duplicateMinutes): array
    {
        $sessions = [];
        $current = null;
        $lastCounted = null;

        foreach ($punches as $p) {
            $at = $p['at'];

            if ($current === null || $current['start']->diffInMinutes($at, true) > $maxShiftMinutes) {
                if ($current !== null) {
                    $sessions[] = $current;
                }
                $current = ['start' => $at, 'end' => null, 'count' => 1, 'ids' => [$p['id']], 'device_id' => $p['device_id']];
                $lastCounted = $at;
                continue;
            }

            $current['ids'][] = $p['id'];

            if ($lastCounted->diffInSeconds($at, true) >= $duplicateMinutes * 60) {
                $current['count']++;
                $current['end'] = $at;
                $lastCounted = $at;
            }
        }

        if ($current !== null) {
            $sessions[] = $current;
        }

        return $sessions;
    }

    /**
     * Merge duties that start on the same date and compute status/shift/late.
     *
     * @return array<string, array<string, mixed>> keyed by Y-m-d
     */
    public static function summariseByDate(array $sessions, CarbonImmutable $now): array
    {
        $maxShiftMinutes = (float) config('zkteco.attendance.max_shift_hours', 16) * 60;
        $days = [];

        foreach ($sessions as $s) {
            $date = $s['start']->format('Y-m-d');

            if ($s['count'] >= 2) {
                $status = EmployeeDailyAttendance::PRESENT;
            } elseif ($s['start']->diffInMinutes($now, true) < $maxShiftMinutes && $s['start']->lessThanOrEqualTo($now)) {
                $status = EmployeeDailyAttendance::ON_DUTY;
            } else {
                $status = EmployeeDailyAttendance::INCOMPLETE;
            }

            $worked = $s['end'] ? (int) $s['start']->diffInMinutes($s['end'], true) : 0;

            if (!isset($days[$date])) {
                [$shift, $late] = self::matchShift($s['start']);
                $days[$date] = [
                    'first_in' => $s['start'],
                    'last_out' => $s['end'],
                    'worked_minutes' => $worked,
                    'punch_count' => $s['count'],
                    'shift_name' => $shift,
                    'late_minutes' => $late,
                    'status' => $status,
                    'first_device_id' => $s['device_id'],
                    'punch_ids' => $s['ids'],
                ];
                continue;
            }

            // Second duty on the same date (e.g. morning + night double shift).
            $d = &$days[$date];
            $d['last_out'] = $s['end'] ?? $d['last_out'];
            $d['worked_minutes'] += $worked;
            $d['punch_count'] += $s['count'];
            $d['punch_ids'] = array_merge($d['punch_ids'], $s['ids']);
            $rank = [EmployeeDailyAttendance::PRESENT => 3, EmployeeDailyAttendance::ON_DUTY => 2, EmployeeDailyAttendance::INCOMPLETE => 1];
            if ($rank[$status] > $rank[$d['status']]) {
                $d['status'] = $status;
            }
            unset($d);
        }

        return $days;
    }

    /**
     * @return array{0: ?string, 1: int} [shift name, late minutes]
     */
    public static function matchShift(CarbonImmutable $firstIn): array
    {
        $shifts = (array) config('zkteco.attendance.shifts', []);
        if ($shifts === []) {
            return [null, 0];
        }

        $grace = (int) config('zkteco.attendance.late_grace_minutes', 10);
        $window = (int) config('zkteco.attendance.match_window_minutes', 180);
        $t = (int) $firstIn->format('G') * 60 + (int) $firstIn->format('i');

        $onTime = null;
        $late = null;

        foreach ($shifts as $shift) {
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string) ($shift['start'] ?? ''), $m)) {
                continue;
            }
            $delta = $t - ((int) $m[1] * 60 + (int) $m[2]);
            // Wrap around midnight into (-720, 720].
            if ($delta > 720) {
                $delta -= 1440;
            } elseif ($delta <= -720) {
                $delta += 1440;
            }
            if (abs($delta) > $window) {
                continue;
            }
            if ($delta <= $grace) {
                if ($onTime === null || $delta > $onTime[1]) {
                    $onTime = [$shift['name'] ?? null, $delta];
                }
            } elseif ($late === null || $delta < $late[1]) {
                $late = [$shift['name'] ?? null, $delta];
            }
        }

        if ($onTime !== null) {
            return [$onTime[0], 0];
        }
        if ($late !== null) {
            return [$late[0], $late[1]];
        }

        return [null, 0];
    }

    private function persist(int $employeeId, array $days, string $fromDate): void
    {
        $appTz = (string) config('app.timezone', 'UTC');
        $dates = array_keys($days);

        // Punch -> duty date.
        foreach ($days as $date => $day) {
            foreach (array_chunk($day['punch_ids'], 500) as $ids) {
                AttendancePunch::query()->whereIn('id', $ids)->update(['attendance_date' => $date]);
            }
        }

        // Rows HR edited by hand are never overwritten.
        $protected = EmployeeDailyAttendance::query()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', '>=', $fromDate)
            ->where(fn ($q) => $q->where('is_locked', true)->orWhere('source', 'manual'))
            ->pluck('attendance_date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();

        foreach ($days as $date => $day) {
            if (in_array($date, $protected, true)) {
                continue;
            }

            EmployeeDailyAttendance::query()->updateOrCreate(
                ['employee_id' => $employeeId, 'attendance_date' => $date],
                [
                    'first_in' => $day['first_in']->setTimezone($appTz)->format('Y-m-d H:i:s'),
                    'last_out' => $day['last_out']?->setTimezone($appTz)->format('Y-m-d H:i:s'),
                    'worked_minutes' => $day['worked_minutes'],
                    'punch_count' => $day['punch_count'],
                    'shift_name' => $day['shift_name'],
                    'late_minutes' => $day['late_minutes'],
                    'status' => $day['status'],
                    'first_device_id' => $day['first_device_id'],
                    'source' => 'device',
                ],
            );
        }

        // Dates that no longer have a duty (punches moved to another duty).
        EmployeeDailyAttendance::query()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', '>=', $fromDate)
            ->whereNotIn('attendance_date', $dates)
            ->where('source', 'device')
            ->where('is_locked', false)
            ->delete();
    }
}
