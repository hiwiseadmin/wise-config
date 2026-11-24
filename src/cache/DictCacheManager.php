<?php
declare(strict_types=1);

namespace wise\config\cache;


use wise\support\context\ContextualLogger;
use think\facade\Cache;

/**
 * 缓存管理器
 *
 * 负责字典数据的缓存读写与清理操作。
 * 缓存键规则：
 *   - 字典类型：dict:type:{dict_code}
 *   - 字典项：  dict:items:{dict_code}
 *
 * 默认使用 think\facade\Cache，可通过构造函数注入自定义缓存驱动。
 * TTL 默认 86400 秒（24 小时），可配置。
 */
class DictCacheManager
{
    /**
     * 缓存键前缀 - 类型
     */
    protected const KEY_TYPE = 'dict:type:';

    /**
     * 缓存键前缀 - 项
     */
    protected const KEY_ITEMS = 'dict:items:';

    /**
     * 缓存键前缀 - 项（带状态过滤）
     * 使用 /status/ 分隔符避免与 dict_code 冲突
     */
    protected const KEY_ITEMS_STATUS = 'dict:items:/status:';

    /**
     * 缓存键前缀 - 树形结构
     */
    protected const KEY_TREE = 'dict:tree:';

    /**
     * 默认缓存 TTL（秒）
     *
     * @var int
     */
    protected int $ttl;

    /**
     * 自定义缓存驱动（可选）
     *
     * @var object|null 实现了 get/set/tag 方法接口的对象
     */
    protected ?object $customCache = null;

    /**
     * 缓存标签（用于批量清除）
     */
    protected const TAG = 'dict';

    /**
     * 构造函数
     *
     * @param int $ttl 缓存有效期（秒），默认 86400
     * @param object|null $cacheDriver 自定义缓存驱动，为空时使用 Cache 门面
     */
    public function __construct(int $ttl = 86400, ?object $cacheDriver = null)
    {
        $this->ttl = $ttl;
        $this->customCache = $cacheDriver;
    }

    /**
     * 获取字典类型缓存
     *
     * @param string $dictCode 字典编码
     * @return mixed 缓存数据，不存在时返回 null
     */
    public function getTypeCache(string $dictCode)
    {
        return $this->getCacheDriver()->get(self::KEY_TYPE . $dictCode);
    }

    /**
     * 通用缓存写入：优先使用 tag 方式，降级为普通 set
     *
     * @param string $key  缓存键
     * @param mixed  $data 缓存数据
     * @return bool 是否设置成功
     */
    protected function setWithTag(string $key, $data): bool
    {
        $driver = $this->getCacheDriver();
        if (method_exists($driver, 'tag')) {
            return $driver->tag(self::TAG)->set($key, $data, $this->ttl);
        }
        return $driver->set($key, $data, $this->ttl);
    }

    /**
     * 设置字典类型缓存
     *
     * @param string $dictCode 字典编码
     * @param mixed  $data     缓存数据
     * @return bool 是否设置成功
     */
    public function setTypeCache(string $dictCode, $data): bool
    {
        return $this->setWithTag(self::KEY_TYPE . $dictCode, $data);
    }

    /**
     * 清除字典类型缓存
     *
     * @param string $dictCode 字典编码
     * @return bool 是否清除成功
     */
    public function clearTypeCache(string $dictCode): bool
    {
        return $this->getCacheDriver()->delete(self::KEY_TYPE . $dictCode);
    }

    /**
     * 获取字典项缓存
     *
     * @param string $dictCode 字典编码
     * @return mixed 缓存数据，不存在时返回 null
     */
    public function getItemsCache(string $dictCode)
    {
        return $this->getCacheDriver()->get(self::KEY_ITEMS . $dictCode);
    }

    /**
     * 设置字典项缓存
     *
     * @param string $dictCode 字典编码
     * @param mixed  $data     缓存数据
     * @return bool 是否设置成功
     */
    public function setItemsCache(string $dictCode, $data): bool
    {
        return $this->setWithTag(self::KEY_ITEMS . $dictCode, $data);
    }

    /**
     * 清除字典项缓存
     *
     * @param string $dictCode 字典编码
     * @return bool 是否清除成功
     */
    public function clearItemsCache(string $dictCode): bool
    {
        return $this->getCacheDriver()->delete(self::KEY_ITEMS . $dictCode);
    }

    /**
     * 获取带状态过滤的字典项缓存
     *
     * @param string $dictCode 字典编码
     * @param int    $status   状态值
     * @return mixed 缓存数据，不存在时返回 null
     */
    public function getItemsStatusCache(string $dictCode, int $status)
    {
        return $this->getCacheDriver()->get(self::KEY_ITEMS_STATUS . $dictCode . ':' . $status);
    }

    /**
     * 设置带状态过滤的字典项缓存
     *
     * @param string $dictCode 字典编码
     * @param int    $status   状态值
     * @param mixed  $data     缓存数据
     * @return bool 是否设置成功
     */
    public function setItemsStatusCache(string $dictCode, int $status, $data): bool
    {
        return $this->setWithTag(self::KEY_ITEMS_STATUS . $dictCode . ':' . $status, $data);
    }

