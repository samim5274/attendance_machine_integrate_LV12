<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users enrolled on each terminal (reported by the device via OPERLOG / USERINFO).
        // Biometric templates are deliberately NOT stored.
        Schema::create('attendance_device_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('pin', 32);
            $table->string('name', 100)->nullable();
            $table->unsignedTinyInteger('privilege')->default(0); // 0 user, 14 admin
            $table->string('card', 32)->nullable();
            $table->boolean('has_face')->default(false);
            $table->unsignedTinyInteger('fingerprint_count')->default(0);
            $table->timestamps();

            $table->unique(['attendance_device_id', 'pin']);
            $table->index('pin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_device_users');
    }
};
