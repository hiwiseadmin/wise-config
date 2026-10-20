<?php
declare(strict_types=1);

namespace Tests\Support\Facade;

/**
 * Mock Db Facade for unit testing.
 */
class MockDb
{
    private static $transactionCallback = null;

    public static function transaction(callable $callback)
    {
        self::$transactionCallback = $callback;
        try {
            return $callback();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function table($name)
    {
        return new class {
            public function where($field, $op, $value = null)
            {
                return $this;
            }
            public function update($data): int
            {
                return 1;
            }
        };
    }

    public static function reset(): void
    {
        self::$transactionCallback = null;
    }
}
