<?php

namespace App\Services\Zkteco;

/**
 * Pure parsing helpers for the ZKTeco attendance PUSH (ADMS / iclock) protocol.
 * No framework or database access — easy to unit test.
 */
class AdmsParser
{
    /**
     * Split a request body into trimmed, non-empty lines.
     *
     * @return list<string>
     */
    public static function lines(string $body): array
    {
        $body = self::toUtf8($body);
        $lines = preg_split('/\r\n|\n|\r/', $body) ?: [];

        return array_values(array_filter(array_map(fn ($l) => trim($l, " \0\x0B"), $lines), fn ($l) => trim($l) !== ''));
    }

    /**
     * ATTLOG body: one punch per line, tab separated:
     *   PIN \t YYYY-MM-DD HH:MM:SS \t Status \t Verify \t WorkCode \t Reserved...
     *
     * @return array{records: list<array{pin:string,time:string,status:int,verify:int,work_code:?string,raw:string}>, skipped: list<string>}
     */
    public static function attLog(string $body, string $timezone = 'UTC'): array
    {
        $records = [];
        $skipped = [];

        foreach (self::lines($body) as $line) {
            $fields = array_map('trim', explode("\t", $line));

            if (count($fields) < 2) {
                // Some firmwares use spaces instead of tabs.
                if (preg_match('/^(\S+)\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})(?:\s+(\d+))?(?:\s+(\d+))?(?:\s+(\S+))?/', $line, $m)) {
                    $fields = [$m[1], $m[2], $m[3] ?? '0', $m[4] ?? '0', $m[5] ?? ''];
                } else {
                    $skipped[] = $line;
                    continue;
                }
            }

            $pin = self::cleanPin($fields[0]);
            $time = self::normaliseTime($fields[1] ?? '', $timezone);

            // PIN 0 / empty = stranger or device telemetry; never a real employee.
            if ($pin === '' || $pin === '0' || $time === null) {
                $skipped[] = $line;
                continue;
            }

            $workCode = isset($fields[4]) && $fields[4] !== '' && $fields[4] !== '0' ? mb_substr($fields[4], 0, 32) : null;

            $records[] = [
                'pin' => $pin,
                'time' => $time,
                'status' => self::int($fields[2] ?? 0, 0, 255),
                'verify' => self::int($fields[3] ?? 0, 0, 65535),
                'work_code' => $workCode,
                'raw' => mb_substr($line, 0, 500),
            ];
        }

        return ['records' => $records, 'skipped' => $skipped];
    }

    /**
     * OPERLOG / USERINFO / BIODATA bodies: "TYPE key=value\tkey=value" lines.
     * "OPLOG" lines are positional and returned under 'fields'.
     *
     * @return list<array{type:string, data:array<string,string>, fields:list<string>}>
     */
    public static function keyValueLines(string $body): array
    {
        $out = [];

        foreach (self::lines($body) as $line) {
            $type = '';
            $rest = $line;

            if (preg_match('/^([A-Za-z_]+)\s+(.*)$/s', $line, $m) && !str_contains($m[1], '=')) {
                $type = strtoupper($m[1]);
                $rest = $m[2];
            }

            $fields = array_map('trim', explode("\t", $rest));
            $data = [];
            foreach ($fields as $field) {
                $pos = strpos($field, '=');
                if ($pos === false) {
                    continue;
                }
                $key = strtolower(ltrim(substr($field, 0, $pos), '~'));
                $data[$key] = substr($field, $pos + 1);
            }

            // USERINFO query results come without a "USER" prefix.
            if ($type === '' && isset($data['pin'])) {
                $type = 'USER';
            }

            $out[] = ['type' => $type, 'data' => $data, 'fields' => $fields];
        }

        return $out;
    }

    /**
     * Device option / info payloads: "~DeviceName=SenseFace 3A,MAC=00:17:..,FWVersion=.."
     * separated by commas, tabs or new lines.
     *
     * @return array<string,string>
     */
    public static function options(string $body): array
    {
        $out = [];
        $parts = preg_split('/[\r\n\t]+|,(?=\s*~?[A-Za-z][A-Za-z0-9_]*\s*=)/', self::toUtf8($body)) ?: [];

        foreach ($parts as $part) {
            $part = trim($part);
            $pos = strpos($part, '=');
            if ($part === '' || $pos === false) {
                continue;
            }
            $key = ltrim(trim(substr($part, 0, $pos)), '~');
            if ($key !== '') {
                $out[$key] = trim(substr($part, $pos + 1));
            }
        }

        return $out;
    }

    /**
     * INFO query string sent with /getrequest:
     *   INFO=Ver 8.0.4.2,UserCount,FPCount,AttLogCount,IP,FPVersion,FaceVersion,FaceTemplateCount,FaceCount,...
     *
     * @return array<string,mixed>
     */
    public static function info(?string $info): array
    {
        if ($info === null || trim($info) === '') {
            return [];
        }

        $p = array_map('trim', explode(',', $info));
        $num = fn ($i) => isset($p[$i]) && is_numeric($p[$i]) ? (int) $p[$i] : null;

        return array_filter([
            'firmware_version' => $p[0] ?? null,
            'user_count' => $num(1),
            'fp_count' => $num(2),
            'attlog_count' => $num(3),
            'ip_address' => isset($p[4]) && filter_var($p[4], FILTER_VALIDATE_IP) ? $p[4] : null,
            'face_count' => $num(8),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * /devicecmd body. Each result starts with "ID=..&Return=..&CMD=..";
     * following lines that don't start with ID= belong to that result (e.g. INFO output).
     *
     * @return list<array{id:int, return:int, cmd:string, content:string}>
     */
    public static function commandResults(string $body): array
    {
        $results = [];
        $current = null;

        foreach (self::lines($body) as $line) {
            if (preg_match('/^ID=/i', $line)) {
                if ($current !== null) {
                    $results[] = $current;
                }
                parse_str($line, $q);
                $q = array_change_key_case($q, CASE_LOWER);
                $current = [
                    'id' => (int) ($q['id'] ?? 0),
                    'return' => (int) ($q['return'] ?? 0),
                    'cmd' => (string) ($q['cmd'] ?? ''),
                    'content' => (string) ($q['content'] ?? ''),
                ];
            } elseif ($current !== null) {
                $current['content'] .= ($current['content'] === '' ? '' : "\n").$line;
            }
        }

        if ($current !== null) {
            $results[] = $current;
        }

        return array_values(array_filter($results, fn ($r) => $r['id'] > 0));
    }

    /**
     * Accepts "YYYY-MM-DD HH:MM:SS", "YYYY-MM-DDTHH:MM:SS" or unix seconds and
     * returns device-local wall-clock time "YYYY-MM-DD HH:MM:SS".
     */
    public static function normaliseTime(string $value, string $timezone = 'UTC'): ?string
    {
        $value = trim(str_replace('T', ' ', $value));

        if (ctype_digit($value) && strlen($value) >= 9) {
            return (new \DateTimeImmutable('@'.$value))
                ->setTimezone(new \DateTimeZone($timezone))
                ->format('Y-m-d H:i:s');
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
            return null;
        }

        return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
    }

    public static function cleanPin(string $pin): string
    {
        return mb_substr(preg_replace('/[^\w\-.]/u', '', trim($pin)) ?? '', 0, 32);
    }

    public static function toUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private static function int(mixed $value, int $min, int $max): int
    {
        $v = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $v));
    }
}
