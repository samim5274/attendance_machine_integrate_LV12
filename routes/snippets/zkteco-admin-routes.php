<?php

/*
|--------------------------------------------------------------------------
| ZKTeco admin routes — paste INSIDE your existing admin route group
|--------------------------------------------------------------------------
| e.g. Route::middleware(['auth', ...])->prefix('admin')->name('admin.')->group(function () {
|          ... (paste here) ...
|      });
|
| Final names: admin.hr.attendance.machine.index, admin.hr.attendance.devices.index, ...
| If you already have Route::resource('hr/attendance', ...), paste these ABOVE it so
| "hr/attendance/machine" is not captured by the resource's {attendance} show route.
| Add your permission middleware (e.g. ->middleware('can:hr.attendance.manage')) as needed.
*/

use App\Http\Controllers\Admin\Hr\AttendanceDeviceController;
use App\Http\Controllers\Admin\Hr\MachineAttendanceController;
use Illuminate\Support\Facades\Route;

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
});
