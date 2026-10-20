<?php
declare(strict_types=1);

namespace wise\config\model;

use think\Model;
use think\db\Query;

/**
 * 系统配置模型
 *
 * 对应数据表 config，提供键值对配置的 ORM 映射。
 * 支持多种数据类型（text, number, html, image, json）。
 *
 * @property int    $id               主键ID
 * @property string $key              配置键名
 * @property string $value            配置值（文本存储）
 * @property string $type             数据类型
 * @property string $group            配置分组
 * @property string $title            配置项标题
 * @property string $description      配置项描述
 * @property string $validation_rules 验证规则（JSON）
 * @property string $namespace        命名空间
 * @property array  $tags             标签冗余快照（JSON）
 * @property int    $inherit          是否允许子级继承
 * @property int    $sort             排序号
 * @property int    $status           状态
 * @property int    $visibility       可见性: 1=公开(系统配置页展示) 0=私有(不展示)
 * @property int    $create_time       创建时间
 * @property int    $update_time       更新时间
 */
class ConfigModel extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'config';

    /**
     * 主键
     *
     * @var string
     */
    protected $pk = 'id';

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
     * 字段白名单
     *
     * @var array
     */
    protected $field = [
        'id',
        'key',
        'value',
        'type',
        'group',
        'title',
        'description',
        'validation_rules',
        'namespace',
        'tags',
        'inherit',
        'sort',
        'status',
        'visibility',
        'create_time',
        'update_time',
    ];

    /**
     * JSON 类型转换
     *
     * @var array
     */
    protected $json = ['validation_rules', 'tags'];

    /**
     * JSON 字段取出时转换为关联数组
     *
     * 缺省情况下 TP 会把 JSON 字段强制转为 stdClass，json_encode 后是对象而非数组，
     * 与 @property array $tags 契约不符（前端会拿到 {"0":"seo"} 而不是 ["seo"]）。
     *
     * @var bool
     */
    protected $jsonAssoc = true;

    /**
     * 允许批量赋值的字段
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'title',
        'description',
        'validation_rules',
        'namespace',
        'tags',
        'inherit',
        'sort',
        'status',
        'visibility',
    ];

    /**
     * 搜索查询构建器
     *
     * 支持按 group、key、title、type、status、namespace 进行筛选和搜索。
     *
     * @param array $params 搜索参数
     *   - group:     string 分组（精确匹配）
     *   - key:       string 配置键名（精确匹配）
     *   - keyword:   string 关键词（模糊搜索 key/title）
     *   - type:      string 类型（精确匹配）
     *   - status:    int    状态（精确匹配）
     *   - namespace: string 命名空间（精确匹配）
     * @return Query
     */
    public function search(array $params = []): Query
    {
        $query = $this->newQuery();

        if (!empty($params['namespace'])) {
            $query->where('namespace', '=', $params['namespace']);
        }

        if (!empty($params['group'])) {
            $query->where('group', '=', $params['group']);
        }

        if (!empty($params['key'])) {
            $query->where('key', '=', $params['key']);
        }

        if (!empty($params['keyword'])) {
            $keyword = trim($params['keyword']);
            $escaped = addcslashes($keyword, '%_\\');
            $query->where(function ($q) use ($escaped) {
                $q->where('key', 'like', "%{$escaped}%")
                  ->whereOr('title', 'like', "%{$escaped}%");
            });
        }

        if (!empty($params['type'])) {
            $query->where('type', '=', $params['type']);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', '=', intval($params['status']));
        }

        $query->order('sort', 'asc')->order('group', 'asc')->order('id', 'desc');

        return $query;
    }
}
