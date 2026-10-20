<?php
declare(strict_types=1);

namespace wise\config\manager;

use think\facade\Db;
use think\facade\Event;
use wise\support\context\ContextualLogger;
use wise\config\model\ConfigModel;
use wise\config\model\ConfigTagModel;
use wise\config\event\TagChanged;

/**
 * 标签管理器
 *
 * 负责 config_tags 关联表与 config.tags JSON 冗余字段的双写一致性。
 *
 * 设计原则：
 *   - config_tags 关联表是权威数据源（通过 ConfigTagModel 操作）
 *   - config.tags JSON 字段作为冗余快照，方便快速展示
 *   - 所有写操作使用数据库事务保证双写一致性
 *
 * @example
 *   $tagManager = app('wise.config.tag_manager');
 *   $tagManager->attach(1, ['seo', 'meta']);
 *   $tags = $tagManager->getTags(1);
 *   $configs = $tagManager->findByTag('seo');
 */
class TagManager
{
    /**
     * 配置标签模型实例
     *
     * @var ConfigTagModel
     */
    protected $tagModel;

    /**
     * 构造方法
     *
     * @param ConfigTagModel $tagModel
     */
    public function __construct(ConfigTagModel $tagModel)
    {
        $this->tagModel = $tagModel;
    }

    /**
     * 为配置附加标签
     *
     * 只添加尚未关联的标签，已存在的标签会被跳过。
     * 使用数据库事务确保关联表和冗余字段的一致性。
     *
     * @param int   $configId 配置记录ID
     * @param array $tags     标签数组，如 ['seo', 'meta']
     * @return bool
     */
    public function attach(int $configId, array $tags): bool
    {
        if (empty($tags)) {
            return false;
        }

        $addedTags = [];

        $ok = Db::transaction(function () use ($configId, $tags, &$addedTags) {
            // 获取配置的 namespace（用于冗余写入 config_tags 表）
            $config = ConfigModel::find($configId);
            if (!$config) {
                return false;
            }
            $namespace = $config->namespace;

            // 1. 找出已存在的标签（避免重复插入）
            $existingTags = ConfigTagModel::where('config_id', $configId)
                ->column('tag');

            $newTags = array_values(array_diff($tags, $existingTags));
            if (empty($newTags)) {
                // 没有新标签，仍然同步一次 JSON 字段以确保一致性
                $this->syncTagsField($configId);
                return true;
            }

            // 2. 批量写入 ConfigTagModel
            $insertData = [];
            foreach ($newTags as $tag) {
                $insertData[] = [
                    'config_id' => $configId,
                    'tag'       => $tag,
                    'namespace' => $namespace,
                ];
            }
            $result = $this->tagModel->saveAll($insertData);
            if ($result === false) {
                return false;
            }
            if (is_countable($result) && count($result) !== count($newTags)) {
                return false;
            }

            // 3-4. 收集所有标签并同步 ConfigModel.tags JSON 字段
            $this->syncTagsField($configId);

            $addedTags = $newTags;

            return true;
        });

        if ($ok) {
            foreach ($addedTags as $tag) {
                $this->fireTagChanged($configId, 'add', $tag);
            }
        }

        return $ok;
    }

    /**
     * 移除配置的标签
     *
     * 使用数据库事务确保关联表和冗余字段的一致性。
     *
     * @param int   $configId 配置记录ID
     * @param array $tags     要移除的标签数组
     * @return bool
     */
    public function detach(int $configId, array $tags): bool
    {
        if (empty($tags)) {
            return false;
        }

        $ok = Db::transaction(function () use ($configId, $tags) {
            // 1. 删除指定的 ConfigTagModel 记录
            ConfigTagModel::where('config_id', $configId)
                ->whereIn('tag', $tags)
                ->delete();

            // 2-3. 重新收集剩余标签并同步 ConfigModel.tags JSON 字段
            $this->syncTagsField($configId);

            return true;
        });

        if ($ok) {
            foreach ($tags as $tag) {
                $this->fireTagChanged($configId, 'remove', (string) $tag);
            }
        }

        return $ok;
    }

