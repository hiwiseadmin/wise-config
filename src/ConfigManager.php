<?php
declare(strict_types=1);

namespace wise\config;

use think\facade\Cache;
use think\facade\Event;
use wise\support\context\ContextualLogger;
use wise\config\model\ConfigModel;
use wise\config\model\ConfigHistoryModel;
use wise\config\contract\TypeHandlerInterface;
use wise\config\event\ConfigSaving;
use wise\config\event\ConfigSaved;
use wise\config\event\ConfigDeleting;
use wise\config\event\ConfigReverted;
use wise\config\resolver\PathResolver;
use wise\config\resolver\NamespaceResolver;
use wise\config\resolver\InheritanceResolver;
use wise\config\manager\TagManager;

/**
 * 配置管理器
 *
 * 提供键值对配置的核心 CRUD 操作，支持缓存自动刷新、类型处理、历史版本记录。
 * 支持多级路径（namespace:group:key）、命名空间感知、继承链解析和标签管理。
 *
 * @example
 *   $manager = app(ConfigManager::class);
 *   $value = $manager->get('site_name', '默认值');
 *   $value = $manager->namespace('project_a')->get('seo:title');
 *   $configs = $manager->getGroup('system');
 */
class ConfigManager
{
    /**
     * 缓存键前缀
     *
     * @var string
     */
    protected string $cachePrefix;

    /**
     * 缓存标签
     *
     * @var string
     */
    protected string $cacheTag;

    /**
     * 缓存有效期（秒）
     *
     * @var int
     */
    protected int $cacheTtl;

    /**
     * 类型处理器注册表
     *
     * @var array<string, TypeHandlerInterface>
     */
    protected $typeHandlers = [];

    /**
     * 默认类型处理器
     *
     * @var TypeHandlerInterface|null
     */
    protected $defaultHandler = null;

    /**
     * 路径解析器
     *
     * @var PathResolver
     */
    protected PathResolver $pathResolver;

    /**
     * 命名空间推断器
     *
     * @var NamespaceResolver
     */
    protected NamespaceResolver $namespaceResolver;

    /**
     * 继承链解析器
     *
     * @var InheritanceResolver
     */
    protected InheritanceResolver $inheritanceResolver;

    /**
     * 标签管理器（可选，P1 功能）
     *
     * @var TagManager|null
     */
    protected ?TagManager $tagManager = null;

    /**
     * 当前作用域 namespace（null = 自动推断）
     *
     * @var string|null
     */
    protected ?string $currentNamespace = null;

    /**
     * 当前正在比较的配置 ID（用于 isConfigUnchanged 中标签比较，用完即清）
     *
     * @var int|null
     */
    protected ?int $lastConfigId = null;

    /**
     * 是否仅返回公开（visibility=1）配置
     *
     * 默认 false：服务端内部读取私有配置（visibility=0）是合法场景。
     * 对外的接口/调试读取应通过 onlyPublic() 显式开启，避免私有配置泄漏。
     *
     * @var bool
     */
    protected bool $onlyPublic = false;

    /**
     * 请求级继承链解析缓存（避免跨请求脏读）
     *
     * 键为 "namespace:group:key"，值为解析结果（null 表示未命中）。
     *
     * @var array<string, mixed>
     */
    protected array $chainCache = [];

    public function __construct()
    {
        $this->cachePrefix = $this->getPackageConfig('cache_key_prefix', 'wise_config:');
        $this->cacheTag    = $this->getPackageConfig('cache_tag', 'wise_config');
        $this->cacheTtl    = (int) $this->getPackageConfig('cache_ttl', 0);

        // 初始化解析器（从容器获取）
        $this->pathResolver        = app('wise.config.path_resolver');
        $this->namespaceResolver   = app('wise.config.namespace_resolver');
        $this->inheritanceResolver = app('wise.config.inheritance_resolver');

        // TagManager 可选（P1，T04 完成后才可用）
        try {
            $this->tagManager = app('wise.config.tag_manager');
        } catch (\Throwable) {
            // TagManager 尚未注册，tag 功能降级
        }
    }

    /**
     * 获取包配置（兼容框架未启动的场景）
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function getPackageConfig(string $key, $default = null)
    {
        if (function_exists('config')) {
            return config('wise-config.' . $key, $default);
        }
        return $default;
    }

    // ==================== 命名空间链式调用 ====================

    /**
     * 设置当前操作 namespace（链式调用）
     *
     * @param string $ns
     * @return $this
     */
    public function namespace(string $ns): self
    {
        // 校验合法性，防止通过冒号/路径等字符污染 `namespace:group:key` 解析与缓存键
        if (!$this->namespaceResolver->isValidNamespace($ns)) {
            throw new \InvalidArgumentException('非法的 namespace: ' . $ns);
        }

        $this->currentNamespace = $ns;
        return $this;
    }

    /**
     * 在指定 namespace 作用域内执行回调，执行完毕后自动恢复原 namespace
     *
     * 相比 namespace() 的“设置后长期生效”，本方法可避免共享实例上的
     * 上下文串扰（尤其在长驻进程/协程复用同一实例时）。
     *
     * @param string   $ns
     * @param callable $callback 接收当前 ConfigManager 实例
     * @return mixed 回调返回值
     */
    public function withNamespace(string $ns, callable $callback)
    {
        if (!$this->namespaceResolver->isValidNamespace($ns)) {
            throw new \InvalidArgumentException('非法的 namespace: ' . $ns);
        }

        $previous = $this->currentNamespace;
        $this->currentNamespace = $ns;
        try {
            return $callback($this);
        } finally {
            $this->currentNamespace = $previous;
        }
    }

    /**
     * 设置是否仅返回公开（visibility=1）配置
     *
     * @param bool $onlyPublic
     * @return $this
     */
    public function onlyPublic(bool $onlyPublic = true): self
    {
        $this->onlyPublic = $onlyPublic;
        return $this;
    }

    /**
     * 是否对读取结果套用 visibility=1 过滤
     *
     * 显式 onlyPublic() 优先；否则读取配置项 wise-config.filter_private_on_read（默认 false）。
     */
    protected function shouldFilterPublic(): bool
    {
        return $this->onlyPublic || (bool) $this->getPackageConfig('filter_private_on_read', false);
    }

