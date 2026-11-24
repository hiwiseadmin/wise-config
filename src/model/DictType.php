<?php
declare(strict_types=1);

namespace wise\config\model;

use think\Model;
use think\db\Query;

/**
 * 字典类型模型
 *
 * 对应数据表 dict_type，提供字典类型的 ORM 映射。
 * 支持自动时间戳、字段只读保护、搜索查询及状态获取器。
 *
 * @property int    $id          主键ID
 * @property string $dict_code   字典编码
 * @property string $name        字典名称
 * @property string $description 描述
 * @property int    $is_tree     是否树形字典
 * @property int    $status      状态
 * @property string $create_time  创建时间
 * @property string $update_time  更新时间
 */
class DictType extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'dict_type';

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
        'name',
        'description',
        'is_tree',
        'status',
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
     * 只读字段（禁止修改 dict_code）
     *
     * @var array
     */
    protected $readonly = ['dict_code'];

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
     * 支持按 dict_code、name、status 进行筛选，
     * 按 id 降序排列。
     *
     * @param array $params 搜索参数
     *   - dict_code: string 字典编码（模糊搜索）
     *   - name:      string 字典名称（模糊搜索）
     *   - status:    int    状态（精确匹配）
     * @return Query
     */
    public function search(array $params = []): Query
    {
        $query = $this->newQuery();

        if (!empty($params['dict_code'])) {
            $escaped = addcslashes((string) $params['dict_code'], '%_\\');
            $query->where('dict_code', 'like', "%{$escaped}%");
        }
        if (!empty($params['name'])) {
            $escaped = addcslashes((string) $params['name'], '%_\\');
            $query->where('name', 'like', "%{$escaped}%");
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', '=', intval($params['status']));
        }

        $query->order('id', 'desc');

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
     * 获取状态映射
     *
     * @return array<int, string>
     */
    public static function getStatusMap(): array
    {
        return self::$statusMap;
    }
}
