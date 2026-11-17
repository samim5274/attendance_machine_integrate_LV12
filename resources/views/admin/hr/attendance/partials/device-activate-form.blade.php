{{-- Approve / enable a device: PUT its current details with is_active = 1. --}}
<form method="POST" action="{{ route('admin.hr.attendance.devices.update', $d['id']) }}" class="d-inline">
    @csrf
    @method('PUT')
    <input type="hidden" name="name" value="{{ str_starts_with($d['name'], 'New device') ? 'Attendance '.$d['serial_number'] : $d['name'] }}">
    <input type="hidden" name="location" value="{{ $d['location'] }}">
    <input type="hidden" name="timezone" value="{{ $d['timezone'] }}">
    <input type="hidden" name="notes" value="{{ $d['notes'] }}">
    <input type="hidden" name="is_active" value="1">
    <button type="submit" class="{{ $class }}">
        @isset($icon)<i class="bi {{ $icon }}"></i>@endisset {{ $label }}
    </button>
</form>