    /**
     * 统一的单项缓存键（按可见性作用域隔离，避免公开/私有读取互相污染）
     */
    protected function itemCacheKey(string $namespace, string $group, string $key): string
    {
        $scope = $this->shouldFilterPublic() ? 'pub:' : '';
        return $this->cachePrefix . 'item:' . $scope . $namespace . ':' . $group . ':' . $key;
    }

    /**
     * 统一的分组缓存键（按可见性作用域隔离）
     */
    protected function groupCacheKey(string $namespace, string $group): string
    {
        $scope = $this->shouldFilterPublic() ? 'pub:' : '';
        return $this->cachePrefix . 'group:' . $scope . $namespace . ':' . $group;
    }

    /**
     * 删除单项缓存（公开/私有两种可见性作用域都清，避免残留脏数据）
     */
    protected function deleteItemCache(string $namespace, string $group, string $key): void
    {
        Cache::delete($this->cachePrefix . 'item:' . $namespace . ':' . $group . ':' . $key);
        Cache::delete($this->cachePrefix . 'item:pub:' . $namespace . ':' . $group . ':' . $key);
    }

    /**
     * 删除分组缓存（公开/私有两种可见性作用域都清）
     */
    protected function deleteGroupCache(string $namespace, string $group): void
    {
        Cache::delete($this->cachePrefix . 'group:' . $namespace . ':' . $group);
        Cache::delete($this->cachePrefix . 'group:pub:' . $namespace . ':' . $group);
    }

    /**
     * 获取当前生效的 namespace
     *
     * @return string
     */
    public function getCurrentNamespace(): string
    {
        if ($this->currentNamespace !== null) {
            return $this->currentNamespace;
        }
        return $this->namespaceResolver->resolve();
    }

    // ==================== 类型处理器 ====================

    /**
     * 注册类型处理器
     *
     * @param TypeHandlerInterface $handler
     * @return $this
     */
    public function registerTypeHandler(TypeHandlerInterface $handler): self
    {
        $this->typeHandlers[$handler->getType()] = $handler;
        return $this;
    }

    /**
     * 设置默认处理器
     *
     * @param TypeHandlerInterface $handler
     * @return $this
     */
    public function setDefaultHandler(TypeHandlerInterface $handler): self
    {
        $this->defaultHandler = $handler;
        return $this;
    }

    /**
     * 获取类型处理器
     *
     * @param string $type
     * @return TypeHandlerInterface|null
     */
    public function getTypeHandler(string $type): ?TypeHandlerInterface
    {
        return $this->typeHandlers[$type] ?? $this->defaultHandler;
    }

    // ==================== 读取操作 ====================

    /**
     * 获取配置值（支持冒号路径 namespace:group:key）
     *
     * @param string $path    配置路径，支持 a:b:c / b:c / a 三种格式
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        // 可选：让 get() 也走继承解析，统一读语义（wise-config.inheritance.apply_on_get）
        // 默认关闭，保持既有行为不变；开启后 get() 与 getResolved() 结果一致。
        if (config('wise-config.inheritance.enabled', true)
            && config('wise-config.inheritance.apply_on_get', false)) {
            return $this->getResolved($path, $default);
        }

        // 解析路径
        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $namespace = $parsed['namespace'];
        $group     = $parsed['group'];
        $key       = $parsed['key'];

        // 缓存键包含 namespace 与可见性作用域
        $cacheKey = $this->itemCacheKey($namespace, $group, $key);

        // 从缓存获取
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            // 区分"不存在"标记和实际值
            return $cached === '__WISECONFIG_NULL__' ? $default : $cached;
        }

        // 查数据库
        $query = ConfigModel::where('namespace', $namespace)
            ->where('group', $group)
            ->where('key', $key)
            ->where('status', 1);
        if ($this->shouldFilterPublic()) {
            $query->where('visibility', 1);
        }
        $config = $query->find();
        if (!$config) {
            // 缓存空结果（防穿透），使用特殊标记区分 null 值和不存在
            $this->cacheNull($cacheKey);
            return $default;
        }

        $value = $this->decodeValue($config->value, (string) $config->type);

        // 写入缓存
        $this->cacheForever($cacheKey, $value);

        return $value;
    }

    /**
     * 批量获取配置
     *
     * @param array  $keys    键名数组
     * @param mixed  $default 默认值
     * @param string $group   配置分组，默认 'system'
     * @return array key => value
     */
    public function many(array $keys, $default = null, string $group = 'system'): array
    {
        $result = [];
        $missKeys = [];
        $ns = $this->getCurrentNamespace();
        
        // 第一轮：从缓存获取
        foreach ($keys as $key) {
            $cacheKey = $this->itemCacheKey($ns, $group, $key);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $result[$key] = $cached === '__WISECONFIG_NULL__' ? $default : $cached;
            } else {
                $missKeys[] = $key;
            }
        }
        
        // 第二轮：批量查数据库（仅缺缓存的部分）
        if (!empty($missKeys)) {
            $query = ConfigModel::where('namespace', $ns)
                ->where('group', $group)
                ->whereIn('key', $missKeys)
                ->where('status', 1);
            if ($this->shouldFilterPublic()) {
                $query->where('visibility', 1);
            }
            $configs = $query->select();
            
            $found = [];
            foreach ($configs as $config) {
                $found[$config->key] = $config;
            }
            
            foreach ($missKeys as $key) {
                $cacheKey = $this->itemCacheKey($ns, $group, $key);
                if (isset($found[$key])) {
                    $value = $this->decodeValue($found[$key]->value, (string) $found[$key]->type);
                    $result[$key] = $value;
                    $this->cacheForever($cacheKey, $value);
                } else {
                    $result[$key] = $default;
                    $this->cacheNull($cacheKey);
                }
            }
        }
        
