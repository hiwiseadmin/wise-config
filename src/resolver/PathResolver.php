<?php

declare(strict_types=1);

namespace wise\config\resolver;

/**
 * 冒号路径三段解析器
 *
 * 将配置路径字符串解析为 [namespace, group, key] 三元组。
 *
 * 解析规则：
 *   'a:b:c' → namespace=a, group=b, key=c
 *   'b:c'   → namespace=currentNs, group=b, key=c
 *   'a'     → namespace=currentNs, group='system', key=a
 */
class PathResolver
{
    /**
     * 解析配置路径
     *
     * 按冒号 : 分割，最多拆成 3 段：
     * - 三段（命名空间:分组:键）→ 全部使用路径中的值
     * - 两段（分组:键）       → namespace 使用当前上下文
     * - 一段（纯键）          → namespace 使用当前上下文，group 默认 'system'
     *
     * @param string $path     原始路径，支持 a:b:c / b:c / a 三种格式
     * @param string $currentNs 当前上下文的 namespace（由 NamespaceResolver 推断）
     * @return array{namespace: string, group: string, key: string}
     */
    public function parse(string $path, string $currentNs): array
    {
        $parts = explode(':', $path, 3);
        $count = count($parts);

        if ($count === 3) {
            // 显式路径中的 namespace 同样必须通过合法性校验：
            // 否则 'a:b:c' 会绕过 namespace()/withNamespace() 的防污染校验，
            // 直接被拼进 namespace:group:key 与缓存键。
            if (preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $parts[0]) !== 1) {
                throw new \InvalidArgumentException('非法的 namespace: ' . $parts[0]);
            }
            return [
                'namespace' => $parts[0],
                'group'     => $parts[1],
                'key'       => $parts[2],
            ];
        }

        if ($count === 2) {
            return [
                'namespace' => $currentNs,
                'group'     => $parts[0],
                'key'       => $parts[1],
            ];
        }

        return [
            'namespace' => $currentNs,
            'group'     => 'system',
            'key'       => $parts[0],
        ];
    }

    /**
     * 判断路径是否显式包含 namespace
     *
     * 当路径包含两段或以上冒号分隔时（即三段格式 namespace:group:key），
     * 认为显式指定了 namespace。
     *
     * @param string $path 原始路径
     * @return bool 包含两段或以上冒号分隔则为 true
     */
    public function hasExplicitNamespace(string $path): bool
    {
        return substr_count($path, ':') >= 2;
    }
}
