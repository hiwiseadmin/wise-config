<?php
/**
 * WiseConfig 配置文件
 *
 * 可通过应用 config/wise_config.php 覆盖默认值。
 */
return [

    // 缓存键前缀
    'cache_key_prefix' => 'wise_config:',

    // 后台路由前缀
    'route_prefix' => 'wise-admin/config',

    // 图片上传磁盘（filesystem.php 中配置的磁盘名）
    'upload_disk' => 'public',

    // 图片上传路径前缀
    'upload_path' => 'uploads/config/',

    // 缓存标签
    'cache_tag' => 'wise_config',

    // 缓存有效期（秒），0 表示永久
    'cache_ttl' => 0,

    // 空值缓存 TTL（秒），空值标记（防穿透）使用独立的缓存时间
    // 建议设置得比正常缓存 TTL 长，以避免缓存穿透保护过早失效
    'cache_null_ttl' => 3600,

    // 读取时是否仅返回公开（visibility=1）配置
    // 默认 false：服务端内部读取 private 配置是合法场景；
    // 对外/调试读取建议通过 ConfigManager::onlyPublic() 或本开关开启。
    'filter_private_on_read' => false,

    // 权限验证回调：接收当前请求上下文，返回 bool
    // 示例: function() { return \app\admin\facade\CurrentAdmin::isLogin(); }
    // 注意：未配置时 ConfigAuth 中间件默认「拒绝访问」，不会放行。
    'auth_callback' => null,

    // 是否允许在未配置 auth_callback 时放行（默认 false，仅用于兼容旧行为）
    'auth_allow_when_unconfigured' => false,

    // 富文本 XSS 过滤（需要 HTMLPurifier 库）
    'html_filter' => true,

    // 图片上传允许的扩展名
    'image_extensions' => 'jpg,jpeg,png,gif,webp,bmp',

    // 图片上传最大大小（KB）
    'image_max_size' => 2048,

    // ========== 字典模块配置 ==========

    // 字典缓存有效期（秒），默认 86400（24小时）
    'dict' => [
        'cache_ttl' => 86400,
    ],

    // ====== 新增：namespace 相关 ======
    'namespace' => [
        // 默认命名空间（未显式指定时使用）
        'default' => 'default',

        // 是否启用自动推断（请求上下文 → 配置默认值 → 'default'）
        'auto_resolve' => true,

        // 是否允许通过请求头 X-WiseConfig-Namespace 推断命名空间
        // 默认关闭，开启后仍受格式校验与 allowed 白名单约束
        'allow_request_header' => false,

        // 命名空间白名单（非空时仅允许其中列出的值）
        'allowed' => [],
    ],

    // ====== 新增：继承相关 ======
    'inheritance' => [
        // 是否启用继承机制
        'enabled' => true,

        // 最大继承深度（防止循环引用）
        'max_depth' => 5,

        // 继承解析结果缓存有效期（秒）
        // 说明：为避免父级变更后子级脏读，getResolved() 现使用「请求级」内存缓存，
        // 本配置项已不再生效，保留仅为向后兼容。
        'cache_ttl' => 300,

        // 是否让 get() 也走继承解析，统一读语义（默认 false，保持既有行为）
        'apply_on_get' => false,
    ],

    // ====== 新增：Tag 相关 ======
    'tag' => [
        // 是否启用标签功能
        'enabled' => true,

        // 每条配置最大标签数
        'max_per_config' => 10,
    ],

    // ====== 新增：TP Config 整合 ======
    'tp_integration' => [
        // 是否在服务启动时自动将数据库配置注入 think\Config
        'auto_inject' => false,

        // 注入时的键名前缀（避免与现有配置冲突）
        'inject_prefix' => 'wise',
    ],
];