    /**
     * 同步标签（全量替换）
     *
     * 先删除所有旧标签，再写入新标签。适用于标签完全变更的场景。
     * 使用数据库事务确保关联表和冗余字段的一致性。
     *
     * @param int   $configId 配置记录ID
     * @param array $tags     新的标签数组
     * @return bool
     */
    public function sync(int $configId, array $tags): bool
    {
        // 去重，避免唯一索引 (config_id, tag) 冲突
        $tags = array_values(array_unique(array_map('strval', $tags)));

        $ok = Db::transaction(function () use ($configId, $tags) {
            // 获取配置的 namespace
            $config = ConfigModel::find($configId);
            if (!$config) {
                return false;
            }
            $namespace = $config->namespace;

            // 1. 先删除所有旧标签
            ConfigTagModel::where('config_id', $configId)->delete();

            // 2. 再批量写入新标签
            if (!empty($tags)) {
                $insertData = [];
                foreach ($tags as $tag) {
                    $insertData[] = [
                        'config_id' => $configId,
                        'tag'       => $tag,
                        'namespace' => $namespace,
                    ];
                }
                $result = $this->tagModel->saveAll($insertData);
                if ($result === false) {
                    return false;
                }
            }

            // 3. 同步 ConfigModel.tags JSON 字段
            $this->syncTagsField($configId);

            return true;
        });

        if ($ok) {
            $this->fireTagChanged($configId, 'sync', '');
        }

        return $ok;
    }

    /**
     * 批量设置多个配置的标签（set() 调用时使用）
     *
     * 如果传入空数组，不做任何操作并返回 true。
     *
     * @param int   $configId 配置记录ID
     * @param array $tags     空数组表示不设置标签
     * @return bool
     */
    public function setConfigTags(int $configId, array $tags = []): bool
    {
        if (empty($tags)) {
            return true; // 不设置标签
        }
        return $this->sync($configId, $tags);
    }

    /**
     * 获取配置的所有标签
     *
     * 始终从 ConfigTagModel（权威数据源）读取，不读取 ConfigModel.tags JSON 字段。
     *
     * @param int $configId 配置记录ID
     * @return array 标签数组
     */
    public function getTags(int $configId): array
    {
        return ConfigTagModel::where('config_id', $configId)
            ->column('tag');
    }

    /**
     * 按标签查找配置（在当前 namespace 内）
     *
     * 查询拥有指定标签且状态为启用的配置记录。
     *
     * @param string      $tag       标签名
     * @param string|null $namespace 命名空间，null 表示不限制 namespace
     * @return array 配置记录数组
     */
    public function findByTag(string $tag, ?string $namespace = null): array
    {
        $query = ConfigTagModel::where('tag', $tag);

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        $configIds = $query->column('config_id');
        if (empty($configIds)) {
            return [];
        }

        return ConfigModel::whereIn('id', $configIds)
            ->where('status', 1)
            ->select()
            ->toArray();
    }

