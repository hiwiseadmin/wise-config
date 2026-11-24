<?php
declare(strict_types=1);

namespace wise\config\facade;

use think\Facade;

/**
 * WiseConfig 静态门面
 *
 * 提供便捷的静态调用方式，底层代理到 ConfigManager。
 *
 * @method static mixed            get(string $key, $default = null)                   获取配置值
 * @method static array            many(array $keys, $default = null, string $group = 'system') 批量获取
 * @method static array            getGroup(string $group)                             获取分组配置
 * @method static bool             has(string $key)                                    检查键是否存在
 * @method static bool             set(string $key, $value, array $extra = [], ?int $id = null) 设置配置
 * @method static int              setMany(array $data, string $defaultGroup = 'system', string $defaultType = 'text') 批量设置
 * @method static bool             delete(string $key)                                 删除配置
 * @method static array            getHistory(string $path, int $limit = 50)          获取历史版本
 * @method static array|null       getHistoryById(int $historyId)                      获取指定历史记录
 * @method static bool             revert(int $historyId)                              回滚到指定版本
 * @method static array            diff(int $historyIdA, int $historyIdB)              对比历史版本
 * @method static void             clearCacheForKey(string $key, string $group = 'system') 清除指定键缓存
 * @method static void             clearCacheForGroup(string $group)                   清除指定分组缓存
 * @method static void             clearAllCache()                                     清除全部缓存
 * @method static $this            registerTypeHandler(\wise\config\contract\TypeHandlerInterface $handler)  注册类型处理器
 * @method static $this            namespace(string $ns)                                设置当前 namespace（链式）
 * @method static string           getCurrentNamespace()                               获取当前 namespace
 * @method static mixed            getResolved(string $path, $default = null)          获取继承链最终生效值
 * @method static array            getOverrideStatus(string $path)                     获取配置来源信息
 * @method static bool             reset(string $path)                                 重置配置，恢复继承
 * @method static array            all()                                                获取当前 namespace 全部配置
 * @method static bool             addTag(string $path, string $tag)                   添加标签
 * @method static bool             removeTag(string $path, string $tag)                移除标签
 * @method static array            whereTags(array $tags, string $logic = 'and')        按标签查询
 *
 * @see \wise\config\ConfigManager
 */
class WiseConfig extends Facade
{
    /**
     * 获取当前 Facade 对应类名
     *
     * @return string
     */
    protected static function getFacadeClass(): string
    {
        return \wise\config\ConfigManager::class;
    }
}
