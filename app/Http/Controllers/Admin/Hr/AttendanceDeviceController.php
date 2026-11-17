<?php

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceCommand;
use App\Services\Zkteco\DeviceCommandService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;

class AttendanceDeviceController extends Controller
{
    private const COMMAND_LABELS = [
        'info' => 'Refresh device info',
        'check' => 'Upload new logs now',
        'resync_all' => 'Re-upload ALL logs',
        'sync_logs' => 'Pull logs for a period',
        'sync_users' => 'Read user list',
        'push_employees' => 'Push employees to device',
        'reboot' => 'Reboot',
        'push_user' => 'Push user',
        'delete_user' => 'Delete user',
        'raw' => 'Raw command',
    ];

    public function index(Request $request): View
    {
        AttendanceDeviceCommand::expireStale();

        $tz = (string) config('zkteco.timezone');
        $todayStart = now($tz)->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $devices = AttendanceDevice::query()
            ->withCount([
                'punches as today_punches' => fn ($q) => $q->where('punched_at', '>=', $todayStart),
                'users as enrolled_users',
                'commands as pending_commands' => fn ($q) => $q->whereIn('status', [AttendanceDeviceCommand::PENDING, AttendanceDeviceCommand::SENT]),
                'punches as total_punches',
                'mappings as active_mappings' => fn ($q) => $q->where('is_active', true),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (AttendanceDevice $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'serial_number' => $d->serial_number,
                'model' => $d->model,
                'location' => $d->location,
                'ip_address' => $d->ip_address,
                'mac_address' => $d->mac_address,
                'port' => $d->port,
                'protocol' => $d->protocol,
                'state' => $d->connectionState(),
                'last_handshake_human' => $d->last_handshake_at?->diffForHumans(),
                'last_seen_at_local' => $d->last_seen_at?->setTimezone($tz)->format('d M Y, h:i:s A'),
                'total_punches' => $d->total_punches,
                'active_mappings' => $d->active_mappings,
                'firmware_version' => $d->firmware_version,
                'push_version' => $d->push_version,
                'timezone' => $d->timezone,
                'is_active' => $d->is_active,
                'is_pending' => !$d->is_active && $d->last_handshake_at === null && $d->punches()->doesntExist(),
                'online' => $d->is_active && $d->isOnline(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'last_seen_human' => $d->last_seen_at?->diffForHumans(),
                'last_punch_at' => $d->last_punch_at?->setTimezone($tz)->format('d M Y, h:i A'),
                'user_count' => $d->user_count,
                'face_count' => $d->face_count,
                'fp_count' => $d->fp_count,
                'attlog_count' => $d->attlog_count,
                'today_punches' => $d->today_punches,
                'effective_timezone' => $d->clockTimezone(),
                'enrolled_users' => $d->enrolled_users,
                'pending_commands' => $d->pending_commands,
                'protocol_warning' => $d->options['protocol_warning'] ?? null,
                'notes' => $d->notes,
            ]);

        $historyDevice = $request->integer('history') ?: null;

        $commands = AttendanceDeviceCommand::query()
            ->with('device:id,name')
            ->when($historyDevice, fn ($q, $id) => $q->where('attendance_device_id', $id))
            ->latest('id')
            ->limit($historyDevice ? 100 : 20)
            ->get()
            ->map(fn (AttendanceDeviceCommand $c) => [
                'id' => $c->id,
                'device' => $c->device?->name,
                'type' => $c->type,
                'label' => self::COMMAND_LABELS[$c->type] ?? $c->type,
                'command' => mb_strimwidth(str_replace("\t", ' ', $c->command), 0, 90, '…'),
                'status' => $c->status,
                'return_code' => $c->return_code,
                'response' => $c->response !== null ? mb_strimwidth($c->response, 0, 300, '…') : null,
                'sent_at' => $c->sent_at?->setTimezone($tz)->format('d M, h:i:s A'),
                'created_human' => $c->created_at?->diffForHumans(),
                'completed_at' => $c->completed_at?->setTimezone($tz)->format('d M, h:i:s A'),
            ]);

        return view('admin.hr.attendance.devices', [
            'devices' => $devices,
            'commands' => $commands,
            'historyDevice' => $historyDevice ? $devices->firstWhere('id', $historyDevice) : null,
            'server' => [
                'address' => config('zkteco.server_address_hint') ?: $request->getHost(),
                'port' => (int) (config('zkteco.server_port_hint') ?: $request->getPort()),
                'timezone' => $tz,
                'online_threshold' => (int) config('zkteco.devices.online_threshold_seconds', 120),
            ],
            'commandOptions' => collect(DeviceCommandService::UI_TYPES)
                ->map(fn ($t) => ['value' => $t, 'label' => self::COMMAND_LABELS[$t]])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['serial_number' => strtoupper(trim((string) $request->input('serial_number')))]);

        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('attendance_devices', 'serial_number')],
            ...$this->detailRules(),
        ], $this->messages());

        AttendanceDevice::create($this->normalise($data) + ['is_active' => true]);

        return back()->with('success', "Device {$data['name']} added. It will connect on its next poll.");
    }

