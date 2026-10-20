<?php
declare(strict_types=1);

namespace wise\config\model;

use think\Model;
use think\db\Query;

/**
 * 配置标签关联模型
 *
 * 对应数据表 config_tags，记录配置记录的标签关联信息。
 * 每条记录关联一个配置 ID 和标签值，配置与标签之间为多对多关系。
 *
 * @property int    $id         主键ID
 * @property int    $config_id  配置记录ID
 * @property string $tag        标签值
 * @property string $namespace  命名空间（冗余）
 * @property string $create_time 创建时间
 */
class ConfigTagModel extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'config_tag';

    /**
     * 主键
     *
     * @var string
     */
    protected $pk = 'id';

    /**
     * 自动写入时间戳（仅 create_time）
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
     * 关闭更新时间
     *
     * @var mixed
     */
    protected $updateTime = false;

    /**
     * 字段白名单
     *
     * @var array
     */
    protected $field = [
        'id',
        'config_id',
        'tag',
        'namespace',
        'create_time',
    ];

    /**
     * 允许批量赋值的字段
     *
     * @var array
     */
    protected $fillable = [
        'config_id',
        'tag',
        'namespace',
    ];

    /**
     * 关联配置模型
     *
     * @return \think\model\relation\BelongsTo
     */
    public function config()
    {
        return $this->belongsTo(ConfigModel::class, 'config_id', 'id');
    }

    /**
     * 搜索查询构建器
     *
     * 支持按 config_id、tag、namespace 进行筛选。
     *
     * @param array $params 搜索参数
     *   - config_id: int    配置ID（精确匹配）
     *   - tag:       string 标签值（精确匹配）
     *   - namespace: string 命名空间（精确匹配）
     * @return Query
     */
    public function search(array $params = []): Query
    {
        $query = $this->newQuery();

        if (!empty($params['config_id'])) {
            $query->where('config_id', '=', intval($params['config_id']));
        }

        if (!empty($params['tag'])) {
            $query->where('tag', '=', $params['tag']);
        }

        if (!empty($params['namespace'])) {
            $query->where('namespace', '=', $params['namespace']);
        }

        $query->order('id', 'desc');

        return $query;
    }
}
