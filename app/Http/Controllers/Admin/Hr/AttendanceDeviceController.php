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
use Inertia\Inertia;
use Inertia\Response;
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

    public function index(Request $request): Response
    {
        AttendanceDeviceCommand::expireStale();

        $tz = (string) config('zkteco.timezone');
        $todayStart = now($tz)->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $devices = AttendanceDevice::query()
            ->withCount([
                'punches as today_punches' => fn ($q) => $q->where('punched_at', '>=', $todayStart),
                'users as enrolled_users',
                'commands as pending_commands' => fn ($q) => $q->whereIn('status', [AttendanceDeviceCommand::PENDING, AttendanceDeviceCommand::SENT]),
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
                'enrolled_users' => $d->enrolled_users,
                'pending_commands' => $d->pending_commands,
                'protocol_warning' => $d->options['protocol_warning'] ?? null,
                'notes' => $d->notes,
            ]);

        $commands = AttendanceDeviceCommand::query()
            ->with('device:id,name')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AttendanceDeviceCommand $c) => [
                'id' => $c->id,
                'device' => $c->device?->name,
                'type' => $c->type,
                'label' => self::COMMAND_LABELS[$c->type] ?? $c->type,
                'command' => mb_strimwidth(str_replace("\t", ' ', $c->command), 0, 90, '…'),
                'status' => $c->status,
                'return_code' => $c->return_code,
                'created_human' => $c->created_at?->diffForHumans(),
                'completed_at' => $c->completed_at?->setTimezone($tz)->format('d M, h:i:s A'),
            ]);

        return Inertia::render('Admin/Hr/Attendance/Devices', [
            'devices' => $devices,
            'commands' => $commands,
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
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('attendance_devices', 'serial_number')],
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'timezone' => ['nullable', 'timezone'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['serial_number'] = strtoupper(trim($data['serial_number']));
        AttendanceDevice::create($data + ['is_active' => true]);

        return back()->with('success', "Device {$data['name']} added. It will connect on its next poll.");
    }

    public function update(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'timezone' => ['nullable', 'timezone'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $device->update($data);

        return back()->with('success', "Device {$device->name} updated.");
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
            'type' => ['required', Rule::in(DeviceCommandService::UI_TYPES)],
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

        if (!$device->isOnline()) {
            $message .= ' The device is offline; it will run when it reconnects.';
        }

        return back()->with('success', $message);
    }
}