    public function update(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $data = $request->validate($this->detailRules(), $this->messages());

        $device->update($this->normalise($data));

        return back()->with('success', "Device {$device->name} updated.");
    }

    /** Fields shared by create and edit. Serial number is fixed after creation (it is the device identity). */
    private function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'ip_address' => ['nullable', 'ip'],
            'mac_address' => ['nullable', 'string', 'regex:/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'protocol' => ['nullable', Rule::in(['TA_PUSH'])],
            'timezone' => ['nullable', 'timezone'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function messages(): array
    {
        return [
            'serial_number.unique' => 'A device with this serial number is already registered.',
            'mac_address.regex' => 'MAC address must look like 00:17:61:11:4f:a1.',
            'port.between' => 'Port must be between 1 and 65535.',
        ];
    }

    private function normalise(array $data): array
    {
        if (!empty($data['mac_address'])) {
            $data['mac_address'] = strtolower(str_replace('-', ':', $data['mac_address']));
        }
        $data['protocol'] = $data['protocol'] ?? 'TA_PUSH';

        return $data;
    }

    public function destroy(AttendanceDevice $device): RedirectResponse
    {
        $name = $device->name;
        $device->delete(); // punches are kept (device link set to null)

        return back()->with('success', "Device {$name} removed. Its attendance records were kept.");
    }

    public function command(Request $request, AttendanceDevice $device, DeviceCommandService $commands): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([...DeviceCommandService::UI_TYPES, 'test'])],
            'from' => ['required_if:type,sync_logs', 'nullable', 'date'],
            'to' => ['required_if:type,sync_logs', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        if (!$device->is_active) {
            return back()->with('error', 'Activate the device first.');
        }

        $userId = $request->user()?->getKey();
        $tz = (string) config('zkteco.timezone');

        try {
            $message = match ($data['type']) {
                'test' => $this->testMessage($device, $commands->info($device, $userId)),
                'info' => $commands->info($device, $userId) ? 'Device info requested.' : '',
                'check' => $commands->check($device, $userId) ? 'Device asked to upload new logs.' : '',
                'resync_all' => $commands->resyncAll($device, $userId) ? 'Device will re-upload every stored log (duplicates are ignored).' : '',
                'sync_logs' => $commands->syncLogs($device, Carbon::parse($data['from'], $tz), Carbon::parse($data['to'], $tz), $userId)
                    ? "Logs from {$data['from']} to {$data['to']} requested." : '',
                'sync_users' => $commands->syncUsers($device, $userId) ? 'User list requested.' : '',
                'push_employees' => $commands->pushEmployees($device, $userId).' employee(s) queued for the device. Enrol each face on the terminal.',
                'reboot' => $commands->reboot($device, $userId) ? 'Reboot queued.' : '',
            };
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (!$device->isOnline() && $data['type'] !== 'test') {
            $message .= ' The device is offline; it will run when it reconnects.';
        }

        return back()->with($data['type'] === 'test' && !$device->isOnline() ? 'error' : 'success', $message);
    }

    /**
     * TA PUSH has no way for the server to reach the device; the only proof of a
     * working connection is the device talking to us. When it hasn't, run quick
     * checks from this server so the message says WHERE the chain is broken, and
     * queue an INFO command whose answer proves the full command round-trip.
     */
    private function testMessage(AttendanceDevice $device, AttendanceDeviceCommand $probe): string
    {
        $threshold = (int) config('zkteco.devices.online_threshold_seconds', 120);

        if ($device->last_seen_at !== null && $device->isOnline()) {
            $ago = (int) $device->last_seen_at->diffInSeconds(now(), true);

            return "Connection OK: last contact {$ago}s ago (threshold {$threshold}s). Test command #{$probe->id} queued — it shows “Done” once the device answers (≈10 s).";
        }

        $status = $device->last_seen_at === null
            ? 'The device has never contacted this server.'
            : 'No contact for '.(int) $device->last_seen_at->diffInSeconds(now(), true)."s (threshold {$threshold}s) — the device is offline.";

        return implode(' ', [$status, ...$this->diagnose($device), "Test command #{$probe->id} will run when it reconnects."]);
    }

    /**
     * What this server can check on its own, most specific first:
     *  1. a terminal with another serial is knocking  → the registered serial is wrong;
     *  2. nothing listens on the address/port the terminal is told to use
     *     (plain `php artisan serve` binds 127.0.0.1 only, or this PC's IP changed);
     *  3. the terminal does not answer on the LAN at all.
     * If all pass, the gap is the terminal's Cloud Server Setting or this PC's firewall
     * (a connection from this PC to itself never goes through its own firewall).
     *
     * @return list<string>
     */
    private function diagnose(AttendanceDevice $device): array
    {
        $found = [];
        $host = (string) (config('zkteco.server_address_hint') ?: request()->getHost());
        $port = (int) (config('zkteco.server_port_hint') ?: request()->getPort());

        $otherSerial = AttendanceDevice::query()
            ->whereKeyNot($device->getKey())
            ->where('is_active', false)
            ->whereNull('last_handshake_at')
            ->whereDoesntHave('punches')
            ->latest('id')
            ->value('serial_number');
        if ($otherSerial !== null) {
            $found[] = "Serial {$otherSerial} is waiting for approval on this page — if that is this terminal, the serial registered here ({$device->serial_number}) is wrong: approve {$otherSerial} and delete this entry.";
        }

        if (!$this->canConnect($host, $port)[0]) {
            $found[] = "Nothing answers at {$host}:{$port} even from this server — start Laravel with `php artisan serve --host=0.0.0.0 --port={$port}` (plain `php artisan serve` listens on 127.0.0.1 only) and check that {$host} is still this PC's IP (`ip -4 addr`).";
        }

        $deviceUp = null;
        if ($device->ip_address) {
            [$open, $errno] = $this->canConnect($device->ip_address, (int) ($device->port ?: 4370));
            $deviceUp = $open || in_array($errno, [111, 10061], true); // "connection refused" = host is up, port closed
            if (!$deviceUp) {
                $found[] = "The terminal does not answer at {$device->ip_address} — check that it is on, its network cable, and COMM → Ethernet (IP {$device->ip_address}, same network as this server).";
            }
        }

        if ($found === []) {
            $found[] = ($deviceUp ? 'This server and the terminal both answer on the network. ' : '')
                ."On the terminal set COMM → Cloud Server Setting: Server Mode ADMS, Enable Domain Name OFF, Server Address {$host}, Server Port {$port}, Proxy OFF, HTTPS OFF — then reboot it. "
                ."On this PC open the port: `sudo ufw allow from ".($device->ip_address ?: 'any')." to any port {$port} proto tcp`.";
        }

        return $found;
    }

    /** @return array{0: bool, 1: int} [connected, errno] — a bare TCP connect, nothing is sent. */
    private function canConnect(string $host, int $port, float $timeout = 1.5): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($socket === false) {
            return [false, (int) $errno];
        }

        fclose($socket);

        return [true, 0];
    }
}