    /**
     * 清除带状态过滤的字典项缓存
     *
     * @param string $dictCode 字典编码
     * @param int    $status   状态值
     * @return bool 是否清除成功
     */
    public function clearItemsStatusCache(string $dictCode, int $status): bool
    {
        return $this->getCacheDriver()->delete(self::KEY_ITEMS_STATUS . $dictCode . ':' . $status);
    }

    /**
     * 获取树形结构缓存
     *
     * @param string $dictCode 字典编码
     * @return mixed 缓存数据，不存在时返回 null
     */
    public function getTreeCache(string $dictCode)
    {
        return $this->getCacheDriver()->get(self::KEY_TREE . $dictCode);
    }

    /**
     * 设置树形结构缓存
     *
     * @param string $dictCode 字典编码
     * @param mixed  $data     缓存数据
     * @return bool 是否设置成功
     */
    public function setTreeCache(string $dictCode, $data): bool
    {
        return $this->setWithTag(self::KEY_TREE . $dictCode, $data);
    }

    /**
     * 清除树形结构缓存
     *
     * @param string $dictCode 字典编码
     * @return bool 是否清除成功
     */
    public function clearTreeCache(string $dictCode): bool
    {
        return $this->getCacheDriver()->delete(self::KEY_TREE . $dictCode);
    }

    /**
     * 清除指定字典的全部缓存（类型 + 项 + 状态变体 + 树形结构）
     *
     * @param string $dictCode 字典编码
     * @return bool 是否全部清除成功
     */
    public function clearCache(string $dictCode): bool
    {
        $driver = $this->getCacheDriver();

        $results = [
            $this->clearTypeCache($dictCode),
            $this->clearItemsCache($dictCode),
            $this->clearTreeCache($dictCode),
        ];

        foreach ([0, 1] as $status) {
            $results[] = $this->clearItemsStatusCache($dictCode, $status);
        }

        foreach ($this->scanKeys(self::KEY_ITEMS_STATUS . $dictCode . ':') as $key) {
            try {
                $driver->delete($key);
            } catch (\Throwable $e) {
                $this->logWarning('DictCacheManager delete status key failed: ' . $e->getMessage());
            }
        }

        return !in_array(false, $results, true);
    }

    /**
     * 清除所有字典缓存
     *
     * 优先通过缓存标签批量清除（高效）；若驱动不支持标签，
     * 则降级为前缀扫描清除。
     *
     * @return bool 是否清除成功
     */
    public function clearAllCache(): bool
    {
        try {
            $driver = $this->getCacheDriver();

            if (method_exists($driver, 'tag')) {
                $driver->tag(self::TAG)->clear();
                return true;
            }

            $typeKeys   = $this->scanKeys(self::KEY_TYPE);
            $itemsKeys  = $this->scanKeys(self::KEY_ITEMS);
            $statusKeys = $this->scanKeys(self::KEY_ITEMS_STATUS);
            $treeKeys   = $this->scanKeys(self::KEY_TREE);
            $allKeys = array_merge($typeKeys, $itemsKeys, $statusKeys, $treeKeys);

            if (empty($allKeys)) {
                return true;
            }

            foreach ($allKeys as $key) {
                $driver->delete($key);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logWarning('DictCacheManager clearAllCache failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取缓存驱动实例
     *
     * 优先使用自定义驱动，否则使用 ThinkPHP Cache 门面。
     *
     * @return object
     */
    protected function getCacheDriver(): object
    {
        if ($this->customCache !== null) {
            return $this->customCache;
        }
        return Cache::store();
    }

    /**
     * 扫描匹配前缀的缓存键
     *
     * 降级方案中使用，当缓存驱动不支持标签时，
     * 通过键前缀扫描清除相关缓存。
     *
     * 优先使用 Redis SCAN 命令（非阻塞），
     * 降级到 KEYS 命令，均不可用时返回空数组。
     *
     * @param string $prefix 键前缀
     * @return array 匹配的缓存键列表
     */
    protected function scanKeys(string $prefix): array
    {
        try {
            $driver = $this->getCacheDriver();
            $handler = null;

            if (method_exists($driver, 'handler')) {
                $handler = $driver->handler();
            } elseif (method_exists($driver, 'getHandler')) {
                $handler = $driver->getHandler();
            }

            if (class_exists('\Redis') && $handler instanceof \Redis) {
                $keys = [];
                $iterator = null;
                $pattern = $prefix . '*';
                while (false !== ($result = $handler->scan($iterator, $pattern, 100))) {
                    if (!empty($result)) {
                        $keys = array_merge($keys, $result);
                    }
                }
                return $keys;
            }

            if (class_exists('\Predis\Client') && $handler instanceof \Predis\Client) {
                return $handler->keys($prefix . '*');
            }

            if (class_exists('\Memcached') && $handler instanceof \Memcached) {
                return [];
            }
        } catch (\Throwable $e) {
            $this->logWarning('DictCacheManager scanKeys failed for prefix [' . $prefix . ']: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * 记录警告日志（兼容不存在日志组件的情况）
     *
     * @param string $message 日志消息
     * @return void
     */
    protected function logWarning(string $message): void
    {
        try {
            ContextualLogger::warning($message);
        } catch (\Throwable) {
        }
    }
}
