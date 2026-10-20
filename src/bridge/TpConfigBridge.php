<?php
declare(strict_types=1);

namespace wise\config\bridge;

use wise\config\ConfigManager;
use wise\config\model\ConfigModel;
use think\facade\Config;

/**
 * ThinkPHP Config 桥接器
 *
 * 将 WiseConfig 的运行时配置注入到 think\Config 中，
 * 使第三方包可以通过 config('wise.xxx') 访问动态配置。
 *
 * @example
 *   $bridge = app('wise.config.tp_config_bridge');
 *   $count = $bridge->injectToConfig('default', 'wise');
 *   // 之后可通过 config('wise.system.site_name') 获取配置
 */
class TpConfigBridge
{
    /** @var ConfigManager 配置管理器 */
    protected ConfigManager $manager;

    /**
     * @param ConfigManager $manager 配置管理器实例
     */
    public function __construct(ConfigManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * 将指定 namespace 的配置注入到 think\Config
     *
     * 从数据库读取指定 namespace 下所有启用状态的配置，
     * 按 group.key 层级结构重组后写入 think\Config。
     *
     * @param string|null $namespace 命名空间，null=当前作用域
     * @param string      $prefix    注入前缀，如 'wise' → config('wise.system.site_name')
     * @return int 注入的配置数量
     */
    public function injectToConfig(?string $namespace = null, string $prefix = 'wise'): int
    {
        // ConfigManager 是容器单例，namespace() 属于「设置后长期生效」的上下文改写：
        // 直接调用会把同一请求内后续所有 get()/set() 都钉在该 namespace（写入落到错误命名空间）。
        // 因此这里先用 withNamespace() 限定作用域，回调结束后自动恢复原上下文。
        $ns = $namespace ?? $this->manager->getCurrentNamespace();

        $inject = function () use ($ns, $prefix): int {
            // 从数据库查询当前 namespace 下所有启用配置
            $configs = ConfigModel::where('namespace', $ns)
                ->where('status', 1)
                ->order('group', 'asc')
                ->order('sort', 'asc')
                ->select();

            $data = [];
            $count = 0;

            foreach ($configs as $config) {
                $group   = $config->group;
                $key     = $config->key;
                $dotKey  = $group . '.' . $key;
                $fullKey = $prefix ? $prefix . '.' . $dotKey : $dotKey;

                // 按类型解码后再注入，使 config('wise.x.json_key') 返回数组/数字等真实类型
                $value = $this->manager->decode($config->value, (string) $config->type);

                // 设置嵌套数组值
                $this->setNestedValue($data, explode('.', $fullKey), $value);
                $count++;
            }

            // 写入 think\Config
            if ($prefix !== '' && !empty($data)) {
                Config::set($data, $prefix);
            } elseif ($prefix === '') {
                foreach ($data as $k => $v) {
                    Config::set($v, $k);
                }
            }

            return $count;
        };

        return $namespace !== null
            ? $this->manager->withNamespace($namespace, $inject)
            : $inject();
    }

    /**
     * 从 think\Config 中移除注入的配置
     *
     * @param string $prefix 注入时的前缀
     * @return void
     */
    public function removeFromConfig(string $prefix = 'wise'): void
    {
        Config::set([], $prefix);
    }

    /**
     * 递归设置嵌套数组值
     *
     * 将点分隔的键路径转为嵌套数组结构。
     *
     * @param array  $array 目标数组（引用传递）
     * @param array  $keys  键路径数组
     * @param mixed  $value 配置值
     * @return void
     */
    protected function setNestedValue(array &$array, array $keys, $value): void
    {
        $key = array_shift($keys);
        if (empty($keys)) {
            $array[$key] = $value;
        } else {
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }
            $this->setNestedValue($array[$key], $keys, $value);
        }
    }
}
