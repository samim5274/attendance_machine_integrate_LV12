<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Jobs\ProcessZktecoAttendanceBatch;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZktecoAdmsController extends Controller
{
    /**
     * Device registration and attendance receiving.
     */
    public function cdata(Request $request)
    {
        $serial = (string) $request->query(
            'SN',
            $request->input('SN', '')
        );

        $device = AttendanceDevice::where(
            'serial_number',
            $serial
        )
        ->where('is_active', true)
        ->first();

        if (!$device) {

            Log::warning('Unknown ZKTeco device', [
                'serial' => $serial,
                'ip' => $request->ip(),
            ]);

            return $this->plain('UNKNOWN DEVICE', 403);
        }

        $this->touchDevice($device, $request);

        // Device registration request
        if ($request->isMethod('GET')) {

            return $this->plain(
                $this->registrationOptions($device)
            );
        }

        // Only process attendance logs
        $table = strtoupper(
            (string) $request->query('table', '')
        );

        if ($table !== 'ATTLOG') {
            return $this->plain('OK');
        }

        $body = $request->getContent();

        $records = $this->parseAttlog(
            $body,
            $device->timezone
        );

        if (empty($records)) {

            Log::warning('ZKTeco ATTLOG received but no valid records', [
                'serial' => $serial,
                'body' => substr($body, 0, 2000),
            ]);

            return $this->plain('OK');
        }

        $now = now();

        $rows = [];

        foreach ($records as $record) {

            $hash = hash('sha256', implode('|', [
                $device->serial_number,
                $record['pin'],
                $record['punched_at'],
                $record['status'] ?? '',
                $record['verify_type'] ?? '',
                $record['work_code'] ?? '',
            ]));

            $rows[] = [
                'device_id' => $device->id,

                'device_user_id' => $record['pin'],

                'punched_at' => $record['punched_at'],

                'punch_status' => $record['status'],

                'verify_type' => $record['verify_type'],

                'work_code' => $record['work_code'],

                'event_hash' => $hash,

                'raw_line' => $record['raw_line'],

                'created_at' => $now,

                'updated_at' => $now,
            ];
        }

        // Duplicate-safe database insertion
        DB::table('biometric_attendance_logs')
            ->insertOrIgnore($rows);

        // Find logs which still need processing
        $hashes = array_column($rows, 'event_hash');

        $ids = DB::table('biometric_attendance_logs')
            ->whereIn('event_hash', $hashes)
            ->whereNull('processed_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Process asynchronously
        if (!empty($ids)) {

            ProcessZktecoAttendanceBatch::dispatch($ids)
                ->afterCommit();
        }

        return $this->plain('OK');
    }


    /**
     * ADMS registration options.
     */
    private function registrationOptions(
        AttendanceDevice $device
    ): string {

        return implode("\r\n", [

            "GET OPTION FROM: {$device->serial_number}",

            "ATTLOGStamp=None",

            "OPERLOGStamp=9999",

            "TransTimes=00:00;23:59",

            "TransInterval=1",

            "TransFlag=TransData AttLog OpLog EnrollUser",

            "Realtime=1",

            "Encrypt=None",

            "ServerVer=2.2.14",

            "OK",

            "",
        ]);
    }


    /**
     * Parse attendance records.
     */
    private function parseAttlog(
        string $body,
        string $timezone
    ): array {

        $records = [];

        $lines = preg_split(
            '/\r\n|\r|\n/',
            trim($body)
        );

        foreach ($lines as $line) {

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t+/', $line);

            if (count($parts) < 2) {
                continue;
            }

            // Supports both PIN and PIN= formats
            $pin = preg_replace(
                '/^PIN\s*=\s*/i',
                '',
                trim($parts[0])
            );

            if (
                $pin === '' ||
                strtolower($pin) === 'pin'
            ) {
                continue;
            }

            try {

                $punchedAt = Carbon::parse(
                    trim($parts[1]),
                    $timezone
                );

            } catch (Throwable $e) {

                Log::warning('Invalid ZKTeco attendance datetime', [
                    'line' => $line,
                ]);

                continue;
            }

            $status = isset($parts[2])
                && is_numeric($parts[2])
                    ? (int) $parts[2]
                    : null;

            $verifyType = isset($parts[3])
                && is_numeric($parts[3])
                    ? (int) $parts[3]
                    : null;

            $workCode = $parts[4] ?? null;

            $records[] = [

                'pin' => $pin,

                'punched_at' => $punchedAt->format(
                    'Y-m-d H:i:s'
                ),

                'status' => $status,

                'verify_type' => $verifyType,

                'work_code' => $workCode,

                'raw_line' => $line,
            ];
        }

        return $records;
    }


    /**
     * Update device heartbeat.
     */
    private function touchDevice(
        AttendanceDevice $device,
        Request $request
    ): void {

        $device->forceFill([

            'last_seen_at' => now(),

            'last_ip_address' => $request->ip(),

        ])->save();
    }


    /**
     * Device command polling.
     */
    public function getRequest(Request $request)
    {
        $device = $this->findDevice($request);

        if (!$device) {
            return $this->plain('UNKNOWN DEVICE', 403);
        }

        $this->touchDevice($device, $request);

        // No pending commands
        return $this->plain('OK');
    }


    /**
     * Device command response.
     */
    public function deviceCommand(Request $request)
    {
        $device = $this->findDevice($request);

        if (!$device) {
            return $this->plain('UNKNOWN DEVICE', 403);
        }

        $this->touchDevice($device, $request);

        return $this->plain('OK');
    }


    /**
     * Device heartbeat.
     */
    public function ping(Request $request)
    {
        $device = $this->findDevice($request);

        if (!$device) {
            return $this->plain('UNKNOWN DEVICE', 403);
        }

        $this->touchDevice($device, $request);

        return $this->plain('OK');
    }


    private function findDevice(Request $request)
    {
        $serial = (string) $request->query('SN', '');

        return AttendanceDevice::where(
            'serial_number',
            $serial
        )
        ->where('is_active', true)
        ->first();
    }


    private function plain(
        string $content,
        int $status = 200
    ) {

        return response(
            $content,
            $status
        )->header(
            'Content-Type',
            'text/plain; charset=utf-8'
        );
    }
}