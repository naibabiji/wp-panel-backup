# 接手摘要

- 日期：2026-11-23。
- 项目：WP PANEL Backup，源站独立备份插件；主文件为 wp-panel-backup.php。
- 当前阶段：候选包 `dist/wp-panel-backup-1.0.0-rc16.zip` 支持 HTTP 和 HTTPS 恢复链接。配套面板候选已部署到局域网，健康检查 HTTP 200，四项核心服务正常，20 个站点的 HTTP 状态一致。历史记录见 [验收文档](docs/acceptance.md)。
- 用户确认首版 1.0.0、GPL-2.0-or-later、作者 WP PANEL、贡献者 naibabiji，并将源站最低版本调整为 WordPress 6.5 / PHP 7.0。当前源码在源站 PHP 主、次版本与面板 PHP 8.3 不同时提示恢复兼容风险。WordPress.org 尚未提交，公网源站是否更新由用户决定。
- 首版导出、站内随机工作目录、维护和保留方案已确认，详见 requirements。交换格式 v1 已确认随 1.0.0 首发冻结，更广的兼容矩阵见 pending-decisions。
- RC13 已完成真实站点上传恢复，HTTPS 链接已完成一例公网实站恢复。RC16 已通过 HTTP/HTTPS 源端及 HTTP 目标站的隔离验收，实际 HTTP 源站到局域网的完整拉取待用户测试。
- 当前活动卡：[数据库导出](docs/work/active/2026-11-23-bounded-sql-export.md)、[插件开发](docs/work/active/2026-11-22-plugin-development.md)。[初始化交接](docs/work/active/2026-11-22-plugin-bootstrap.md)保留历史决策。
- 先读 AGENTS.md 的必读列表；跨项目事实见 integrations 文档，面板旧要求中“暂不创建插件”的历史措辞已被当前指令取代。

## 已有证据如何使用

面板项目 P1–P4 测过数据库核心、维护隔离、大文件分包/补传、维护提示和基本锁。无需原样重做，但其临时代码不等于插件。P4 后用户明确收敛：只测常见流程，不为绝对停写新增状态或框架。源数据库短批次一致性、安全工作区与兼容范围仍须结合实际插件验证，不夸大已有结论。

## 下一位 AI 的起点

读取开发任务卡及待定事项。历史隔离站使用缓存镜像且没有宿主端口。任务卡记录实际导出以及文件和数据库比对结果。面板侧验收与部署记录见跨项目集成文档，WordPress.org 审核单独跟进。
