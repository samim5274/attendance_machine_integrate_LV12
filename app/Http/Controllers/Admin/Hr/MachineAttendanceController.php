<?php

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Services\Zkteco\AttendanceIngestService;
use App\Services\Zkteco\EmployeeResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Machine Attendance" screen: daily sheet, raw punch log, unmapped PINs.
 */
class MachineAttendanceController extends Controller
{
    private const STATUSES = ['all', 'present', 'on_duty', 'incomplete', 'late', 'absent'];

    public function __construct(private readonly EmployeeResolver $employees)
    {
    }

    public function index(Request $request): View
    {   
        $tz = $this->tz();
        $today = now($tz)->toDateString();

        $filters = $request->validate([
            'tab' => ['nullable', 'in:daily,punches,unmapped'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'integer'],
        ]);

        $filters = [
            'tab' => $filters['tab'] ?? 'daily',
            'date' => $filters['date'] ?? $today,
            'from' => $filters['from'] ?? $today,
            'to' => $filters['to'] ?? $today,
            'status' => $filters['status'] ?? 'all',
            'search' => trim((string) ($filters['search'] ?? '')),
            'device_id' => isset($filters['device_id']) ? (int) $filters['device_id'] : null,
        ];
        if ($filters['to'] < $filters['from']) {
            [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
        }

        $configured = $this->employees->available();

        return view('admin.hr.attendance.machine', [
            'filters' => $filters,
            'timezone' => $tz,
            'employeeModelOk' => $configured,
            'stats' => $configured ? $this->stats($filters['date']) : null,
            'devices' => AttendanceDevice::query()->orderBy('name')->get()->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'online' => $d->is_active && $d->isOnline(),
                'last_seen_human' => $d->last_seen_at?->diffForHumans(),
            ]),
            'unmappedCount' => AttendancePunch::query()->whereNull('employee_id')->select('attendance_device_id', 'pin')->distinct()->get()->count(),
            'daily' => $filters['tab'] === 'daily' && $configured ? $this->daily($filters) : null,
            'punches' => $filters['tab'] === 'punches' ? $this->punches($filters) : null,
            'unmapped' => $filters['tab'] === 'unmapped' ? $this->unmapped() : null,
        ]);
    }

    /** CSV of the daily sheet for a date range (max 62 days). */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 62, 422, 'Please export at most 62 days at a time.');
        abort_unless($this->employees->available(), 422, 'Employee model is not configured.');

        $model = $this->employees->model();
        $table = $model->getTable();
        $key = $model->getKeyName();
        $pin = $this->employees->pinColumn();
        $name = $this->employees->nameColumn();
        $filename = "machine-attendance-{$data['from']}-to-{$data['to']}.csv";

        return response()->streamDownload(function () use ($from, $to, $table, $key, $pin, $name) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Bangla names correctly
            fputcsv($out, ['Date', 'Employee Code', 'Employee Name', 'Shift', 'First In', 'Last Out', 'Worked (h:mm)', 'Punches', 'Late (min)', 'Status']);

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $date = $day->toDateString();
                $this->dailyQuery($date)
                    ->orderBy("{$table}.{$name}")
                    ->orderBy("{$table}.{$key}")
                    ->select($this->dailyColumns($table, $key, $pin, $name))
                    ->chunk(500, function ($rows) use ($out, $date) {
                        foreach ($rows as $r) {
                            $row = $this->formatDaily($r, $date);
                            fputcsv($out, [
                                $date, $row['pin'], $row['name'], $row['shift_name'] ?? '',
                                $row['first_in'] ?? '', $row['last_out'] ? $row['last_out'].($row['last_out_next_day'] ? ' (+1)' : '') : '',
                                $row['worked_minutes'] ? intdiv($row['worked_minutes'], 60).':'.str_pad((string) ($row['worked_minutes'] % 60), 2, '0', STR_PAD_LEFT) : '',
                                $row['punch_count'], $row['late_minutes'], $row['status_label'],
                            ]);
                        }
                    });
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function relink(AttendanceIngestService $ingest): RedirectResponse
    {
        $linked = $ingest->relinkUnmapped();

        return back()->with('success', $linked > 0
            ? "{$linked} punch(es) linked to employees. Daily attendance is being recalculated."
            : 'No mapping found for the unmapped PINs yet. Add them under PIN Mapping.');
    }

    // ---------------------------------------------------------------- daily

    /**
     * Employees that can punch (active PIN mapping on any device, or the legacy
     * global PIN column) LEFT JOIN their attendance row for $date.
     */
    private function dailyQuery(string $date): Builder
    {
        $model = $this->employees->model();
        $table = $model->getTable();
        $key = "{$table}.{$model->getKeyName()}";
        $pin = $this->employees->pinColumn();
        $useGlobal = $this->employees->usesGlobalPin();

        $query = $this->employees->query()->toBase() // keeps SoftDeletes & other global scopes
            ->leftJoin('employee_daily_attendances as d', function ($join) use ($key, $date) {
                $join->on('d.employee_id', '=', $key)
                    ->where('d.attendance_date', '=', $date);
            })
            ->where(function ($q) use ($table, $key, $pin, $useGlobal) {
                $q->whereExists(fn ($m) => $m->selectRaw('1')->from('employee_device_mappings as m')
                    ->whereColumn('m.employee_id', $key)->where('m.is_active', true));
                if ($useGlobal) {
                    $q->orWhere(fn ($g) => $g->whereNotNull("{$table}.{$pin}")->where("{$table}.{$pin}", '!=', ''));
                }
            });

        $this->employees->applyActive($query, "{$table}.");

        return $query;
    }

    private function dailyColumns(string $table, string $key, string $pin, string $name): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $mapped = 'SELECT GROUP_CONCAT(m.device_user_id) FROM employee_device_mappings m WHERE m.employee_id = '
            .$grammar->wrap("{$table}.{$key}").' AND m.is_active = 1';
        $pinExpr = $this->employees->usesGlobalPin()
            ? 'COALESCE(NULLIF('.$grammar->wrap("{$table}.{$pin}").", ''), ({$mapped}))"
            : "({$mapped})";

        return [
            "{$table}.{$key} as employee_id", "{$table}.{$name} as employee_name", DB::raw("{$pinExpr} as employee_pin"),
            'd.id as row_id', 'd.first_in', 'd.last_out', 'd.worked_minutes', 'd.punch_count', 'd.shift_name',
            'd.late_minutes', 'd.status', 'd.source', 'd.remarks',
        ];
    }

    private function daily(array $f)
    {
        $model = $this->employees->model();
        $table = $model->getTable();
        $key = $model->getKeyName();
        $pin = $this->employees->pinColumn();
        $name = $this->employees->nameColumn();

        $query = $this->dailyQuery($f['date'])->select($this->dailyColumns($table, $key, $pin, $name));

        if ($f['search'] !== '') {
            $s = '%'.$f['search'].'%';
            $query->where(function ($q) use ($table, $key, $pin, $name, $s) {
                $q->where("{$table}.{$name}", 'like', $s)
                    ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('employee_device_mappings as m2')
                        ->whereColumn('m2.employee_id', "{$table}.{$key}")->where('m2.device_user_id', 'like', $s));
                if ($this->employees->usesGlobalPin()) {
                    $q->orWhere("{$table}.{$pin}", 'like', $s);
                }
            });
        }

        match ($f['status']) {
            'present', 'on_duty', 'incomplete' => $query->where('d.status', $f['status']),
            'late' => $query->where('d.late_minutes', '>', 0),
            'absent' => $query->whereNull('d.id'),
            default => null,
        };

        return $query
            ->orderByRaw('CASE WHEN d.first_in IS NULL THEN 1 ELSE 0 END')
            ->orderBy('d.first_in')
            ->orderBy("{$table}.{$name}")
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($r) => $this->formatDaily($r, $f['date']));
    }

    private function stats(string $date): array
    {
        $row = $this->dailyQuery($date)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN d.id IS NULL THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN d.status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN d.status = 'on_duty' THEN 1 ELSE 0 END) as on_duty,
            SUM(CASE WHEN d.status = 'incomplete' THEN 1 ELSE 0 END) as incomplete,
            SUM(CASE WHEN d.late_minutes > 0 THEN 1 ELSE 0 END) as late
        ")->first();

        $appTz = (string) config('app.timezone');
        $start = CarbonImmutable::parse($date, $this->tz())->startOfDay()->setTimezone($appTz);

        return [
            'total' => (int) ($row->total ?? 0),
            'present' => (int) ($row->present ?? 0),
            'on_duty' => (int) ($row->on_duty ?? 0),
            'incomplete' => (int) ($row->incomplete ?? 0),
            'late' => (int) ($row->late ?? 0),
            'absent' => (int) ($row->absent ?? 0),
            'punches' => AttendancePunch::query()
                ->whereBetween('punched_at', [$start->format('Y-m-d H:i:s'), $start->addDay()->subSecond()->format('Y-m-d H:i:s')])
                ->count(),
        ];
    }

    private function formatDaily(object $r, string $date): array
    {
        $tz = $this->tz();
        $appTz = (string) config('app.timezone');
        $in = $r->first_in ? Carbon::parse($r->first_in, $appTz)->setTimezone($tz) : null;
        $out = $r->last_out ? Carbon::parse($r->last_out, $appTz)->setTimezone($tz) : null;
        $status = $r->row_id ? $r->status : 'absent';

        return [
            'employee_id' => $r->employee_id,
            'name' => $r->employee_name,
            'pin' => (string) $r->employee_pin,
            'first_in' => $in?->format('h:i A'),
            'last_out' => $out?->format('h:i A'),
            'last_out_next_day' => $out !== null && $out->toDateString() !== $date,
            'worked_minutes' => (int) ($r->worked_minutes ?? 0),
            'punch_count' => (int) ($r->punch_count ?? 0),
            'shift_name' => $r->shift_name,
            'late_minutes' => (int) ($r->late_minutes ?? 0),
            'status' => $status,
            'status_label' => match ($status) {
                'present' => 'Present',
                'on_duty' => 'On duty',
                'incomplete' => 'Missing OUT',
                default => 'No punch',
            },
            'source' => $r->source,
            'remarks' => $r->remarks,
        ];
    }

    // -------------------------------------------------------------- punches

    private function punches(array $f)
    {
        $tz = $this->tz();
        $appTz = (string) config('app.timezone');
        $from = CarbonImmutable::parse($f['from'], $tz)->startOfDay()->setTimezone($appTz)->format('Y-m-d H:i:s');
        $to = CarbonImmutable::parse($f['to'], $tz)->endOfDay()->setTimezone($appTz)->format('Y-m-d H:i:s');

        $query = AttendancePunch::query()
            ->leftJoin('attendance_devices as dv', 'dv.id', '=', 'attendance_punches.attendance_device_id')
            ->whereBetween('attendance_punches.punched_at', [$from, $to])
            ->when($f['device_id'], fn ($q, $id) => $q->where('attendance_punches.attendance_device_id', $id))
            ->select('attendance_punches.*', 'dv.name as device_name');

        if ($this->employees->available()) {
            $model = $this->employees->model();
            $table = $model->getTable();
            $query->leftJoin($table, "{$table}.{$model->getKeyName()}", '=', 'attendance_punches.employee_id')
                ->addSelect("{$table}.{$this->employees->nameColumn()} as employee_name");

            if ($f['search'] !== '') {
                $s = '%'.$f['search'].'%';
                $query->where(fn ($q) => $q->where('attendance_punches.pin', 'like', $s)
                    ->orWhere("{$table}.{$this->employees->nameColumn()}", 'like', $s));
            }
        } elseif ($f['search'] !== '') {
            $query->where('attendance_punches.pin', 'like', '%'.$f['search'].'%');
        }

        return $query
            ->orderByDesc('attendance_punches.punched_at')
            ->orderByDesc('attendance_punches.id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AttendancePunch $p) => [
                'id' => $p->id,
                'pin' => $p->pin,
                'employee_id' => $p->employee_id,
                'employee_name' => $p->employee_name ?? null,
                'device' => $p->device_name ?? $p->device_sn,
                'date' => $p->punched_at->setTimezone($tz)->format('d M Y'),
                'time' => $p->punched_at->setTimezone($tz)->format('h:i:s A'),
                'duty_date' => $p->attendance_date,
                'status' => $p->statusLabel(),
                'verify' => $p->verifyLabel(),
            ]);
    }

    // ------------------------------------------------------------- unmapped

    /** Unresolved (device, PIN) pairs: punches kept, waiting for a mapping. */
    private function unmapped(): array
    {
        $tz = $this->tz();
        $names = AttendanceDeviceUser::query()->whereNotNull('name')->get(['attendance_device_id', 'pin', 'name'])
            ->mapWithKeys(fn ($u) => [$u->attendance_device_id.'|'.$u->pin => $u->name]);
        $devices = AttendanceDevice::query()->pluck('name', 'id');

        return AttendancePunch::query()
            ->whereNull('employee_id')
            ->groupBy('attendance_device_id', 'device_sn', 'pin')
            ->selectRaw('attendance_device_id, device_sn, pin, COUNT(*) as punches, MIN(punched_at) as first_seen, MAX(punched_at) as last_seen')
            ->orderByDesc('last_seen')
            ->limit(300)
            ->get()
            ->map(fn ($r) => [
                'pin' => $r->pin,
                'device_id' => $r->attendance_device_id,
                'device' => $devices[$r->attendance_device_id] ?? $r->device_sn,
                'device_name' => $names[$r->attendance_device_id.'|'.$r->pin] ?? null,
                'punches' => (int) $r->punches,
                'first_seen' => Carbon::parse($r->first_seen, config('app.timezone'))->setTimezone($tz)->format('d M Y, h:i A'),
                'last_seen' => Carbon::parse($r->last_seen, config('app.timezone'))->setTimezone($tz)->format('d M Y, h:i A'),
            ])
            ->all();
    }

    private function tz(): string
    {
        return (string) config('zkteco.timezone', 'Asia/Dhaka');
    }
}
