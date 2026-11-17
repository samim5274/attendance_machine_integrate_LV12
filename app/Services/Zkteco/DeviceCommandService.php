<?php

namespace App\Services\Zkteco;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceCommand;
use App\Models\EmployeeDeviceMapping;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Queues commands for a device. They are delivered on the device's next
 * /iclock/getrequest poll (every ~10 s) and acknowledged on /iclock/devicecmd.
 */
class DeviceCommandService
{
    /** Types an admin may trigger from the UI. */
    public const UI_TYPES = ['info', 'check', 'resync_all', 'sync_logs', 'sync_users', 'push_employees', 'reboot'];

    public function __construct(private readonly EmployeeResolver $employees)
    {
    }

    public function queue(AttendanceDevice $device, string $type, string $command, ?int $userId = null): AttendanceDeviceCommand
    {
        $command = str_replace(["\r", "\n"], ' ', trim($command));
        if ($command === '') {
            throw new InvalidArgumentException('Empty device command.');
        }

        return $device->commands()->create([
            'type' => $type,
            'command' => $command,
            'status' => AttendanceDeviceCommand::PENDING,
            'requested_by' => $userId,
        ]);
    }

    /** Device reports firmware, counters, IP, MAC … */
    public function info(AttendanceDevice $device, ?int $userId = null): AttendanceDeviceCommand
    {
        return $this->queue($device, 'info', 'INFO', $userId);
    }

    /** Ask the device to handshake again and upload anything newer than its stamps. */
    public function check(AttendanceDevice $device, ?int $userId = null): AttendanceDeviceCommand
    {
        return $this->queue($device, 'check', 'CHECK', $userId);
    }

    /**
     * Full re-upload of every attendance log stored on the device (safe: duplicates
     * are ignored). Works on all TA PUSH firmwares: reset the ATTLOG stamp, then CHECK.
     * The OPERLOG stamp is left alone on purpose: the operation-log history is not
     * used here (user names come from "Read user list"), and resetting it makes the
     * terminal re-send its entire operation log, 128 lines a second, for a long time.
     */
    public function resyncAll(AttendanceDevice $device, ?int $userId = null): AttendanceDeviceCommand
    {
        $device->forceFill(['attlog_stamp' => '0'])->save();

        return $this->check($device, $userId);
    }

    /** Pull logs for a period (newer firmwares). */
    public function syncLogs(AttendanceDevice $device, CarbonInterface $from, CarbonInterface $to, ?int $userId = null): AttendanceDeviceCommand
    {
        $tz = $device->clockTimezone();
        $start = $from->copy()->setTimezone($tz)->startOfDay()->format('Y-m-d H:i:s');
        $end = $to->copy()->setTimezone($tz)->endOfDay()->format('Y-m-d H:i:s');

        return $this->queue($device, 'sync_logs', "DATA QUERY ATTLOG StartTime={$start}\tEndTime={$end}", $userId);
    }

    /** Ask the device to upload its user list (names, cards, privilege). */
    public function syncUsers(AttendanceDevice $device, ?int $userId = null): AttendanceDeviceCommand
    {
        return $this->queue($device, 'sync_users', 'DATA QUERY USERINFO', $userId);
    }

    public function reboot(AttendanceDevice $device, ?int $userId = null): AttendanceDeviceCommand
    {
        return $this->queue($device, 'reboot', 'REBOOT', $userId);
    }

    /**
     * Create/update a user on the terminal (face must still be enrolled on the device).
     */
    public function upsertUser(AttendanceDevice $device, string $pin, string $name, ?string $card = null, int $privilege = 0, ?int $userId = null): AttendanceDeviceCommand
    {
        $pin = AdmsParser::cleanPin($pin);
        if ($pin === '' || $pin === '0') {
            throw new InvalidArgumentException('Invalid PIN.');
        }

        $name = mb_substr(trim(preg_replace('/[\t\r\n]+/', ' ', $name) ?? ''), 0, 24);
        $card = $card ? preg_replace('/\D/', '', $card) : '';

        return $this->queue(
            $device,
            'push_user',
            "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPri={$privilege}\tPasswd=\tCard={$card}\tGrp=1\tTZ=0000000100000000\tVerify=0",
            $userId,
        );
    }

    public function deleteUser(AttendanceDevice $device, string $pin, ?int $userId = null): AttendanceDeviceCommand
    {
        $pin = AdmsParser::cleanPin($pin);
        if ($pin === '' || $pin === '0') {
            // An empty PIN must never reach the terminal.
            throw new InvalidArgumentException('Invalid PIN.');
        }

        return $this->queue($device, 'delete_user', "DATA DELETE USERINFO PIN={$pin}", $userId);
    }

    /** Create/update one mapped employee on its terminal (User ID = mapped PIN). */
    public function pushMapping(EmployeeDeviceMapping $mapping, ?int $userId = null): AttendanceDeviceCommand
    {
        $employee = $mapping->employee;
        if ($employee === null || $employee->trashed()) {
            throw new InvalidArgumentException('The employee of this mapping no longer exists.');
        }

        return $this->upsertUser($mapping->device, $mapping->device_user_id, (string) $employee->{$this->employees->nameColumn()}, null, 0, $userId);
    }

    /**
     * Push every active employee to the device:
     *   - employees with an active mapping on this device, using the mapped PIN;
     *   - (legacy) employees with a global biometric_id, unless they are already
     *     mapped on this device or their biometric_id is taken by another mapping here.
     *
     * @return int number of commands queued
     */
    public function pushEmployees(AttendanceDevice $device, ?int $userId = null): int
    {
        $query = $this->employees->query();
        if ($query === null) {
            throw new InvalidArgumentException('Employee model ['.config('zkteco.employee.model').'] not found. Check config/zkteco.php.');
        }

        $key = $query->getModel()->getKeyName();
        $nameColumn = $this->employees->nameColumn();
        $count = 0;

        $mappings = $device->mappings()->active()->get(['employee_id', 'device_user_id']);
        $mappedEmployees = $mappings->pluck('employee_id')->unique()->all();
        $usedPins = $mappings->pluck('device_user_id')->map(fn ($p) => (string) $p)->flip();

        if ($mappings->isNotEmpty()) {
            $active = (clone $query);
            $this->employees->applyActive($active);
            $names = $active->whereIn($key, $mappedEmployees)->pluck($nameColumn, $key);

            foreach ($mappings as $m) {
                if (!isset($names[$m->employee_id])) {
                    continue; // inactive or deleted employee
                }
                $this->upsertUser($device, (string) $m->device_user_id, (string) $names[$m->employee_id], null, 0, $userId);
                $count++;
            }
        }

        if (!$this->employees->usesGlobalPin()) {
            return $count;
        }

        $pinColumn = $this->employees->pinColumn();
        $this->employees->applyActive($query);

        $query->whereNotNull($pinColumn)->where($pinColumn, '!=', '')
            ->when($mappedEmployees !== [], fn ($q) => $q->whereNotIn($key, $mappedEmployees))
            ->select([$key, $pinColumn, $nameColumn])
            ->orderBy($key)
            ->chunk(200, function (Collection $chunk) use ($device, $pinColumn, $nameColumn, $userId, $usedPins, &$count) {
                foreach ($chunk as $employee) {
                    $pin = AdmsParser::cleanPin((string) $employee->{$pinColumn});
                    if ($pin === '' || $pin === '0' || isset($usedPins[$pin])) {
                        continue;
                    }
                    $this->upsertUser($device, $pin, (string) $employee->{$nameColumn}, null, 0, $userId);
                    $count++;
                }
            });

        return $count;
    }
}