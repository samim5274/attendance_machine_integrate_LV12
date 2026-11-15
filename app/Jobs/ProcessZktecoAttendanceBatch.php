<?php

namespace App\Jobs;

use App\Models\BiometricAttendanceLog;
use App\Services\ZktecoAttendanceProcessor;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessZktecoAttendanceBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = [10, 30, 60];

    public function __construct(
        public array $logIds
    ) {}

    public function handle(
        ZktecoAttendanceProcessor $processor
    ): void {

        BiometricAttendanceLog::whereIn(
            'id',
            $this->logIds
        )
        ->whereNull('processed_at')
        ->orderBy('id')
        ->chunkById(100, function ($logs) use ($processor) {

            foreach ($logs as $log) {

                $processor->process($log->id);

            }

        });
    }
}