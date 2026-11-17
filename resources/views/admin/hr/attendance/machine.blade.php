@extends('admin.layouts.master')

@section('title', 'Machine Attendance')

@php
    use Carbon\CarbonImmutable;

    $today = now($timezone)->toDateString();
    $isLive = ($filters['tab'] === 'daily' && $filters['date'] === $today)
        || ($filters['tab'] === 'punches' && $filters['to'] >= $today);
    $onlineCount = $devices->where('online', true)->count();

    // Index URL for the current filters + $changes (same rules as the original SPA).
    $url = function (array $changes = []) use ($filters) {
        $n = array_merge($filters, $changes);
        $q = ['tab' => $n['tab']];
        if ($n['tab'] === 'daily') {
            $q['date'] = $n['date'];
            if ($n['status'] !== 'all') {
                $q['status'] = $n['status'];
            }
        }
        if ($n['tab'] === 'punches') {
            $q['from'] = $n['from'];
            $q['to'] = $n['to'];
            if ($n['device_id']) {
                $q['device_id'] = $n['device_id'];
            }
        }
        if ($n['search'] !== '' && $n['tab'] !== 'unmapped') {
            $q['search'] = $n['search'];
        }

        return route('admin.hr.attendance.machine.index', $q);
    };

    $hm = fn (int $m) => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
    $initials = fn (string $name) => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
    $statusPill = [
        'present' => 'pill-success',
        'on_duty' => 'pill-info',
        'incomplete' => 'pill-warning',
        'absent' => 'pill-secondary',
    ];
    $dash = '<span class="text-faint">—</span>';
@endphp

