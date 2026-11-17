@extends('admin.layouts.master')

@section('title', 'PIN Mapping')

@php
    $employeeOptions = $employees->map(fn ($e) => [
        'id' => $e->id,
        'label' => $e->name.' ('.$e->employee_id.')'.($e->status !== 'active' ? ' — inactive' : ''),
    ]);
    $prefill = ($filters['new_pin'] ?? null) !== null ? ['attendance_device_id' => $filters['new_device'] ?? null, 'device_user_id' => $filters['new_pin']] : null;
@endphp

@section('content')
    {{-- Header --}}
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <a href="{{ route('admin.hr.attendance.devices.index') }}" class="small-caps text-teal text-decoration-none">
                <i class="bi bi-arrow-left"></i> Attendance devices
            </a>
            <h1 class="h3 fw-bold text-body-emphasis mt-1 mb-1">Employee PIN Mapping</h1>
            <p class="text-body-secondary small mb-0">
                {{ $totals['active'] }} active of {{ $totals['all'] }} mapping(s)
                @if ($totals['unresolved_punches'] > 0)
                    · <a href="{{ route('admin.hr.attendance.machine.index', ['tab' => 'unmapped']) }}" class="text-warning-emphasis">{{ $totals['unresolved_punches'] }} punch(es) waiting for a mapping</a>
                @endif
            </p>
        </div>
        <button type="button" class="btn btn-teal align-self-start align-self-lg-auto js-map-new" @disabled($devices->isEmpty() || $employees->isEmpty())>
            <i class="bi bi-plus-lg"></i> Add mapping
        </button>
    </div>

    <div class="alert alert-info small d-flex gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <div>
            The <b>machine PIN</b> is the <b>User ID</b> shown on the terminal for that person. It does not have to match the employee ID or code.
            A PIN is unique per device, and one employee can have a PIN on several devices. Punches with an unmapped PIN are kept, and
            they are linked automatically when you add the mapping.
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('admin.hr.attendance.mappings.index') }}" class="card mb-3" role="search">
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-medium" for="q">Search</label>
                <input type="search" class="form-control" id="q" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="PIN, employee name or code">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-medium" for="fd">Device</label>
                <select class="form-select" id="fd" name="device_id">
                    <option value="">All devices</option>
                    @foreach ($devices as $d)
                        <option value="{{ $d->id }}" @selected(($filters['device_id'] ?? null) == $d->id)>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-medium" for="fe">Employee</label>
                <select class="form-select" id="fe" name="employee_id">
                    <option value="">All employees</option>
                    @foreach ($employeeOptions as $e)
                        <option value="{{ $e['id'] }}" @selected(($filters['employee_id'] ?? null) == $e['id'])>{{ $e['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-medium" for="fs">Status</label>
                <select class="form-select" id="fs" name="status">
                    @foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $v => $l)
                        <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-surface w-100" title="Apply filters"><i class="bi bi-funnel"></i></button>
                <a href="{{ route('admin.hr.attendance.mappings.index') }}" class="btn btn-ghost" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    {{-- List --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th scope="col">Device</th>
                        <th scope="col">Machine PIN</th>
                        <th scope="col">Employee</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mappings as $m)
                        @php
                            $waiting = $unresolved[$m->attendance_device_id.'|'.$m->device_user_id] ?? 0;
                            $emp = $m->employee;
                            $payload = ['id' => $m->id, 'attendance_device_id' => $m->attendance_device_id, 'employee_id' => $m->employee_id, 'device_user_id' => $m->device_user_id, 'is_active' => $m->is_active];
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-medium text-body-emphasis">{{ $m->device?->name ?? '—' }}</div>
                                <div class="small text-body-secondary font-monospace">{{ $m->device?->serial_number }}</div>
                            </td>
                            <td class="font-monospace fw-semibold text-body-emphasis">
                                {{ $m->device_user_id }}
                                @if ($waiting > 0)
                                    <span class="pill pill-warning ms-1" title="Unresolved punches for this PIN">{{ $waiting }} unlinked</span>
                                @endif
                            </td>
                            <td>
                                @if ($emp)
                                    <div class="fw-medium text-body-emphasis">{{ $emp->name }}</div>
                                    <div class="small text-body-secondary">
                                        {{ $emp->employee_id }}
                                        @if ($emp->trashed()) · <span class="text-danger">deleted</span>
                                        @elseif ($emp->status !== 'active') · <span class="text-warning-emphasis">inactive employee</span>@endif
                                    </div>
                                @else
                                    <span class="text-danger">Employee not found</span>
                                @endif
                            </td>
                            <td>
                                @if ($m->is_active)
                                    <span class="pill pill-success">Active</span>
                                @else
                                    <span class="pill pill-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end no-print text-nowrap">
                                <div class="dropdown d-inline-block">
                                    <button class="btn btn-sm btn-surface" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Device actions for PIN {{ $m->device_user_id }}">
                                        <i class="bi bi-cpu"></i> Device
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" style="width: 17rem">
                                        <li>
                                            <form method="POST" action="{{ route('admin.hr.attendance.mappings.device', $m) }}">
                                                @csrf <input type="hidden" name="action" value="push">
                                                <button type="submit" class="dropdown-item d-flex gap-3 py-2">
                                                    <i class="bi bi-person-plus opacity-75"></i>
                                                    <span><span class="d-block fw-medium">Push user to device</span><span class="d-block small text-body-secondary text-wrap">Create/update User ID {{ $m->device_user_id }} with this name. Enrol the face on the terminal.</span></span>
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.hr.attendance.mappings.device', $m) }}"
                                                  data-confirm="User ID {{ $m->device_user_id }} and its face/fingerprints will be deleted from the terminal. Attendance already received is kept."
                                                  data-confirm-title="Delete user from {{ $m->device?->name }}?" data-confirm-label="Delete from device" data-confirm-danger>
                                                @csrf <input type="hidden" name="action" value="remove">
                                                <button type="submit" class="dropdown-item d-flex gap-3 py-2 text-danger">
                                                    <i class="bi bi-person-x opacity-75"></i>
                                                    <span><span class="d-block fw-medium">Delete user from device</span><span class="d-block small text-body-secondary text-wrap">Removes the enrolment on the terminal.</span></span>
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                                <button type="button" class="btn btn-sm btn-surface js-map-edit" data-mapping='@json($payload)'><i class="bi bi-pencil"></i> Edit</button>
                                <form method="POST" action="{{ route('admin.hr.attendance.mappings.destroy', $m) }}" class="d-inline"
                                      data-confirm="The link between PIN {{ $m->device_user_id }} and {{ $emp?->name ?? 'this employee' }} is removed. Attendance already recorded is kept; new punches with this PIN will be unmapped."
                                      data-confirm-title="Delete mapping?" data-confirm-label="Delete mapping" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-ghost text-danger" aria-label="Delete mapping {{ $m->device_user_id }}"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-state">
                            @if ($totals['all'] === 0)
                                No mappings yet. Add one per person enrolled on the terminal.
                            @else
                                No mappings match these filters.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.hr.attendance.partials.pagination', ['page' => $mappings])
    </div>

    {{-- Add / edit mapping --}}
    <div class="modal fade" id="mapModal" tabindex="-1" aria-labelledby="mapModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" id="mapForm"
                  data-store="{{ route('admin.hr.attendance.mappings.store') }}"
                  data-update="{{ route('admin.hr.attendance.mappings.update', '__ID__') }}">
                @csrf
                <input type="hidden" name="_method" value="POST" id="mapMethod">
                <input type="hidden" name="_mapping_id" value="" id="mapId">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="mapModalTitle">Add PIN mapping</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="m_device">Device <span class="text-danger">*</span></label>
                        <select class="form-select @error('attendance_device_id') is-invalid @enderror" id="m_device" name="attendance_device_id" required>
                            <option value="">Select device</option>
                            @foreach ($devices as $d)
                                <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->serial_number }}){{ $d->is_active ? '' : ' — inactive' }}</option>
                            @endforeach
                        </select>
                        @error('attendance_device_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="m_employee">Employee <span class="text-danger">*</span></label>
                        <select class="form-select @error('employee_id') is-invalid @enderror" id="m_employee" name="employee_id" required>
                            <option value="">Select employee</option>
                            @foreach ($employeeOptions as $e)
                                <option value="{{ $e['id'] }}">{{ $e['label'] }}</option>
                            @endforeach
                        </select>
                        @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="m_pin">Machine PIN (User ID on the device) <span class="text-danger">*</span></label>
                        <input class="form-control font-monospace @error('device_user_id') is-invalid @enderror" id="m_pin" name="device_user_id" maxlength="32" placeholder="e.g. 1001" required autocomplete="off">
                        @error('device_user_id')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">On the terminal: Menu → User Mgt. → All Users.</div>@enderror
                    </div>
                    <div class="form-check border rounded-3 p-2 ps-5 mb-0">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" value="1" id="m_active" name="is_active" checked>
                        <label class="form-check-label" for="m_active">
                            <span class="fw-medium">Active</span>
                            <span class="d-block small text-body-secondary">Inactive mappings are ignored, so punches with this PIN stay unlinked.</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal" id="mapSubmit">Save mapping</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var f = function (id) { return document.getElementById(id); };
    var form = f('mapForm');
    var modal = bootstrap.Modal.getOrCreateInstance(f('mapModal'));

    function open(m, keepErrors) {
        var editing = !!(m && m.id);
        if (!keepErrors) {
            form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        }
        form.action = editing ? form.dataset.update.replace('__ID__', m.id) : form.dataset.store;
        f('mapMethod').value = editing ? 'PUT' : 'POST';
        f('mapId').value = editing ? m.id : '';
        f('mapModalTitle').textContent = editing ? 'Edit PIN mapping' : 'Add PIN mapping';
        f('mapSubmit').textContent = editing ? 'Save changes' : 'Save mapping';
        f('m_device').value = (m && m.attendance_device_id) || '';
        f('m_employee').value = (m && m.employee_id) || '';
        f('m_pin').value = (m && m.device_user_id) || '';
        f('m_active').checked = m && m.is_active !== undefined && m.is_active !== null ? !!m.is_active : true;
        modal.show();
    }

    f('mapModal').addEventListener('shown.bs.modal', function () {
        (f('m_device').value ? (f('m_pin').value ? f('m_employee') : f('m_pin')) : f('m_device')).focus();
    });
    document.querySelectorAll('.js-map-new').forEach(function (b) { b.addEventListener('click', function () { open(null); }); });
    document.querySelectorAll('.js-map-edit').forEach(function (b) {
        b.addEventListener('click', function () { open(JSON.parse(b.dataset.mapping)); });
    });

    @if ($errors->any() && old('_method'))
        open({
            id: @json(old('_mapping_id')) || null,
            attendance_device_id: @json(old('attendance_device_id')),
            employee_id: @json(old('employee_id')),
            device_user_id: @json(old('device_user_id')),
            is_active: @json((bool) old('is_active', true)),
        }, true);
    @elseif ($prefill)
        open(@json($prefill));
    @endif
});
</script>
@endpush
