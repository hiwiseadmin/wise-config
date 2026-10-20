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
 * 字典项 CRUD 服务
 *
 * 提供字典项的增删改查、列表分页、批量操作、
 * value=>label 映射、标签查询及树形结构构建等操作。
 * 每个写操作完成后自动清理缓存并触发 DictChangedEvent 事件。
 */
class DictItemService
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
     * 获取指定字典的字典项列表
     *
     * 优先从缓存读取，缓存未命中时查询数据库并写入缓存。
     * 可按状态过滤：
     *   - null：返回所有状态，缓存键 dict:items:{code}
     *   - int： 返回指定状态，缓存键 dict:items:status:{code}:{status}
     *
     * @param string   $dictCode 字典编码
     * @param int|null $status   状态过滤，null 表示不过滤
     * @return array 字典项数组
     */
    public function getItems(string $dictCode, ?int $status = null): array
    {
        // 尝试从缓存获取（先查缓存，避免不必要的 DB 查询）
        if ($status === null) {
            $cached = $this->cacheManager->getItemsCache($dictCode);
        } else {
            $cached = $this->cacheManager->getItemsStatusCache($dictCode, $status);
        }
        if ($cached !== null) {
            return $cached;
        }

        // 验证字典类型是否存在（仅缓存未命中时才查询）
        $type = DictType::where('dict_code', $dictCode)->find();
        if ($type === null) {
            throw DictException::notFound($dictCode);
        }

        $query = DictItem::where('dict_code', $dictCode);
        if ($status !== null) {
            $query->where('status', $status);
        }
        $query->order('sort', 'asc')->order('id', 'asc');

        $items = $query->select()->toArray();

        // 写入缓存
        if ($status === null) {
            $this->cacheManager->setItemsCache($dictCode, $items);
        } else {
            $this->cacheManager->setItemsStatusCache($dictCode, $status, $items);
        }

        return $items;
    }

    /**
     * 获取指定字典的 value=>label 映射
     *
     * 仅返回启用状态的字典项。
     *
     * @param string $dictCode 字典编码
     * @return array value=>label 键值对
     */
    public function getValueLabelMap(string $dictCode): array
    {
        $items = $this->getItems($dictCode, 1);
        $map = [];
        foreach ($items as $item) {
            $map[$item['item_value']] = $item['item_label'];
        }
        return $map;
    }

    /**
     * 根据字典编码和值获取标签
     *
     * @param string $dictCode 字典编码
     * @param string $value    字典项值
     * @return string 对应的标签，未找到时返回空字符串
     */
    public function getLabel(string $dictCode, string $value): string
    {
        $map = $this->getValueLabelMap($dictCode);
        return $map[$value] ?? '';
    }

    /**
     * 获取树形字典结构
     *
     * 优先从缓存读取已构建的树，缓存未命中时查询数据库构建。
     * 使用 wise\support\tools\Tree 工具库将扁平字典项构建为树形结构。
     * 仅适用于 is_tree=1 的字典类型。
     *
     * @param string $dictCode 字典编码
     * @return array 树形结构数据
     * @throws DictException 字典不存在或非树形字典时抛出异常
     */
    public function getTree(string $dictCode): array
    {
        // 尝试从缓存获取已构建的树
        $cached = $this->cacheManager->getTreeCache($dictCode);
        if ($cached !== null) {
            return $cached;
        }

        $type = DictType::where('dict_code', $dictCode)->find();
        if ($type === null) {
            throw DictException::notFound($dictCode);
        }
        if ((int)$type->is_tree !== 1) {
            throw DictException::invalidParam("字典 {$dictCode} 不是树形字典");
        }

        $items = $this->getItems($dictCode, 1);

        // 尝试使用 Tree 工具库
        if (class_exists(\wise\support\tools\Tree::class)) {
            $tree = new \wise\support\tools\Tree();
            $tree->init($items, 'parent_id');
            $result = $tree->getTreeArray(0);
        } else {
            // 降级方案：O(n) 单次遍历构建树形结构
            $result = $this->buildTree($items);
        }

        // 归一化输出结构，统一使用 children 字段（Tree 库默认输出 childlist）
        $result = $this->normalizeTree($result);

        // 缓存已构建的树
        $this->cacheManager->setTreeCache($dictCode, $result);

        return $result;
    }

    /**
     * 归一化树形结构字段
     *
     * 兼容 Tree 工具库输出的 childlist 字段，统一为 children，
     * 并移除仅供文本渲染使用的 spacer 字段，保证两条构建分支输出契约一致。
     *
     * @param array $nodes 树形节点数组
     * @return array 归一化后的树形结构
     */
    protected function normalizeTree(array $nodes): array
    {
        $normalized = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $children = $node['children'] ?? $node['childlist'] ?? [];
            unset($node['childlist'], $node['spacer']);

            if (is_array($children) && !empty($children)) {
                $node['children'] = $this->normalizeTree($children);
            }

            $normalized[] = $node;
        }

        return $normalized;
    }

    /**
     * 手动构建树形结构（降级方案，O(n) 复杂度）
     *
     * 先按 parent_id 分组，再单次遍历构建，替代原 O(n²) 递归。
     *
     * @param array $items 字典项列表
     * @return array 树形结构数据
     */
    protected function buildTree(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        // 第一步：按 parent_id 分组，建立索引（O(n)）
        $grouped = [];
        $tree = [];
        foreach ($items as &$item) {
            $pid = (int)$item['parent_id'];
            $grouped[$pid][] = &$item;
        }
        unset($item);

        // 第二步：从顶级节点出发，递归组装（O(n)）
        $this->attachChildren($grouped, 0, $tree);

        return $tree;
    }

    /**
     * 递归附加子节点
     *
     * @param array $grouped  按 parent_id 预分组的数组
     * @param int   $parentId 当前父级 ID
     * @param array &$result  结果数组引用
     * @return void
     */
    private function attachChildren(array &$grouped, int $parentId, array &$result): void
    {
        if (!isset($grouped[$parentId])) {
            return;
        }
        foreach ($grouped[$parentId] as &$item) {
            $id = (int)$item['id'];
            if (isset($grouped[$id])) {
                $children = [];
                $this->attachChildren($grouped, $id, $children);
                $item['children'] = $children;
            }
            $result[] = $item;
        }
    }

    /**
     * 创建字典项
     *
     * @param array $data 字典项数据
     *   - dict_code:  string (必填) 关联字典编码
     *   - item_value: string (必填) 存储值
     *   - item_label: string (必填) 显示标签
     *   - parent_id:  int    (可选) 父级ID，默认 0
     *   - sort:       int    (可选) 排序号，默认 0
     *   - status:     int    (可选) 状态，默认 1
     *   - remark:     string (可选) 备注
     * @return DictItem 创建成功的模型实例
     * @throws DictException 字典类型不存在或 item_value 重复时抛出异常
     */
    public function createItem(array $data): DictItem
    {
        // 验证字典类型是否存在
        $type = DictType::where('dict_code', $data['dict_code'])->find();
        if ($type === null) {
            throw DictException::notFound($data['dict_code']);
        }

        // 检查同一字典下 item_value 是否重复
        $exists = DictItem::where('dict_code', $data['dict_code'])
            ->where('item_value', $data['item_value'])
            ->find();
        if ($exists !== null) {
            throw DictException::duplicate("{$data['dict_code']}:{$data['item_value']}");
        }

        $item = DictItem::create([
            'dict_code'  => $data['dict_code'],
            'item_value' => $data['item_value'],
            'item_label' => $data['item_label'],
            'parent_id'  => $data['parent_id'] ?? 0,
            'sort'       => $data['sort'] ?? 0,
            'status'     => $data['status'] ?? 1,
            'remark'     => $data['remark'] ?? '',
        ]);

        $this->cacheManager->clearCache($data['dict_code']);
        $this->fireEvent($data['dict_code'], 'create', $item);

        return $item;
    }

    /**
     * 更新字典项
     *
     * @param int   $id   字典项ID
     * @param array $data 更新数据
     *   - item_value: string (可选) 存储值
     *   - item_label: string (可选) 显示标签
     *   - parent_id:  int    (可选) 父级ID
     *   - sort:       int    (可选) 排序号
     *   - status:     int    (可选) 状态
     *   - remark:     string (可选) 备注
     * @return DictItem 更新后的模型实例
     * @throws DictException 字典项不存在时抛出异常
     */
    public function updateItem(int $id, array $data): DictItem
    {
        $item = DictItem::find($id);
        if ($item === null) {
            throw DictException::notFound("DictItem:{$id}");
        }

        $updateData = [];
        if (isset($data['item_value'])) {
            // 检查修改后的 item_value 是否与其他记录冲突
            $exists = DictItem::where('dict_code', $item->dict_code)
                ->where('item_value', $data['item_value'])
                ->where('id', '<>', $id)
                ->find();
            if ($exists !== null) {
                throw DictException::duplicate("{$item->dict_code}:{$data['item_value']}");
            }
            $updateData['item_value'] = $data['item_value'];
        }
        if (isset($data['item_label'])) {
            $updateData['item_label'] = $data['item_label'];
        }
        if (isset($data['parent_id'])) {
            $updateData['parent_id'] = intval($data['parent_id']);
        }
        if (isset($data['sort'])) {
            $updateData['sort'] = intval($data['sort']);
        }
        if (isset($data['status'])) {
            $updateData['status'] = intval($data['status']);
        }
        if (isset($data['remark'])) {
            $updateData['remark'] = $data['remark'];
        }

        if (!empty($updateData)) {
            $item->save($updateData);
        }

        $this->cacheManager->clearCache($item->dict_code);
        $this->fireEvent($item->dict_code, 'update', $item);

        return $item;
    }

    /**
     * 删除字典项
     *
     * @param int $id 字典项ID
     * @return bool 是否删除成功
     * @throws DictException 字典项不存在时抛出异常
     */
    public function deleteItem(int $id): bool
    {
        $item = DictItem::find($id);
        if ($item === null) {
            throw DictException::notFound("DictItem:{$id}");
        }

        $dictCode = $item->dict_code;
        $item->delete();

        $this->cacheManager->clearCache($dictCode);
        $this->fireEvent($dictCode, 'delete', null);

        return true;
    }

    /**
     * 批量更新字典项状态
     *
     * @param array $ids    字典项ID数组
     * @param int   $status 目标状态（1=启用, 0=禁用）
     * @return bool 是否更新成功
     */
    public function batchUpdateStatus(array $ids, int $status): bool
    {
        if (empty($ids)) {
            return false;
        }

        $items = DictItem::where('id', 'in', $ids)->select();
        if ($items->isEmpty()) {
            return false;
        }

        DictItem::where('id', 'in', $ids)->update(['status' => $status]);

        // 清除涉及的所有字典缓存
        $dictCodes = $items->column('dict_code');
        $dictCodes = array_unique($dictCodes);
        foreach ($dictCodes as $dictCode) {
            $this->cacheManager->clearCache($dictCode);
            $this->fireEvent($dictCode, 'update', null);
        }

        return true;
    }

    /**
     * 批量删除字典项
     *
     * @param array $ids 字典项ID数组
     * @return bool 是否删除成功
     */
    public function batchDelete(array $ids): bool
    {
        if (empty($ids)) {
            return false;
        }

        $items = DictItem::where('id', 'in', $ids)->select();
        if ($items->isEmpty()) {
            return false;
        }

        // 收集涉及的字典编码
        $dictCodes = $items->column('dict_code');
        $dictCodes = array_unique($dictCodes);

        DictItem::where('id', 'in', $ids)->delete();

        // 清除涉及的所有字典缓存
        foreach ($dictCodes as $dictCode) {
            $this->cacheManager->clearCache($dictCode);
            $this->fireEvent($dictCode, 'delete', null);
        }

        return true;
    }

    /**
     * 获取字典项分页列表
     *
     * @param string $dictCode 字典编码
     * @param array  $params   搜索参数
     *   - item_value: string (可选) 存储值（模糊搜索）
     *   - item_label: string (可选) 显示标签（模糊搜索）
     *   - status:     int    (可选) 状态（精确匹配）
     *   - page:       int    (可选) 页码，默认 1
     *   - limit:      int    (可选) 每页数量，默认 15
     * @return \think\Paginator 分页结果
     */
    public function getList(string $dictCode, array $params = []): \think\Paginator
    {
        $params['dict_code'] = $dictCode;
        $page = intval($params['page'] ?? 1);
        $limit = intval($params['limit'] ?? 15);

        $query = (new DictItem())->search($params);

        return $query->paginate([
            'list_rows' => $limit,
            'page'      => $page,
        ]);
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
