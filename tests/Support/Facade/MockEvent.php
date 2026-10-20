<?php
declare(strict_types=1);

namespace Tests\Support\Facade;

/**
 * Mock Event Facade for unit testing.
 */
class MockEvent
{
    private static $triggered = [];

    public static function trigger($event): void
    {
        self::$triggered[] = $event;
    }

    public static function listen($event, $listener): void
    {
        // no-op
    }

    public static function getTriggered(): array
    {
        return self::$triggered;
    }

    public static function reset(): void
    {
        self::$triggered = [];
    }
}
