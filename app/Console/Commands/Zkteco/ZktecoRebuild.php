<?php

namespace App\Console\Commands\Zkteco;

use App\Models\AttendancePunch;
use App\Services\Zkteco\AttendanceIngestService;
use App\Services\Zkteco\DailyAttendanceBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ZktecoRebuild extends Command
{
    protected $signature = 'zkteco:rebuild
        {--from= : Rebuild from this date (Y-m-d). Default: 7 days ago}
        {--employee=* : Only these employee IDs}
        {--relink : First link punches whose PIN was unknown when they arrived}';

    protected $description = 'Recalculate daily attendance from raw punches (after changing shifts/config or employee codes)';

    public function handle(DailyAttendanceBuilder $builder, AttendanceIngestService $ingest): int
    {
        if ($this->option('relink')) {
            $linked = $ingest->relinkUnmapped();
            $this->components->info("{$linked} previously unmapped punch(es) linked to employees.");
        }

        $tz = (string) config('app.timezone');
        $from = CarbonImmutable::parse($this->option('from') ?: now()->subDays(7)->toDateString(), $tz)->startOfDay();

        $employees = AttendancePunch::query()
            ->whereNotNull('employee_id')
            ->where('punched_at', '>=', $from->format('Y-m-d H:i:s'))
            ->when($this->option('employee'), fn ($q, $ids) => $q->whereIn('employee_id', $ids))
            ->distinct()
            ->pluck('employee_id');

        if ($employees->isEmpty()) {
            $this->components->warn('No mapped punches found in that period.');

            return self::SUCCESS;
        }

        $dates = 0;
        $this->withProgressBar($employees, function ($employeeId) use ($builder, $from, &$dates) {
            $dates += count($builder->rebuild((int) $employeeId, $from));
        });
        $this->newLine();
        $this->components->info("Rebuilt {$dates} employee-day row(s) for {$employees->count()} employee(s).");

        return self::SUCCESS;
    }
}