        return $result;
    }

    /**
     * 获取分组下所有配置
     *
     * @param string      $group 分组标识
     * @param string|null $ns    命名空间，null 时使用当前 namespace
     * @return array key => value
     */
    public function getGroup(string $group, ?string $ns = null): array
    {
        $namespace = $ns ?? $this->getCurrentNamespace();
        $cacheKey = $this->groupCacheKey($namespace, $group);

        $cached = Cache::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $query = ConfigModel::where('namespace', $namespace)
            ->where('group', $group)
            ->where('status', 1);
        if ($this->shouldFilterPublic()) {
            $query->where('visibility', 1);
        }
        $configs = $query->order('sort', 'asc')
            ->select();

        $result = [];
        foreach ($configs as $config) {
            $result[$config->key] = $this->decodeValue($config->value, (string) $config->type);
        }

        $this->cacheForever($cacheKey, $result);

        return $result;
    }

    /**
     * 检查配置键是否存在
     *
     * @param string $path 配置路径，支持 a:b:c / b:c / a 三种格式
     * @return bool
     */
    public function has(string $path): bool
    {
        // 开启继承统一读语义时，has() 也应反映继承链结果（用哨兵区分“不存在”与合法空值）
        if (config('wise-config.inheritance.enabled', true)
            && config('wise-config.inheritance.apply_on_get', false)) {
            $sentinel = '__WISECONFIG_MISSING__';
            return $this->getResolved($path, $sentinel) !== $sentinel;
        }

        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());

        // 先读缓存：需区分"真实值"与 get() 写入的负缓存标记
        $cacheKey = $this->itemCacheKey($parsed['namespace'], $parsed['group'], $parsed['key']);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached !== '__WISECONFIG_NULL__';
        }

        $query = ConfigModel::where('namespace', $parsed['namespace'])
            ->where('group', $parsed['group'])
            ->where('key', $parsed['key'])
            ->where('status', 1);
        if ($this->shouldFilterPublic()) {
            $query->where('visibility', 1);
        }

        return $query->count() > 0;
    }

    // ==================== 写入操作 ====================

    /**
     * 设置配置值（新增或更新）
     *
     * @param string   $path  配置路径，支持 a:b:c / b:c / a 三种格式
     * @param mixed    $value 配置值（原始值，会被编码）
     * @param array    $extra 额外字段（type, group, title, description, tags, inherit 等）
     * @param int|null $id    可选，指定要更新的记录ID（用于键名变更场景）
     * @return bool
     */
    public function set(string $path, $value, array $extra = [], ?int $id = null): bool
    {
        // 解析路径
        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $namespace = $parsed['namespace'];
        $group     = $parsed['group'];
        $key       = $parsed['key'];

        $type    = $extra['type'] ?? 'text';
        $tags    = $extra['tags'] ?? [];
        $inherit = isset($extra['inherit']) ? (int) $extra['inherit'] : 1;

        // 查找或新建（改为 namespace + key 定位）
        $config = null;
        if ($id !== null) {
            // 明确指定ID时，通过ID查找
            $config = ConfigModel::find($id);
        }
        if (!$config) {
            // 通过 namespace + key 查找
            $config = ConfigModel::where('namespace', $namespace)
                ->where('key', $key)
                ->find();
        }

        $isNew  = false;
        $oldValue = null;
        $oldKey = null;  // 记录旧键名，用于键名变更时清除旧缓存
        $oldAttrs = [];  // 修改前的原始属性，用于 isConfigUnchanged 比较

        if (!$config) {
            $config = new ConfigModel();
            $config->namespace = $namespace;
            $config->key       = $key;
            $config->group     = $group;
            $config->type      = $type;
            $config->inherit   = $inherit;
            $isNew = true;
        } else {
            $oldValue = $config->value;
            // 记录修改前的原始属性，用于 isConfigUnchanged 比较
            // 必须覆盖 set() 方法中所有可能修改的字段
            $oldAttrs = [
                'type'             => $config->type,
                'group'            => $config->group,
                'inherit'          => (int) $config->inherit,
                'title'            => $config->title ?? '',
                'description'      => $config->description ?? '',
                'sort'             => (int) $config->sort,
                'status'           => (int) $config->status,
                'visibility'       => (int) $config->visibility,
                'validation_rules' => $config->validation_rules ?? '',
            ];
            // 允许键名变更（用于重命名场景）
            if ($config->key !== $key) {
                $oldKey = $config->key;  // 记录旧键名
                $config->key = $key;
            }
            // 更新 type 和 group（如果提供了）
            if (isset($extra['type'])) {
                $config->type = $extra['type'];
                $type = $extra['type'];
            } else {
                $type = $config->type;
            }
            if (isset($extra['group'])) {
                $group = $extra['group'];
                $config->group = $extra['group'];
            } else {
                $group = $config->group;
            }
            // 更新 inherit（如果提供了）
            if (isset($extra['inherit'])) {
                $config->inherit = (int) $extra['inherit'];
            }
        }

        // 编码值（在 type 最终确定后执行，确保类型变更时使用新 type 编码）
        $encodedValue = $this->encodeValue($value, $type);

        $config->value = $encodedValue;

        // 使用 array_key_exists 而非 !empty，允许清空 title 和 description
        if (array_key_exists('title', $extra)) {
            $config->title = $extra['title'] ?? '';
        }
        if (array_key_exists('description', $extra)) {
            $config->description = $extra['description'] ?? '';
        }
        if (isset($extra['validation_rules'])) {
            $config->validation_rules = $extra['validation_rules'];
        }
        if (isset($extra['sort'])) {
            $config->sort = (int) $extra['sort'];
        }
        if (isset($extra['status'])) {
            $config->status = (int) $extra['status'];
        }
        if (isset($extra['visibility'])) {
            $config->visibility = (int) $extra['visibility'];
        }

        // 更新已有配置时，如果新旧值完全相同则跳过数据库写入（在触发事件前判断）
        if (!$isNew) {
            // 记录 config id 供 isConfigUnchanged 中标签比较使用
            $this->lastConfigId = (int) $config->id;
            if ($this->isConfigUnchanged($oldValue, $encodedValue, $oldKey, $oldAttrs, $extra, $tags)) {
                $this->lastConfigId = null;
                return true;
            }
            $this->lastConfigId = null;
        }

        // 触发保存前事件（真正的写库之前，便于监听器做校验/预处理）
        Event::trigger(new ConfigSaving($key, $value, $type, $group, $extra, $namespace));

        $saved = $config->save();

        if ($saved) {
            // 处理 tags
            if (!empty($tags) && $this->tagManager) {
                $this->tagManager->sync($config->id, $tags);
            }

            // 记录历史
            if (!$isNew) {
                $this->recordHistory(
                    $config->id,
                    $key,
                    (string) $oldValue,
                    $encodedValue,
                    $namespace
                );
            }

            // 刷新缓存（namespace 感知）：必须同时清理变更前的 group/键，
            // 否则改分组（extra['group']）后旧分组的分组缓存与旧键缓存仍残留旧值。
            $oldGroup = $oldAttrs['group'] ?? $group;
            $this->clearCacheForKeyInNamespace($namespace, $group, $key);
            if ($oldGroup !== $group) {
                $this->clearCacheForKeyInNamespace($namespace, $oldGroup, $key);
                $this->clearCacheForGroup($oldGroup, $namespace);
            }
            if ($oldKey !== null) {
                $this->clearCacheForKeyInNamespace($namespace, $oldGroup, $oldKey);
            }
            $this->clearCacheForGroup($config->group, $namespace);
            // 清除继承链缓存
            $this->clearInheritanceChain($key, $namespace, $group);

            // 触发保存后事件（新增 namespace 参数）
            Event::trigger(new ConfigSaved(
                $key,
                (string) $oldValue,
                $encodedValue,
                $type,
                $group,
                $namespace
            ));
        }

        return $saved;
    }

    /**
     * 批量设置配置
     *
     * @param array  $data key => ['value' => ..., 'type' => ..., ...] 或 key => value
     * @param string $defaultGroup 默认分组
     * @param string $defaultType  默认类型
     * @return int 更新数量
     */
    public function setMany(array $data, string $defaultGroup = 'system', string $defaultType = 'text'): int
    {
        $count = 0;
        foreach ($data as $key => $item) {
            if (is_array($item)) {
                $value = $item['value'] ?? null;
                $extra = $item;
                unset($extra['value']);
                if (!isset($extra['group'])) {
                    $extra['group'] = $defaultGroup;
                }
                if (!isset($extra['type'])) {
                    $extra['type'] = $defaultType;
                }
            } else {
                $value = $item;
                $extra = ['group' => $defaultGroup, 'type' => $defaultType];
            }

            if ($this->set($key, $value, $extra)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 删除配置
     *
     * @param string $path 配置路径，支持 a:b:c / b:c / a 三种格式
     * @return bool
     */
    public function delete(string $path): bool
    {
        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $namespace = $parsed['namespace'];
        $group     = $parsed['group'];
        $key       = $parsed['key'];

        $config = ConfigModel::where('namespace', $namespace)
            ->where('key', $key)
            ->find();
        if (!$config) {
            return false;
        }

        $configId = $config->id;
        $group = $config->group;

        // 触发删除前事件（新增 namespace）
        Event::trigger(new ConfigDeleting($key, $configId, $group, $namespace));

        $deleted = $config->delete();

        if ($deleted) {
            // 清理关联的历史记录，避免孤儿数据
            ConfigHistoryModel::where('config_id', $configId)->delete();

            // 清理 tags
            if ($this->tagManager) {
                $this->tagManager->deleteConfigTags($configId);
            }

            $this->clearCacheForKeyInNamespace($namespace, $group, $key);
            $this->clearCacheForGroup($group, $namespace);
            $this->clearInheritanceChain($key, $namespace, $group);
        }

        return $deleted;
    }

    // ==================== 继承相关（新增） ====================

    /**
     * 获取最终生效值（含继承链）
     *
     * 沿继承链向上查找配置值，返回最终生效的值。
     * 可通过 config('wise-config.inheritance.enabled', true) 关闭继承特性。
     *
     * @param string $path    配置路径，支持 a:b:c / b:c / a 三种格式
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function getResolved(string $path, $default = null)
    {
        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $namespace = $parsed['namespace'];
        $group     = $parsed['group'];
        $key       = $parsed['key'];

        if (!config('wise-config.inheritance.enabled', true)) {
            return $this->get($path, $default);
        }

        // 请求级缓存：继承链结果不做跨请求持久化，
        // 避免父级 namespace 变更后子级已缓存的解析结果永久脏读（cache_ttl=0 时为永久）。
        $localKey = $namespace . ':' . $group . ':' . $key;
        if (array_key_exists($localKey, $this->chainCache)) {
            $cachedValue = $this->chainCache[$localKey];
            return $cachedValue === null ? $default : $cachedValue;
        }

        // 获取继承链中所有可能的 namespace 的配置
        $chain = $this->inheritanceResolver->getChain($namespace);
        $query = ConfigModel::whereIn('namespace', $chain)
            ->where('group', $group)
            ->where('key', $key);
        if ($this->shouldFilterPublic()) {
            $query->where('visibility', 1);
        }
        $configs = $query->select();

        $resolved = $this->inheritanceResolver->resolve($configs->toArray(), $namespace, $group, $key);

        if ($resolved === null) {
            $this->chainCache[$localKey] = null;
            return $default;
        }

        $resolvedValue = $this->decodeValue($resolved['value'], $resolved['type'] ?? 'text');
        $this->chainCache[$localKey] = $resolvedValue;

        return $resolvedValue;
    }

    /**
     * 获取配置来源信息
     *
     * 返回配置是否由当前 namespace 定义，还是从上级继承。
     *
     * @param string $path 配置路径
     * @return array ['exists' => bool, 'value' => mixed, 'source_ns' => string, 'is_inherited' => bool]
     */
    public function getOverrideStatus(string $path): array
    {
        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $namespace = $parsed['namespace'];
        $group     = $parsed['group'];
        $key       = $parsed['key'];

        $chain = $this->inheritanceResolver->getChain($namespace);
        $query = ConfigModel::whereIn('namespace', $chain)
            ->where('group', $group)
            ->where('key', $key);
        if ($this->shouldFilterPublic()) {
            $query->where('visibility', 1);
        }
        $configs = $query->select();

        $resolved = $this->inheritanceResolver->resolve($configs->toArray(), $namespace, $group, $key);

        if ($resolved === null) {
            return ['exists' => false];
        }

        return [
            'exists'        => true,
            'value'         => $this->decodeValue($resolved['value'], $resolved['type'] ?? 'text'),
            'source_ns'     => $resolved['source_ns'],
            'is_inherited'  => $resolved['is_inherited'],
        ];
    }

    /**
     * 删除当前 namespace 覆盖值，恢复继承
     *
     * @param string $path 配置路径
     * @return bool
     */
    public function reset(string $path): bool
    {
        return $this->delete($path);
    }

    /**
     * 获取当前 namespace 全部配置
     *
     * @return array key => value
     */
    public function all(): array
    {
        $namespace = $this->getCurrentNamespace();
        $query = ConfigModel::where('namespace', $namespace)
            ->where('status', 1);
        if ($this->shouldFilterPublic()) {
            $query->where('visibility', 1);
        }
        $configs = $query->order('group', 'asc')
            ->order('sort', 'asc')
            ->select();

        $result = [];
        foreach ($configs as $config) {
            $result[$config->key] = $this->decodeValue($config->value, (string) $config->type);
        }
        return $result;
    }

    // ==================== Tag 操作（新增） ====================

    /**
     * 为配置添加标签
     *
     * @param string $path 配置路径
     * @param string $tag  标签名
     * @return bool
     */
    public function addTag(string $path, string $tag): bool
    {
        if (!$this->tagManager) {
            return false;
        }

        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $config = ConfigModel::where('namespace', $parsed['namespace'])
            ->where('key', $parsed['key'])
            ->find();
        if (!$config) {
            return false;
        }

        return $this->tagManager->attach($config->id, [$tag]);
    }

    /**
     * 移除配置的标签
     *
     * @param string $path 配置路径
     * @param string $tag  标签名
     * @return bool
     */
    public function removeTag(string $path, string $tag): bool
    {
        if (!$this->tagManager) {
            return false;
        }

        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $config = ConfigModel::where('namespace', $parsed['namespace'])
            ->where('key', $parsed['key'])
            ->find();
        if (!$config) {
            return false;
        }

        return $this->tagManager->detach($config->id, [$tag]);
    }

    /**
     * 按标签查询配置
     *
     * @param array  $tags      标签数组
     * @param string $logic     'and' | 'or'，默认 'and'
     * @return array 配置记录数组
     */
    public function whereTags(array $tags, string $logic = 'and'): array
    {
        if (!$this->tagManager) {
            return [];
        }
        $namespace = $this->getCurrentNamespace();
        return $this->tagManager->findByTags($tags, $namespace, $logic);
    }

    // ==================== 历史版本 ====================

    /**
     * 获取配置历史版本列表
     *
     * @param string $path  配置路径，支持 a:b:c / b:c / a 三种格式
     * @param int    $limit 条数限制
     * @return array
     */
    public function getHistory(string $path, int $limit = 50): array
    {
        // 先解析路径获取 namespace
        $parsed = $this->pathResolver->parse($path, $this->getCurrentNamespace());
        $config = ConfigModel::where('namespace', $parsed['namespace'])
            ->where('key', $parsed['key'])
            ->find();
        if (!$config) {
            return [];
        }

        return ConfigHistoryModel::where('config_id', $config->id)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取指定历史记录
     *
     * @param int $historyId
     * @return array|null
     */
    public function getHistoryById(int $historyId): ?array
    {
        $history = ConfigHistoryModel::find($historyId);
        return $history ? $history->toArray() : null;
    }

    /**
     * 回滚配置到指定历史版本
     *
     * @param int $historyId 历史记录ID
     * @return bool
     */
    public function revert(int $historyId): bool
    {
        $history = ConfigHistoryModel::find($historyId);
        if (!$history) {
            return false;
        }

        // 优先按历史记录中的 config_id 精确定位，避免同名 key 重建后回滚到错误记录
        $config = ConfigModel::find($history->config_id);
        if (!$config) {
            $config = ConfigModel::where('namespace', $history->namespace)
                ->where('key', $history->key)
                ->find();
        }
        if (!$config) {
            return false;
        }

        $currentValue = $config->value;
        $restoreValue = $history->old_value;
        $namespace = $config->namespace ?? 'default';

        // 触发保存前事件（回滚也是一种保存）
        Event::trigger(new ConfigSaving(
            $history->key,
            $restoreValue,
            (string) $config->type,
            $config->group,
            $config->toArray(),
            $namespace
        ));

        // 回滚到 old_value
        $config->value = $restoreValue;
        $saved = $config->save();

        if ($saved) {
            // 记录本次回滚为新的历史记录
            $this->recordHistory(
                $config->id,
                $history->key,
                (string) $currentValue,
                (string) $restoreValue,
                $namespace
            );

            // 刷新缓存（使用配置实际的 namespace 而非当前上下文）
            $this->clearCacheForKeyInNamespace($namespace, $config->group, $history->key);
            $this->clearCacheForGroup($config->group, $namespace);
            // 清理继承链缓存，避免回滚后继承读取仍返回旧值
            $this->clearInheritanceChain($history->key, $namespace, $config->group);

            // 触发回滚事件（新增 namespace）
            Event::trigger(new ConfigReverted(
                $history->key,
                $currentValue,
                $restoreValue,
                $historyId,
                $namespace
            ));

            // 同时触发保存后事件，供通用的「配置已变更」监听器使用
            Event::trigger(new ConfigSaved(
                $history->key,
                $currentValue,
                $restoreValue,
                (string) $config->type,
                $config->group,
                $namespace
            ));
        }

        return $saved;
    }

    /**
     * 对比两个历史版本
     *
     * @param int $historyIdA
     * @param int $historyIdB
     * @return array ['old' => ..., 'new' => ..., 'diff' => ...]
     */
    public function diff(int $historyIdA, int $historyIdB): array
    {
        $a = ConfigHistoryModel::find($historyIdA);
        $b = ConfigHistoryModel::find($historyIdB);

        $oldValue = $a ? $a->old_value : '';
        $newValue = $b ? $b->new_value : '';

        // 解析 JSON 值进行结构化对比
        $oldDecoded = json_decode($oldValue, true);
        $oldJsonErr = json_last_error();
        $newDecoded = json_decode($newValue, true);
        $newJsonErr = json_last_error();

        $isJson = ($oldJsonErr === JSON_ERROR_NONE)
            && ($newJsonErr === JSON_ERROR_NONE)
            && is_array($oldDecoded) && is_array($newDecoded);

        if ($isJson) {
            return [
                'old'   => $oldDecoded,
                'new'   => $newDecoded,
                'diff'  => $this->arrayDiff($oldDecoded, $newDecoded),
                'is_json' => true,
            ];
        }

        // 文本差异
        return [
            'old'     => $oldValue,
            'new'     => $newValue,
            'diff'    => $this->textDiff($oldValue, $newValue),
            'is_json' => false,
        ];
    }

    // ==================== 缓存管理 ====================

    /**
     * 清除指定键的缓存（兼容旧版，使用当前 namespace）
     *
     * @param string $key
     * @return void
     */
    public function clearCacheForKey(string $key, string $group = 'system', ?string $namespace = null): void
    {
        $ns = $namespace ?? $this->getCurrentNamespace();
        $this->deleteItemCache($ns, $group, $key);
    }

    /**
     * 清除指定分组下指定 namespace 的所有缓存
     *
     * @param string      $group     分组
     * @param string|null $namespace 命名空间，null 时使用当前 namespace
     * @return void
     */
    public function clearCacheForGroup(string $group, ?string $namespace = null): void
    {
        $ns = $namespace ?? $this->getCurrentNamespace();
        $this->deleteGroupCache($ns, $group);
    }

    /**
     * 清除全部缓存
     *
     * @return void
     */
    public function clearAllCache(): void
    {
        Cache::tag($this->cacheTag)->clear();
    }

    /**
     * namespace 感知的键缓存清除（内部方法）
     *
     * @param string $namespace 命名空间
     * @param string $group     分组
     * @param string $key       键名
     * @return void
     */
    protected function clearCacheForKeyInNamespace(string $namespace, string $group, string $key): void
    {
        $this->deleteItemCache($namespace, $group, $key);
    }

    /**
     * 继承链缓存清除（内部方法）
     *
     * @param string $key       键名
     * @param string $namespace 命名空间
     * @param string $group     分组
     * @return void
     */
    protected function clearInheritanceChain(string $key, string $namespace = 'default', string $group = 'system'): void
    {
        // 清理请求级继承链缓存
        unset($this->chainCache[$namespace . ':' . $group . ':' . $key]);
        // 兼容旧版本遗留的持久化 chain 键
        Cache::delete($this->cachePrefix . 'chain:' . $namespace . ':' . $group . ':' . $key);
    }

    // ==================== 辅助方法 ====================

    /**
     * 按类型解码原始存储值（公开方法）
     *
     * 供 TpConfigBridge / 导出等场景复用，避免重复实现解码逻辑。
     *
     * @param mixed  $rawValue 数据库原始值
     * @param string $type     类型
     * @return mixed 解码后的值
     */
    public function decode($rawValue, string $type = 'text')
    {
        return $this->decodeValue($rawValue, $type);
    }

    /**
     * 解码值（数据库 -> 应用）
     *
     * @param mixed  $rawValue 数据库中的原始值（string 或已由 ORM JSON cast 解码的 array）
     * @param string $type     类型
     * @return mixed
     */
    protected function decodeValue($rawValue, string $type)
    {
        $handler = $this->getTypeHandler($type);

        if ($handler) {
            return $handler->decode($rawValue);
        }

        // 内置类型处理
        switch ($type) {
            case 'number':
                return is_numeric($rawValue) ? $rawValue + 0 : $rawValue;

            case 'json':
                $decoded = json_decode($rawValue, true);
                return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $rawValue;

            case 'image':
                // 如果是相对路径，添加完整 URL
                if (!empty($rawValue) && !preg_match('#^(https?://|/)#', $rawValue)) {
                    return $this->getUploadUrl($rawValue);
                }
                return $rawValue;

            case 'html':
                // 直接返回（由前端负责 XSS 过滤或由 HTMLPurifier 处理）
                return $rawValue;

            case 'text':
            default:
                return $rawValue;
        }
    }

    /**
     * 编码值（应用 -> 数据库）
     *
     * @param mixed  $inputValue 用户输入
     * @param string $type       类型
     * @return string
     */
    protected function encodeValue($inputValue, string $type): string
    {
        $handler = $this->getTypeHandler($type);

        if ($handler) {
            return $handler->encode($inputValue);
        }

        switch ($type) {
            case 'number':
                return (string) $inputValue;

            case 'json':
                if (is_string($inputValue)) {
                    // 尝试解析 JSON 字符串
                    $decoded = json_decode($inputValue, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    }
                    return $inputValue;
                }
                return json_encode($inputValue, JSON_UNESCAPED_UNICODE);

            case 'html':
                // 可选 XSS 过滤
                if ($this->getPackageConfig('html_filter', true)) {
                    return $this->sanitizeHtml((string) $inputValue);
                }
                return (string) $inputValue;

            case 'image':
                // 存储相对路径
                if (is_array($inputValue) && isset($inputValue['url'])) {
                    return $inputValue['url'];
                }
                return (string) $inputValue;

            case 'text':
            default:
                return (string) $inputValue;
        }
    }

    /**
     * HTML 净化
     *
     * 防护顺序：
     *   1. HTMLPurifier（若已安装，最健壮）；
     *   2. DOM 结构化净化（移除危险标签 / 所有 on* 事件属性 / 危险协议）；
     *   3. 正则兜底（仅当 DOM 扩展不可用时使用）。
     *
     * 注意：正则兜底能力有限，生产环境仍建议安装 ezyang/htmlpurifier。
     *
     * @param string $html
     * @return string
     */
    protected function sanitizeHtml(string $html): string
    {
        // 如果 HTMLPurifier 可用，优先使用
        if (class_exists('\HTMLPurifier') && class_exists('\HTMLPurifier_Config')) {
            try {
                $config = \HTMLPurifier_Config::createDefault();
                $config->set('Cache.SerializerPath', app()->getRuntimePath() . 'htmlpurifier');
                $purifier = new \HTMLPurifier($config);
                return $purifier->purify($html);
            } catch (\Throwable $e) {
                $this->logSanitizeWarning('HTMLPurifier failed, falling back to basic filter: ' . $e->getMessage());
            }
        }

        // 移除 NUL 字节，避免控制字符绕过
        $html = str_replace("\0", '', $html);

        // 优先使用 DOM 做结构化净化（可正确处理 `/` 属性分隔符与 HTML 实体编码）
        if (class_exists('\DOMDocument')) {
            $sanitized = $this->sanitizeHtmlWithDom($html);
            if ($sanitized !== null) {
                return $sanitized;
            }
        }

        // 最终降级：正则过滤（能力有限，仅作兜底）
        return $this->sanitizeHtmlWithRegex($html);
    }

    /**
     * 基于 DOM 的结构化 HTML 净化
     *
     * 移除危险标签、所有 on* 事件属性，以及 javascript:/vbscript:/data:text 等危险协议。
     * 解析失败时返回 null，由调用方降级为正则过滤。
     *
     * @param string $html
     * @return string|null
     */
    protected function sanitizeHtmlWithDom(string $html): ?string
    {
        $dangerousTags = [
            'script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select',
            'svg', 'math', 'base', 'meta', 'link', 'style', 'applet', 'frame', 'frameset',
            'template', 'noscript',
        ];

        try {
            $previous = libxml_use_internal_errors(true);

            $dom = new \DOMDocument('1.0', 'UTF-8');
            $wrapped = '<?xml encoding="UTF-8"><div id="__wise_root__">' . $html . '</div>';
            $loaded  = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (!$loaded) {
                return null;
            }

            $xpath = new \DOMXPath($dom);

            // 1. 整体移除危险标签
            foreach ($dangerousTags as $tag) {
                $nodes = $xpath->query('//' . $tag);
                if ($nodes === false) {
                    continue;
                }
                for ($i = $nodes->length - 1; $i >= 0; $i--) {
                    $node = $nodes->item($i);
                    if ($node !== null && $node->parentNode !== null) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }

            // 2. 清理事件属性与危险协议
            $elements = $xpath->query('//*');
            if ($elements !== false) {
                foreach ($elements as $element) {
                    if (!$element instanceof \DOMElement) {
                        continue;
                    }

                    $attributeNames = [];
                    foreach ($element->attributes as $attr) {
                        $attributeNames[] = $attr->name;
                    }

                    foreach ($attributeNames as $name) {
                        $lower = strtolower($name);
                        $value = (string) $element->getAttribute($name);

                        // 所有内联事件处理器
                        if (str_starts_with($lower, 'on')) {
                            $element->removeAttribute($name);
                            continue;
                        }

                        // 危险 URL 协议
                        if (in_array($lower, ['href', 'src', 'xlink:href', 'action', 'formaction'], true)
                            && $this->hasDangerousProtocol($value)) {
                            $element->removeAttribute($name);
                            continue;
                        }

                        // style 中的 expression()/javascript: 注入
                        if ($lower === 'style' && preg_match('/expression\s*\(|javascript\s*:/i', $value)) {
                            $element->removeAttribute($name);
                        }
                    }
                }
            }

            // 3. 取回 root 内部 HTML
            $rootNodes = $xpath->query('//div[@id="__wise_root__"]');
            $root = $rootNodes !== false ? $rootNodes->item(0) : null;
            if ($root === null) {
                return null;
            }

            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= (string) $dom->saveHTML($child);
            }

            return $output;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 正则兜底净化（DOM 扩展不可用时使用）
     *
     * 已针对 `/` 属性分隔符与 HTML 实体编码做补强，但仍不保证覆盖所有绕过手法。
     *
     * @param string $html
     * @return string
     */
    protected function sanitizeHtmlWithRegex(string $html): string
    {
        $tags = 'script|iframe|object|embed|form|svg|math|base|meta|link|style|applet|frame|frameset|noscript|input|button|textarea|select';

        // 成对标签整体移除
        $html = preg_replace('#<(' . $tags . ')\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        // 自闭合 / 未闭合标签移除
        $html = preg_replace('#<(' . $tags . ')\b[^>]*/?>#is', '', $html) ?? $html;
        // 事件属性：`/`、空白、引号、控制符均可作为分隔符
        $html = preg_replace('#(?<=[\s/"\'`\x00-\x1f])on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html) ?? $html;
        // 危险协议（先实体解码再判断）
        $html = preg_replace_callback(
            '#\b(href|src|xlink:href|action|formaction)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#is',
            function (array $m): string {
                $raw = $m[3] ?? '';
                if ($raw === '') {
                    $raw = $m[4] ?? '';
                }
                if ($raw === '') {
                    $raw = $m[5] ?? '';
                }
                return $this->hasDangerousProtocol($raw) ? '' : $m[0];
            },
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * 判断 URL 是否使用危险协议（先做 HTML 实体解码与空白归一化，防绕过）
     */
    protected function hasDangerousProtocol(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = strtolower($decoded);
        // 去掉控制符与空白（如 java\tscript:）
        $decoded = preg_replace('/[\x00-\x20]+/', '', $decoded) ?? $decoded;

        foreach (['javascript:', 'vbscript:', 'data:text/html', 'data:application/xhtml'] as $protocol) {
            if (str_contains($decoded, $protocol)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录净化降级告警（日志组件不可用时静默）
     */
    protected function logSanitizeWarning(string $message): void
    {
        try {
            ContextualLogger::warning($message);
        } catch (\Throwable) {
            // 忽略日志写入失败
        }
    }

    /**
     * 记录历史版本
     *
     * @param int    $configId
     * @param string $key
     * @param string $oldValue
     * @param string $newValue
     * @param string $namespace
     * @return void
     */
    protected function recordHistory(int $configId, string $key, string $oldValue, string $newValue, string $namespace = 'default'): void
    {
        try {
            $history = new ConfigHistoryModel();
            $history->config_id = $configId;
            $history->key       = $key;
            $history->namespace = $namespace;
            $history->old_value = $oldValue;
            $history->new_value = $newValue;
            $history->user_id   = $this->getCurrentUserId();
            $history->ip        = $this->getClientIp();
            $history->save();
        } catch (\Throwable $e) {
            ContextualLogger::error('WiseConfig history record failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前用户ID
     *
     * 优先通过 AuthManager 获取，回退到 app 层 facade（兼容旧版）。
     *
     * @return int
     */
    protected function getCurrentUserId(): int
    {
        // 优先使用 AuthManager
        if (function_exists('auth_id')) {
            $id = auth_id('admin');
            if ($id !== null) {
                return (int) $id;
            }
        }

        // 回退 app 层 facade（兼容旧系统）
        try {
            if (class_exists('\\app\\admin\\facade\\CurrentAdmin') && method_exists('\\app\\admin\\facade\\CurrentAdmin', 'getAdminId')) {
                return (int) \app\admin\facade\CurrentAdmin::getAdminId();
            }
        } catch (\Throwable) {
            // 忽略
        }
        return 0;
    }

    /**
     * 获取客户端IP
     *
     * @return string
     */
    protected function getClientIp(): string
    {
        try {
            $request = app('request');
            return $request->ip() ?: '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 数组差异对比
     *
     * @param array $old
     * @param array $new
     * @return array
     */
    protected function arrayDiff(array $old, array $new): array
    {
        $diff = [];
        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $k) {
            $vOld = $old[$k] ?? null;
            $vNew = $new[$k] ?? null;

            if ($vOld !== $vNew) {
                $diff[$k] = ['old' => $vOld, 'new' => $vNew];
            }
        }

        return $diff;
    }

    /**
     * 文本差异对比（简单行级 diff）
     *
     * @param string $old
     * @param string $new
     * @return array
     */
    protected function textDiff(string $old, string $new): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);
        $diff = [];

        $maxLines = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $maxLines; $i++) {
            $ol = $oldLines[$i] ?? null;
            $nl = $newLines[$i] ?? null;

            if ($ol !== $nl) {
                $diff[] = [
                    'line'  => $i + 1,
                    'old'   => $ol,
                    'new'   => $nl,
                ];
            }
        }

        return $diff;
    }

    /**
     * 写入缓存
     *
     * @param string $key
     * @param mixed  $value
     */
    protected function cacheForever(string $key, $value): void
    {
        if ($this->cacheTtl > 0) {
            Cache::tag($this->cacheTag)->set($key, $value, $this->cacheTtl);
        } else {
            Cache::tag($this->cacheTag)->set($key, $value);
        }
    }

    /**
     * 缓存空值标记（防穿透，使用独立 TTL）
     *
     * 空值标记使用独立的 cache_null_ttl 配置，
     * 避免因正常缓存 TTL 过期导致缓存穿透保护失效。
     *
     * @param string $key
     * @return void
     */
    protected function cacheNull(string $key): void
    {
        $nullTtl = (int) $this->getPackageConfig('cache_null_ttl', 3600);
        Cache::tag($this->cacheTag)->set($key, '__WISECONFIG_NULL__', $nullTtl);
    }

    /**
     * 判断配置是否未发生实际变化
     *
     * 比较新旧值以及所有可修改属性（type/group/inherit/title/description/sort/status/validation_rules/tags）。
     * 使用修改前的原始属性（oldAttrs）与新的 extra 值比较，而非模型当前属性。
     *
     * @param string|null $oldValue    旧值（编码后的数据库值）
     * @param string      $newValue    新值（编码后的数据库值）
     * @param string|null $oldKey      旧键名（键名变更时非 null）
     * @param array       $oldAttrs    修改前的原始属性（从数据库读取的旧值）
     * @param array       $extra       要写入的新属性
     * @param array       $tags        标签
     * @return bool  true 表示无变化可跳过
     */
    protected function isConfigUnchanged(?string $oldValue, string $newValue, ?string $oldKey, array $oldAttrs, array $extra, array $tags): bool
    {
        // 键名变更时，即使值相同也需要更新
        if ($oldKey !== null) {
            return false;
        }

        // 值变化检测
        if ($oldValue !== $newValue) {
            return false;
        }

        // 定义需要比较的字段及其从 extra 中提取值的方式
        // [字段名 => 是否使用 array_key_exists 判断（否则用 isset）]
        $fieldsToCheck = [
            'type'             => 'isset',
            'group'            => 'isset',
            'inherit'          => 'isset',
            'title'            => 'array_key_exists',
            'description'      => 'array_key_exists',
            'sort'             => 'isset',
            'status'           => 'isset',
            'visibility'       => 'isset',
            'validation_rules' => 'isset',
        ];

        foreach ($fieldsToCheck as $field => $checkType) {
            if ($checkType === 'array_key_exists') {
                if (!array_key_exists($field, $extra)) continue;
                $newFieldValue = $extra[$field] ?? '';
            } else {
                if (!isset($extra[$field])) continue;
                $newFieldValue = $extra[$field];
            }

            // 类型转换对齐
            $oldFieldValue = $oldAttrs[$field] ?? null;
            if (in_array($field, ['inherit', 'sort', 'status', 'visibility'], true)) {
                $oldFieldValue = (int) $oldFieldValue;
                $newFieldValue = (int) $newFieldValue;
            }

            if ($oldFieldValue !== $newFieldValue) {
                return false;
            }
        }

        // 检查标签变化
        if ($this->tagManager && !empty($tags)) {
            // 需要从数据库获取当前标签进行比较
            // 注意：此处 config 可能尚未保存，但 id 已确定
            $configId = $this->getConfigIdForTagCheck($oldAttrs);
            if ($configId) {
                $currentTags = $this->tagManager->getTags($configId);
                sort($currentTags);
                $newTags = $tags;
                sort($newTags);
                if ($currentTags !== $newTags) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 获取配置 ID 用于标签比较
     *
     * @param array $oldAttrs 旧属性（预留扩展，当前通过 lastConfigId 传递）
     * @return int|null
     */
    protected function getConfigIdForTagCheck(array $oldAttrs): ?int
    {
        unset($oldAttrs);  // 当前通过 lastConfigId 传递，保留参数供子类扩展
        return $this->lastConfigId ?? null;
    }

    /**
     * 获取上传文件完整 URL
     *
     * @param string $path
     * @return string
     */
    protected function getUploadUrl(string $path): string
    {
        try {
            $disk = $this->getPackageConfig('upload_disk', 'public');
            if (function_exists('config')) {
                $filesystemConfig = config('filesystem.disks.' . $disk);
            } else {
                return $path;
            }

            if ($filesystemConfig && ($filesystemConfig['type'] ?? '') === 'local') {
                $domain = $filesystemConfig['url'] ?? '';
                $domain = rtrim($domain, '/');
                return $domain . '/' . ltrim($path, '/');
            }
        } catch (\Throwable) {
            // 忽略
        }

        return $path;
    }
}
