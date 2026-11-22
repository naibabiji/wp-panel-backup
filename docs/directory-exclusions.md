# 已识别的缓存和备份目录

两个类别默认排除，管理员可以在高级选项中为当次备份关闭某个类别。预览列出实际目录，任务启动时重新识别并记录路径。扫描按精确路径跳过相应目录，索引记录原因及来源插件。被排除的目录在源站保留。

## 规则

下表路径相对于 `wp-content/`。默认目录使用已安装的插件入口作为归属证据，停用状态也能识别。UpdraftPlus、All-in-One WP Migration 和 WPvivid 的专用归档目录还可按已知归档名称和目录内容识别；自定义目录混有用途不明材料时继续备份。链接、站点根、公共内容根、插件和主题目录保留正常检查。

| 类型 | 插件 | 已支持目录 |
| --- | --- | --- |
| 备份 | UpdraftPlus | `updraft`，或可确认专用用途的 `updraft_dir` |
| 备份 | Duplicator | `duplicator-backups`、`backups-dup-lite`、`backups-dup-pro` |
| 备份 | All-in-One WP Migration | `ai1wm-backups`，或可确认专用用途的 `ai1wm_backups_path` |
| 备份 | WPvivid | `wpvividbackups`，或可确认专用用途的 `wpvivid_local_setting.path` |
| 备份 | BackWPup | 按当前上传路径及插件记录的站点标识识别 `backwpup/<标识>/backups` 和 `backwpup/<标识>/temp` |
| 缓存 | WP Super Cache | `cache/supercache` |
| 缓存 | W3 Total Cache | `cache/page_enhanced`、`cache/minify`、`cache/db`、`cache/object` |
| 缓存 | WP Fastest Cache | `cache/all`、`cache/wpfc-minified`、`cache/wpfc-mobile-cache`、`cache/wpfc-widget-cache` |
| 缓存 | WP Rocket | `cache/wp-rocket`、`cache/min`、`cache/busting`、`cache/critical-css`、`cache/used-css`、`cache/background-css` |
| 缓存 | LiteSpeed Cache | `litespeed` 静态缓存目录 |
| 缓存 | WP-Optimize | `cache/wpo-cache`、`cache/wpo-minify` |
| 缓存 | Cache Enabler | `cache/cache-enabler` |
| 缓存 | Autoptimize | `cache/autoptimize` |
| 缓存 | Breeze | `cache/breeze`、`cache/breeze-minification`、`cache/breeze-extra` |

证据来自 WordPress.org 对应插件的官方源码、[WP Rocket 缓存目录文档](https://docs.wp-rocket.me/article/99-pages-not-cached-or-optimizations-not-working)和 [LiteSpeed Cache 静态缓存文档](https://docs.litespeedtech.com/lscache/lscwp/ts-optimize/)。保留 `cache` 容器以及用途未知的子目录，WP-Optimize 的 `wpo-cache/config` 等配置目录照常备份。

标准的 `wp-content/object-cache.php` 和 `wp-content/advanced-cache.php` 固定排除，目录类别开关不影响它们。自定义数据库加载文件 `wp-content/db.php` 仍会在预检阻断。
