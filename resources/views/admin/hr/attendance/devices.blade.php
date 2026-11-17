@extends('admin.layouts.master')

@section('title', 'Attendance Devices')

@php
    $commandMeta = [
        'info' => ['icon' => 'bi-info-circle', 'help' => 'Read firmware, counters, MAC'],
        'check' => ['icon' => 'bi-upload', 'help' => 'Upload anything not yet sent'],
        'sync_logs' => ['icon' => 'bi-download', 'help' => 'Pull logs between two dates'],
        'resync_all' => ['icon' => 'bi-arrow-counterclockwise', 'help' => 'Re-send every stored log',
            'confirm' => 'The terminal will re-upload every attendance log it has stored. Duplicates are ignored, so nothing is double-counted, but it can take a few minutes on large devices.'],
        'sync_users' => ['icon' => 'bi-people', 'help' => 'Names & IDs enrolled on the device'],
        'push_employees' => ['icon' => 'bi-person-bounding-box', 'help' => 'Create every employee on the device',
            'confirm' => 'Every active employee with a device ID will be created/updated on the terminal (User ID = device ID). Faces still have to be enrolled on the device itself.'],
        'reboot' => ['icon' => 'bi-power', 'help' => 'Restart the terminal', 'danger' => true,
            'confirm' => 'The terminal will restart and be unavailable for about a minute. No data is lost.'],
    ];
    $online = $devices->where('online', true)->count();
    $pending = $devices->where('is_pending', true);
    $regular = $devices->where('is_pending', false);
    $isLocal = in_array($server['address'], ['127.0.0.1', 'localhost', '::1'], true);
    $statusStyle = [
        'pending' => ['pill-secondary', 'Waiting for device'],
        'sent' => ['pill-info', 'Sent'],
        'success' => ['pill-success', 'Done'],
        'failed' => ['pill-danger', 'Failed'],
        'expired' => ['pill-warning', 'No answer'],
    ];
    $stateStyle = [
        'online' => ['pill-success', 'dot-success pulse', 'Online'],
        'offline' => ['pill-danger', 'dot-danger', 'Offline'],
        'disabled' => ['pill-secondary', 'dot-muted', 'Disabled'],
    ];
@endphp

