<?php
declare(strict_types=1);

namespace wise\config\event;

/**
 * 配置保存后事件
 *
 * 在配置值成功写入数据库并刷新缓存后触发。
 */
class ConfigSaved
{
    /**
     * @var string 配置键名
     */
    public $key;

    /**
     * @var string 旧值
     */
    public $oldValue;

    /**
     * @var string 新值
     */
    public $newValue;

    /**
     * @var string 配置类型
     */
    public $type;

    /**
     * @var string 配置分组
     */
    public $group;

    /**
     * @var string 命名空间
     */
    public $namespace;

    /**
     * @param string $key       配置键名
     * @param string $oldValue  旧值
     * @param string $newValue  新值
     * @param string $type      类型
     * @param string $group     分组
     * @param string $namespace 命名空间
     */
    public function __construct(string $key, string $oldValue, string $newValue, string $type, string $group, string $namespace = 'default')
    {
        $this->key       = $key;
        $this->oldValue  = $oldValue;
        $this->newValue  = $newValue;
        $this->type      = $type;
        $this->group     = $group;
        $this->namespace = $namespace;
    }
}
