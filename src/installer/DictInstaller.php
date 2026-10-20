<?php
declare(strict_types=1);

namespace wise\config\installer;

use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 字典表维护工具
 *
 * 提供字典表清空能力，仅用于本地/测试环境。
 * 生产环境调用会被拒绝，确需时必须显式传入 $force = true。
 */
class DictInstaller
{
    /**
     * 重置字典表（清空数据）
     *
     * @param bool $force 是否强制跳过环境校验
     * @return bool
     */
    public static function reset(bool $force = false): bool
    {
        if (!$force && !self::isEnvironmentAllowed()) {
            self::logError('DictInstaller::reset 已拒绝：当前环境不允许清空字典表（如确需请传入 $force = true）');
            return false;
        }

        try {
            Db::name('dict_type')->delete();
            Db::name('dict_item')->delete();
            return true;
        } catch (\Throwable $e) {
            self::logError('DictInstaller reset failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 当前环境是否允许执行破坏性操作
     *
     * 判定顺序：APP_ENV → app()->isDebug()；无法判断时默认拒绝。
     *
     * @return bool
     */
    protected static function isEnvironmentAllowed(): bool
    {
        if (function_exists('env')) {
            try {
                $env = env('APP_ENV');
                if (is_string($env) && $env !== '') {
                    return in_array(strtolower($env), ['local', 'dev', 'development', 'test', 'testing'], true);
                }
            } catch (\Throwable) {
                // 忽略，继续回退
            }
        }

        if (function_exists('app')) {
            try {
                $app = app();
                if (is_object($app) && method_exists($app, 'isDebug')) {
                    return (bool) $app->isDebug();
                }
            } catch (\Throwable) {
                // 忽略
            }
        }

        return false;
    }

    /**
     * 记录错误日志（日志组件不可用时静默）
     */
    protected static function logError(string $message): void
    {
        try {
            ContextualLogger::error($message);
        } catch (\Throwable) {
            // 忽略日志写入失败
        }
    }
}
