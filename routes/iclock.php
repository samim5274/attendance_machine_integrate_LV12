<?php

use App\Http\Controllers\Zkteco\IclockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ZKTeco ADMS (TA PUSH) device endpoints
|--------------------------------------------------------------------------
| Loaded WITHOUT the "web" middleware group (no session, no CSRF) and
| guarded by the "zkteco.device" middleware. See bootstrap/app.php.
| The terminal calls these paths itself; do not add a prefix.
*/

Route::prefix('iclock')
    ->controller(IclockController::class)
    ->name('iclock.')
    ->group(function () {
        foreach (['', '.aspx'] as $ext) {
            Route::get('cdata'.$ext, 'handshake')->name('cdata.get'.$ext);
            Route::post('cdata'.$ext, 'receive')->name('cdata.post'.$ext);
            Route::get('getrequest'.$ext, 'getRequest')->name('getrequest'.$ext);
            Route::post('devicecmd'.$ext, 'deviceCommand')->name('devicecmd'.$ext);
        }

        Route::get('ping', 'ping')->name('ping');
        Route::get('rtdata', 'rtdata')->name('rtdata');

        // Access-control PUSH endpoints: answered so we can warn about the wrong mode.
        Route::match(['GET', 'POST'], 'registry', 'unsupported')->name('registry');
        Route::match(['GET', 'POST'], 'push', 'unsupported')->name('push');
        Route::match(['GET', 'POST'], 'querydata', 'unsupported')->name('querydata');
    });
