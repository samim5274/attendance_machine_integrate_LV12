<?php

namespace App\Jobs\Zkteco;

use App\Services\Zkteco\DailyAttendanceBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RebuildDailyAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 60];

    public function __construct(
        public int $employeeId,
        public string $from,
    ) {
        if ($connection = config('zkteco.queue_connection')) {
            $this->onConnection($connection);
        }
        $this->onQueue(config('zkteco.queue', 'default'));
    }

    /** One rebuild per employee at a time (a queue worker is required for this to matter). */
    public function middleware(): array
    {
        if (($this->connection ?? config('queue.default')) === 'sync') {
            return [];
        }

        return [(new WithoutOverlapping('zkteco-employee-'.$this->employeeId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(DailyAttendanceBuilder $builder): void
    {
        $builder->rebuild($this->employeeId, $this->from);
    }
}
