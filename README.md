# WP PANEL Backup

独立 WordPress 源站备份插件，供管理员将常规网站备份为可下载材料，后续由 WP Panel 从备份创建同域名网站。

**当前状态：开发源码已在隔离 WordPress 验证全目录与全库备份、三种模式、下载和清理。** 源码及文档保存在本地 Git，使用 `dist/wp-panel-backup-0.0.0-dev.zip` 作为本轮用户测试包。正式版本及发布方式待后续确认。

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

在 WordPress 后台进入「插件 → 安装插件 → 上传插件」，上传 `wp-panel-backup-0.0.0-dev.zip` 并启用。测试包由本地提交中的 `wp-panel-backup.php`、`includes/*.php` 和 `assets/advance.js` 组成。

确认站点独占的私有目录位于服务器所有公网文档根和 Web 别名之外，由 PHP 用户持有，权限为 `0700`。核对服务器映射后，才在测试站的 `wp-config.php` 中设置：

```php
define( 'WP_PANEL_BACKUP_PRIVATE_DIR', '/absolute/private/site-backups' );
define( 'WP_PANEL_BACKUP_PRIVATE_DIR_VERIFIED', true );
```

在「工具 → WP PANEL Backup」运行预检并选择模式。备份时保持页面打开，离开后可返回继续。完成后下载清单、索引和全部 ZIP，按整套保存。完整材料保留最近两套且最长 7 天，半成品从最后有效推进起保留 7 天。

临时维护异常时可在插件页面手动解除，搬家维护须使用「取消搬家并恢复原站」。后台无法访问且确需开放原站时，由主机管理员等当前备份请求结束后，移除该站独占私有目录中的 `maintenance.json`，再进后台确认任务状态。

实际使用范围与已验证限制见[验收记录](docs/acceptance.md)和[开发任务卡](docs/work/active/2026-11-22-plugin-development.md)。
