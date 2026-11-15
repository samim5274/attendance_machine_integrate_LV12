<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per employee per duty date, rebuilt automatically from punches.
        Schema::create('employee_daily_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('attendance_date');
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('punch_count')->default(0);
            $table->string('shift_name', 32)->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->string('status', 16)->default('present'); // present, on_duty, incomplete
            $table->unsignedBigInteger('first_device_id')->nullable();
            $table->string('source', 16)->default('device');   // device, manual
            $table->boolean('is_locked')->default(false);      // locked rows are never overwritten by the builder
            $table->string('remarks', 255)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index('attendance_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_daily_attendances');
    }
};
