<?php
declare(strict_types=1);

namespace Tests\Support\Facade;

/**
 * Mock Cache Facade for unit testing.
 * Provides minimal Cache interface for ConfigManager.
 */
class MockCache
{
    private static $store = [];

    public static function get($key)
    {
        return self::$store[$key] ?? null;
    }

    public static function set($key, $value, $ttl = null): bool
    {
        self::$store[$key] = $value;
        return true;
    }

    public static function has($key): bool
    {
        return array_key_exists($key, self::$store);
    }

    public static function delete($key): bool
    {
        unset(self::$store[$key]);
        return true;
    }

    public static function tag($tag)
    {
        return new class(self::$store) {
            private $storeRef;
            public function __construct(&$store) { $this->storeRef = &$store; }
            public function set($key, $value, $ttl = null): bool {
                $this->storeRef[$key] = $value;
                return true;
            }
            public function clear(): bool {
                // no-op
                return true;
            }
        };
    }

    public static function clear(): void
    {
        self::$store = [];
    }

    /**
     * Reset store between tests.
     */
    public static function resetStore(): void
    {
        self::$store = [];
    }
}
