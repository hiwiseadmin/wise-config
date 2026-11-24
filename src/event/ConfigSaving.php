<?php
declare(strict_types=1);

namespace wise\config\event;

/**
 * 配置保存前事件
 *
 * 在配置值写入数据库之前触发，可监听此事件进行预处理或校验。
 */
class ConfigSaving
{
    /**
     * @var string 配置键名
     */
    public $key;

    /**
     * @var mixed 即将写入的新值（编码前）
     */
    public $value;

    /**
     * @var string 配置类型
     */
    public $type;

    /**
     * @var string 配置分组
     */
    public $group;

    /**
     * @var array 配置项完整数据
     */
    public $config;

    /**
     * @var string 命名空间
     */
    public $namespace;

    /**
     * @param string $key       配置键名
     * @param mixed  $value     新值
     * @param string $type      类型
     * @param string $group     分组
     * @param array  $config    完整数据
     * @param string $namespace 命名空间
     */
    public function __construct(string $key, $value, string $type, string $group, array $config = [], string $namespace = 'default')
    {
        $this->key       = $key;
        $this->value     = $value;
        $this->type      = $type;
        $this->group     = $group;
        $this->config    = $config;
        $this->namespace = $namespace;
    }
}
