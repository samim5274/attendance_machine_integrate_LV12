<?php

namespace App\Console\Commands\Zkteco;

use App\Models\AttendanceDevice;
use App\Services\Zkteco\DeviceCommandService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ZktecoSend extends Command
{
    protected $signature = 'zkteco:send
        {serial : Device serial number}
        {type : info | check | resync_all | sync_logs | sync_users | push_employees | reboot | raw}
        {--from= : sync_logs start date (Y-m-d)}
        {--to= : sync_logs end date (Y-m-d)}
        {--raw= : Raw protocol command for type=raw, e.g. "DATA QUERY USERINFO"}';

    protected $description = 'Queue a command for a ZKTeco terminal (delivered on its next poll)';

    public function handle(DeviceCommandService $commands): int
    {
        $device = AttendanceDevice::query()->where('serial_number', $this->argument('serial'))->first();
        if ($device === null) {
            $this->components->error('Device not found. Register it first: php artisan zkteco:device '.$this->argument('serial'));

            return self::FAILURE;
        }

        $type = (string) $this->argument('type');

        $result = match ($type) {
            'info' => $commands->info($device),
            'check' => $commands->check($device),
            'resync_all' => $commands->resyncAll($device),
            'sync_logs' => $commands->syncLogs(
                $device,
                Carbon::parse($this->option('from') ?: now()->subDays(7)->toDateString()),
                Carbon::parse($this->option('to') ?: now()->toDateString()),
            ),
            'sync_users' => $commands->syncUsers($device),
            'push_employees' => $commands->pushEmployees($device),
            'reboot' => $this->confirm('Reboot '.$device->name.'?') ? $commands->reboot($device) : null,
            'raw' => $this->option('raw') ? $commands->queue($device, 'raw', (string) $this->option('raw')) : null,
            default => false,
        };

        if ($result === false) {
            $this->components->error("Unknown type [{$type}].");

            return self::INVALID;
        }
        if ($result === null) {
            $this->components->warn('Nothing queued.');

            return self::SUCCESS;
        }

        $this->components->info(is_int($result)
            ? "{$result} user command(s) queued for {$device->name}."
            : "Command #{$result->id} [{$result->command}] queued for {$device->name}.");

        if (!$device->isOnline()) {
            $this->components->warn('Device is offline; the command will be delivered when it reconnects.');
        }

        return self::SUCCESS;
    }
}
