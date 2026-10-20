<?php
declare(strict_types=1);

namespace wise\config\event;

/**
 * 配置回滚事件
 *
 * 在配置回滚到历史版本后触发，通知其他模块配置已恢复到指定版本。
 */
class ConfigReverted
{
    /**
     * @var string 配置键名
     */
    public $key;

    /**
     * @var string 回滚前的值
     */
    public $fromValue;

    /**
     * @var string 回滚后的值
     */
    public $toValue;

    /**
     * @var int 历史记录ID
     */
    public $historyId;

    /**
     * @var string 命名空间
     */
    public $namespace;

    /**
     * @param string $key       配置键名
     * @param string $fromValue 回滚前的值
     * @param string $toValue   回滚后的值
     * @param int    $historyId 历史记录ID
     * @param string $namespace 命名空间
     */
    public function __construct(string $key, string $fromValue, string $toValue, int $historyId, string $namespace = 'default')
    {
        $this->key       = $key;
        $this->fromValue = $fromValue;
        $this->toValue   = $toValue;
        $this->historyId = $historyId;
        $this->namespace = $namespace;
    }
}
