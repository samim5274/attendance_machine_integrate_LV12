<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integrations\ZktecoAdmsController;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\EmployeeDeviceMappingController;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::resource(
        'employee-device-mappings',
        EmployeeDeviceMappingController::class
    );
});


Route::prefix('iclock')->controller(ZktecoAdmsController::class)->group(function () {
    // Attendance logs & data synchronization
    Route::match(['GET', 'POST'], '/cdata', 'cdata');
    Route::match(['GET', 'POST'], '/cdata.aspx', 'cdata');

    // Device command polling
    Route::get('/getrequest', 'getRequest');
    Route::get('/getrequest.aspx', 'getRequest');

    // Device command response handling
    Route::match(['GET', 'POST'], '/devicecmd', 'deviceCommand');
    Route::match(['GET', 'POST'], '/devicecmd.aspx', 'deviceCommand');

    // Heartbeat / Status check
    Route::get('/ping', 'ping');
});