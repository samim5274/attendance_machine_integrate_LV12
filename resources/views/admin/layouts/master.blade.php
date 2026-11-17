@php
    // Product name shown in the UI; falls back when APP_NAME is still Laravel's default.
    $brand = in_array(config('app.name'), [null, '', 'Laravel'], true) ? 'Attendance' : config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f3f5f8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b1120" media="(prefers-color-scheme: dark)">

    <title>@hasSection('title')@yield('title') · @endif{{ $brand }}</title>

    {{-- Apply the saved / OS theme before first paint (no light flash in dark mode). --}}
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('app.theme'); } catch (e) {}
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>

    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @stack('styles')
</head>

<body class="d-flex flex-column">
    <a href="#main" class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-teal btn-sm" style="z-index: 1100">Skip to content</a>

    <nav class="navbar navbar-expand-md app-navbar sticky-top">
        <div class="container-fluid px-3 px-lg-4" style="max-width: 1600px">
            <a class="navbar-brand d-flex align-items-center" href="{{ route('admin.hr.attendance.machine.index') }}">
                <span class="brand-mark"><i class="bi bi-fingerprint"></i></span>
                {{ $brand }}
            </a>

            <div class="d-flex align-items-center gap-1 order-md-last">
                {{-- Theme switcher --}}
                <div class="dropdown theme-switch">
                    <button class="btn btn-ghost btn-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Change theme" title="Theme">
                        <i class="bi bi-circle-half" data-theme-icon></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="min-width: 9rem">
                        <li><button type="button" class="dropdown-item d-flex align-items-center gap-2" data-theme-value="light"><i class="bi bi-sun"></i> Light</button></li>
                        <li><button type="button" class="dropdown-item d-flex align-items-center gap-2" data-theme-value="dark"><i class="bi bi-moon-stars"></i> Dark</button></li>
                        <li><button type="button" class="dropdown-item d-flex align-items-center gap-2" data-theme-value="auto"><i class="bi bi-circle-half"></i> System</button></li>
                    </ul>
                </div>
                <button class="navbar-toggler border-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#appNav" aria-controls="appNav" aria-expanded="false" aria-label="Toggle navigation">
                    <i class="bi bi-list fs-4"></i>
                </button>
            </div>

            <div class="collapse navbar-collapse" id="appNav">
                <ul class="navbar-nav ms-md-4 gap-md-1 mt-2 mt-md-0">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('admin.hr.attendance.machine.*') ? 'active' : '' }}"
                           @if (request()->routeIs('admin.hr.attendance.machine.*')) aria-current="page" @endif
                           href="{{ route('admin.hr.attendance.machine.index') }}"><i class="bi bi-calendar-check me-1"></i> Machine Attendance</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('admin.hr.attendance.devices.*') ? 'active' : '' }}"
                           @if (request()->routeIs('admin.hr.attendance.devices.*')) aria-current="page" @endif
                           href="{{ route('admin.hr.attendance.devices.index') }}"><i class="bi bi-cpu me-1"></i> Attendance Devices</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('admin.hr.attendance.mappings.*') ? 'active' : '' }}"
                           @if (request()->routeIs('admin.hr.attendance.mappings.*')) aria-current="page" @endif
                           href="{{ route('admin.hr.attendance.mappings.index') }}"><i class="bi bi-person-badge me-1"></i> PIN Mapping</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main id="main" class="container-fluid px-3 px-lg-4 py-4 flex-grow-1" style="max-width: 1600px">
        @yield('content')
    </main>

    <footer class="app-footer container-fluid px-3 px-lg-4 pb-4" style="max-width: 1600px">
        © {{ now()->year }} {{ $brand }}
    </footer>

    {{-- Generic confirm dialog, used by <form data-confirm="..."> --}}
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="confirmModalTitle"></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"><p class="mb-0 text-body-secondary js-confirm-message"></p></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-teal js-confirm-ok">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090">
        @foreach (['success' => ['success', 'bi-check-circle-fill'], 'error' => ['danger', 'bi-exclamation-octagon-fill']] as $key => [$tone, $icon])
            @if (session($key))
                <div class="toast align-items-center text-bg-{{ $tone }} border-0 js-flash" role="{{ $tone === 'danger' ? 'alert' : 'status' }}" aria-live="{{ $tone === 'danger' ? 'assertive' : 'polite' }}" aria-atomic="true" data-bs-delay="6000">
                    <div class="d-flex">
                        <div class="toast-body d-flex gap-2"><i class="bi {{ $icon }}"></i> <span>{{ session($key) }}</span></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    @stack('scripts')
</body>
</html>
