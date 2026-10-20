<?php
declare(strict_types=1);

namespace wise\config\event;

/**
 * 配置删除前事件
 */
class ConfigDeleting
{
    /**
     * @var string 配置键名
     */
    public $key;

    /**
     * @var int 配置ID
     */
    public $configId;

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
     * @param int    $configId  配置ID
     * @param string $group     分组
     * @param string $namespace 命名空间
     */
    public function __construct(string $key, int $configId, string $group, string $namespace = 'default')
    {
        $this->key       = $key;
        $this->configId  = $configId;
        $this->group     = $group;
        $this->namespace = $namespace;
    }
}
