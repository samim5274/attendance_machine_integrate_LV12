<?php

namespace App\Http\Middleware;

use App\Models\AttendanceDevice;
use App\Services\Zkteco\AdmsParser;
use App\Services\Zkteco\ZkLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /iclock/*: only registered, active terminals (by serial number) may
 * push data. Unknown terminals are recorded as "pending approval".
 */
class AuthenticateZktecoDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('zkteco.enabled', true)) {
            return $this->plain('SERVICE DISABLED', 503);
        }

        $ip = (string) $request->ip();
        $allowed = (array) config('zkteco.allowed_ips', []);
        if ($allowed !== [] && !IpUtils::checkIp($ip, $allowed)) {
            ZkLog::warning('Rejected request from IP not in allow-list', ['ip' => $ip, 'path' => $request->path()]);

            return $this->plain('FORBIDDEN', 403);
        }

        $maxBytes = (int) config('zkteco.max_body_kb', 4096) * 1024;
        if ((int) $request->header('Content-Length', 0) > $maxBytes || strlen((string) $request->getContent()) > $maxBytes) {
            ZkLog::warning('Request body too large', ['ip' => $ip, 'path' => $request->path(), 'bytes' => (int) $request->header('Content-Length', 0)]);

            return $this->plain('PAYLOAD TOO LARGE', 413);
        }

        $sn = AdmsParser::cleanPin((string) ($request->query('SN') ?? $request->query('sn') ?? ''));
        if ($sn === '') {
            return $this->plain('ERROR: SN required', 400);
        }

        $device = AttendanceDevice::query()->where('serial_number', $sn)->first();

        if ($device === null) {
            $this->registerPending($sn, $ip);

            return $this->plain('UNKNOWN DEVICE', 401);
        }

        if (!$device->is_active) {
            $this->touch($device, $ip);

            return $this->plain('DEVICE DISABLED', 403);
        }

        $this->touch($device, $ip);
        $request->attributes->set('zk_device', $device);

        if (config('zkteco.log.raw_bodies')) {
            ZkLog::debug('<< '.$request->method().' '.$request->getRequestUri(), ['body' => mb_substr((string) $request->getContent(), 0, 4000)]);
        }

        return $next($request);
    }

    /** Update last_seen_at / ip, at most every N seconds to spare the DB. */
    private function touch(AttendanceDevice $device, string $ip): void
    {
        $interval = (int) config('zkteco.devices.last_seen_write_interval', 30);

        if ($device->last_seen_at === null
            || $device->last_seen_at->lt(now()->subSeconds($interval))
            || $device->ip_address !== $ip) {
            $device->forceFill(['last_seen_at' => now(), 'ip_address' => $ip])->saveQuietly();
        }
    }

    private function registerPending(string $sn, string $ip): void
    {
        if (!config('zkteco.auto_register', true)) {
            ZkLog::warning('Unknown device rejected', ['sn' => $sn, 'ip' => $ip]);

            return;
        }

        $pending = AttendanceDevice::query()->where('is_active', false)->whereNull('last_handshake_at')->count();
        if ($pending >= (int) config('zkteco.max_pending_devices', 10)) {
            return;
        }

        AttendanceDevice::query()->firstOrCreate(
            ['serial_number' => $sn],
            ['name' => 'New device '.$sn, 'is_active' => false, 'ip_address' => $ip, 'last_seen_at' => now()],
        );

        ZkLog::warning('Unknown device registered as pending approval', ['sn' => $sn, 'ip' => $ip]);
    }

    private function plain(string $text, int $status): Response
    {
        return response($text, $status)->header('Content-Type', 'text/plain');
    }
}