@section('content')
    {{-- Header --}}
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <div class="small-caps text-teal"><i class="bi bi-fingerprint"></i> HR · Attendance</div>
            <h1 class="h3 fw-bold text-body-emphasis mt-1 mb-1">Machine Attendance</h1>
            <p class="text-body-secondary small mb-0">Punches pushed live by ZKTeco terminals · times shown in {{ $timezone }}</p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 no-print">
            @forelse ($devices->take(3) as $d)
                <span class="pill pill-secondary border" title="{{ $d['last_seen_human'] ? 'Last seen '.$d['last_seen_human'] : 'Never connected' }}">
                    <span class="dot {{ $d['online'] ? 'dot-success pulse' : 'dot-danger' }}"></span>
                    {{ $d['name'] }}
                </span>
            @empty
                <span class="small text-body-secondary">No devices yet</span>
            @endforelse

            <button type="button" id="liveToggle" class="btn btn-surface" @unless ($isLive) disabled @endunless
                    title="{{ $isLive ? 'Refresh every 30 seconds' : 'Live refresh is only used for today' }}" aria-pressed="false">
                <i class="bi bi-arrow-repeat"></i> <span>{{ $isLive ? 'Live' : 'Paused' }}</span>
            </button>
            <button type="button" class="btn btn-surface" data-bs-toggle="modal" data-bs-target="#exportModal" @unless ($employeeModelOk) disabled @endunless>
                <i class="bi bi-download"></i> Export
            </button>
            <a href="{{ route('admin.hr.attendance.devices.index') }}" class="btn btn-teal">
                <i class="bi bi-cpu"></i> Devices
                <span class="badge rounded-pill bg-black bg-opacity-25 ms-1">{{ $onlineCount }}/{{ $devices->count() }}</span>
            </a>
        </div>
    </div>

    @unless ($employeeModelOk)
        <div class="alert alert-danger d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                <div class="fw-semibold">Employee model not found</div>
                Check <code>config/zkteco.php → employee.model</code>. Punches are still being stored, but they
                can't be linked to employees until this is fixed.
            </div>
        </div>
    @endunless

    {{-- Tabs --}}
    <ul class="nav nav-pills app-tabs mb-4 flex-nowrap overflow-auto no-print">
        <li class="nav-item">
            <a class="nav-link {{ $filters['tab'] === 'daily' ? 'active' : '' }}" @if ($filters['tab'] === 'daily') aria-current="page" @endif href="{{ $url(['tab' => 'daily']) }}">
                <i class="bi bi-calendar3 me-1"></i> Daily sheet
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $filters['tab'] === 'punches' ? 'active' : '' }}" @if ($filters['tab'] === 'punches') aria-current="page" @endif href="{{ $url(['tab' => 'punches']) }}">
                <i class="bi bi-clock-history me-1"></i> Punch log
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $filters['tab'] === 'unmapped' ? 'active' : '' }}" @if ($filters['tab'] === 'unmapped') aria-current="page" @endif href="{{ $url(['tab' => 'unmapped']) }}">
                <i class="bi bi-link-45deg me-1"></i> Unmapped PINs
                @if ($unmappedCount > 0)<span class="badge rounded-pill text-bg-warning ms-1">{{ $unmappedCount }}</span>@endif
            </a>
        </li>
    </ul>

    {{-- ============================================================ Daily sheet --}}
    @if ($filters['tab'] === 'daily')
        @php
            $s = $stats ?? ['total' => 0, 'present' => 0, 'on_duty' => 0, 'incomplete' => 0, 'late' => 0, 'absent' => 0, 'punches' => 0];
            $attended = $s['present'] + $s['on_duty'] + $s['incomplete'];
            $rate = $s['total'] > 0 ? round($attended / $s['total'] * 100) : 0;
            $date = CarbonImmutable::parse($filters['date']);
        @endphp

        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
            <form method="GET" action="{{ route('admin.hr.attendance.machine.index') }}" class="d-flex align-items-center gap-2 no-print">
                <input type="hidden" name="tab" value="daily">
                @if ($filters['status'] !== 'all')<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
                @if ($filters['search'] !== '')<input type="hidden" name="search" value="{{ $filters['search'] }}">@endif
                <a class="btn btn-surface px-2" href="{{ $url(['date' => $date->subDay()->toDateString()]) }}" title="Previous day" aria-label="Previous day"><i class="bi bi-chevron-left"></i></a>
                <input type="date" name="date" value="{{ $filters['date'] }}" max="{{ $today }}" class="form-control w-auto" aria-label="Date" onchange="this.value && this.form.submit()">
                @if ($filters['date'] < $today)
                    <a class="btn btn-surface px-2" href="{{ $url(['date' => $date->addDay()->toDateString()]) }}" title="Next day" aria-label="Next day"><i class="bi bi-chevron-right"></i></a>
                    <a class="small fw-semibold text-teal text-decoration-none ms-1" href="{{ $url(['date' => $today]) }}">Today</a>
                @else
                    <button type="button" class="btn btn-surface px-2" disabled aria-label="Next day"><i class="bi bi-chevron-right"></i></button>
                @endif
            </form>
            <p class="small fw-semibold text-body-secondary mb-0">{{ $date->format('l, d M Y') }}</p>
        </div>

        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4">
            @foreach ([
                ['bi-people', 'Staff with device ID', $s['total'], null, 'tone-secondary'],
                ['bi-person-check', 'Attended', $attended, $rate.'% of staff', 'tone-success'],
                ['bi-box-arrow-in-right', 'On duty now', $s['on_duty'], null, 'tone-info'],
                ['bi-box-arrow-right', 'Missing OUT', $s['incomplete'], null, 'tone-warning'],
                ['bi-stopwatch', 'Late', $s['late'], null, 'tone-orange'],
                ['bi-person-dash', 'No punch', $s['absent'], $s['punches'].' punches today', 'tone-danger'],
            ] as [$icon, $label, $value, $hint, $tone])
                <div class="col">
                    <div class="card stat-card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <span class="small text-body-secondary fw-medium">{{ $label }}</span>
                                <span class="stat-icon {{ $tone }}"><i class="bi {{ $icon }}"></i></span>
                            </div>
                            <div class="stat-value mt-1">{{ $value }}</div>
                            @if ($hint)<div class="small text-body-secondary">{{ $hint }}</div>@endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 py-3">
                <div class="d-flex flex-wrap gap-1 no-print">
                    @foreach ([
                        'all' => ['All', $s['total']], 'present' => ['Present', $s['present']], 'on_duty' => ['On duty', $s['on_duty']],
                        'incomplete' => ['Missing OUT', $s['incomplete']], 'late' => ['Late', $s['late']], 'absent' => ['No punch', $s['absent']],
                    ] as $key => [$label, $count])
                        <a href="{{ $url(['status' => $key]) }}" class="chip {{ $filters['status'] === $key ? 'active' : '' }}"
                           @if ($filters['status'] === $key) aria-current="true" @endif>
                            {{ $label }} <span class="count">{{ $count }}</span>
                        </a>
                    @endforeach
                </div>
                @include('admin.hr.attendance.partials.search', ['placeholder' => 'Search name or ID…', 'hidden' => ['tab' => 'daily', 'date' => $filters['date'], 'status' => $filters['status'] !== 'all' ? $filters['status'] : null]])
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr><th scope="col">Employee</th><th scope="col">Shift</th><th scope="col">In</th><th scope="col">Out</th><th scope="col">Worked</th><th scope="col" class="text-center">Punches</th><th scope="col">Late</th><th scope="col">Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($daily ?? [] as $r)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="avatar" aria-hidden="true">{{ $initials($r['name']) }}</span>
                                        <div class="min-w-0">
                                            <div class="fw-semibold text-body-emphasis text-truncate">{{ $r['name'] }}</div>
                                            <div class="font-monospace small text-body-secondary">ID {{ $r['pin'] }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-body-secondary">{!! $r['shift_name'] !== null ? e($r['shift_name']) : $dash !!}</td>
                                <td class="fw-semibold text-body-emphasis tabular text-nowrap">{!! $r['first_in'] !== null ? e($r['first_in']) : $dash !!}</td>
                                <td class="tabular text-nowrap">
                                    @if ($r['last_out'])
                                        {{ $r['last_out'] }}
                                        @if ($r['last_out_next_day'])<span class="pill pill-primary ms-1 py-1 px-2" title="Next day">+1</span>@endif
                                    @else
                                        {!! $dash !!}
                                    @endif
                                </td>
                                <td class="tabular text-body-secondary text-nowrap">{!! $r['worked_minutes'] > 0 ? $hm($r['worked_minutes']) : $dash !!}</td>
                                <td class="text-center tabular text-body-secondary">{!! $r['punch_count'] ?: $dash !!}</td>
                                <td>
                                    @if ($r['late_minutes'] > 0)
                                        <span class="pill tone-orange">{{ $r['late_minutes'] }} min</span>
                                    @else
                                        {!! $dash !!}
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    <span class="pill {{ $statusPill[$r['status']] ?? 'pill-secondary' }}">{{ $r['status_label'] }}</span>
                                    @if ($r['source'] === 'manual')<span class="small-caps text-faint ms-1">manual</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="empty-state">No employees match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($daily)
                @include('admin.hr.attendance.partials.pagination', ['page' => $daily])
            @endif
        </div>
    @endif

    {{-- ============================================================ Punch log --}}
    @if ($filters['tab'] === 'punches')
        <div class="card">
            <div class="card-header d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-2 py-3">
                <form method="GET" action="{{ route('admin.hr.attendance.machine.index') }}" class="d-flex flex-wrap align-items-center gap-2 no-print">
                    <input type="hidden" name="tab" value="punches">
                    @if ($filters['search'] !== '')<input type="hidden" name="search" value="{{ $filters['search'] }}">@endif
                    <label class="small-caps text-body-secondary" for="p_from">From</label>
                    <input type="date" id="p_from" name="from" value="{{ $filters['from'] }}" max="{{ $today }}" class="form-control w-auto" onchange="this.value && this.form.submit()">
                    <label class="small-caps text-body-secondary" for="p_to">To</label>
                    <input type="date" id="p_to" name="to" value="{{ $filters['to'] }}" max="{{ $today }}" class="form-control w-auto" onchange="this.value && this.form.submit()">
                    <select name="device_id" class="form-select w-auto" aria-label="Device" onchange="this.form.submit()">
                        <option value="">All devices</option>
                        @foreach ($devices as $d)
                            <option value="{{ $d['id'] }}" @selected($filters['device_id'] === $d['id'])>{{ $d['name'] }}</option>
                        @endforeach
                    </select>
                </form>
                <div class="d-flex align-items-center gap-3">
                    @if ($punches)<span class="small text-body-secondary text-nowrap tabular">{{ number_format($punches->total()) }} punches</span>@endif
                    @include('admin.hr.attendance.partials.search', ['placeholder' => 'Search name or PIN…', 'hidden' => ['tab' => 'punches', 'from' => $filters['from'], 'to' => $filters['to'], 'device_id' => $filters['device_id']]])
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle small">
                    <thead>
                        <tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Employee</th><th scope="col">PIN</th><th scope="col">Device</th><th scope="col">Type</th><th scope="col">Verified by</th><th scope="col">Duty date</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($punches ?? [] as $p)
                            <tr>
                                <td class="text-nowrap text-body-secondary">{{ $p['date'] }}</td>
                                <td class="text-nowrap fw-semibold text-body-emphasis tabular">{{ $p['time'] }}</td>
                                <td>
                                    @if ($p['employee_id'])
                                        <span class="fw-medium text-body-emphasis">{{ $p['employee_name'] ?? '#'.$p['employee_id'] }}</span>
                                    @else
                                        <span class="pill pill-warning"><i class="bi bi-exclamation-triangle"></i> Not linked</span>
                                    @endif
                                </td>
                                <td class="font-monospace text-body-secondary">{{ $p['pin'] }}</td>
                                <td class="text-body-secondary">{{ $p['device'] }}</td>
                                <td class="text-body-secondary">{{ $p['status'] }}</td>
                                <td><span class="pill pill-secondary">{{ $p['verify'] }}</span></td>
                                <td class="text-nowrap text-body-secondary">{!! $p['duty_date'] !== null ? e($p['duty_date']) : $dash !!}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="empty-state">No punches in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($punches)
                @include('admin.hr.attendance.partials.pagination', ['page' => $punches])
            @endif
        </div>
    @endif

    {{-- ========================================================= Unmapped PINs --}}
    @if ($filters['tab'] === 'unmapped')
        @php $rows = $unmapped ?? []; @endphp
        <div class="alert alert-info d-flex gap-2">
            <i class="bi bi-info-circle-fill"></i>
            <div>
                <div class="fw-semibold">Why are these PINs unmapped?</div>
                Nobody is mapped to this <b>User ID</b> on that terminal yet. The punches are kept. Press <b>Map</b> to link the PIN to an
                employee: earlier punches are linked and their attendance is recalculated automatically.
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <h2 class="h6 fw-semibold text-body-emphasis mb-0">{{ count($rows) }} unmapped PIN(s)</h2>
                <form method="POST" action="{{ route('admin.hr.attendance.machine.relink') }}" class="no-print">
                    @csrf
                    <button type="submit" class="btn btn-teal btn-sm"><i class="bi bi-link-45deg"></i> Re-link now</button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr><th scope="col">Device</th><th scope="col">PIN on device</th><th scope="col">Name on device</th><th scope="col" class="text-center">Punches</th><th scope="col">First seen</th><th scope="col">Last seen</th><th scope="col" class="no-print"></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr>
                                <td class="text-body-secondary">{{ $r['device'] }}</td>
                                <td class="font-monospace fw-semibold text-body-emphasis">{{ $r['pin'] }}</td>
                                <td class="text-body-secondary">{!! $r['device_name'] !== null ? e($r['device_name']) : $dash !!}</td>
                                <td class="text-center tabular text-body-secondary">{{ $r['punches'] }}</td>
                                <td class="text-body-secondary text-nowrap">{{ $r['first_seen'] }}</td>
                                <td class="text-body-secondary text-nowrap">{{ $r['last_seen'] }}</td>
                                <td class="text-end no-print">
                                    @if ($r['device_id'])
                                        <a class="btn btn-sm btn-teal" href="{{ route('admin.hr.attendance.mappings.index', ['new_device' => $r['device_id'], 'new_pin' => $r['pin']]) }}"><i class="bi bi-link-45deg"></i> Map</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="empty-state"><i class="bi bi-check2-circle text-success"></i> Every punch is linked to an employee.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Export modal --}}
    <div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="GET" action="{{ route('admin.hr.attendance.machine.export') }}" id="exportForm">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="exportModalTitle">Export daily sheet (CSV)</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-medium" for="e_from">From</label>
                            <input type="date" class="form-control" id="e_from" name="from" max="{{ $today }}" value="{{ $filters['tab'] === 'daily' ? $filters['date'] : $filters['from'] }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium" for="e_to">To</label>
                            <input type="date" class="form-control" id="e_to" name="to" max="{{ $today }}" value="{{ $filters['tab'] === 'daily' ? $filters['date'] : $filters['to'] }}" required>
                        </div>
                    </div>
                    <p class="small mt-3 mb-0" id="exportHint" aria-live="polite"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal" id="exportSubmit"><i class="bi bi-download"></i> Download</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Debounced search boxes (submit 400 ms after typing stops); keep the caret after reload.
    document.querySelectorAll('.js-search').forEach(function (input) {
        var t;
        input.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(function () { input.form.submit(); }, 400);
        });
        if (input.value) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }
    });

    // Export range check (max 62 days).
    var from = document.getElementById('e_from'), to = document.getElementById('e_to');
    var hint = document.getElementById('exportHint'), submit = document.getElementById('exportSubmit');
    function checkRange() {
        var days = Math.round((new Date(to.value + 'T00:00:00') - new Date(from.value + 'T00:00:00')) / 86400000) + 1;
        var invalid = !from.value || !to.value || !(days >= 1 && days <= 63);
        hint.className = 'small mt-3 mb-0 ' + (invalid ? 'text-danger' : 'text-body-secondary');
        hint.textContent = invalid ? 'Choose a valid range of up to 62 days.' : days + ' day(s) · one row per employee per day · opens in Excel';
        submit.disabled = invalid;
    }
    from.addEventListener('change', checkRange);
    to.addEventListener('change', checkRange);
    checkRange();
    document.getElementById('exportForm').addEventListener('submit', function () {
        setTimeout(function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('exportModal')).hide(); }, 300);
    });

    // Live refresh every 30 s while looking at today's data (toggle remembered per browser).
    var isLive = @json($isLive);
    var lastInput = 0;
    document.addEventListener('input', function () { lastInput = Date.now(); });
    var btn = document.getElementById('liveToggle');
    var on = true;
    try { on = localStorage.getItem('machineAttendance.live') !== '0'; } catch (e) {}
    function paint() {
        var live = isLive && on;
        btn.classList.toggle('is-live', live);
        btn.setAttribute('aria-pressed', String(live));
        btn.querySelector('span').textContent = live ? 'Live' : 'Paused';
        btn.querySelector('i').className = live ? 'bi bi-broadcast' : 'bi bi-pause-circle';
    }
    btn.addEventListener('click', function () {
        on = !on;
        try { localStorage.setItem('machineAttendance.live', on ? '1' : '0'); } catch (e) {}
        paint();
    });
    paint();
    setInterval(function () {
        if (!isLive || !on || document.hidden) return;
        if (document.querySelector('.modal.show, .dropdown-menu.show') || Date.now() - lastInput < 10000) return;
        window.location.reload();
    }, 30000);
});
</script>
@endpush
