<?php
declare(strict_types=1);

namespace wise\config\contract;

/**
 * 类型处理器接口
 *
 * 允许开发者扩展自定义配置类型。
 * 实现此接口并通过 ConfigManager::registerTypeHandler() 注册。
 */
interface TypeHandlerInterface
{
    /**
     * 获取类型标识（如 'text', 'image', 'json' 等）
     *
     * @return string
     */
    public function getType(): string;

    /**
     * 在输出/读取时对值进行处理（序列化）
     *
     * @param mixed $rawValue 数据库中的原始值
     * @return mixed 返回给调用方的值
     */
    public function decode($rawValue): mixed;

    /**
     * 在写入/保存前对值进行处理（反序列化）
     *
     * @param mixed $inputValue 用户输入的值
     * @return string 存入数据库的值
     */
    public function encode($inputValue): string;

    /**
     * 获取该类型在后台表单中的输入控件 HTML
     *
     * @param string $name   表单控件 name
     * @param mixed  $value  当前值（解码后）
     * @param array  $config 配置项元数据
     * @return string HTML 片段
     */
    public function renderFormField(string $name, $value, array $config): string;
}
