<?php

declare(strict_types=1);

namespace wise\config;


use wise\support\context\ContextualLogger;
use wise\support\traits\ServiceTrait;
use wise\config\DictSDK;
use wise\config\cache\DictCacheManager;
use wise\config\service\DictTypeService;
use wise\config\service\DictItemService;

/**
 * WiseConfig 服务提供者
 *
 * 职责：
 * 1. 合并配置文件
 * 2. 注册 ConfigManager 到容器
 * 3. 注册控制台命令
 * 4. 注册字典相关服务
 * 5. 注册 namespace/标签/继承相关服务（预留）
 */
class ConfigServiceProvider extends \think\Service
{
    use ServiceTrait;
    use \wise\support\traits\RegistersMigrations;

    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->mergeConfig('wise-config');

        $this->app->bind('wise.config.manager', ConfigManager::class);
        $this->app->bind(ConfigManager::class, function () {
            return new ConfigManager();
        });

        $this->app->bind('wise.dict.sdk', DictSDK::class);
        $this->app->bind('wise.dict.cache', DictCacheManager::class);
        $this->app->bind(DictCacheManager::class, function () {
            // 读取 wise-config.dict.cache_ttl，否则该配置项改了不生效（构造默认 86400）
            return new DictCacheManager((int) config('wise-config.dict.cache_ttl', 86400));
        });
        $this->app->bind(DictTypeService::class, function () {
            return new DictTypeService($this->app->make(DictCacheManager::class));
        });
        $this->app->bind(DictItemService::class, function () {
            return new DictItemService($this->app->make(DictCacheManager::class));
        });

        $this->app->bind('wise.config.namespace_resolver', \wise\config\resolver\NamespaceResolver::class);
        $this->app->bind(\wise\config\resolver\NamespaceResolver::class, function () {
            return new \wise\config\resolver\NamespaceResolver();
        });

        $this->app->bind('wise.config.path_resolver', \wise\config\resolver\PathResolver::class);
        $this->app->bind(\wise\config\resolver\PathResolver::class, function () {
            return new \wise\config\resolver\PathResolver();
        });

        $this->app->bind('wise.config.inheritance_resolver', \wise\config\resolver\InheritanceResolver::class);
        $this->app->bind(\wise\config\resolver\InheritanceResolver::class, function () {
            return new \wise\config\resolver\InheritanceResolver();
        });

        $this->app->bind('wise.config.tag_manager', \wise\config\manager\TagManager::class);
        $this->app->bind(\wise\config\manager\TagManager::class, function () {
            return new \wise\config\manager\TagManager($this->app->make(\wise\config\model\ConfigTagModel::class));
        });

        $this->app->bind('wise.config.tp_config_bridge', \wise\config\bridge\TpConfigBridge::class);
        $this->app->bind(\wise\config\bridge\TpConfigBridge::class, function () {
            return new \wise\config\bridge\TpConfigBridge($this->app->make(ConfigManager::class));
        });
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        $this->registerMigrations();

        // TP Config 自动注入：启动时将数据库配置注入 think\Config
        $autoInject = config('wise-config.tp_integration.auto_inject', false);
        if ($autoInject) {
            try {
                $bridge = $this->app->get(\wise\config\bridge\TpConfigBridge::class);
                $prefix = config('wise-config.tp_integration.inject_prefix', 'wise');
                $bridge->injectToConfig(null, $prefix);
            } catch (\Throwable $e) {
                // 注入失败不应阻塞应用启动，但记录日志便于排查
                try {
                    ContextualLogger::warning('WiseConfig auto-inject failed: ' . $e->getMessage());
                } catch (\Throwable) {
                    // 日志组件不可用时静默
                }
            }
        }
    }
}
