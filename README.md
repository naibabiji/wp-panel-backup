# WP PANEL Backup

独立 WordPress 源站备份插件，供管理员将常规网站备份为可下载材料，后续由 WP Panel 从备份创建同域名网站。

**当前状态：开发源码已在隔离 WordPress 验证全目录与全库备份、三种模式、下载和清理。** 当前本地提交候选包为 `dist/wp-panel-backup-1.0.0.zip`，基于 GPL-2.0-or-later。WordPress.org 审核和发布另行进行。

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
- [自动排除目录与识别依据](docs/directory-exclusions.md)
- [待定事项](docs/pending-decisions.md)
- [常见场景验收](docs/acceptance.md)
- [活动任务](docs/work/README.md)

此目录是独立本地 Git 仓库，未设远程。

## 开发测试站使用

在 WordPress 后台进入「插件 → 安装插件 → 上传插件」，上传 `wp-panel-backup-1.0.0.zip` 并启用。安装包包含插件运行文件、公开说明和许可证。最低 WordPress 7.0、PHP 8.3。

首次开始备份时，插件自动在 `wp-content` 下建立带随机后缀的工作目录，并生成 Apache 的 `.htaccess` 和 IIS 的 `web.config`。工作目录及新建任务子目录都生成 `index.php`、`index.html`，用于启用默认索引的服务器避免列出文件。创建成功便可备份，主机无需支持回环请求。写入失败会提示检查剩余空间、主机账号配额和目录权限。

下载列表会显示直链访问提醒。请勿分享备份文件的 URL。如果服务器允许直接读取备份目录，未登录的访客知道完整 URL 后也能下载。使用 Nginx 时，可在对应站点的 `server` 配置中添加下面的规则，并检测配置后重载 Nginx。此规则覆盖站内所有由插件创建的随机工作目录，后台授权下载仍可使用。

```nginx
location ^~ /wp-content/wp-panel-backup-storage- {
    return 403;
}
```

在插件列表点 WP PANEL Backup 下方的「备份」，或通过「工具 → WP PANEL Backup」打开备份页。备份页会列出识别到的缓存和备份目录，默认跳过。存在标准缓存加载文件时，页面也会列出并自动跳过，源文件照常使用，目标恢复后重新配置缓存。选择模式并点「开始备份」，插件会自动完成预检。执行期间仅更新进度区域，保持此页打开即可自动推进。离开页面或请求失败后，可点「继续备份」从已保存的进度继续。创建备份时可选 128 MiB、512 MiB 或 1 GiB 的单包原始载荷，默认 512 MiB。完成后自动进入「备份列表」，可下载一个整套 TAR，也可逐份下载清单、索引和 ZIP。整套 TAR 解开后得到完全相同的材料。完整材料保留最近两套且最长 7 天，半成品从最后有效推进起保留 7 天。

临时维护异常时可在插件页面手动解除，搬家维护须使用「取消搬家并恢复原站」。后台无法访问且确需开放原站时，由主机管理员等当前备份请求结束后，移除该站工作目录中的 `maintenance.json`，再进后台确认任务状态。

实际使用范围与已验证限制见[验收记录](docs/acceptance.md)和[开发任务卡](docs/work/active/2026-11-22-plugin-development.md)。
