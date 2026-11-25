# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.3] - 2026-11-25

### Security
- HTML 净化改为多级策略，修复属性分隔符与实体编码绕过；命名空间校验与请求头开关默认关闭。
- 配置鉴权默认拒绝；新增只读公开配置开关，公开/私有缓存隔离；字典重置增加环境护栏。

### Fixed
- 修复负缓存误判、继承链脏读、字典缓存误清、标签查询、树形输出、历史回滚与模糊搜索转义等问题。

### Changed
- 新增 `inheritance.apply_on_get` 开关；`config.tags` 列改为 text 以容纳长快照。

### Added
- Initial release

[Unreleased]: https://github.com/hiwiseadmin/wise-config/compare/v1.0.3...HEAD


[Unreleased]: https://github.com/hiwiseadmin/wise-config/compare/v1.0.3...HEAD
[1.0.3]: https://github.com/hiwiseadmin/wise-config/releases/tag/v1.0.3
