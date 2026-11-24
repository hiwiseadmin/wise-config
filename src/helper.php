<?php

declare(strict_types=1);

/**
 * WiseConfig 辅助函数
 *
 * 面向视图模板与业务代码的轻量读取入口，所有函数均为 `wise_config_*` 前缀，
 * 避免与框架自带的 `config()` 等函数冲突。
 *
 * 特性：
 *  - 读取失败（框架未启动、数据表未迁移、路径非法等）不会抛异常，按默认值返回；
 *  - 传入 $namespace 时通过 withNamespace() 执行，不会污染共享实例的当前命名空间；
 *  - 结果复用 ConfigManager 的缓存，可放心在模板循环中调用。
 *
 * 视图模板用法示例（ThinkPHP 模板）：
 *   {:wise_config('site:site_name', '默认站点')}
 *   {if wise_config_bool('site:site_status')}...{/if}
 *   {php}$site = wise_config_group('site');{/php}
 *   {volist name="site" id="item" key="k"}{$k} = {$item}{/volist}
 */

use wise\config\ConfigManager;
use wise\support\context\ContextualLogger;

if (!function_exists('wise_config_manager')) {
    /**
     * 获取 ConfigManager 实例（框架不可用时返回 null）
     *
     * 需要在视图中直接调用管理器方法时使用本函数，普通读取请优先用 wise_config()。
     *
     * @return ConfigManager|null
     */
    function wise_config_manager(): ?ConfigManager
    {
        if (!function_exists('app')) {
            return null;
        }

        try {
            return app(ConfigManager::class);
        } catch (\Throwable) {
            return null;
        }
    }
}

if (!function_exists('_wise_config_log_failure')) {
    /**
     * 记录辅助函数内部异常（日志组件不可用时静默）
     *
     * @param string     $function 辅助函数名
     * @param \Throwable $e        异常
     * @return void
     */
    function _wise_config_log_failure(string $function, \Throwable $e): void
    {
        try {
            ContextualLogger::warning(sprintf('%s failed: %s', $function, $e->getMessage()));
        } catch (\Throwable) {
        }
    }
}

if (!function_exists('wise_config')) {
    /**
     * 读取配置值（支持 namespace:group:key / group:key / key 三种路径）
     *
     * @param string      $path      配置路径
     * @param mixed       $default   配置不存在时的默认值
     * @param string|null $namespace 指定命名空间，null 表示使用当前上下文推断
     * @return mixed
     *
     * @example
     *   wise_config('site_name');                       // 当前 namespace / 默认 system 分组
     *   wise_config('site:site_name');                  // 当前 namespace / site 分组
     *   wise_config('project_a:site:site_name');        // 显式 namespace
     *   wise_config('site:site_name', '', 'project_a'); // 第三种：临时作用域
     */
    function wise_config(string $path, $default = null, ?string $namespace = null)
    {
        $manager = wise_config_manager();
        if ($manager === null) {
            return $default;
        }

        try {
            if ($namespace !== null) {
                return $manager->withNamespace(
                    $namespace,
                    static fn (ConfigManager $m) => $m->get($path, $default)
                );
            }

            return $manager->get($path, $default);
        } catch (\Throwable $e) {
            _wise_config_log_failure(__FUNCTION__, $e);
            return $default;
        }
    }
}

if (!function_exists('wise_config_resolved')) {
    /**
     * 读取继承链最终生效值
     *
     * 与 wise_config() 的区别：会沿 namespace 继承链向上查找，父级同名配置可被子级继承。
     *
     * @param string      $path      配置路径
     * @param mixed       $default   默认值
     * @param string|null $namespace 指定命名空间，null 表示使用当前上下文推断
     * @return mixed
     */
    function wise_config_resolved(string $path, $default = null, ?string $namespace = null)
    {
        $manager = wise_config_manager();
        if ($manager === null) {
            return $default;
        }

        try {
            if ($namespace !== null) {
                return $manager->withNamespace(
                    $namespace,
                    static fn (ConfigManager $m) => $m->getResolved($path, $default)
                );
            }

            return $manager->getResolved($path, $default);
        } catch (\Throwable $e) {
            _wise_config_log_failure(__FUNCTION__, $e);
            return $default;
        }
    }
}

