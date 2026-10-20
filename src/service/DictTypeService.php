<?php
declare(strict_types=1);

namespace wise\config\service;


use wise\support\context\ContextualLogger;
use wise\config\cache\DictCacheManager;
use wise\config\event\DictChangedEvent;
use wise\config\exception\DictException;
use wise\config\model\DictType;
use wise\config\model\DictItem;
use think\facade\Event;

/**
 * 字典类型 CRUD 服务
 *
 * 提供字典类型的增删改查、列表分页、状态切换等操作。
 * 每个写操作完成后自动清理缓存并触发 DictChangedEvent 事件。
 */
class DictTypeService
{
    /**
     * 缓存管理器
     *
     * @var DictCacheManager
     */
    protected DictCacheManager $cacheManager;

    /**
     * 构造函数
     *
     * @param DictCacheManager $cacheManager 缓存管理器实例
     */
    public function __construct(DictCacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * 根据字典编码获取字典类型
     *
     * 优先从缓存读取，缓存未命中时查询数据库并写入缓存。
     *
     * @param string $dictCode 字典编码
     * @return DictType|null 字典类型模型，不存在时返回 null
     */
    public function getType(string $dictCode): ?DictType
    {
        // 尝试从缓存获取
        $cached = $this->cacheManager->getTypeCache($dictCode);
        if ($cached !== null) {
            return $cached;
        }

        // 查询数据库
        $type = DictType::where('dict_code', $dictCode)->find();
        if ($type !== null) {
            $this->cacheManager->setTypeCache($dictCode, $type);
        }

        return $type;
    }

    /**
     * 创建字典类型
     *
     * @param array $data 字典类型数据
     *   - dict_code:   string (必填) 字典编码
     *   - name:        string (必填) 字典名称
     *   - description: string (可选) 描述
     *   - is_tree:     int    (可选) 是否树形字典，默认 0
     *   - status:      int    (可选) 状态，默认 1
     * @return DictType 创建成功的模型实例
     * @throws DictException 编码重复时抛出异常
     */
    public function createType(array $data): DictType
    {
        // 检查编码是否重复
        $exists = DictType::where('dict_code', $data['dict_code'])->find();
        if ($exists !== null) {
            throw DictException::duplicate($data['dict_code']);
        }

        $type = DictType::create([
            'dict_code'   => $data['dict_code'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? '',
            'is_tree'     => $data['is_tree'] ?? 0,
            'status'      => $data['status'] ?? 1,
        ]);

        $this->cacheManager->clearCache($data['dict_code']);
        $this->fireEvent($data['dict_code'], 'create', $type);

        return $type;
    }

    /**
     * 更新字典类型
     *
     * dict_code 字段为只读，不可通过此方法修改。
     *
     * @param string $dictCode 字典编码
     * @param array  $data     更新数据
     *   - name:        string (可选) 字典名称
     *   - description: string (可选) 描述
     *   - is_tree:     int    (可选) 是否树形字典
     *   - status:      int    (可选) 状态
     * @return DictType 更新后的模型实例
     * @throws DictException 字典不存在时抛出异常
     */
    public function updateType(string $dictCode, array $data): DictType
    {
        $type = DictType::where('dict_code', $dictCode)->find();
        if ($type === null) {
            throw DictException::notFound($dictCode);
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }
        if (isset($data['is_tree'])) {
            $updateData['is_tree'] = intval($data['is_tree']);
        }
        if (isset($data['status'])) {
            $updateData['status'] = intval($data['status']);
        }

        if (!empty($updateData)) {
            $type->save($updateData);
        }

        $this->cacheManager->clearCache($dictCode);
        $this->fireEvent($dictCode, 'update', $type);

        return $type;
    }

    /**
     * 删除字典类型
     *
     * 在事务中级联删除该字典类型下的所有字典项，并清除缓存。
     *
     * @param string $dictCode 字典编码
     * @return bool 是否删除成功
     * @throws DictException 字典不存在时抛出异常
     */
    public function deleteType(string $dictCode): bool
    {
        $type = DictType::where('dict_code', $dictCode)->find();
        if ($type === null) {
            throw DictException::notFound($dictCode);
        }

        // 事务级联删除
        DictType::startTrans();
        try {
            // 删除关联的字典项
            DictItem::where('dict_code', $dictCode)->delete();
            // 删除字典类型
            $type->delete();
            DictType::commit();
        } catch (\Throwable $e) {
            DictType::rollback();
            throw $e;
        }

        $this->cacheManager->clearCache($dictCode);
        $this->fireEvent($dictCode, 'delete', null);

        return true;
    }

    /**
     * 获取字典类型分页列表
     *
     * @param array $params 搜索参数
     *   - dict_code: string (可选) 字典编码（模糊搜索）
     *   - name:      string (可选) 字典名称（模糊搜索）
     *   - status:    int    (可选) 状态（精确匹配）
     *   - page:      int    (可选) 页码，默认 1
     *   - limit:     int    (可选) 每页数量，默认 15
     * @return \think\Paginator 分页结果
     */
    public function getList(array $params = []): \think\Paginator
    {
        $page = intval($params['page'] ?? 1);
        $limit = intval($params['limit'] ?? 15);

        $query = (new DictType())->search($params);

        return $query->paginate([
            'list_rows' => $limit,
            'page'      => $page,
        ]);
    }

    /**
     * 切换字典类型状态
     *
     * 在启用(1)和禁用(0)之间切换。
     *
     * @param string $dictCode 字典编码
     * @return DictType 更新后的模型实例
     * @throws DictException 字典不存在时抛出异常
     */
    public function toggleStatus(string $dictCode): DictType
    {
        $type = DictType::where('dict_code', $dictCode)->find();
        if ($type === null) {
            throw DictException::notFound($dictCode);
        }

        $newStatus = $type->status === 1 ? 0 : 1;
        $type->save(['status' => $newStatus]);

        $this->cacheManager->clearCache($dictCode);
        $this->fireEvent($dictCode, 'update', $type);

        return $type;
    }

    /**
     * 触发字典变更事件
     *
     * @param string     $dictCode 字典编码
     * @param string     $action   操作类型
     * @param mixed|null $data     变更数据
     * @return void
     */
    protected function fireEvent(string $dictCode, string $action, $data = null): void
    {
        try {
            $event = new DictChangedEvent($dictCode, $action, $data);
            Event::trigger($event);
        } catch (\Throwable $e) {
            // 事件触发失败不影响主流程，记录日志
            try {
                ContextualLogger::warning('DictChangedEvent trigger failed: ' . $e->getMessage());
            } catch (\Throwable) {
                // 忽略日志写入失败
            }
        }
    }
}
