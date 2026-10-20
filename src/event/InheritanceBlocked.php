<?php
declare(strict_types=1);

namespace wise\config\event;

/**
 * 继承阻断事件
 *
 * 当某条配置的 inherit 字段设为 false（不允许子级继承）时触发。
 * 监听器可据此执行清理子 namespace 缓存、记录日志等操作。
 */
class InheritanceBlocked
{
    /** @var string 配置键名 */
    public $key;

    /** @var string 当前 namespace */
    public $namespace;

    /** @var string 试图继承的上级 namespace */
    public $parentNs;

    /**
     * @param string $key       配置键名
     * @param string $namespace 当前（阻断）namespace
     * @param string $parentNs  被阻断的上级 namespace
     */
    public function __construct(string $key, string $namespace, string $parentNs)
    {
        $this->key       = $key;
        $this->namespace = $namespace;
        $this->parentNs  = $parentNs;
    }
}
