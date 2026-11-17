<?php

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\EmployeeDeviceMapping;
use App\Services\Zkteco\AttendanceIngestService;
use App\Services\Zkteco\DeviceCommandService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * "PIN Mapping": which employee a User ID (PIN) on a given terminal belongs to.
 */
class EmployeeDeviceMappingController extends Controller
{
    /** Same characters the protocol parser keeps (AdmsParser::cleanPin). */
    private const PIN_RULE = 'regex:/^[A-Za-z0-9_.\-]{1,32}$/';

    public function __construct(private readonly AttendanceIngestService $ingest)
    {
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,active,inactive'],
            'search' => ['nullable', 'string', 'max:100'],
            // Pre-fill from "Unmapped PINs" -> "Map"
            'new_pin' => ['nullable', 'string', 'max:32'],
            'new_device' => ['nullable', 'integer'],
        ]);
        $filters['status'] ??= 'all';
        $filters['search'] = trim((string) ($filters['search'] ?? ''));

        $mappings = EmployeeDeviceMapping::query()
            ->with(['device:id,name,serial_number,is_active', 'employee:id,employee_id,name,status,deleted_at'])
            ->when($filters['device_id'] ?? null, fn ($q, $id) => $q->where('attendance_device_id', $id))
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $s = '%'.$filters['search'].'%';
                $q->where(fn ($q) => $q->where('device_user_id', 'like', $s)
                    ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', $s)->orWhere('employee_id', 'like', $s)));
            })
            ->orderBy('attendance_device_id')
            ->orderByRaw('LENGTH(device_user_id), device_user_id')
            ->paginate(25)
            ->withQueryString();

        // Punches still waiting for each mapping's (device, PIN) — useful right after creating one.
        $unresolved = AttendancePunch::query()
            ->whereNull('employee_id')
            ->selectRaw('attendance_device_id, pin, COUNT(*) as n')
            ->groupBy('attendance_device_id', 'pin')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->attendance_device_id.'|'.$r->pin => (int) $r->n]);

        return view('admin.hr.attendance.mappings', [
            'mappings' => $mappings,
            'filters' => $filters,
            'unresolved' => $unresolved,
            'devices' => AttendanceDevice::query()->orderBy('name')->get(['id', 'name', 'serial_number', 'is_active']),
            'employees' => Employee::query()->orderBy('name')->get(['id', 'employee_id', 'name', 'status']),
            'totals' => [
                'all' => EmployeeDeviceMapping::count(),
                'active' => EmployeeDeviceMapping::where('is_active', true)->count(),
                'unresolved_punches' => AttendancePunch::whereNull('employee_id')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $linked = DB::transaction(function () use ($data) {
            $mapping = EmployeeDeviceMapping::create($data);

            return $mapping->is_active ? $this->ingest->relinkUnmapped($mapping->attendance_device_id, $mapping->device_user_id) : 0;
        });

        return back()->with('success', 'Mapping saved.'.$this->linkedMessage($linked));
    }

    public function update(Request $request, EmployeeDeviceMapping $mapping): RedirectResponse
    {
        $data = $this->validated($request, $mapping);

        $linked = DB::transaction(function () use ($mapping, $data) {
            $mapping->update($data);

            return $mapping->is_active ? $this->ingest->relinkUnmapped($mapping->attendance_device_id, $mapping->device_user_id) : 0;
        });

        return back()->with('success', 'Mapping updated.'.$this->linkedMessage($linked)
            .' Punches that were already linked keep their employee.');
    }

    public function destroy(EmployeeDeviceMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('success', "Mapping for PIN {$mapping->device_user_id} removed. Attendance already recorded is kept.");
    }

    /** Create (push) or delete this user on the terminal. */
    public function device(Request $request, EmployeeDeviceMapping $mapping, DeviceCommandService $commands): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:push,remove']])['action'];
        $device = $mapping->device;

        if (!$device->is_active) {
            return back()->with('error', 'Activate the device first.');
        }

        try {
            $command = $action === 'push'
                ? $commands->pushMapping($mapping, $request->user()?->getKey())
                : $commands->deleteUser($device, $mapping->device_user_id, $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = ($action === 'push' ? "Command #{$command->id} queued: create/update user {$mapping->device_user_id} on {$device->name}."
            : "Command #{$command->id} queued: delete user {$mapping->device_user_id} from {$device->name}.")
            .' Check “Recent device commands” for the device’s answer.';

        if (!$device->isOnline()) {
            $message .= ' The device is offline; it will run when it reconnects.';
        }

        return back()->with('success', $message);
    }

    private function validated(Request $request, ?EmployeeDeviceMapping $mapping = null): array
    {
        $request->merge(['device_user_id' => trim((string) $request->input('device_user_id'))]);

        $data = $request->validate([
            'attendance_device_id' => ['required', 'integer', Rule::exists('attendance_devices', 'id')],
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'device_user_id' => [
                'required', 'string', self::PIN_RULE, 'not_in:0',
                Rule::unique('employee_device_mappings', 'device_user_id')
                    ->where('attendance_device_id', $request->input('attendance_device_id'))
                    ->ignore($mapping?->id),
            ],
            'is_active' => ['boolean'],
        ], [
            'device_user_id.unique' => 'This PIN is already mapped to an employee on that device.',
            'device_user_id.regex' => 'The PIN may only contain letters, digits, dot, dash or underscore (max 32).',
            'device_user_id.not_in' => 'PIN 0 is reserved by the device.',
            'employee_id.exists' => 'Select an existing employee.',
            'attendance_device_id.exists' => 'Select an existing device.',
        ], [
            'attendance_device_id' => 'device',
            'employee_id' => 'employee',
            'device_user_id' => 'machine PIN',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function linkedMessage(int $linked): string
    {
        return $linked > 0 ? " {$linked} earlier punch(es) were linked and attendance recalculated." : '';
    }
}
