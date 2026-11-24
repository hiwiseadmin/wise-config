<?php
declare(strict_types=1);

namespace wise\config\event;

/**
 * 标签变更事件
 *
 * 在配置的标签被添加、移除或批量同步后触发。
 * 监听器可据此执行标签相关的缓存刷新或业务联动。
 */
class TagChanged
{
    /** @var int 配置ID */
    public $configId;

    /** @var string 操作类型：'add' | 'remove' | 'sync' */
    public $action;

    /** @var string 变更的标签值 */
    public $tag;

    /** @var int 变更时间戳 */
    public $timestamp;

    /**
     * @param int    $configId 配置ID
     * @param string $action   操作类型：'add' | 'remove' | 'sync'
     * @param string $tag      变更的标签值（sync 时可传空字符串表示全量替换）
     */
    public function __construct(int $configId, string $action, string $tag = '')
    {
        $this->configId  = $configId;
        $this->action    = $action;
        $this->tag       = $tag;
        $this->timestamp = time();
    }
}
