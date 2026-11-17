@extends('admin.layouts.master')

@section('title', 'Home')

@section('content')
    <div class="mx-auto py-lg-4" style="max-width: 56rem">
        <div class="mb-4">
            <div class="small-caps text-teal"><i class="bi bi-fingerprint"></i> Supreme Balora Hospital</div>
            <h1 class="h3 fw-bold mt-1 mb-1">Attendance system</h1>
            <p class="text-body-secondary mb-0">Face-terminal punches arrive here in real time. Choose where to go.</p>
        </div>

        <div class="row g-3">
            @foreach ([
                [route('admin.hr.attendance.machine.index'), 'bi-calendar-check', 'tone-success', 'Machine Attendance', 'Daily sheet, punch log, unmapped PINs and CSV export.'],
                [route('admin.hr.attendance.devices.index'), 'bi-cpu', 'tone-info', 'Attendance Devices', 'Terminal status, setup values and remote commands.'],
            ] as [$href, $icon, $tone, $title, $text])
                <div class="col-md-6">
                    <a href="{{ $href }}" class="card stat-card h-100 text-decoration-none text-reset">
                        <div class="card-body p-4 d-flex gap-3">
                            <span class="stat-icon {{ $tone }}"><i class="bi {{ $icon }}"></i></span>
                            <span>
                                <span class="d-block fw-semibold text-body-emphasis">{{ $title }} <i class="bi bi-arrow-right small text-teal"></i></span>
                                <span class="d-block small text-body-secondary mt-1">{{ $text }}</span>
                            </span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
@endsection
