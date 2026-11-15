<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbox of commands; the device picks them up on its next /getrequest poll.
        Schema::create('attendance_device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('type', 32)->default('raw');      // info, check, reboot, sync_logs ...
            $table->text('command');                         // exact text sent after "C:<id>:"
            $table->string('status', 16)->default('pending'); // pending, sent, success, failed, expired
            $table->integer('return_code')->nullable();
            $table->text('response')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['attendance_device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_device_commands');
    }
};
