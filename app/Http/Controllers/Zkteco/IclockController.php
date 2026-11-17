<?php

namespace App\Http\Controllers\Zkteco;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceCommand;
use App\Services\Zkteco\AdmsParser;
use App\Services\Zkteco\AdmsProtocol;
use App\Services\Zkteco\AttendanceIngestService;
use App\Services\Zkteco\ZkLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ZKTeco attendance PUSH protocol endpoints (/iclock/*).
 *
 * Contract with the device:
 *  - every reply is text/plain;
 *  - a 200 "OK" tells the device it may delete/advance past the data it sent,
 *    so we only answer OK after the data is committed. Any exception -> 500,
 *    and the device simply retries later (no data loss).
 */
class IclockController extends Controller
{
    public function __construct(private readonly AttendanceIngestService $ingest)
    {
    }

    /** GET /iclock/cdata — handshake (options=all) or keep-alive. */
    public function handshake(Request $request): Response
    {
        $device = $this->device($request);

        if (strtolower((string) $request->query('options')) !== 'all') {
            return $this->plain('OK');
        }

        $device->forceFill([
            'push_version' => $this->q($request, 'pushver') ?? $device->push_version,
            'last_handshake_at' => now(),
        ])->save();

        ZkLog::info('Handshake', ['sn' => $device->serial_number, 'ip' => $request->ip(), 'pushver' => $this->q($request, 'pushver')]);

        return $this->plain(AdmsProtocol::handshake($device));
    }

    /** POST /iclock/cdata?table=ATTLOG|OPERLOG|options|... — data upload. */
    public function receive(Request $request): Response
    {
        $device = $this->device($request);
        $table = strtoupper((string) ($this->q($request, 'table') ?? ''));
        $stamp = $this->stampFor($request, $table);
        $body = (string) $request->getContent();

        try {
            switch ($table) {
                case 'ATTLOG':
                    $parsed = AdmsParser::attLog($body, $device->clockTimezone());
                    $new = $this->ingest->storePunches($device, $parsed['records']);
                    if ($stamp !== null) {
                        $device->attlog_stamp = mb_substr($stamp, 0, 32);
                    }
                    $device->save();

                    ZkLog::info('ATTLOG', [
                        'sn' => $device->serial_number,
                        'received' => count($parsed['records']),
                        'new' => $new,
                        'skipped' => count($parsed['skipped']),
                    ]);
                    if ($parsed['skipped']) {
                        ZkLog::warning('ATTLOG lines skipped', ['sn' => $device->serial_number, 'lines' => array_slice($parsed['skipped'], 0, 20)]);
                    }

                    return $this->plain('OK: '.(count($parsed['records']) + count($parsed['skipped'])));

                case 'OPERLOG':
                case 'USERINFO':
                case 'USER':
                case 'BIODATA':
                    $lines = AdmsParser::keyValueLines($body);
                    $users = DB::transaction(fn () => $this->ingest->storeDeviceUsers($device, $lines));
                    if ($stamp !== null && $table === 'OPERLOG') {
                        $device->operlog_stamp = mb_substr($stamp, 0, 32);
                        $device->save();
                    }
                    // types/hash/oplog_* show at a glance whether the device is working
                    // through old history (hash and dates change every batch) or re-sending
                    // the same batch (same hash). No line content is logged (may hold templates).
                    $opTimes = array_values(array_filter(array_map(
                        fn ($l) => $l['type'] === 'OPLOG' ? ($l['fields'][2] ?? null) : null,
                        $lines,
                    )));
                    ZkLog::info($table, [
                        'sn' => $device->serial_number,
                        'lines' => count($lines),
                        'users' => $users,
                        'types' => array_count_values(array_map(fn ($l) => $l['type'] !== '' ? $l['type'] : '?', $lines)),
                        'oplog_from' => $opTimes ? min($opTimes) : null,
                        'oplog_to' => $opTimes ? max($opTimes) : null,
                        'stamp' => $stamp,
                        'hash' => substr(sha1($body), 0, 10),
                    ]);

                    return $this->plain('OK: '.count($lines));

                case 'OPTIONS':
                    $this->applyOptions($device, AdmsParser::options($body));

                    return $this->plain('OK');

                case 'ATTPHOTO':
                    // Snapshots are not stored (privacy / disk). Advance the stamp so the device moves on.
                    if ($stamp !== null) {
                        $device->forceFill(['attphoto_stamp' => mb_substr($stamp, 0, 32)])->save();
                    }

                    return $this->plain('OK');

                default:
                    ZkLog::info('Ignored upload table', ['sn' => $device->serial_number, 'table' => $table, 'bytes' => strlen($body)]);

                    return $this->plain('OK');
            }
        } catch (Throwable $e) {
            report($e);
            ZkLog::error('Upload failed; device will retry', [
                'sn' => $device->serial_number,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return $this->plain('ERROR', 500);
        }
    }

    /** GET /iclock/getrequest — heartbeat; hands over queued commands. */
    public function getRequest(Request $request): Response
    {
        $device = $this->device($request);

        if ($info = AdmsParser::info($this->q($request, 'INFO'))) {
            unset($info['ip_address']); // trust the socket address, not the payload
            $device->forceFill($info)->save();
        }

        $commands = DB::transaction(function () use ($device) {
            $commands = $device->commands()
                ->where('status', AttendanceDeviceCommand::PENDING)
                ->orderBy('id')
                ->limit((int) config('zkteco.devices.commands_per_poll', 10))
                ->lockForUpdate()
                ->get();

            if ($commands->isNotEmpty()) {
                AttendanceDeviceCommand::query()
                    ->whereIn('id', $commands->pluck('id'))
                    ->update(['status' => AttendanceDeviceCommand::SENT, 'sent_at' => now()]);
            }

            return $commands;
        });

        if ($commands->isEmpty()) {
            return $this->plain('OK');
        }

        ZkLog::info('Commands sent', ['sn' => $device->serial_number, 'ids' => $commands->pluck('id')->all()]);

        return $this->plain(AdmsProtocol::commands($commands));
    }

    /** POST /iclock/devicecmd — command results. */
    public function deviceCommand(Request $request): Response
    {
        $device = $this->device($request);

        foreach (AdmsParser::commandResults((string) $request->getContent()) as $result) {
            $command = $device->commands()->whereKey($result['id'])->first();
            if ($command === null) {
                continue;
            }

            $command->forceFill([
                'status' => $result['return'] >= 0 ? AttendanceDeviceCommand::SUCCESS : AttendanceDeviceCommand::FAILED,
                'return_code' => $result['return'],
                'response' => $result['content'] !== '' ? mb_substr($result['content'], 0, 5000) : null,
                'completed_at' => now(),
            ])->save();

            if (strtoupper($result['cmd']) === 'INFO' && $result['content'] !== '') {
                $this->applyOptions($device, AdmsParser::options($result['content']));
            }

            ZkLog::info('Command result', ['sn' => $device->serial_number, 'id' => $result['id'], 'cmd' => $result['cmd'], 'return' => $result['return']]);
        }

        return $this->plain('OK');
    }

    /** GET /iclock/ping — keep-alive on some firmwares during long uploads. */
    public function ping(): Response
    {
        return $this->plain('OK');
    }

    /** GET /iclock/rtdata?type=time — clock sync on newer firmwares. */
    public function rtdata(Request $request): Response
    {
        $device = $this->device($request);

        if (strtolower((string) $request->query('type')) === 'time') {
            return $this->plain(AdmsProtocol::serverTime($device->clockTimezone()));
        }

        return $this->plain('OK');
    }

    /**
     * /iclock/registry, /iclock/push, /iclock/querydata … belong to the ACCESS
     * CONTROL push protocol. If you see these, the terminal is in AC PUSH mode.
     */
    public function unsupported(Request $request): Response
    {
        $device = $this->device($request);
        $device->mergeOptions(['protocol_warning' => 'Device called /'.$request->path().' (access-control PUSH). Switch the terminal to T&A PUSH.']);
        $device->save();

        ZkLog::warning('Access-control PUSH endpoint called — switch device to T&A PUSH', [
            'sn' => $device->serial_number,
            'path' => $request->path(),
        ]);

        return $this->plain('OK');
    }

    private function applyOptions(AttendanceDevice $device, array $options): void
    {
        if ($options === []) {
            return;
        }

        $get = function (array $keys) use ($options): ?string {
            foreach ($keys as $k) {
                foreach ($options as $key => $value) {
                    if (strcasecmp($key, $k) === 0 && $value !== '') {
                        return $value;
                    }
                }
            }

            return null;
        };
        $int = fn (array $keys) => is_numeric($v = $get($keys)) ? (int) $v : null;

        $device->forceFill(array_filter([
            'model' => $get(['DeviceName', '~DeviceName']),
            'firmware_version' => $get(['FWVersion', 'FirmVer', 'FirmwareVersion']),
            'mac_address' => $get(['MAC', 'MACAddress']),
            'user_count' => $int(['UserCount']),
            'fp_count' => $int(['FPCount', 'FingerCount']),
            'face_count' => $int(['FaceCount']),
            'attlog_count' => $int(['TransactionCount', 'AttLogCount']),
        ], fn ($v) => $v !== null));

        $device->mergeOptions(array_slice($options, 0, 80, true));
        $device->save();
    }

    private function device(Request $request): AttendanceDevice
    {
        return $request->attributes->get('zk_device');
    }

    /**
     * The stamp parameter is named per table: ATTLOG sends "Stamp", OPERLOG sends
     * "OpStamp", ATTPHOTO sends "PhotoStamp". Reading only "Stamp" meant the
     * OPERLOG stamp was never saved, so every handshake answered OpStamp=0 and the
     * device re-uploaded its whole operation log after each reboot / CHECK.
     */
    private function stampFor(Request $request, string $table): ?string
    {
        $keys = match ($table) {
            'OPERLOG' => ['OpStamp', 'Stamp'],
            'ATTPHOTO' => ['PhotoStamp', 'Stamp'],
            default => ['Stamp'],
        };

        foreach ($keys as $key) {
            $value = $this->q($request, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Case-insensitive query parameter. */
    private function q(Request $request, string $key): ?string
    {
        foreach ($request->query() as $k => $v) {
            if (strcasecmp((string) $k, $key) === 0 && is_scalar($v)) {
                return (string) $v;
            }
        }

        return null;
    }

    private function plain(string $text, int $status = 200): Response
    {
        return response($text, $status)->header('Content-Type', 'text/plain');
    }
}