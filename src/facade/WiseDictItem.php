<?php
declare(strict_types=1);

namespace wise\config\facade;

use think\Facade;

/**
 * WiseDictItem 静态门面
 *
 * 提供字典项（DictItemService）的便捷静态调用方式，底层代理到容器中的服务实例。
 * 与 `wise\config\facade\WiseConfig` 保持一致的调用风格。
 *
 * @example
 *   use wise\config\facade\WiseDictItem;
 *
 *   $items = WiseDictItem::getItems('order_status');           // 全部字典项
 *   $items = WiseDictItem::getItems('order_status', 1);        // 仅启用项
 *   $map   = WiseDictItem::getValueLabelMap('order_status');   // value => label
 *   $label = WiseDictItem::getLabel('order_status', '1');      // 按值取标签
 *   $tree  = WiseDictItem::getTree('region');                  // 树形结构
 *   WiseDictItem::createItem([...]);                           // 创建字典项
 *
 * @method static array            getItems(string $dictCode, int|null $status = null)          获取字典项列表（status=null 返回全部）
 * @method static array            getValueLabelMap(string $dictCode)                           获取 value => label 映射（仅启用项）
 * @method static string           getLabel(string $dictCode, string $value)                    根据值获取标签，未找到返回空串
 * @method static array            getTree(string $dictCode)                                    获取树形字典结构（须为 is_tree=1）
 * @method static \wise\config\model\DictItem createItem(array $data)                            创建字典项
 * @method static \wise\config\model\DictItem updateItem(int $id, array $data)                   更新字典项
 * @method static bool             deleteItem(int $id)                                          删除字典项
 * @method static bool             batchUpdateStatus(array $ids, int $status)                   批量更新字典项状态
 * @method static bool             batchDelete(array $ids)                                      批量删除字典项
 * @method static \think\Paginator getList(string $dictCode, array $params = [])                获取指定字典的字典项分页列表
 *
 * @see \wise\config\service\DictItemService
 */
class WiseDictItem extends Facade
{
    /**
     * 获取当前 Facade 对应类名
     *
     * @return string
     */
    protected static function getFacadeClass(): string
    {
        return \wise\config\service\DictItemService::class;
    }
}
