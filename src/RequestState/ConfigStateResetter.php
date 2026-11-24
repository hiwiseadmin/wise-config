<?php
declare(strict_types=1);

namespace wise\config\RequestState;

use wise\config\ConfigManager;
use wise\support\contracts\RequestStateResetterInterface;

/**
 * ConfigManager 请求级状态重置器
 *
 * `ConfigManager` 是容器单例，常驻进程下不随请求重建，其请求级字段必须每请求清理：
 *   - `chainCache`        键为 "namespace:group:key" 的解析结果缓存；
 *   - `currentNamespace`  链式 API 的作用域；
 *   - `lastConfigId`      单次比较用的临时 ID；
 *   - `onlyPublic`        可见性过滤开关（泄漏会导致后续请求漏读私有配置）。
 *
 * 解析器、类型处理器等**构造期依赖不在重置范围**（它们本身无请求状态）。
 * 具体语义见 {@see ConfigManager::resetRequestState()}。
 *
 * 注意：这里清的是「请求级字段」，不是 `think\Config` 的文件配置快照 ——
 * 后者由 think-swoole 的 `ResetConfig` 每个请求 clone 初始快照保证，无需重复处理。
 *
 * @package wise\config\RequestState
 */
class ConfigStateResetter implements RequestStateResetterInterface
{
    /**
     * {@inheritDoc}
     */
    public function resetRequestState(): void
    {
        if (!function_exists('app')) {
            return;
        }

        try {
            $container = app();
        } catch (\Throwable) {
            return;
        }

        if (!$container->exists(ConfigManager::class)) {
            return;
        }

        $instance = $container->make(ConfigManager::class);

        if ($instance instanceof ConfigManager) {
            $instance->resetRequestState();
        }
    }
}
