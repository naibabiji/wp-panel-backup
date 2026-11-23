# WP PANEL Backup

独立 WordPress 源站备份插件，供管理员将常规网站备份为可下载材料，后续由 WP Panel 从备份创建同域名网站。

**当前候选包：`dist/wp-panel-backup-1.0.0-rc11.zip`（提交 `4ea34f2`）。** WordPress.org 尚未提交。

当前源码面向最低 WordPress 6.5、PHP 7.0。源站的 WordPress 版本还须支持其实际 PHP 版本，并满足 64 位 PHP、ZIP、原生 mysqli、InnoDB 和既有预检要求。许可证为 GPL-2.0-or-later。公开说明见 [readme.txt](readme.txt)。两项目的备份格式复核和端到端恢复验收仍待进行。

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

在 WordPress 后台进入「插件 → 安装插件 → 上传插件」，上传 `wp-panel-backup-1.0.0-rc11.zip` 并启用。安装包包含插件运行文件、公开说明和许可证。最低 WordPress 6.5、PHP 7.0。

当前源码的备份页在源站 PHP 主、次版本与 WP PANEL 的 PHP 8.3 不同时显示兼容提示。提示不影响备份。恢复后如网站无法打开，可查看目标站 PHP 错误日志，并自行修复或使用 WP PANEL 的 AI 开发功能辅助排查。

首次开始备份时，插件自动在 `wp-content` 下建立带随机后缀的工作目录，并生成 Apache 的 `.htaccess` 和 IIS 的 `web.config`。工作目录及新建任务子目录都生成 `index.php`、`index.html`，用于启用默认索引的服务器避免列出文件。创建成功便可备份，主机无需支持回环请求。写入失败会提示检查剩余空间、主机账号配额和目录权限。

下载列表会显示直链访问提醒。请勿分享备份文件的 URL。如果服务器允许直接读取备份目录，未登录的访客知道完整 URL 后也能下载。使用 Nginx 时，应在创建备份前将下面的规则添加到对应站点的 `server` 配置，检测配置后重载 Nginx。此规则覆盖站内所有由插件创建的随机工作目录，后台授权下载仍可使用。

```nginx
location ^~ /wp-content/wp-panel-backup-storage- {
    return 403;
}
```

在插件列表点 WP PANEL Backup 下方的「备份」，或通过「工具 → WP PANEL Backup」打开备份页。备份页会列出识别到的缓存和备份目录，默认跳过。存在标准缓存加载文件时，页面也会列出并自动跳过，源文件照常使用，目标恢复后重新配置缓存。选择模式并点「开始备份」，插件会自动完成预检。执行期间仅更新进度区域，圆环在等待批次时持续转动。启用系统的「减少动态效果」设置时显示静态圆环。保持此页打开即可自动推进。离开页面或网络请求中断后，可点「继续备份」从已保存的进度继续。创建备份时可选 128 MiB、512 MiB 或 1 GiB 的单包原始载荷，默认 512 MiB。完成后自动进入「备份列表」，可下载一个整套 TAR，也可逐份下载清单、索引和 ZIP。整套 TAR 解开后得到完全相同的材料。完整材料保留最近两套且最长 7 天，半成品从最后有效推进起保留 7 天。

备份运行时会提示大型站点可能需要较长时间。阶段标题下方固定一行显示已导出的 SQL 大小和已处理的站点文件原始大小，数值随批次更新。

RC8 修复 WooCommerce 等插件开启后台输出缓冲时的大文件下载内存问题。升级后可从备份列表重新下载尚在保留期内的备份。

整套 TAR 下载名示例为 `18kcustoms.com-20261123-100935-a7f3c2e9.tar`，包含源站域名、站点时区的备份完成时间和备份 ID 前 8 位。旧备份升级后首次按新规则下载时使用当时的站点时区，之后重复下载沿用同名。

RC11 减少数据库导出的 HTTP 请求次数和批次等待。对照 RC10 时，使用同一站点和同一备份方式，记录「导出数据库」到「生成 ZIP 分包」之间的耗时。

临时维护异常时可在插件页面手动解除，搬家维护须使用「取消搬家并恢复原站」。后台无法访问且确需开放原站时，由主机管理员等当前备份请求结束后，移除该站工作目录中的 `maintenance.json`，再进后台确认任务状态。

实际使用范围与已验证限制见[验收记录](docs/acceptance.md)和[开发任务卡](docs/work/active/2026-11-22-plugin-development.md)。
