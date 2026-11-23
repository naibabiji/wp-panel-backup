# WP PANEL Backup

WP PANEL Backup 是一个 WordPress 备份和迁移插件，可把站点文件和数据库导出为可下载的备份。你可以保留本地副本，也可以使用兼容的 WP Panel 从备份创建同域名的新站。源站可独立运行插件，无需安装 WP Panel。

## 功能

- 导出站点根目录内的文件和受支持数据库中的全部表，包括自建表和历史前缀表。
- 提供正常备份、临时维护备份和搬家备份三种模式。备份分批推进，离开页面后可回来继续。
- 支持整套 TAR 下载，也可分别下载清单、文件索引和 ZIP 分包。
- 可为已完成的备份生成最长有效 12 小时的可撤销恢复链接，供兼容的 WP Panel 拉取材料。恢复链接支持 HTTP 和 HTTPS，HTTP 会明文传输备份数据。
- 最多保留最近两套成品，每套最长保留七天。

## 安装与使用

1. 下载 [WP PANEL Backup 1.0.0 安装包](https://github.com/naibabiji/wp-panel-backup/releases/download/v1.0.0/wp-panel-backup-1.0.0.zip)。
2. 在 WordPress 后台打开「插件 → 安装插件 → 上传插件」，上传 ZIP 并启用。
3. 打开「工具 → WP PANEL Backup」，选择备份模式与分包大小，点击「开始备份」。
4. 保持页面打开，直到备份完成。随后到「备份列表」下载成品，或生成恢复链接并粘贴到 WP Panel 的「从备份创建网站」页面。

## 运行要求

- WordPress 6.5 或以上，PHP 7.0 或以上。实际使用的 WordPress 版本须支持源站 PHP 版本。
- 64 位 PHP，启用 ZIP 扩展，并使用原生 mysqli 连接。
- WordPress 安装于网站根目录，采用标准 HTTP 或 HTTPS 端口，站点地址与 WordPress 地址一致。支持单站点。
- 数据库表使用 InnoDB，数据库账号可读取全部表及对象元数据。预检会拒绝视图、触发器、事件和存储程序。

备份包含数据库及站点配置副本。备份目录需由 Web 服务器禁止直接访问，Nginx 配置示例可在备份列表查看。恢复链接仅交给目标面板管理员。

## 许可证

[GPL-2.0-or-later](LICENSE.txt)
