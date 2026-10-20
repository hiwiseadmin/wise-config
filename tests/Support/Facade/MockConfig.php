<?php
declare(strict_types=1);

namespace Tests\Support\Facade;

/**
 * Mock Config Facade for think\Config.
 */
class MockConfig
{
    private static $store = [];

    public static function set($value, $key = null): void
    {
        if ($key === null) {
            return;
        }
        self::$store[$key] = $value;
    }

    public static function get($key, $default = null)
    {
        return self::$store[$key] ?? $default;
    }

    public static function has($key): bool
    {
        return array_key_exists($key, self::$store);
    }

    public static function reset(): void
    {
        self::$store = [];
    }
}
