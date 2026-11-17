<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which employee a "User ID" (PIN) on a given terminal belongs to.
 * A PIN is unique per device; one employee may be enrolled on many devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_device_mappings')) {
            return; // never created by the old code, but stay safe on other installs
        }

        Schema::create('employee_device_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('device_user_id', 32); // PIN / "User ID" on the terminal
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['attendance_device_id', 'device_user_id'], 'edm_device_pin_unique');
            $table->index(['employee_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_device_mappings');
    }
};