    /**
     * 按多个标签查找配置
     *
     * 支持 AND/OR 两种逻辑：
     *   - AND：config_id 必须同时拥有所有指定标签
     *   - OR：config_id 拥有任一指定标签即可
     *
     * 标签数量受 config('wise-config.tag.max_per_config', 10) 限制。
     *
     * @param array       $tags      标签数组
     * @param string|null $namespace 命名空间，null 表示不限制
     * @param string      $logic     'and' | 'or'，默认 'and'
     * @return array 配置记录数组
     */
    public function findByTags(array $tags, ?string $namespace = null, string $logic = 'and'): array
    {
        if (empty($tags)) {
            return [];
        }

        // 去重后再计数，避免重复标签导致 AND 计数失真
        $tags = array_values(array_unique(array_map('strval', $tags)));

        $maxPerConfig = (int) config('wise-config.tag.max_per_config', 10);

        if (count($tags) > $maxPerConfig) {
            // 超过限制，截断
            $tags = array_slice($tags, 0, $maxPerConfig);
        }

        $query = ConfigTagModel::whereIn('tag', $tags);
        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        // 关联表 (config_id, tag) 唯一，因此在 PHP 侧统计每个配置命中的标签集合，
        // 避免依赖 having + 命名参数绑定（跨驱动行为不一致）：
        //   AND → 命中标签数 >= 请求标签数；OR → 命中任一即可
        $rows = $query->field('config_id, tag')->select()->toArray();
        if (empty($rows)) {
            return [];
        }

        $matched = [];
        foreach ($rows as $row) {
            $configId = (int) ($row['config_id'] ?? 0);
            if ($configId > 0) {
                $matched[$configId][(string) $row['tag']] = true;
            }
        }

        if ($logic === 'and') {
            $expected = count($tags);
            $configIds = array_keys(array_filter(
                $matched,
                static fn (array $tagSet): bool => count($tagSet) >= $expected
            ));
        } else {
            $configIds = array_keys($matched);
        }

        if (empty($configIds)) {
            return [];
        }

        return ConfigModel::whereIn('id', $configIds)
            ->where('status', 1)
            ->select()
            ->toArray();
    }

    /**
     * 删除配置时清理其所有标签
     *
     * 供 ConfigManager::delete() 或事件监听器调用，确保删除配置时
     * 关联的标签记录也被同步清理，避免孤儿数据。
     *
     * @param int $configId 配置记录ID
     * @return bool
     */
    public function deleteConfigTags(int $configId): bool
    {
        return (bool)ConfigTagModel::where('config_id', $configId)->delete();
    }

    /**
     * 触发标签变更事件
     *
     * 事件在事务提交后调用，监听器可安全读取已落库的数据；
     * 事件失败不影响主流程，仅记录告警。
     *
     * @param int    $configId 配置记录ID
     * @param string $action   'add' | 'remove' | 'sync'
     * @param string $tag      变更的标签值（sync 时为空字符串）
     * @return void
     */
    protected function fireTagChanged(int $configId, string $action, string $tag = ''): void
    {
        try {
            Event::trigger(new TagChanged($configId, $action, $tag));
        } catch (\Throwable $e) {
            try {
                ContextualLogger::warning('TagChanged trigger failed: ' . $e->getMessage());
            } catch (\Throwable) {
                // 忽略日志写入失败
            }
        }
    }

    /**
     * 同步 ConfigModel.tags JSON 冗余字段（内部方法）
     *
     * 从 config_tags 权威数据源读取所有标签，序列化后直接更新
     * ConfigModel 对应记录的 tags 字段。使用 Db::table() 直接更新，
     * 避免触发 Model 事件循环。
     *
     * @param int $configId 配置记录ID
     * @return void
     */
    /**
     * ConfigModel 表名缓存，避免每次同步时重复实例化
     *
     * @var string|null
     */
    private static ?string $configTableName = null;

    protected function syncTagsField(int $configId): void
    {
        $tags = $this->getTags($configId);
        // 不使用 JSON_THROW_ON_ERROR：本方法在 Db::transaction 内被 attach/detach/sync 调用，
        // 抛出的 JsonException 会穿透到 ConfigManager::set()（此时配置行已落库），
        // 导致历史记录与缓存清理被跳过，并把异常抛给调用方。
        $tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($tagsJson === false) {
            try {
                ContextualLogger::warning('TagManager: json_encode tags failed for config ' . $configId);
            } catch (\Throwable) {
                // 日志组件不可用时静默，冗余字段降级不影响主流程
            }
            return;
        }
        if (self::$configTableName === null) {
            self::$configTableName = (new ConfigModel())->getTable();
        }
        Db::table(self::$configTableName)
            ->where('id', $configId)
            ->update(['tags' => $tagsJson]);
    }
}
