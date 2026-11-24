<?php
declare(strict_types=1);

namespace wise\config\event;

/**
 * 字典变更事件类
 *
 * 当字典类型或字典项发生增删改操作时触发此事件，
 * 携带变更的字典编码、操作类型及相关数据。
 * 可被外部监听器捕获以实现业务联动。
 */
class DictChangedEvent
{
    /**
     * 字典编码
     *
     * @var string
     */
    protected string $dictCode;

    /**
     * 操作类型：create / update / delete
     *
     * @var string
     */
    protected string $action;

    /**
     * 变更相关数据
     *
     * @var mixed
     */
    protected $data;

    /**
     * 构造函数
     *
     * @param string $dictCode 字典编码
     * @param string $action   操作类型（create/update/delete）
     * @param mixed  $data     变更数据
     */
    public function __construct(string $dictCode, string $action, $data = null)
    {
        $this->dictCode = $dictCode;
        $this->action = $action;
        $this->data = $data;
    }

    /**
     * 获取字典编码
     *
     * @return string
     */
    public function getDictCode(): string
    {
        return $this->dictCode;
    }

    /**
     * 获取操作类型
     *
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * 获取变更数据
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * 将事件转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'dict_code' => $this->dictCode,
            'action'    => $this->action,
            'data'      => $this->data,
        ];
    }
}
