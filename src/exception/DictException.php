<?php
declare(strict_types=1);

namespace wise\config\exception;

use wise\support\exception\WiseException;

/**
 * 字典异常类
 *
 * 封装字典库中各类业务异常，通过错误码区分异常类型。
 * 错误码统一定义在 {@see ErrorCode} 枚举中（号段 150000~159999），
 * 本类不再重复定义错误码常量。
 */
class DictException extends WiseException
{
    /**
     * 构造函数
     *
     * @param string          $message  异常消息，为空时使用默认消息
     * @param int             $code     异常码（传 0 时回退到 ErrorCode::DICT_NOT_FOUND）
     * @param \Throwable|null $previous 前序异常
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $code = $code ?: ErrorCode::DICT_NOT_FOUND->value;
        // 未知错误码不能直接 ErrorCode::from()（会抛 ValueError 盖掉原始异常），回退取默认文案
        $errorCode = ErrorCode::tryFrom($code) ?? ErrorCode::DICT_NOT_FOUND;
        if ($message === '') {
            $message = $errorCode->defaultMessage();
        }
        parent::__construct($code, $message, 400, $previous);
    }

    /**
     * 快速创建"字典不存在"异常
     *
     * @param string $dictCode 字典编码
     * @return static
     */
    public static function notFound(string $dictCode): self
    {
        return new static("字典不存在: {$dictCode}", ErrorCode::DICT_NOT_FOUND->value);
    }

    /**
     * 快速创建"字典编码重复"异常
     *
     * @param string $dictCode 字典编码
     * @return static
     */
    public static function duplicate(string $dictCode): self
    {
        return new static("字典编码已存在: {$dictCode}", ErrorCode::DICT_DUPLICATE->value);
    }

    /**
     * 快速创建"参数无效"异常
     *
     * @param string $param 参数名或描述
     * @return static
     */
    public static function invalidParam(string $param): self
    {
        return new static("参数无效: {$param}", ErrorCode::DICT_INVALID_PARAM->value);
    }

    /**
     * 快速创建"缓存操作错误"异常
     *
     * @param string $detail 错误详情
     * @return static
     */
    public static function cacheError(string $detail = ''): self
    {
        $message = $detail !== '' ? "缓存操作失败: {$detail}" : '缓存操作失败';
        return new static($message, ErrorCode::DICT_CACHE_ERROR->value);
    }
}
