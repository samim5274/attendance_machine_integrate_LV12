<?php

namespace App\Services;

use App\Models\BiometricAttendanceLog;
use App\Models\EmployeeDeviceMapping;

use Illuminate\Support\Facades\DB;

class ZktecoAttendanceProcessor
{
    public function process(int $logId): void
    {
        DB::transaction(function () use ($logId) {

            $log = BiometricAttendanceLog::lockForUpdate()
                ->find($logId);

            if (!$log || $log->processed_at) {
                return;
            }

            $mapping = EmployeeDeviceMapping::where(
                'device_id',
                $log->device_id
            )
            ->where(
                'device_user_id',
                $log->device_user_id
            )
            ->where('is_active', true)
            ->first();

            // Employee PIN not mapped
            if (!$mapping) {

                $log->update([
                    'processing_error' =>
                        'Employee device PIN is not mapped',
                ]);

                return;
            }

            // Save employee relationship
            $log->update([
                'employee_id' => $mapping->employee_id,
            ]);

            // Generate daily attendance
            app(ZktecoDailyAttendanceSync::class)->sync(
                $mapping->employee_id,
                $log
            );

            $log->update([
                'processed_at' => now(),
                'processing_error' => null,
            ]);

        }, 3);
    }
}