<?php

namespace App\Services\Zkteco;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dedicated daily log file (storage/logs/zkteco-*.log) without touching config/logging.php.
 */
class ZkLog
{
    private static ?LoggerInterface $logger = null;

    public static function channel(): LoggerInterface
    {
        if (self::$logger === null) {
            self::$logger = config('zkteco.log.enabled', true)
                ? Log::build([
                    'driver' => 'daily',
                    'path' => storage_path('logs/zkteco.log'),
                    'days' => (int) config('zkteco.log.days', 30),
                    'level' => 'debug',
                ])
                : new NullLogger();
        }

        return self::$logger;
    }

    public static function info(string $message, array $context = []): void
    {
        self::channel()->info($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::channel()->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::channel()->error($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::channel()->debug($message, $context);
    }

    /** Reset (used by tests). */
    public static function reset(): void
    {
        self::$logger = null;
    }
}
