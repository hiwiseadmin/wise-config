<?php
declare(strict_types=1);

namespace wise\config\model;

use think\Model;
use think\db\Query;

/**
 * 字典项模型
 *
 * 对应数据表 dict_item，提供字典项的 ORM 映射。
 * 支持自动时间戳、搜索查询、状态获取器及父级标签获取器。
 *
 * @property int    $id         主键ID
 * @property string $dict_code  关联字典编码
 * @property string $item_value 存储值
 * @property string $item_label 显示标签
 * @property int    $parent_id  父级ID
 * @property int    $sort       排序号
 * @property int    $status     状态
 * @property string $remark     备注
 * @property string $create_time 创建时间
 * @property string $update_time 更新时间
 */
class DictItem extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'dict_item';

    /**
     * 主键
     *
     * @var string
     */
    protected $pk = 'id';

    /**
     * 字段白名单
     *
     * @var array
     */
    protected $field = [
        'id',
        'dict_code',
        'item_value',
        'item_label',
        'parent_id',
        'sort',
        'status',
        'remark',
        'create_time',
        'update_time',
    ];

    /**
     * 自动写入时间戳
     *
     * @var bool|string
     */
    protected $autoWriteTimestamp = true;

    /**
     * 创建时间字段名
     *
     * @var string
     */
    protected $createTime = 'create_time';

    /**
     * 更新时间字段名
     *
     * @var string
     */
    protected $updateTime = 'update_time';

    /**
     * 状态文本映射
     *
     * @var array<int, string>
     */
    protected static array $statusMap = [
        0 => '禁用',
        1 => '启用',
    ];

    /**
     * 搜索查询构建器
     *
     * 支持按 dict_code、item_value、item_label、status 进行筛选，
     * 按 sort 升序、id 降序排列。
     *
     * @param array $params 搜索参数
     *   - dict_code:  string 字典编码（精确匹配）
     *   - item_value: string 存储值（模糊搜索）
     *   - item_label: string 显示标签（模糊搜索）
     *   - status:     int    状态（精确匹配）
     * @return Query
     */
    public function search(array $params = []): Query
    {
        $query = $this->newQuery();

        if (!empty($params['dict_code'])) {
            $query->where('dict_code', '=', $params['dict_code']);
        }
        if (!empty($params['item_value'])) {
            $escaped = addcslashes((string) $params['item_value'], '%_\\');
            $query->where('item_value', 'like', "%{$escaped}%");
        }
        if (!empty($params['item_label'])) {
            $escaped = addcslashes((string) $params['item_label'], '%_\\');
            $query->where('item_label', 'like', "%{$escaped}%");
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', '=', intval($params['status']));
        }

        $query->order('sort', 'asc')->order('id', 'desc');

        return $query;
    }

    /**
     * 状态文本获取器
     *
     * 将 status 字段值转换为可读文本。
     *
     * @param mixed $value 状态值
     * @param array $data  完整行数据
     * @return string 状态文本
     */
    public function getStatusTextAttr($value, array $data): string
    {
        $status = intval($data['status'] ?? 0);
        return self::$statusMap[$status] ?? '未知';
    }

    /**
     * 父级标签缓存（请求级）
     *
     * 避免列表场景中 N+1 查询问题。
     * 键格式："{dict_code}:{parent_id}"，值为 item_label 字符串或 null。
     *
     * @var array<string, string|null>
     */
    private const MAX_CACHE_SIZE = 500;

    private static array $parentLabelCache = [];

    /**
     * 父级标签获取器
     *
     * 根据 parent_id 查询父级字典项的 item_label。
     * 使用请求级静态缓存避免重复查询（解决 N+1 问题）。
     * parent_id 为 0 时返回 "顶级"。
     *
     * @param mixed $value parent_id 字段值
     * @param array $data  完整行数据
     * @return string 父级标签
     */
    public function getParentLabelAttr($value, array $data): string
    {
        $parentId = intval($data['parent_id'] ?? 0);
        if ($parentId === 0) {
            return '顶级';
        }

        $dictCode = $data['dict_code'] ?? '';
        $cacheKey = "{$dictCode}:{$parentId}";

        // 请求级缓存：避免同一 parent 被重复查询
        if (array_key_exists($cacheKey, self::$parentLabelCache)) {
            return self::$parentLabelCache[$cacheKey] ?? '未知';
        }

        $parent = self::where('id', $parentId)
            ->where('dict_code', $dictCode)
            ->find();

        $label = $parent !== null ? $parent->item_label : null;
        if (count(self::$parentLabelCache) >= self::MAX_CACHE_SIZE) {
            // 超过容量阈值时清空缓存，避免常驻进程内存泄漏
            self::$parentLabelCache = [];
        }
        self::$parentLabelCache[$cacheKey] = $label;

        return $label ?? '未知';
    }

    /**
     * 清除父级标签缓存（主要用于单元测试）
     *
     * @return void
     */
    public static function clearParentLabelCache(): void
    {
        self::$parentLabelCache = [];
    }

    /**
     * 获取状态映射
     *
     * @return array<int, string>
     */
    public static function getStatusMap(): array
    {
        return self::$statusMap;
    }
}
