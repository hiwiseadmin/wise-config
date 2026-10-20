<?php
declare(strict_types=1);

namespace wise\config;

use wise\config\service\DictTypeService;
use wise\config\service\DictItemService;
use wise\config\cache\DictCacheManager;
use wise\config\installer\DictInstaller;

/**
 * DictSDK 静态门面类
 *
 * 提供字典库的统一入口，通过懒加载单例模式管理内部服务实例。
 * 所有公开方法均为静态方法，可直接通过类名调用。
 *
 * @example
 *   DictSDK::getItems('order_status');           // 获取启用项列表
 *   DictSDK::getValueLabelMap('order_status');    // value=>label 映射
 *   DictSDK::getLabel('order_status', '1');       // 根据值获取标签
 *   DictSDK::getTree('region');                   // 树形结构
 *   DictSDK::clearCache('order_status');          // 清除指定字典缓存
 *   DictSDK::clearAllCache();                     // 清除全部字典缓存
 */
class DictSDK
{
    /**
     * @var DictTypeService|null 字典类型服务实例
     */
    private static ?DictTypeService $typeService = null;

    /**
     * @var DictItemService|null 字典项服务实例
     */
    private static ?DictItemService $itemService = null;

    /**
     * @var DictCacheManager|null 缓存管理器实例
     */
    private static ?DictCacheManager $cacheManager = null;

    /**
     * 获取字典类型服务实例（懒加载单例）
     *
     * @return DictTypeService
     */
    public static function getTypeService(): DictTypeService
    {
        if (self::$typeService === null) {
            // 优先从容器获取，与 ConfigServiceProvider 保持一致性
            if (function_exists('app') && app()->has(DictTypeService::class)) {
                self::$typeService = app(DictTypeService::class);
            } else {
                self::$typeService = new DictTypeService(self::getCacheManager());
            }
        }
        return self::$typeService;
    }

    /**
     * 获取字典项服务实例（懒加载单例）
     *
     * @return DictItemService
     */
    public static function getItemService(): DictItemService
    {
        if (self::$itemService === null) {
            if (function_exists('app') && app()->has(DictItemService::class)) {
                self::$itemService = app(DictItemService::class);
            } else {
                self::$itemService = new DictItemService(self::getCacheManager());
            }
        }
        return self::$itemService;
    }

    /**
     * 获取缓存管理器实例（懒加载单例）
     *
     * @return DictCacheManager
     */
    public static function getCacheManager(): DictCacheManager
    {
        if (self::$cacheManager === null) {
            self::$cacheManager = new DictCacheManager();
        }
        return self::$cacheManager;
    }

    /**
     * 获取指定字典的启用项列表
     *
     * @param string $dictCode 字典编码
     * @return array 字典项列表
     */
    public static function getItems(string $dictCode): array
    {
        return self::getItemService()->getItems($dictCode, 1);
    }

    /**
     * 获取指定字典的 value=>label 映射
     *
     * @param string $dictCode 字典编码
     * @return array value=>label 键值对
     */
    public static function getValueLabelMap(string $dictCode): array
    {
        return self::getItemService()->getValueLabelMap($dictCode);
    }

    /**
     * 根据字典编码和值获取标签
     *
     * @param string $dictCode 字典编码
     * @param string $value   字典项值
     * @return string 对应的标签，未找到时返回空字符串
     */
    public static function getLabel(string $dictCode, string $value): string
    {
        return self::getItemService()->getLabel($dictCode, $value);
    }

    /**
     * 获取树形字典结构
     *
     * @param string $dictCode 字典编码（须为树形字典）
     * @return array 树形结构数据
     */
    public static function getTree(string $dictCode): array
    {
        return self::getItemService()->getTree($dictCode);
    }

    /**
     * 清除指定字典的缓存
     *
     * @param string $dictCode 字典编码
     * @return bool 是否清除成功
     */
    public static function clearCache(string $dictCode): bool
    {
        return self::getCacheManager()->clearCache($dictCode);
    }

    /**
     * 清除全部字典缓存
     *
     * @return bool 是否清除成功
     */
    public static function clearAllCache(): bool
    {
        return self::getCacheManager()->clearAllCache();
    }

    /**
     * 重置内部单例（主要用于测试）
     *
     * 仅清除服务实例引用，不操作数据库。
     *
     * @return void
     */
    public static function resetInstances(): void
    {
        self::$typeService = null;
        self::$itemService = null;
        self::$cacheManager = null;
    }

    /**
     * 完全重置（清空数据库 + 重置单例，仅用于集成测试）
     *
     * ⚠️ 警告：此方法会删除所有字典数据。
     * 除本地/测试环境外会被环境护栏拦截，确需强制执行请传入 $force = true。
     *
     * @param bool $force 是否强制跳过环境校验
     * @return void
     */
    public static function resetAll(bool $force = false): void
    {
        self::resetInstances();
        DictInstaller::reset($force);
    }
}
