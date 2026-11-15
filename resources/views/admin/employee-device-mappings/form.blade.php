
<div class="mb-3">
    <label class="form-label">Attendance Device</label>

    <select name="device_id" class="form-select" required>
        <option value="">Select Device</option>

        @foreach($devices as $device)
            <option value="{{ $device->id }}"
                @selected(old('device_id', $mapping->device_id ?? '') == $device->id)>
                {{ $device->name ?? 'Device #' . $device->id }}
            </option>
        @endforeach
    </select>

    @error('device_id')
        <small class="text-danger">{{ $message }}</small>
    @enderror
</div>

<div class="mb-3">
    <label class="form-label">Employee</label>

    <select name="employee_id" class="form-select" required>
        <option value="">Select Employee</option>

        @foreach($employees as $employee)
            <option value="{{ $employee->id }}"
                @selected(old('employee_id', $mapping->employee_id ?? '') == $employee->id)>
                {{ $employee->name }} ({{ $employee->employee_id }})
            </option>
        @endforeach
    </select>

    @error('employee_id')
        <small class="text-danger">{{ $message }}</small>
    @enderror
</div>

<div class="mb-3">
    <label class="form-label">Machine Employee PIN</label>

    <input
        type="text"
        name="device_user_id"
        class="form-control"
        value="{{ old('device_user_id', $mapping->device_user_id ?? '') }}"
        placeholder="Example: 1001"
        required
    >

    @error('device_user_id')
        <small class="text-danger">{{ $message }}</small>
    @enderror
</div>

<div class="mb-3">
    <label class="form-label">Status</label>

    <select name="is_active" class="form-select" required>
        <option value="1"
            @selected(old('is_active', $mapping->is_active ?? 1) == 1)>
            Active
        </option>

        <option value="0"
            @selected((string) old('is_active', $mapping->is_active ?? 1) === '0')>
            Inactive
        </option>
    </select>

    @error('is_active')
        <small class="text-danger">{{ $message }}</small>
    @enderror
</div>

<div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">
        {{ isset($mapping) ? 'Update Mapping' : 'Save Mapping' }}
    </button>

    <a href="{{ route('admin.employee-device-mappings.index') }}"
       class="btn btn-secondary">
        Cancel
    </a>
</div>
