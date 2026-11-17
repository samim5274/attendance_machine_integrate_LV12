{{-- GET search box that keeps the other filters. $hidden: name => value (null = omitted). --}}
<form method="GET" action="{{ route('admin.hr.attendance.machine.index') }}" class="search-box" role="search">
    @foreach ($hidden as $name => $value)
        @if ($value !== null && $value !== '')
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endif
    @endforeach
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="{{ $placeholder }}"
           class="form-control js-search" autocomplete="off" aria-label="{{ $placeholder }}">
</form>
