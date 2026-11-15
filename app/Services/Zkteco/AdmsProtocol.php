<?php

namespace App\Services\Zkteco;

use App\Models\AttendanceDevice;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the plain-text replies the device expects.
 */
class AdmsProtocol
{
    /**
     * Reply to GET /iclock/cdata?SN=..&options=all (the handshake).
     * Both the classic (ATTLOGStamp) and the 2.x (Stamp) key names are sent;
     * firmwares ignore keys they do not know.
     */
    public static function handshake(AttendanceDevice $device): string
    {
        $cfg = config('zkteco.handshake');
        $att = self::stamp($device->attlog_stamp);
        $op = self::stamp($device->operlog_stamp);
        $photo = self::stamp($device->attphoto_stamp);

        $lines = [
            'GET OPTION FROM: '.$device->serial_number,
            'ATTLOGStamp='.$att,
            'OPERLOGStamp='.$op,
            'ATTPHOTOStamp='.$photo,
            'Stamp='.$att,
            'OpStamp='.$op,
            'PhotoStamp='.$photo,
            'ErrorDelay='.(int) ($cfg['error_delay'] ?? 30),
            'Delay='.(int) ($cfg['delay'] ?? 10),
            'TransTimes='.($cfg['trans_times'] ?? '00:00;14:05'),
            'TransInterval='.(int) ($cfg['trans_interval'] ?? 1),
            'TransFlag='.($cfg['trans_flag'] ?? 'TransData AttLog'),
            'TimeZone='.self::timezoneOffset($device->clockTimezone()),
            'Realtime='.(int) ($cfg['realtime'] ?? 1),
            'Encrypt=None',
        ];

        foreach ((array) ($cfg['extra'] ?? []) as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        return implode("\n", $lines)."\n";
    }

    /** "C:<id>:<command>" lines for /getrequest. */
    public static function commands(iterable $commands): string
    {
        $out = '';
        foreach ($commands as $command) {
            $out .= 'C:'.$command->id.':'.$command->command."\n";
        }

        return $out;
    }

    /**
     * Reply to GET /iclock/rtdata?type=time (newer firmwares sync their clock with it).
     * DateTime uses ZKTeco's packed encoding; ServerTZ is the UTC offset.
     */
    public static function serverTime(string $timezone): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return 'DateTime='.self::encodeTime($now).',ServerTZ='.$now->format('O');
    }

    public static function encodeTime(DateTimeImmutable $t): int
    {
        $y = (int) $t->format('Y');
        $mo = (int) $t->format('n');
        $d = (int) $t->format('j');
        $h = (int) $t->format('G');
        $mi = (int) $t->format('i');
        $s = (int) $t->format('s');

        return ((($y - 2000) * 12 * 31 + ($mo - 1) * 31 + $d - 1) * 86400) + (($h * 60 + $mi) * 60) + $s;
    }

    /** Hours offset from UTC (6 for Asia/Dhaka); configurable override. */
    public static function timezoneOffset(string $timezone): string
    {
        $override = config('zkteco.timezone_offset');
        if ($override !== null && $override !== '') {
            return (string) $override;
        }

        $seconds = (new DateTimeZone($timezone))->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $hours = $seconds / 3600;

        return fmod($hours, 1.0) === 0.0 ? (string) (int) $hours : (string) round($hours, 2);
    }

    private static function stamp(?string $value): string
    {
        return ($value === null || $value === '') ? '0' : $value;
    }
}
