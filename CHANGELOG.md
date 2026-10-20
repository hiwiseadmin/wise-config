# Changelog

All notable changes to this project will be documented in this file.

## [1.0.1] - 2026-09-19

### Security
- HTML 净化改为「HTMLPurifier → DOM 结构化净化 → 增强正则」三级策略，
  修复 `/` 属性分隔符与 HTML 实体编码导致的 XSS 绕过。
- 命名空间推断增加格式校验与白名单；请求头 `X-WiseConfig-Namespace`
  默认关闭（`namespace.allow_request_header`）。
- `ConfigManager::namespace()` / `withNamespace()` 增加命名空间合法性校验，
  关闭显式调用侧的路径/缓存键注入。
- `ConfigAuth` 中间件改为默认拒绝（`auth_allow_when_unconfigured` 可显式放行），
  并修复 debug 模式下 `error()->code()` 对 string 调用导致的致命错误。
- 新增读取可见性开关 `filter_private_on_read` 与 `ConfigManager::onlyPublic()`，
  公开/私有读取的缓存键相互隔离。
- 字典表清空 API 增加环境护栏（`DictInstaller::reset()` / `DictSDK::resetAll()`）。

### Fixed
- `has()` 不再把 `get()` 写入的负缓存标记误判为"存在"，并统一 `status` 过滤。
- 继承链解析改为请求级缓存，修复父级 namespace 变更后子级永久脏读；
  `revert()` 同步清理继承链缓存。
- `ConfigSaving` 事件调整为真正在写库前触发。
- `DictCacheManager::clearCache()` 改为按字典精确清理，不再清空全部字典缓存。
- `TagManager::findByTags()` AND 逻辑改用 PHP 侧统计，去除存疑的 having 参数绑定，
  并对标签去重。
- 树形字典统一输出 `children` 字段（兼容 Tree 库的 `childlist`）。
- 修复 `function_exists('think\facade\Log::xxx')` 恒为 false 导致告警日志静默的问题。
- 补齐 `TagChanged` / `InheritanceBlocked` 事件触发。
- `config.tags` 列改为 text，避免 JSON 冗余快照超长截断；
  新增迁移 `20251007000006_alter_config_tags_to_text` 用于已存在的旧库。
- 模糊搜索统一转义 LIKE 通配符（`%` `_` `\`）。
- `revert()` 改为按历史记录的 `config_id` 精确定位，避免同名 key 重建后回滚到错误记录。
- `TpConfigBridge` 注入前按类型解码，`config('wise.x')` 不再返回原始编码串。

### Changed
- 新增 `wise-config.inheritance.apply_on_get` 开关（默认关闭），可按需统一 `get()` 与
  `getResolved()` 的读语义；`inheritance.cache_ttl` 因改请求级缓存而失效（保留兼容）。
- `composer.json` 补齐 `topthink/think-migration`、`wiseadmin/wise-support` 依赖。
- `phpunit.xml` 升级至 PHPUnit 10.x 规范（与宿主 10.5 对齐）。
- 整理过期单元测试（`findInCollection` → `indexByNamespace`/`findInIndex`），
  并补充继承阻断事件用例。

## [1.0.0] - 2024-01-01

### Added
- Initial release

[1.0.1]: https://github.com/wiseadmin/wise-config/releases/tag/v1.0.1
[1.0.0]: https://github.com/wiseadmin/wise-config/releases/tag/v1.0.0
