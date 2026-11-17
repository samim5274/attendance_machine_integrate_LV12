<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port = the terminal's own SDK/comm port (4370 on ZKTeco). It is informational only:
 * with TA PUSH the terminal connects to US over HTTP, we never connect to this port.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->unsignedInteger('port')->nullable()->after('mac_address');
            $table->string('protocol', 16)->default('TA_PUSH')->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->dropColumn(['port', 'protocol']);
        });
    }
};
