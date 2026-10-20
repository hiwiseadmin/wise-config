<?php

declare(strict_types=1);

namespace wise\config\resolver;

use think\facade\Event;
use wise\config\event\InheritanceBlocked;

/**
 * 层次继承链解析器
 *
 * 核心规则：
 *   1. namespace 层级由 . 分隔：project_a.plugin_mail.prod
 *   2. 继承链从深到浅：[project_a.plugin_mail.prod, project_a.plugin_mail, project_a, default]
 *   3. 沿链向上查找 key，遇 inherit=false 则停止向上
 *   4. max_depth 限制最大层级（配置项 wise-config.inheritance.max_depth，默认 5）
 */
class InheritanceResolver
{
    /**
     * 生成 namespace 继承链
     *
     * 从目标 namespace 开始，逐级截断最后一个 . 后的部分，直到只剩一级。
     * 最后确保 'default' 在链的末尾（如果不在链中且目标不是 default）。
     *
     * 示例：
     *   'project_a.plugin_mail.prod'
     *   → ['project_a.plugin_mail.prod', 'project_a.plugin_mail', 'project_a', 'default']
     *
     * @param string $namespace 目标 namespace
     * @return array<string> 从目标到 default 的 namespace 数组（已去重）
     */
    public function getChain(string $namespace): array
    {
        $chain = [$namespace];
        $current = $namespace;
        $maxDepth = config('wise-config.inheritance.max_depth', 5);

        while (($dotPos = strrpos($current, '.')) !== false) {
            if (count($chain) >= $maxDepth) {
                break;
            }
            $current = substr($current, 0, $dotPos);
            $chain[] = $current;
        }

        // 确保 default 在末尾（如果不在链中）
        if ($namespace !== 'default' && !in_array('default', $chain, true)) {
            $chain[] = 'default';
        }

        return $chain;
    }

    /**
     * 沿继承链解析配置值
     *
     * 查找流程：
     *   1. 从目标 namespace 开始
     *   2. 查找匹配 (namespace, group, key) 且 status=1 的记录
     *   3. 如果目标 namespace 有 inherit=false 的配置，则阻止向上继承
     *   4. 返回第一个命中的配置值及其来源信息
     *
     * @param \think\Collection|array $configs   预查询的配置集合（性能优化：一次查询所有相关记录）
     * @param string                  $namespace 目标 namespace
     * @param string           $group     配置分组
     * @param string           $key       配置键
     * @return array{value: mixed, source_ns: string, is_inherited: bool, type: string}|null
     */
    public function resolve($configs, string $namespace, string $group, string $key): ?array
    {
        $chain = $this->getChain($namespace);
        $targetNs = $namespace;

        // 将配置集合按 namespace 索引，避免每层都做 O(n) 线性扫描
        $indexed = $this->indexByNamespace($configs);

        // 先检查目标 namespace 是否有 inherit=false 阻断
        $blocked = false;
        $targetConfig = $this->findInIndex($indexed, $targetNs, $group, $key);
        if ($targetConfig !== null && ($targetConfig['inherit'] ?? 1) == 0) {
            $blocked = true;

            // 仅当上级确实存在同名配置时，才认为发生了「实际阻断」并触发事件（只触发一次）。
            // 必须在主循环之前判断：主循环命中目标 namespace 后会立即 return，
            // 原实现把事件触发放在循环内，导致正常阻断场景下事件永不触发（死代码）。
            foreach ($chain as $ns) {
                if ($ns === $targetNs) {
                    continue;
                }
                if ($this->findInIndex($indexed, $ns, $group, $key) !== null) {
                    $this->fireInheritanceBlocked($key, $targetNs, $ns);
                    break;
                }
            }
        }

        foreach ($chain as $ns) {
            // 如果继承被阻断，跳过所有上级 namespace
            if ($blocked && $ns !== $targetNs) {
                continue;
            }

            $config = $this->findInIndex($indexed, $ns, $group, $key);
            if ($config !== null && ($config['status'] ?? 0) == 1) {
                return [
                    'value'        => $config['value'] ?? null,
                    'source_ns'    => $ns,
                    'is_inherited' => ($ns !== $targetNs),
                    'type'         => $config['type'] ?? 'text',
                ];
            }
        }

        return null;
    }

    /**
     * 触发继承阻断事件（事件失败不影响解析主流程）
     *
     * @param string $key       配置键名
     * @param string $namespace 当前（阻断）namespace
     * @param string $parentNs  被阻断的上级 namespace
     * @return void
     */
    protected function fireInheritanceBlocked(string $key, string $namespace, string $parentNs): void
    {
        try {
            Event::trigger(new InheritanceBlocked($key, $namespace, $parentNs));
        } catch (\Throwable) {
            // 事件系统不可用时静默，避免影响配置解析
        }
    }

    /**
     * 将配置集合按 namespace 分组索引
     *
     * 将 O(n) 的线性查找优化为 O(1) 的哈希查找，
     * 在继承链较长或配置条目较多时显著提升性能。
     *
     * @param \think\Collection|array $configs 配置集合
     * @return array<string, array<array>> 按 namespace 分组的配置数组
     */
    protected function indexByNamespace($configs): array
    {
        $indexed = [];
        foreach ($configs as $config) {
            $ns = $config['namespace'] ?? '';
            $indexed[$ns][] = $config;
        }
        return $indexed;
    }

    /**
     * 在 namespace 索引中查找匹配的记录
     *
     * 仅在目标 namespace 的分组内遍历，比全量扫描效率更高。
     *
     * @param array  $indexed 按 namespace 索引的配置（indexByNamespace 输出）
     * @param string $ns      namespace
     * @param string $group   分组
     * @param string $key     键
     * @return array|null 匹配的配置记录，未找到返回 null
     */
    protected function findInIndex(array $indexed, string $ns, string $group, string $key): ?array
    {
        $bucket = $indexed[$ns] ?? [];
        foreach ($bucket as $config) {
            if (($config['group'] ?? '') === $group
                && ($config['key'] ?? '') === $key) {
                return $config;
            }
        }
        return null;
    }
}
