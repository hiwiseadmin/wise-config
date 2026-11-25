<?php
declare(strict_types=1);

namespace wise\config\model;

use think\Model;
use think\db\Query;

/**
 * 配置变更历史模型
 *
 * 对应数据表 config_history，记录每次配置变更的详情。
 *
 * @property int    $id         主键ID
 * @property int    $config_id  关联配置ID
 * @property string $key        配置键名
 * @property string $old_value  旧值
 * @property string $new_value  新值
 * @property int    $user_id    操作用户ID
 * @property string $ip         请求IP
 * @property string $namespace  命名空间
 * @property int    $create_time 操作时间
 */
class ConfigHistoryModel extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'config_history';

    /**
     * 主键
     *
     * @var string
     */
    protected $pk = 'id';

    /**
     * 关闭自动时间戳（仅使用 create_time）
     *
     * @var bool
     */
    protected $autoWriteTimestamp = true;

    /**
     * 创建时间字段
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
        'key',
        'old_value',
        'new_value',
        'user_id',
        'ip',
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
        'key',
        'old_value',
        'new_value',
        'user_id',
        'ip',
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
     * @param array $params
     *   - config_id:  int    配置ID
     *   - key:        string 配置键名
     *   - user_id:    int    用户ID
     *   - namespace:  string 命名空间
     *   - start_time: string 开始时间
     *   - end_time:   string 结束时间
     * @return Query
     */
    public function search(array $params = []): Query
    {
        $query = $this->newQuery();

        if (!empty($params['config_id'])) {
            $query->where('config_id', '=', intval($params['config_id']));
        }

        if (!empty($params['key'])) {
            $query->where('key', '=', $params['key']);
        }

        if (!empty($params['user_id'])) {
            $query->where('user_id', '=', intval($params['user_id']));
        }

        if (!empty($params['namespace'])) {
            $query->where('namespace', '=', $params['namespace']);
        }

        if (!empty($params['start_time'])) {
            $query->where('create_time', '>=', $params['start_time']);
        }

        if (!empty($params['end_time'])) {
            $query->where('create_time', '<=', $params['end_time']);
        }

        $query->order('id', 'desc');

        return $query;
    }
}
