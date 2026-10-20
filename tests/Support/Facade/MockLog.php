<?php
declare(strict_types=1);

namespace Tests\Support\Facade;

/**
 * Mock Log Facade for unit testing.
 */
class MockLog
{
    private static $logs = [];

    public static function error($message): void
    {
        self::$logs[] = ['level' => 'error', 'message' => $message];
    }

    public static function warning($message): void
    {
        self::$logs[] = ['level' => 'warning', 'message' => $message];
    }

    public static function info($message): void
    {
        self::$logs[] = ['level' => 'info', 'message' => $message];
    }

    public static function getLogs(): array
    {
        return self::$logs;
    }

    public static function reset(): void
    {
        self::$logs = [];
    }
}
