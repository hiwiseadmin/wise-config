# wiseadmin/wise-config

> ThinkPHP 6/8 键值对配置管理扩展包，提供配置存储、缓存管理、历史版本追踪、字典管理、多命名空间隔离、继承链解析、标签分类和后台管理界面

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-Apache%202.0-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--config-orange)](https://packagist.org/packages/wiseadmin/wise-config)

---

## ✨ 特性

### 核心配置管理
- **多类型支持**：文本(text)、数字(number)、富文本(html)、图片(image)、JSON(json)
- **多命名空间隔离**：支持按 namespace 隔离配置，自动从请求上下文推断或显式指定
- **冒号路径解析**：支持 `namespace:group:key`、`group:key`、`key` 三种路径格式
- **配置分组**：支持任意分组（如 system、mail、upload）
- **缓存自动刷新**：配置变更时自动清除相关缓存，支持空值防缓存穿透
- **历史版本**：完整记录每次配置变更，支持对比(diff)和回滚(revert)
- **批量操作**：支持 `setMany()` 批量设置、`all()` 获取全部配置

### 字典管理（DictSDK）
- **字典类型管理**：定义字典编码、名称、描述，支持树形字典标记
- **字典项 CRUD**：完整的增删改查、分页列表、批量操作
- **树形字典**：支持父子层级结构，兼容 Tree 工具库
- **便捷查询**：value→label 映射、按值取标签
- **专用缓存**：字典数据独立缓存，变更自动清理
- **自动安装**：支持服务启动时自动创建数据表

### 高级特性
- **继承链（Inheritance）**：按 namespace 层级（如 `project_a.plugin_mail.prod`）向上查找配置，支持 `inherit=false` 阻断
- **标签管理（Tag）**：为配置附加标签，支持按标签 AND/OR 逻辑查询
- **TP Config 桥接**：将动态配置注入到 `think\Config` 体系，使 `config('wise.system.xxx')` 可直接访问
- **类型扩展**：通过实现 `TypeHandlerInterface` 接口注册自定义类型处理器
- **事件驱动**：保存前后、回滚、字典变更、标签变更时触发事件，便于其他模块监听
- **权限预留**：支持通过闭包配置管理权限
- **HTML XSS 过滤**：内置基础过滤，建议搭配 `ezyang/htmlpurifier` 获得更强防护
- **符合 ThinkPHP 约定**：自动服务发现、门面模式、命令行工具

---

## 📦 安装

```bash
composer require wiseadmin/wise-config
```

### 推荐包

```bash
# 增强 HTML XSS 过滤
composer require ezyang/htmlpurifier
```

### 初始化

```bash
php think vendor:publish
```

### 执行数据库迁移

```bash
php think migrate:run
```

> 将创建 `config`、`config_history`、`config_tag`、`dict_type`、`dict_item` 五张数据表。

---

## 🚀 快速开始

### ConfigManager（核心配置管理）

#### 基础读写

使用 `WiseConfig` 门面：

```php
use wise\config\facade\WiseConfig;

// 读取配置
$siteName = WiseConfig::get('site_name', '默认名称');

// 设置配置
WiseConfig::set('site_name', '我的网站', [
    'type'  => 'text',
    'group' => 'system',
    'title' => '站点名称',
]);

// 批量获取
$configs = WiseConfig::many(['site_name', 'site_desc', 'admin_email']);

// 获取分组所有配置
$mailConfigs = WiseConfig::getGroup('mail');

// 检查键是否存在
if (WiseConfig::has('site_name')) {
    // ...
}

// 删除配置
WiseConfig::delete('old_config_key');

// 批量设置
WiseConfig::setMany([
    'site_name' => ['value' => '新站点', 'type' => 'text', 'group' => 'system'],
    'max_upload' => ['value' => '10', 'type' => 'number', 'group' => 'upload'],
]);

// 获取当前 namespace 全部配置
$allConfigs = WiseConfig::all();
```

#### 命名空间与路径

支持冒号分隔的三段路径 `namespace:group:key`，以及链式调用指定 namespace：

```php
use wise\config\facade\WiseConfig;

// 方式一：链式指定 namespace
$value = WiseConfig::namespace('project_a')->get('site_name');

// 方式二：路径中指定 namespace
$value = WiseConfig::get('project_a:system:site_name');

// namespace:group:key — 三段完整路径
// group:key — namespace 自动推断，默认值为 'default'
// key — namespace 自动推断，group 默认为 'system'
```

#### 继承链

支持多层级 namespace 的点分命名（如 `project_a.plugin_mail.prod`），子级可继承父级的配置：

```php
// 获取最终生效值（自动沿继承链向上查找）
$seoTitle = WiseConfig::namespace('project_a.plugin_mail')
    ->getResolved('seo:title', '默认标题');

// 链解析路径：project_a.plugin_mail → project_a → default

// 查看配置来源
$status = WiseConfig::namespace('project_a.plugin_mail')
    ->getOverrideStatus('seo:title');
// 返回：['exists' => true, 'value' => '...', 'source_ns' => 'project_a', 'is_inherited' => true]

// 删除当前 namespace 的覆盖值，恢复继承上级
WiseConfig::namespace('project_a.plugin_mail')->reset('seo:title');
```

> 设置配置时，通过 `'inherit' => 0` 可阻断继承，上级同名配置不会生效。
>
> 默认情况下 `get()` 只读取当前 namespace，只有 `getResolved()` 才走继承链。
> 如需让 `get()` 也统一走继承解析，可开启配置
> `wise-config.inheritance.apply_on_get = true`（默认关闭，保持既有行为）。

#### 标签管理

```php
// 为配置添加标签
WiseConfig::addTag('site_name', 'seo');

// 移除标签
WiseConfig::removeTag('site_name', 'seo');

// 按标签查询配置（AND 逻辑）
$seoConfigs = WiseConfig::whereTags(['seo', 'meta'], 'and');

// 按标签查询配置（OR 逻辑）
$relatedConfigs = WiseConfig::whereTags(['seo', 'meta'], 'or');
```

#### TP Config 桥接

将动态配置注入到 ThinkPHP 原生 `config()` 函数体系：

```php
use wise\config\bridge\TpConfigBridge;

$bridge = app('wise.config.tp_config_bridge');
$bridge->injectToConfig('default', 'wise');

// 之后可通过 ThinkPHP 方式访问
echo config('wise.system.site_name');       // 站点名称
echo config('wise.upload.max_size');         // 上传限制
```

#### 历史版本

```php
// 获取历史版本列表
$histories = WiseConfig::getHistory('site_name');

// 获取指定历史记录
$history = WiseConfig::getHistoryById(1);

// 回滚到指定版本
WiseConfig::revert($historyId);

// 对比两个版本差异
$diff = WiseConfig::diff($historyIdA, $historyIdB);
// 返回：['old' => ..., 'new' => ..., 'diff' => ..., 'is_json' => bool]
```

#### 缓存管理

```php
// 清除指定键缓存
WiseConfig::clearCacheForKey('site_name');

// 清除分组缓存
WiseConfig::clearCacheForGroup('system');

// 清除全部缓存
WiseConfig::clearAllCache();
```

### 字典管理（Facade，推荐）

`WiseDictType` / `WiseDictItem` 是 `DictTypeService` / `DictItemService` 的静态门面，
与 `WiseConfig` 风格一致，底层从容器解析服务实例：

```php
use wise\config\facade\WiseDictType;
use wise\config\facade\WiseDictItem;

// 字典项查询
$map   = WiseDictItem::getValueLabelMap('order_status'); // ['1' => '待支付', '2' => '已支付', ...]
$label = WiseDictItem::getLabel('order_status', '1');    // '待支付'
$items = WiseDictItem::getItems('order_status');         // 全部字典项
$items = WiseDictItem::getItems('order_status', 1);      // 仅启用项
$tree  = WiseDictItem::getTree('region');                // 树形结构

// 字典项写入
WiseDictItem::createItem(['dict_code' => 'order_status', 'item_value' => '4', 'item_label' => '已完成']);
WiseDictItem::updateItem(1, ['item_label' => '更新后标签']);
WiseDictItem::deleteItem(1);
WiseDictItem::batchUpdateStatus([1, 2, 3], 1);
WiseDictItem::batchDelete([1, 2]);

// 字典类型
$type = WiseDictType::getType('order_status');
$page = WiseDictType::getList(['name' => '订单']);
WiseDictType::createType(['dict_code' => 'pay_type', 'name' => '支付方式']);
WiseDictType::updateType('pay_type', ['name' => '支付方式（新）']);
WiseDictType::toggleStatus('pay_type');
WiseDictType::deleteType('pay_type');
```

> 完整方法列表见 `facade/WiseDictType.php` / `facade/WiseDictItem.php` 的 `@method` 注解。

### DictSDK（兼容入口，容器未就绪时可用）

`DictSDK` 是早期的静态入口，聚合了类型 / 字典项 / 缓存三个服务，并内置
「容器不可用则自行 new」的兜底逻辑，因此可在安装器、迁移、CLI、单元测试等
DI 尚未注册的场景直接调用：

```php
use wise\config\DictSDK;

// 获取 service 实例（容器就绪时走容器，否则自行构造）
$itemService = DictSDK::getItemService();

// 缓存清理（当前仍由 DictSDK / DictCacheManager 提供）
DictSDK::clearCache('order_status');
DictSDK::clearAllCache();

// 测试用重置内部单例
DictSDK::resetInstances();
```

> 业务代码（容器已就绪）建议优先使用上面的 Facade；`DictSDK` 保留用于无容器场景与向后兼容。

#### 字典项 CRUD（服务层）

```php
use wise\config\service\DictItemService;

$service = app(DictItemService::class);

// 创建字典项
$item = $service->createItem([
    'dict_code'  => 'order_status',
    'item_value' => '4',
    'item_label' => '已完成',
    'sort'       => 4,
]);

// 更新字典项
$service->updateItem(1, ['item_label' => '更新后标签']);

// 分页查询
$list = $service->getList('order_status', [
    'page'  => 1,
    'limit' => 15,
]);

// 批量操作
$service->batchUpdateStatus([1, 2, 3], 1); // 批量启用
$service->batchDelete([1, 2]);               // 批量删除

// 删除字典项
$service->deleteItem(1);
```

### 支持的数据类型

| 类型标识 | 说明 | 存储格式 | 读取行为 |
|---------|------|---------|---------|
| `text` | 单行文本 | 字符串 | 原样返回 |
| `number` | 数字 | 字符串 | 自动转为 int/float |
| `html` | 富文本 | HTML 字符串 | 返回 HTML（安全过滤后） |
| `image` | 单图 | 相对路径 | 自动拼接完整 URL |
| `json` | JSON 对象/数组 | JSON 字符串 | 自动解析为数组 |

### 类型扩展

实现 `wise\config\contract\TypeHandlerInterface` 接口即可扩展自定义类型：

```php
use wise\config\contract\TypeHandlerInterface;

class ColorHandler implements TypeHandlerInterface
{
    public function getType(): string
    {
        return 'color';
    }

    public function decode($rawValue)
    {
        return '#' . $rawValue;
    }

    public function encode($inputValue): string
    {
        return ltrim($inputValue, '#');
    }

    public function renderFormField(string $name, $value, array $config): string
    {
        return '<input type="color" name="' . $name . '" value="' . $value . '">';
    }
}

// 注册
$manager = app(\wise\config\ConfigManager::class);
$manager->registerTypeHandler(new ColorHandler());
```

### 事件

#### 配置事件

| 事件类 | 触发时机 | 关键参数 |
|--------|---------|---------|
| `ConfigSaving` | 配置保存前 | key, value, type, group, namespace |
| `ConfigSaved` | 配置保存后 | key, oldValue, newValue, type, group, namespace |
| `ConfigDeleting` | 配置删除前 | key, configId, group, namespace |
| `ConfigReverted` | 配置回滚后 | key, fromValue, toValue, historyId, namespace |

#### 字典事件

| 事件类 | 触发时机 | 关键参数 |
|--------|---------|---------|
| `DictChangedEvent` | 字典项增删改时 | dictCode, action(create/update/delete), data |

#### 其他事件

| 事件类 | 触发时机 | 说明 |
|--------|---------|------|
| `TagChanged` | 标签关联变更时 | 配置标签增删改 |
| `InheritanceBlocked` | 继承被阻断时 | inherit=false 阻断上级继承 |

#### 监听示例

```php
// app/event.php
use wise\config\event\ConfigSaved;
use wise\config\event\DictChangedEvent;

return [
    'listen' => [
        ConfigSaved::class => [
            \app\listener\ConfigChangeListener::class,
        ],
        DictChangedEvent::class => [
            \app\listener\DictChangeListener::class,
        ],
    ],
];
```

---

## ⚙️ 配置

配置文件 `config/wise-config.php`：

```php
return [
    // 缓存键前缀
    'cache_key_prefix' => 'wise_config:',
    // 后台路由前缀
    'route_prefix'     => 'wise-admin/config',
    // 图片上传磁盘
    'upload_disk'      => 'public',
    // 图片上传路径前缀
    'upload_path'      => 'uploads/config/',
    // 缓存标签
    'cache_tag'        => 'wise_config',
    // 缓存有效期（秒），0 表示永久
    'cache_ttl'        => 0,
    // 空值缓存 TTL（秒），防穿透
    'cache_null_ttl'   => 3600,
    // 读取时是否仅返回公开（visibility=1）配置（默认 false，服务端可读取私有配置）
    'filter_private_on_read' => false,
    // 权限验证回调，返回 bool
    'auth_callback'    => null,
    // 未配置 auth_callback 时是否放行（默认 false，即默认拒绝）
    'auth_allow_when_unconfigured' => false,
    // HTML XSS 过滤
    'html_filter'      => true,
    // 图片上传扩展名限制
    'image_extensions' => 'jpg,jpeg,png,gif,webp,bmp',
    // 图片上传最大大小（KB）
    'image_max_size'   => 2048,

    // ========== 字典模块 ==========
    'dict' => [
        'cache_ttl' => 86400,
    ],

    // ========== 命名空间 ==========
    'namespace' => [
        'default'      => 'default',
        'auto_resolve' => true,
        // 是否允许请求头 X-WiseConfig-Namespace 推断命名空间（默认关闭）
        'allow_request_header' => false,
        // 命名空间白名单（非空时仅放行其中的值）
        'allowed'      => [],
    ],

    // ========== 继承 ==========
    'inheritance' => [
        'enabled'   => true,
        'max_depth' => 5,
        'cache_ttl' => 300,
    ],

    // ========== 标签 ==========
    'tag' => [
        'enabled'        => true,
        'max_per_config' => 10,
    ],

    // ========== TP Config 整合 ==========
    'tp_integration' => [
        'inject_prefix' => 'wise',
    ],
];
```

### 权限配置示例

```php
// 在 config/wise-config.php 中配置
'auth_callback' => function () {
    return \app\admin\facade\CurrentAdmin::isLogin();
},
```

---

## 📂 目录结构

```
src/
├── ConfigServiceProvider.php    # 服务提供者
├── ConfigManager.php            # 核心配置管理器
├── DictSDK.php                  # 字典静态入口（兼容 / 无容器场景）
├── bridge/
│   └── TpConfigBridge.php       # ThinkPHP Config 桥接器
├── cache/
│   └── DictCacheManager.php     # 字典缓存管理器
├── contract/
│   └── TypeHandlerInterface.php # 类型处理器接口
├── event/
│   ├── ConfigSaving.php         # 配置保存前事件
│   ├── ConfigSaved.php          # 配置保存后事件
│   ├── ConfigDeleting.php       # 配置删除前事件
│   ├── ConfigReverted.php       # 配置回滚事件
│   ├── DictChangedEvent.php     # 字典变更事件
│   ├── InheritanceBlocked.php   # 继承阻断事件
│   └── TagChanged.php           # 标签变更事件
├── exception/
│   └── DictException.php        # 字典异常类
├── facade/
│   ├── WiseConfig.php           # 配置管理静态门面
│   ├── WiseDictType.php         # 字典类型静态门面
│   └── WiseDictItem.php         # 字典项静态门面
├── installer/
│   └── DictInstaller.php        # 字典表安装器
├── manager/
│   └── TagManager.php           # 标签管理器
├── middleware/
│   └── ConfigAuth.php           # 权限中间件
├── model/
│   ├── ConfigModel.php          # 配置模型
│   ├── ConfigHistoryModel.php   # 历史模型
│   ├── ConfigTagModel.php       # 标签关联模型
│   ├── DictItem.php             # 字典项模型
│   └── DictType.php             # 字典类型模型
├── resolver/
│   ├── PathResolver.php         # 冒号路径三段解析器
│   ├── NamespaceResolver.php    # 命名空间三级推断器
│   └── InheritanceResolver.php  # 继承链解析器
├── service/
│   ├── DictItemService.php      # 字典项 CRUD 服务
│   └── DictTypeService.php      # 字典类型 CRUD 服务
└── trait/
    └── ServiceTrait.php         # 服务提供者共用 Trait
```

---

## 依赖关系

- **必需**：`topthink/framework`、`topthink/think-migration`、`wiseadmin/wise-support`、`ext-json`
- **可选（suggest）**：`ezyang/htmlpurifier`（更健壮的 HTML XSS 过滤）
- **迁移**：包内置 `migrate-path`，安装后需执行迁移

> 说明：本包仅提供服务层与数据模型，**不含后台控制器/路由/视图**。
> 后台管理界面（配置管理、字典管理）位于宿主应用的 `app/admin/controller/WiseConfig*.php`
> 与 `app/admin/controller/WiseDict.php`；配置中的 `route_prefix` 仅供宿主路由使用。

## 许可证

Apache-2.0