@section('content')
    {{-- Header --}}
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <a href="{{ route('admin.hr.attendance.machine.index') }}" class="small-caps text-teal text-decoration-none">
                <i class="bi bi-arrow-left"></i> Machine attendance
            </a>
            <h1 class="h3 fw-bold text-body-emphasis mt-1 mb-1">Attendance Devices</h1>
            <p class="text-body-secondary small mb-0">
                {{ $devices->count() }} device(s) · {{ $online }} online · ZKTeco ADMS (TA PUSH)
            </p>
        </div>
        <button type="button" class="btn btn-teal align-self-start align-self-lg-auto js-device-new">
            <i class="bi bi-plus-lg"></i> Add device
        </button>
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

    {{-- Terminal setup --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-column flex-lg-row gap-4">
            <div style="max-width: 18rem">
                <h2 class="h6 fw-semibold text-body-emphasis d-flex align-items-center gap-2 mb-2">
                    <span class="stat-icon bg-teal-soft"><i class="bi bi-hdd-network"></i></span> Terminal setup
                </h2>
                <p class="small text-body-secondary mb-0">
                    On the device: <b class="text-body-emphasis">Menu → COMM. → Cloud Server Setting</b>. The device then pushes every
                    punch here within seconds. Clock time zone: {{ $server['timezone'] }}.
                </p>
            </div>
            <div class="row row-cols-2 row-cols-sm-3 g-2 flex-grow-1">
                @foreach ([
                    'Server Mode' => 'ADMS',
                    'Enable Domain Name' => 'OFF',
                    'Server Address' => $server['address'],
                    'Server Port' => (string) $server['port'],
                    'Enable Proxy Server' => 'OFF',
                    'HTTPS' => 'OFF',
                ] as $label => $value)
                    <div class="col">
                        <button type="button" class="copy-field js-copy" data-value="{{ $value }}" title="Click to copy" aria-label="Copy {{ $label }}: {{ $value }}">
                            <span class="small-caps text-body-secondary d-flex justify-content-between">
                                {{ $label }} <i class="bi bi-clipboard copy-icon"></i>
                            </span>
                            <span class="d-block value">{{ $value }}</span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
        @if ($isLocal)
            <div class="card-footer bg-warning-subtle text-warning-emphasis small">
                <i class="bi bi-exclamation-triangle"></i>
                You opened this page via {{ $server['address'] }}. On the device use this server's LAN IP (e.g. 192.168.10.x) — or set ZKTECO_SERVER_ADDRESS in .env.
            </div>
        @endif
    </div>

    {{-- Pending approval --}}
    @if ($pending->isNotEmpty())
        <div class="alert alert-warning">
            <div class="fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> New terminal(s) waiting for approval</div>
            <p class="small mb-2">These connected to the server but are not trusted yet. Their data stays on the device until you approve them.</p>
            <div class="d-flex flex-wrap gap-2">
                @foreach ($pending as $d)
                    <div class="d-flex align-items-center gap-3 bg-body border rounded-3 px-3 py-2">
                        <div>
                            <div class="font-monospace fw-semibold small text-body-emphasis">{{ $d['serial_number'] }}</div>
                            <div class="small text-body-secondary">{{ $d['ip_address'] ?? 'unknown IP' }} · {{ $d['last_seen_human'] ?? 'never' }}</div>
                        </div>
                        @include('admin.hr.attendance.partials.device-activate-form', ['d' => $d, 'label' => 'Approve', 'class' => 'btn btn-success btn-sm'])
                        <form method="POST" action="{{ route('admin.hr.attendance.devices.destroy', $d['id']) }}"
                              data-confirm="The terminal is refused. If it is still pointed at this server it will re-appear here."
                              data-confirm-title="Reject {{ $d['serial_number'] }}?" data-confirm-label="Reject" data-confirm-danger>
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm text-danger">Reject</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Device cards --}}
    @if ($regular->isEmpty())
        <div class="card card-dashed text-center py-5 mb-4">
            <i class="bi bi-cpu fs-1 text-faint"></i>
            <h3 class="h6 fw-semibold text-body-emphasis mt-2">No devices yet</h3>
            <p class="small text-body-secondary mx-auto mb-0" style="max-width: 28rem">
                Add the terminal's serial number, or just point the terminal at this server — it will appear above for approval.
            </p>
        </div>
    @else
        <div class="row row-cols-1 row-cols-xl-2 g-4 mb-4">
            @foreach ($regular as $d)
                @php
                    $state = !$d['is_active'] ? 'disabled' : ($d['online'] ? 'online' : 'offline');
                    [$pill, $dot, $stateLabel] = $stateStyle[$state];
                    $firmware = collect([$d['firmware_version'], $d['push_version'] ? 'push '.$d['push_version'] : null])->filter()->implode(' · ');
                @endphp
                <div class="col">
                    <div class="card h-100">
                        <div class="card-body d-flex justify-content-between gap-3">
                            <div class="d-flex gap-3 min-w-0">
                                <span class="device-icon {{ $d['online'] ? 'online' : '' }}" aria-hidden="true"><i class="bi bi-fingerprint"></i></span>
                                <div class="min-w-0">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <h3 class="h6 fw-semibold text-body-emphasis mb-0 text-truncate">{{ $d['name'] }}</h3>
                                        <span class="pill {{ $pill }}"><span class="dot {{ $dot }}"></span> {{ $stateLabel }}</span>
                                    </div>
                                    <div class="small text-body-secondary mt-1">
                                        {{ $d['model'] ?? 'ZKTeco terminal' }}
                                        @if ($d['location'])
                                            <span class="ms-2"><i class="bi bi-geo-alt"></i> {{ $d['location'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Actions menu --}}
                            <div class="dropdown no-print">
                                <button class="btn btn-ghost btn-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for {{ $d['name'] }}">
                                    <i class="bi bi-three-dots-vertical fs-5"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" style="width: 18rem">
                                    @if ($d['is_active'])
                                        @foreach ($commandOptions as $o)
                                            @php $meta = $commandMeta[$o['value']] ?? ['icon' => 'bi-gear', 'help' => '']; @endphp
                                            <li>
                                                @if ($o['value'] === 'sync_logs')
                                                    <button type="button" class="dropdown-item d-flex gap-3 py-2 js-sync-logs" data-device="{{ $d['id'] }}" data-name="{{ $d['name'] }}">
                                                        <i class="bi {{ $meta['icon'] }} opacity-75"></i>
                                                        <span><span class="d-block fw-medium">{{ $o['label'] }}</span><span class="d-block small text-body-secondary text-wrap">{{ $meta['help'] }}</span></span>
                                                    </button>
                                                @else
                                                    <form method="POST" action="{{ route('admin.hr.attendance.devices.command', $d['id']) }}"
                                                          @isset($meta['confirm'])
                                                              data-confirm="{{ $meta['confirm'] }}" data-confirm-title="{{ $o['label'] }} — {{ $d['name'] }}" data-confirm-label="Send to device"
                                                              @if (!empty($meta['danger'])) data-confirm-danger @endif
                                                          @endisset>
                                                        @csrf
                                                        <input type="hidden" name="type" value="{{ $o['value'] }}">
                                                        <button type="submit" class="dropdown-item d-flex gap-3 py-2 {{ !empty($meta['danger']) ? 'text-danger' : '' }}">
                                                            <i class="bi {{ $meta['icon'] }} opacity-75"></i>
                                                            <span><span class="d-block fw-medium">{{ $o['label'] }}</span><span class="d-block small text-body-secondary text-wrap">{{ $meta['help'] }}</span></span>
                                                        </button>
                                                    </form>
                                                @endif
                                            </li>
                                        @endforeach
                                    @else
                                        <li>@include('admin.hr.attendance.partials.device-activate-form', ['d' => $d, 'label' => 'Enable device', 'class' => 'dropdown-item text-success fw-medium', 'icon' => 'bi-check-circle'])</li>
                                    @endif
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button type="button" class="dropdown-item fw-medium js-device-edit" data-device='@json($d)'>
                                            <i class="bi bi-pencil opacity-75 me-2"></i> Edit details
                                        </button>
                                    </li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.hr.attendance.devices.destroy', $d['id']) }}"
                                              data-confirm="The device is removed from the system. Attendance already received is kept. If the terminal is still pointed at this server it will re-appear as 'waiting for approval'."
                                              data-confirm-title="Remove {{ $d['name'] }}?" data-confirm-label="Remove device" data-confirm-danger>
                                            @csrf @method('DELETE')
                                            <button type="submit" class="dropdown-item text-danger fw-medium"><i class="bi bi-trash me-2"></i> Remove device</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        @if ($d['protocol_warning'])
                            <div class="alert alert-warning small mx-3 py-2 d-flex gap-2"><i class="bi bi-exclamation-triangle-fill"></i> {{ $d['protocol_warning'] }}</div>
                        @endif

                        <div class="row g-0 metric-strip">
                            @foreach ([
                                ['Punches today', $d['today_punches'], true],
                                ['Users / Faces', ($d['user_count'] ?? '–').' / '.($d['face_count'] ?? '–'), false],
                                ['Logs on device', $d['attlog_count'] ?? '–', false],
                            ] as [$label, $value, $accent])
                                <div class="col-4 px-3 py-2">
                                    <div class="small-caps text-body-secondary">{{ $label }}</div>
                                    <div class="metric-value {{ $accent ? 'accent' : '' }}">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>

                        <dl class="row row-cols-2 row-cols-sm-3 g-3 small px-3 py-3 mb-0">
                            @foreach ([
                                ['Serial number', $d['serial_number'], true],
                                ['IP address', $d['ip_address'], true],
                                ['MAC', $d['mac_address'], true],
                                ['Device port', $d['port'], true],
                                ['Protocol', $d['protocol'] ? str_replace('_', ' ', $d['protocol']) : null, false],
                                ['Clock time zone', $d['effective_timezone'], false],
                                ['Last seen', $d['last_seen_human'] ?? 'Never', false, $d['last_seen_at_local']],
                                ['Last handshake', $d['last_handshake_human'] ?? 'Never', false],
                                ['Last punch', $d['last_punch_at'], false],
                                ['Firmware', $firmware ?: null, false],
                                ['Logs received', number_format($d['total_punches']), false],
                                ['Mapped PINs', $d['active_mappings'], false],
                            ] as $row)
                                @php [$label, $value, $mono] = $row; $title = $row[3] ?? $value; @endphp
                                <div class="col min-w-0">
                                    <dt class="fw-normal text-body-secondary">{{ $label }}</dt>
                                    <dd class="fw-medium text-body-emphasis mb-0 text-truncate {{ $mono ? 'font-monospace' : '' }}" title="{{ $title }}">{{ $value ?? '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="card-footer d-flex flex-wrap align-items-center gap-2 mt-auto no-print">
                            @if ($d['is_active'])
                                @foreach (['test' => ['bi-broadcast', 'Test connection'], 'check' => ['bi-upload', 'Upload new logs'], 'info' => ['bi-arrow-clockwise', 'Refresh info']] as $type => [$icon, $label])
                                    <form method="POST" action="{{ route('admin.hr.attendance.devices.command', $d['id']) }}">
                                        @csrf
                                        <input type="hidden" name="type" value="{{ $type }}">
                                        <button type="submit" class="btn btn-sm btn-surface"><i class="bi {{ $icon }}"></i> {{ $label }}</button>
                                    </form>
                                @endforeach
                                <button type="button" class="btn btn-sm btn-surface js-sync-logs" data-device="{{ $d['id'] }}" data-name="{{ $d['name'] }}">
                                    <i class="bi bi-download"></i> Pull by date
                                </button>
                                <a href="{{ route('admin.hr.attendance.mappings.index', ['device_id' => $d['id']]) }}" class="btn btn-sm btn-surface"><i class="bi bi-person-badge"></i> PIN mapping</a>
                                <a href="{{ route('admin.hr.attendance.devices.index', ['history' => $d['id']]) }}#commands" class="btn btn-sm btn-surface"><i class="bi bi-clock-history"></i> Command history</a>
                            @else
                                @include('admin.hr.attendance.partials.device-activate-form', ['d' => $d, 'label' => 'Enable device', 'class' => 'btn btn-sm btn-surface text-success', 'icon' => 'bi-check-circle'])
                            @endif
                            @if ($d['pending_commands'] > 0)
                                <span class="ms-auto small fw-medium text-info-emphasis"><span class="dot dot-success pulse me-1" style="background: var(--bs-info)"></span> {{ $d['pending_commands'] }} command(s) waiting for the device</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Recent commands --}}
    <div class="card" id="commands">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
            <h2 class="h6 fw-semibold text-body-emphasis mb-0">
                @if ($historyDevice)
                    Command history — {{ $historyDevice['name'] }}
                    <a href="{{ route('admin.hr.attendance.devices.index') }}#commands" class="small fw-normal ms-2">show all devices</a>
                @else
                    Recent device commands
                @endif
            </h2>
            <span class="small text-body-secondary">Delivered on the device's next poll (~10 s). “Done” only after the device confirms.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle small">
                <thead>
                    <tr><th scope="col">#</th><th scope="col">Device</th><th scope="col">Action</th><th scope="col">Protocol command</th><th scope="col">Status</th><th scope="col">Requested</th><th scope="col">Sent</th><th scope="col">Completed</th><th scope="col">Device reply</th></tr>
                </thead>
                <tbody>
                    @forelse ($commands as $c)
                        @php [$pill, $label] = $statusStyle[$c['status']] ?? ['pill-secondary', $c['status']]; @endphp
                        <tr>
                            <td class="font-monospace text-faint">{{ $c['id'] }}</td>
                            <td>{{ $c['device'] ?? '—' }}</td>
                            <td class="fw-medium text-body-emphasis">{{ $c['label'] }}</td>
                            <td class="font-monospace text-body-secondary text-truncate" style="max-width: 20rem" title="{{ $c['command'] }}">{{ $c['command'] }}</td>
                            <td class="text-nowrap">
                                <span class="pill {{ $pill }}">
                                    {{ $label }}@if ($c['status'] === 'failed' && $c['return_code'] !== null) ({{ $c['return_code'] }})@endif
                                </span>
                            </td>
                            <td class="text-nowrap text-body-secondary">{{ $c['created_human'] }}</td>
                            <td class="text-nowrap text-body-secondary">{{ $c['sent_at'] ?? '—' }}</td>
                            <td class="text-nowrap text-body-secondary">{{ $c['completed_at'] ?? '—' }}</td>
                            <td class="font-monospace text-body-secondary text-truncate" style="max-width: 16rem" title="{{ $c['response'] }}">{{ $c['response'] ?? ($c['return_code'] !== null ? 'Return='.$c['return_code'] : '—') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty-state">No commands sent yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Add / edit device --}}
    <div class="modal fade" id="deviceModal" tabindex="-1" aria-labelledby="deviceModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" id="deviceForm"
                  data-store="{{ route('admin.hr.attendance.devices.store') }}"
                  data-update="{{ route('admin.hr.attendance.devices.update', '__ID__') }}">
                @csrf
                <input type="hidden" name="_method" value="POST" id="deviceMethod">
                <input type="hidden" name="_device_id" value="" id="deviceId">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="deviceModalTitle">Add attendance device</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="f_serial">Serial number <span class="text-danger">*</span></label>
                        <input class="form-control font-monospace text-uppercase @error('serial_number') is-invalid @enderror" id="f_serial" name="serial_number" maxlength="64" placeholder="e.g. VGU56244900309" required>
                        @error('serial_number')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">Menu → System Info → Device Info</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="f_name">Name <span class="text-danger">*</span></label>
                        <input class="form-control @error('name') is-invalid @enderror" id="f_name" name="name" maxlength="100" placeholder="e.g. Main Entrance" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-medium" for="f_model">Model</label>
                            <input class="form-control @error('model') is-invalid @enderror" id="f_model" name="model" maxlength="100" placeholder="e.g. SenseFace 3A">
                            @error('model')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-medium" for="f_location">Location</label>
                            <input class="form-control @error('location') is-invalid @enderror" id="f_location" name="location" maxlength="150" placeholder="e.g. Reception">
                            @error('location')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-medium" for="f_ip">Device IP address</label>
                            <input class="form-control font-monospace @error('ip_address') is-invalid @enderror" id="f_ip" name="ip_address" placeholder="e.g. 192.168.10.136">
                            @error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-medium" for="f_mac">MAC address</label>
                            <input class="form-control font-monospace @error('mac_address') is-invalid @enderror" id="f_mac" name="mac_address" placeholder="00:17:61:11:4f:a1">
                            @error('mac_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-medium" for="f_port">Device port</label>
                            <input type="number" min="1" max="65535" class="form-control font-monospace @error('port') is-invalid @enderror" id="f_port" name="port" placeholder="4370">
                            @error('port')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">The terminal's own port — not the server port.</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-medium" for="f_protocol">Protocol</label>
                            <select class="form-select @error('protocol') is-invalid @enderror" id="f_protocol" name="protocol">
                                <option value="TA_PUSH">TA PUSH (ADMS)</option>
                            </select>
                            @error('protocol')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="f_timezone">Clock time zone (optional)</label>
                        <input class="form-control font-monospace @error('timezone') is-invalid @enderror" id="f_timezone" name="timezone" placeholder="Default: {{ $server['timezone'] }}">
                        @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <input type="hidden" name="notes" id="f_notes">
                    <div class="form-check border rounded-3 p-2 ps-5 mb-0">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" value="1" id="f_active" name="is_active" checked>
                        <label class="form-check-label" for="f_active">
                            <span class="fw-medium">Active</span>
                            <span class="d-block small text-body-secondary">Inactive devices are refused; they keep their data until enabled.</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal" id="deviceSubmit">Add device</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Pull logs by date --}}
    <div class="modal fade" id="syncModal" tabindex="-1" aria-labelledby="syncModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" id="syncForm" data-action="{{ route('admin.hr.attendance.devices.command', '__ID__') }}">
                @csrf
                <input type="hidden" name="type" value="sync_logs">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="syncModalTitle">Pull logs — <span id="syncName"></span></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-medium" for="s_from">From</label>
                            <input type="date" class="form-control" id="s_from" name="from" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium" for="s_to">To</label>
                            <input type="date" class="form-control" id="s_to" name="to" required>
                        </div>
                    </div>
                    <p class="small text-body-secondary mt-3 mb-0">
                        Already-received punches are ignored automatically. If this command shows <b>Failed (-1002)</b>, your firmware doesn't support date
                        queries — use <b>Re-upload ALL logs</b> instead.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal"><i class="bi bi-download"></i> Request logs</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var today = @json(now($server['timezone'])->toDateString());
    var weekAgo = @json(now($server['timezone'])->subDays(6)->toDateString());
    var f = function (id) { return document.getElementById(id); };

    // Copy-to-clipboard for the setup fields (clipboard API needs https or localhost; ignore failures).
    document.querySelectorAll('.js-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(btn.dataset.value).then(function () {
                var icon = btn.querySelector('.copy-icon');
                icon.className = 'bi bi-check2 text-success copy-icon';
                setTimeout(function () { icon.className = 'bi bi-clipboard copy-icon'; }, 1200);
            }).catch(function () {});
        });
    });

    // Add / edit device modal.
    var form = f('deviceForm');
    var modal = bootstrap.Modal.getOrCreateInstance(f('deviceModal'));

    function openDevice(d, keepErrors) {
        var editing = !!(d && d.id);
        if (!keepErrors) {
            form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        }
        form.action = editing ? form.dataset.update.replace('__ID__', d.id) : form.dataset.store;
        f('deviceMethod').value = editing ? 'PUT' : 'POST';
        f('deviceId').value = editing ? d.id : '';
        f('deviceModalTitle').textContent = editing ? 'Edit ' + d.name : 'Add attendance device';
        f('deviceSubmit').textContent = editing ? 'Save changes' : 'Add device';
        f('f_serial').value = (d && d.serial_number) || '';
        f('f_serial').disabled = editing;          // serial can't be changed (and isn't sent on update)
        f('f_name').value = (d && d.name) || '';
        f('f_location').value = (d && d.location) || '';
        f('f_model').value = (d && d.model) || '';
        f('f_ip').value = (d && d.ip_address) || '';
        f('f_mac').value = (d && d.mac_address) || '';
        f('f_port').value = (d && d.port) || '';
        f('f_protocol').value = (d && d.protocol) || 'TA_PUSH';
        f('f_timezone').value = (d && d.timezone) || '';
        f('f_notes').value = (d && d.notes) || '';
        f('f_active').checked = d ? !!d.is_active : true;
        modal.show();
    }

    f('deviceModal').addEventListener('shown.bs.modal', function () {
        (f('f_serial').disabled ? f('f_name') : f('f_serial')).focus();
    });
    document.querySelectorAll('.js-device-new').forEach(function (b) { b.addEventListener('click', function () { openDevice(null); }); });
    document.querySelectorAll('.js-device-edit').forEach(function (b) {
        b.addEventListener('click', function () { openDevice(JSON.parse(b.dataset.device)); });
    });
    f('f_serial').addEventListener('input', function (e) { e.target.value = e.target.value.toUpperCase(); });

    // Re-open the form with the old input after a validation error.
    @if ($errors->any() && old('_method'))
        openDevice({
            id: @json(old('_device_id')) || null,
            serial_number: @json(old('serial_number')),
            name: @json(old('name')),
            location: @json(old('location')),
            model: @json(old('model')),
            ip_address: @json(old('ip_address')),
            mac_address: @json(old('mac_address')),
            port: @json(old('port')),
            protocol: @json(old('protocol')),
            timezone: @json(old('timezone')),
            notes: @json(old('notes')),
            is_active: @json((bool) old('is_active', true)),
        }, true);
    @endif

    // Pull-by-date modal.
    var syncForm = f('syncForm');
    var syncModal = bootstrap.Modal.getOrCreateInstance(f('syncModal'));
    document.querySelectorAll('.js-sync-logs').forEach(function (b) {
        b.addEventListener('click', function () {
            syncForm.action = syncForm.dataset.action.replace('__ID__', b.dataset.device);
            f('syncName').textContent = b.dataset.name;
            f('s_from').value = weekAgo; f('s_from').max = today;
            f('s_to').value = today; f('s_to').max = today; f('s_to').min = weekAgo;
            syncModal.show();
        });
    });
    f('s_from').addEventListener('change', function () { f('s_to').min = f('s_from').value; });

    // Keep status fresh while the page is open (skip while the user is interacting).
    setInterval(function () {
        if (document.hidden || document.querySelector('.modal.show, .dropdown-menu.show')) return;
        window.location.reload();
    }, 15000);
});
</script>
@endpush
