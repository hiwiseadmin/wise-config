<?php
declare(strict_types=1);

namespace wise\config\facade;

use think\Facade;

/**
 * WiseDictType 静态门面
 *
 * 提供字典类型（DictTypeService）的便捷静态调用方式，底层代理到容器中的服务实例。
 * 与 `wise\config\facade\WiseConfig` 保持一致的调用风格。
 *
 * @example
 *   use wise\config\facade\WiseDictType;
 *
 *   $type  = WiseDictType::getType('order_status');            // 获取字典类型
 *   $page  = WiseDictType::getList(['name' => '订单']);         // 分页列表
 *   WiseDictType::createType([...]);                           // 创建字典类型
 *   WiseDictType::updateType('order_status', ['name' => '新名']); // 更新
 *   WiseDictType::toggleStatus('order_status');                // 切换启用/禁用
 *   WiseDictType::deleteType('order_status');                  // 删除（级联删除字典项）
 *
 * @method static \wise\config\model\DictType|null getType(string $dictCode)                     根据字典编码获取字典类型
 * @method static \wise\config\model\DictType      createType(array $data)                       创建字典类型
 * @method static \wise\config\model\DictType      updateType(string $dictCode, array $data)     更新字典类型（dict_code 只读）
 * @method static bool                             deleteType(string $dictCode)                  删除字典类型（事务级联删除字典项）
 * @method static \think\Paginator                 getList(array $params = [])                   获取字典类型分页列表
 * @method static \wise\config\model\DictType      toggleStatus(string $dictCode)                切换启用(1)/禁用(0)状态
 *
 * @see \wise\config\service\DictTypeService
 */
class WiseDictType extends Facade
{
    /**
     * 获取当前 Facade 对应类名
     *
     * @return string
     */
    protected static function getFacadeClass(): string
    {
        return \wise\config\service\DictTypeService::class;
    }
}
