<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\Employee;
use App\Models\EmployeeDeviceMapping;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeDeviceMappingController extends Controller
{
    public function index()
    {
        $mappings = EmployeeDeviceMapping::with([
            'device',
            'employee',
        ])->latest()->paginate(15);

        return view('admin.employee-device-mappings.index', compact('mappings'));
    }

    public function create()
    {
        $devices = AttendanceDevice::orderBy('id')->get();
        $employees = Employee::orderBy('name')->get();

        return view('admin.employee-device-mappings.create', compact(
            'devices',
            'employees'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => [
                'required',
                'exists:attendance_devices,id',
            ],
            'employee_id' => [
                'required',
                'exists:employees,id',
            ],
            'device_user_id' => [
                'required',
                'string',
                'max:100',
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        $exists = EmployeeDeviceMapping::where(
            'device_id',
            $validated['device_id']
        )->where(
            'device_user_id',
            $validated['device_user_id']
        )->exists();

        if ($exists) {
            return back()
                ->withInput()
                ->withErrors([
                    'device_user_id' => 'This machine PIN is already mapped to an employee on this device.',
                ]);
        }

        EmployeeDeviceMapping::create($validated);

        return redirect()
            ->route('admin.employee-device-mappings.index')
            ->with('success', 'Employee PIN mapping created successfully.');
    }

    public function edit(EmployeeDeviceMapping $employee_device_mapping)
    {
        $devices = AttendanceDevice::orderBy('id')->get();
        $employees = Employee::orderBy('name')->get();

        return view('admin.employee-device-mappings.edit', [
            'mapping' => $employee_device_mapping,
            'devices' => $devices,
            'employees' => $employees,
        ]);
    }

    public function update(
        Request $request,
        EmployeeDeviceMapping $employee_device_mapping
    ) {
        $validated = $request->validate([
            'device_id' => [
                'required',
                'exists:attendance_devices,id',
            ],
            'employee_id' => [
                'required',
                'exists:employees,id',
            ],
            'device_user_id' => [
                'required',
                'string',
                'max:100',
                Rule::unique('employee_device_mappings', 'device_user_id')
                    ->where('device_id', $request->device_id)
                    ->ignore($employee_device_mapping->id),
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        $employee_device_mapping->update($validated);

        return redirect()
            ->route('admin.employee-device-mappings.index')
            ->with('success', 'Employee PIN mapping updated successfully.');
    }

    public function destroy(EmployeeDeviceMapping $employee_device_mapping)
    {
        $employee_device_mapping->delete();

        return redirect()
            ->route('admin.employee-device-mappings.index')
            ->with('success', 'Employee PIN mapping deleted successfully.');
    }
}