if (!function_exists('wise_config_has')) {
    /**
     * 判断配置项是否存在
     *
     * @param string      $path      配置路径
     * @param string|null $namespace 指定命名空间，null 表示使用当前上下文推断
     * @return bool
     */
    function wise_config_has(string $path, ?string $namespace = null): bool
    {
        $manager = wise_config_manager();
        if ($manager === null) {
            return false;
        }

        try {
            if ($namespace !== null) {
                return $manager->withNamespace(
                    $namespace,
                    static fn (ConfigManager $m) => $m->has($path)
                );
            }

            return $manager->has($path);
        } catch (\Throwable $e) {
            _wise_config_log_failure(__FUNCTION__, $e);
            return false;
        }
    }
}

if (!function_exists('wise_config_bool')) {
    /**
     * 读取布尔型配置（'1'/true/'on'/'yes' 视为 true）
     *
     * @param string      $path      配置路径
     * @param bool        $default   配置不存在时的默认值
     * @param string|null $namespace 指定命名空间，null 表示使用当前上下文推断
     * @return bool
     *
     * @example
     *   {if wise_config_bool('system:open_register')}...{/if}
     */
    function wise_config_bool(string $path, bool $default = false, ?string $namespace = null): bool
    {
        $value = wise_config($path, $default, $namespace);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}

if (!function_exists('wise_config_many')) {
    /**
     * 批量读取同一分组下的多个配置
     *
     * @param array       $keys      配置键名数组
     * @param mixed       $default   缺失时的默认值
     * @param string      $group     配置分组，默认 system
     * @param string|null $namespace 指定命名空间，null 表示使用当前上下文推断
     * @return array key => value
     *
     * @example
     *   ['site_name' => 'x', 'site_desc' => null] = wise_config_many(['site_name', 'site_desc']);
     */
    function wise_config_many(array $keys, $default = null, string $group = 'system', ?string $namespace = null): array
    {
        $manager = wise_config_manager();
        if ($manager === null) {
            return array_fill_keys($keys, $default);
        }

        try {
            if ($namespace !== null) {
                return $manager->withNamespace(
                    $namespace,
                    static fn (ConfigManager $m) => $m->many($keys, $default, $group)
                );
            }

            return $manager->many($keys, $default, $group);
        } catch (\Throwable $e) {
            _wise_config_log_failure(__FUNCTION__, $e);
            return array_fill_keys($keys, $default);
        }
    }
}

if (!function_exists('wise_config_group')) {
    /**
     * 读取某个分组下的全部配置（key => value）
     *
     * @param string      $group     分组标识
     * @param string|null $namespace 指定命名空间，null 表示使用当前上下文推断
     * @return array
     *
     * @example
     *   // 模板中遍历 seo 分组
     *   {volist name=":wise_config_group('seo')" id="value" key="k"}{$k} = {$value}{/volist}
     */
    function wise_config_group(string $group, ?string $namespace = null): array
    {
        $manager = wise_config_manager();
        if ($manager === null) {
            return [];
        }

        try {
            return $manager->getGroup($group, $namespace);
        } catch (\Throwable $e) {
            _wise_config_log_failure(__FUNCTION__, $e);
            return [];
        }
    }
}

if (!function_exists('wise_config_all')) {
    /**
     * 读取当前命名空间下的全部配置（扁平 key => value）
     *
     * @param string|null $namespace 指定命名空间，null 表示使用当前上下文推断
     * @return array
     */
    function wise_config_all(?string $namespace = null): array
    {
        $manager = wise_config_manager();
        if ($manager === null) {
            return [];
        }

        try {
            if ($namespace !== null) {
                return $manager->withNamespace(
                    $namespace,
                    static fn (ConfigManager $m) => $m->all()
                );
            }

            return $manager->all();
        } catch (\Throwable $e) {
            _wise_config_log_failure(__FUNCTION__, $e);
            return [];
        }
    }
}
