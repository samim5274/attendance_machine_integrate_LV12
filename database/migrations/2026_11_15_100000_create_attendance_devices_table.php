<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 64)->unique();
            $table->string('name', 100);
            $table->string('model', 100)->nullable();
            $table->string('location', 150)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('mac_address', 32)->nullable();
            $table->string('firmware_version', 100)->nullable();
            $table->string('push_version', 32)->nullable();
            $table->string('timezone', 64)->nullable(); // null => config('zkteco.timezone')
            $table->boolean('is_active')->default(true)->index();

            // Upload cursors returned to the device in the handshake.
            $table->string('attlog_stamp', 32)->nullable();
            $table->string('operlog_stamp', 32)->nullable();
            $table->string('attphoto_stamp', 32)->nullable();

            // Last reported counters.
            $table->unsignedInteger('user_count')->nullable();
            $table->unsignedInteger('fp_count')->nullable();
            $table->unsignedInteger('face_count')->nullable();
            $table->unsignedInteger('attlog_count')->nullable();
            $table->json('options')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_handshake_at')->nullable();
            $table->timestamp('last_punch_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_devices');
    }
};
