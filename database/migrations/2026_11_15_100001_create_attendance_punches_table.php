<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw, immutable punch log exactly as received from the terminals.
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->nullable()
                ->constrained('attendance_devices')->nullOnDelete();
            $table->string('device_sn', 64);
            $table->string('pin', 32);                       // "User ID" on the device
            $table->unsignedBigInteger('employee_id')->nullable(); // resolved from pin
            $table->dateTime('punched_at');                  // app timezone
            $table->date('attendance_date')->nullable();     // duty date, set by the builder
            $table->unsignedTinyInteger('status')->default(0);       // 0 in, 1 out, 2 break out, 3 break in, 4 OT in, 5 OT out
            $table->unsignedSmallInteger('verify_type')->default(0); // 1 FP, 3 password, 4 card, 15 face ...
            $table->string('work_code', 32)->nullable();
            $table->string('raw', 500)->nullable();
            $table->timestamp('received_at')->useCurrent();

            // Devices re-send records after reconnects; this makes ingest idempotent.
            $table->unique(['device_sn', 'pin', 'punched_at'], 'attendance_punches_unique');
            $table->index(['employee_id', 'punched_at']);
            $table->index(['pin', 'employee_id']);
            $table->index('punched_at');
            $table->index('attendance_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
