# WP PANEL Backup

独立 WordPress 源站备份插件，供管理员将常规网站备份为可下载材料，后续由 WP Panel 从备份创建同域名网站。

**当前状态：开发源码已在隔离 WordPress 验证全目录与全库备份、三种模式、下载和清理。** 源码及文档保存在本地 Git，本轮用户测试包为 `dist/wp-panel-backup-0.0.0-dev-auto-storage.zip`。正式版本及发布方式待后续确认。

开发顺序：

1. 完成本项目需求/格式的最小待定选择。
2. 开发插件：预检、全目录/全库导出、分包、维护和下载。
3. 用真实插件生成备份，验证常见流程及文件/数据库准确性。
4. 再由 WP Panel 项目实现接收、校验、恢复建站和 HTTPS。

源站不需要安装或连接 WP Panel；插件不承担恢复、域名替换、云存储或定时备份。

- [AI 接手入口](AI-CONTEXT.md)
- [当前需求与确认来源](docs/requirements.md)
- [职责和开发阶段](docs/architecture.md)
- [备份格式候选](docs/backup-format.md)
- [与 WP Panel 的关系](docs/integrations/wp-panel.md)
- [待定事项](docs/pending-decisions.md)
- [常见场景验收](docs/acceptance.md)
- [活动任务](docs/work/README.md)

此目录是独立本地 Git 仓库，未设远程。

## 开发测试站使用

在 WordPress 后台进入「插件 → 安装插件 → 上传插件」，上传 `wp-panel-backup-0.0.0-dev-auto-storage.zip` 并启用。测试包由 `wp-panel-backup.php`、`includes/*.php` 和 `assets/advance.js` 组成。

首次开始备份时，插件自动在 `wp-content` 下建立带随机后缀的工作目录，并通过无敏感探测文件自检。自检失败时，请查看后台的具体提示，由主机商设置站内目录禁访规则后重试。

在插件列表点 WP PANEL Backup 下方的「设置」，或通过「工具 → WP PANEL Backup」打开备份页。运行预检并选择模式。备份时保持页面打开，离开后可返回继续。完成后下载清单、索引和全部 ZIP，按整套保存。完整材料保留最近两套且最长 7 天，半成品从最后有效推进起保留 7 天。

临时维护异常时可在插件页面手动解除，搬家维护须使用「取消搬家并恢复原站」。后台无法访问且确需开放原站时，由主机管理员等当前备份请求结束后，移除该站工作目录中的 `maintenance.json`，再进后台确认任务状态。

实际使用范围与已验证限制见[验收记录](docs/acceptance.md)和[开发任务卡](docs/work/active/2026-11-22-plugin-development.md)。
