<?php
/**
 * PHPUnit 测试引导文件
 *
 * 为 wise-config 包的单元测试提供最小 ThinkPHP 环境模拟。
 * 注册类自动加载、facade mock 基础、config() 辅助函数等。
 */

// 加载 Composer autoloader
// 从 tests/ 目录向上 4 级到 src/，然后进入 vendor/
$autoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    // 备用：如果从包根目录运行
    $autoloadPath = dirname(__DIR__, 4) . '/vendor/autoload.php';
}
require $autoloadPath;

// ===== 模拟 ThinkPHP config() 辅助函数 =====
if (!function_exists('config')) {
    function config($key = null, $default = null) {
        static $configStore = [
            'wise-config.cache_key_prefix' => 'wise_config:',
            'wise-config.cache_tag'        => 'wise_config',
            'wise-config.cache_ttl'        => 0,
            'wise-config.upload_disk'      => 'public',
            'wise-config.upload_path'      => 'uploads/config/',
            'wise-config.route_prefix'     => 'wise-admin/config',
            'wise-config.html_filter'      => true,
            'wise-config.image_extensions' => 'jpg,jpeg,png,gif,webp,bmp',
            'wise-config.image_max_size'   => 2048,
            'wise-config.auth_callback'    => null,
            'wise-config.cache_null_ttl'   => 3600,
            'wise-config.xxx'              => null,
            'database.prefix'             => 'ws_',
            'filesystem.disks.public'     => [
                'type' => 'local',
                'url'  => '/storage',
                'root' => sys_get_temp_dir() . '/wiseconfig_test',
            ],
        ];

        if ($key === null) {
            return $configStore;
        }
        return $configStore[$key] ?? $default;
    }
}

// ===== 模拟 ThinkPHP app() 辅助函数（增强：支持 resolver 绑定） =====
if (!function_exists('app')) {
    function app($name = null) {
        static $instances = [];
        if ($name === null) {
            return new class {
                public function getConfigPath() { return ''; }
                public function getRuntimePath() { return sys_get_temp_dir() . '/wiseconfig_runtime/'; }
                public function runningInConsole() { return true; }
            };
        }
        if ($name === 'request') {
            return new class {
                public function ip() { return '127.0.0.1'; }
                public function header($key, $default = '') {
                    return $default;
                }
            };
        }
        // ===== resolver 绑定（支持 ConfigManager 构造） =====
        if ($name === 'wise.config.path_resolver') {
            if (!isset($instances[$name])) {
                $instances[$name] = new \wise\config\resolver\PathResolver();
            }
            return $instances[$name];
        }
        if ($name === 'wise.config.namespace_resolver') {
            if (!isset($instances[$name])) {
                $instances[$name] = new \wise\config\resolver\NamespaceResolver();
            }
            return $instances[$name];
        }
        if ($name === 'wise.config.inheritance_resolver') {
            if (!isset($instances[$name])) {
                $instances[$name] = new \wise\config\resolver\InheritanceResolver();
            }
            return $instances[$name];
        }
        if ($name === 'wise.config.tag_manager') {
            // TagManager 在单元测试中返回 null（降级模式）
            // 需要完整 DB 环境的测试应在 Functional 中标记 skipped
            return null;
        }
        if ($name === 'wise.config.tp_config_bridge') {
            // TpConfigBridge 在单元测试中不可用
            return null;
        }
        return $instances[$name] ?? null;
    }
}

// ===== 模拟 ThinkPHP request() 辅助函数 =====
if (!function_exists('request')) {
    function request() {
        return new class {
            public function ip() { return '127.0.0.1'; }
            public function header($key, $default = '') { return $default; }
        };
    }
}

// ===== 加载 Mock Facade 支持类 =====
// 测试支持类不在 Composer autoload 范围内，需要手动 require
$supportDir = __DIR__ . '/Support/Facade';
require_once $supportDir . '/MockCache.php';
require_once $supportDir . '/MockEvent.php';
require_once $supportDir . '/MockLog.php';
require_once $supportDir . '/MockDb.php';
require_once $supportDir . '/MockConfig.php';

// ===== Mock ThinkPHP Facade 类（如果不存在） =====
// Cache facade
if (!class_exists('think\\facade\\Cache')) {
    class_alias(\Tests\Support\Facade\MockCache::class, 'think\\facade\\Cache');
}
// Event facade
if (!class_exists('think\\facade\\Event')) {
    class_alias(\Tests\Support\Facade\MockEvent::class, 'think\\facade\\Event');
}
// Log facade
if (!class_exists('think\\facade\\Log')) {
    class_alias(\Tests\Support\Facade\MockLog::class, 'think\\facade\\Log');
}
// Db facade
if (!class_exists('think\\facade\\Db')) {
    class_alias(\Tests\Support\Facade\MockDb::class, 'think\\facade\\Db');
}
// Config facade (think\Config, not config() function)
if (!class_exists('think\\facade\\Config')) {
    class_alias(\Tests\Support\Facade\MockConfig::class, 'think\\facade\\Config');
}
