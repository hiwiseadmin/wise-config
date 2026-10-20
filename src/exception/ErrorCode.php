<?php
declare(strict_types=1);

namespace wise\config\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-config 业务错误码（号段 150000~159999）
 *
 *  150000~150099  字典（Dict）相关
 *
 * @package wise\config\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 字典不存在 */
    case DICT_NOT_FOUND     = 150001;

    /** 字典编码重复 */
    case DICT_DUPLICATE     = 150002;

    /** 参数无效 */
    case DICT_INVALID_PARAM = 150003;

    /** 缓存操作错误 */
    case DICT_CACHE_ERROR   = 150004;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::DICT_NOT_FOUND     => '字典不存在',
            self::DICT_DUPLICATE     => '字典编码已存在',
            self::DICT_INVALID_PARAM => '参数无效',
            self::DICT_CACHE_ERROR   => '缓存操作失败',
        };
    }
}
