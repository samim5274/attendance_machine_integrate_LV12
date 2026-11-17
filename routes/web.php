<?php

use App\Http\Controllers\Admin\Hr\AttendanceDeviceController;
use App\Http\Controllers\Admin\Hr\EmployeeDeviceMappingController;
use App\Http\Controllers\Admin\Hr\MachineAttendanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ZKTeco device endpoints (/iclock/*) live in routes/iclock.php, loaded outside
// the web group in bootstrap/app.php.

// Admin area: login required (HTTP Basic against the users table — create the
// first user with `php artisan zkteco:admin-user`) + the manage-attendance-devices gate.
Route::prefix('admin')->name('admin.')->middleware(['auth.basic', 'can:manage-attendance-devices'])->group(function () {
    Route::prefix('hr/attendance')->name('hr.attendance.')->group(function () {
        // Machine attendance (daily sheet, punch log, unmapped PINs)
        Route::get('machine', [MachineAttendanceController::class, 'index'])->name('machine.index');
        Route::get('machine/export', [MachineAttendanceController::class, 'export'])->name('machine.export');
        Route::post('machine/relink', [MachineAttendanceController::class, 'relink'])->name('machine.relink');

        // Devices
        Route::get('devices', [AttendanceDeviceController::class, 'index'])->name('devices.index');
        Route::post('devices', [AttendanceDeviceController::class, 'store'])->name('devices.store');
        Route::put('devices/{device}', [AttendanceDeviceController::class, 'update'])->name('devices.update');
        Route::delete('devices/{device}', [AttendanceDeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('devices/{device}/commands', [AttendanceDeviceController::class, 'command'])
            ->middleware('throttle:20,1')
            ->name('devices.command');

        // Employee <-> device PIN mapping
        Route::get('mappings', [EmployeeDeviceMappingController::class, 'index'])->name('mappings.index');
        Route::post('mappings', [EmployeeDeviceMappingController::class, 'store'])->name('mappings.store');
        Route::put('mappings/{mapping}', [EmployeeDeviceMappingController::class, 'update'])->name('mappings.update');
        Route::delete('mappings/{mapping}', [EmployeeDeviceMappingController::class, 'destroy'])->name('mappings.destroy');
        Route::post('mappings/{mapping}/device', [EmployeeDeviceMappingController::class, 'device'])
            ->middleware('throttle:20,1')
            ->name('mappings.device');
    });
});
