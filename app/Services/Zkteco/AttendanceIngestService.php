<?php

namespace App\Services\Zkteco;

use App\Events\Zkteco\PunchesReceived;
use App\Jobs\Zkteco\RebuildDailyAttendance;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores punches/users pushed by a device. Idempotent: the device may resend
 * the same records any number of times.
 */
class AttendanceIngestService
{
    public function __construct(private readonly EmployeeResolver $employees)
    {
    }

    /**
     * @param  list<array{pin:string,time:string,status:int,verify:int,work_code:?string,raw:string}>  $records
     * @return int number of NEW punches stored
     */
    public function storePunches(AttendanceDevice $device, array $records): int
    {
        if ($records === []) {
            return 0;
        }

        $deviceTz = $device->clockTimezone();
        $appTz = (string) config('app.timezone', 'UTC');
        $pinMap = $this->employees->map(array_column($records, 'pin'));
        $now = now()->format('Y-m-d H:i:s');

        // Build rows keyed by the unique key to drop duplicates inside the batch.
        $rows = [];
        foreach ($records as $r) {
            $at = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $r['time'], $deviceTz)->setTimezone($appTz);
            $punchedAt = $at->format('Y-m-d H:i:s');
            $rows[$r['pin'].'|'.$punchedAt] = [
                'attendance_device_id' => $device->id,
                'device_sn' => $device->serial_number,
                'pin' => $r['pin'],
                'employee_id' => $pinMap[$r['pin']] ?? null,
                'punched_at' => $punchedAt,
                'status' => $r['status'],
                'verify_type' => $r['verify'],
                'work_code' => $r['work_code'],
                'raw' => $r['raw'],
                'received_at' => $now,
            ];
        }

        $new = $this->withoutExisting($device->serial_number, $rows);

        DB::transaction(function () use ($new) {
            foreach (array_chunk(array_values($new), 500) as $chunk) {
                // insertOrIgnore keeps us safe if two requests race on the same rows.
                AttendancePunch::query()->insertOrIgnore($chunk);
            }
        });

        $latest = max(array_column($rows, 'punched_at'));
        if ($device->last_punch_at === null || $device->last_punch_at->lt($latest)) {
            $device->last_punch_at = $latest;
        }

        if ($new !== []) {
            $this->scheduleRebuilds($new);
            event(new PunchesReceived($device, count($new)));
        }

        return count($new);
    }

    /**
     * USER lines from OPERLOG / USERINFO, FACE/FP/BIODATA counters.
     *
     * @param  list<array{type:string,data:array<string,string>,fields:list<string>}>  $lines
     * @return int number of user rows touched
     */
    public function storeDeviceUsers(AttendanceDevice $device, array $lines): int
    {
        $touched = 0;

        foreach ($lines as $line) {
            $data = $line['data'];
            $pin = AdmsParser::cleanPin((string) ($data['pin'] ?? ''));
            if ($pin === '' || $pin === '0') {
                continue;
            }

            switch ($line['type']) {
                case 'USER':
                    AttendanceDeviceUser::query()->updateOrCreate(
                        ['attendance_device_id' => $device->id, 'pin' => $pin],
                        [
                            'name' => isset($data['name']) ? mb_substr(AdmsParser::toUtf8($data['name']), 0, 100) : null,
                            'privilege' => (int) ($data['pri'] ?? $data['privilege'] ?? 0),
                            'card' => isset($data['card']) && $data['card'] !== '' ? mb_substr($data['card'], 0, 32) : null,
                        ],
                    );
                    $touched++;
                    break;

                case 'FACE':
                case 'BIOPHOTO':
                    $this->deviceUser($device, $pin)->forceFill(['has_face' => true])->save();
                    break;

                case 'BIODATA':
                    // Type 9 = visible-light face, 2 = face (NIR), 1 = fingerprint.
                    $type = (int) ($data['type'] ?? 0);
                    $user = $this->deviceUser($device, $pin);
                    if (in_array($type, [2, 9], true)) {
                        $user->has_face = true;
                    } elseif ($type === 1) {
                        // "No" is the finger index (0-9); keep the highest seen + 1.
                        $user->fingerprint_count = min(10, max($user->fingerprint_count, (int) ($data['no'] ?? 0) + 1));
                    }
                    $user->save();
                    break;

                case 'FP':
                    $user = $this->deviceUser($device, $pin);
                    $user->fingerprint_count = min(10, max($user->fingerprint_count, (int) ($data['fid'] ?? 0) + 1));
                    $user->save();
                    break;
            }
        }

        return $touched;
    }

    /**
     * Link punches of PINs that were unknown when they arrived (e.g. the
     * employee code was added later) and rebuild their attendance.
     *
     * @return int punches linked
     */
    public function relinkUnmapped(): int
    {
        $this->employees->flush();
        $pins = AttendancePunch::query()->whereNull('employee_id')->distinct()->pluck('pin')->all();
        $map = $this->employees->map($pins);
        $linked = 0;

        foreach ($map as $pin => $employeeId) {
            $from = AttendancePunch::query()->where('pin', $pin)->whereNull('employee_id')->min('punched_at');
            $linked += AttendancePunch::query()->where('pin', $pin)->whereNull('employee_id')->update(['employee_id' => $employeeId]);
            if ($from) {
                RebuildDailyAttendance::dispatch($employeeId, (string) $from);
            }
        }

        return $linked;
    }

    /** Remove rows already stored (one query per batch instead of one per row). */
    private function withoutExisting(string $sn, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $times = array_column($rows, 'punched_at');
        $existing = AttendancePunch::query()
            ->where('device_sn', $sn)
            ->whereIn('pin', array_unique(array_column($rows, 'pin')))
            ->whereBetween('punched_at', [min($times), max($times)])
            ->get(['pin', 'punched_at'])
            ->map(fn ($p) => $p->pin.'|'.$p->punched_at->format('Y-m-d H:i:s'))
            ->flip();

        return array_filter($rows, fn ($key) => !isset($existing[$key]), ARRAY_FILTER_USE_KEY);
    }

    /** One rebuild job per employee, starting at that employee's earliest new punch. */
    private function scheduleRebuilds(array $rows): void
    {
        $earliest = [];
        foreach ($rows as $row) {
            if ($row['employee_id'] === null) {
                continue;
            }
            $id = $row['employee_id'];
            if (!isset($earliest[$id]) || $row['punched_at'] < $earliest[$id]) {
                $earliest[$id] = $row['punched_at'];
            }
        }

        foreach ($earliest as $employeeId => $from) {
            RebuildDailyAttendance::dispatch((int) $employeeId, $from)->afterCommit();
        }
    }

    private function deviceUser(AttendanceDevice $device, string $pin): AttendanceDeviceUser
    {
        return AttendanceDeviceUser::query()->firstOrNew(['attendance_device_id' => $device->id, 'pin' => $pin]);
    }
}
