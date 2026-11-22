# WP PANEL Backup：AI 协作规则

## 先读

1. 本文件和 AI-CONTEXT.md、README.md。
2. docs/requirements.md、docs/architecture.md。
3. docs/integrations/wp-panel.md、docs/backup-format.md。
4. docs/pending-decisions.md、docs/acceptance.md。
5. docs/work/README.md、全部 docs/work/active/ 卡及 git status。
6. WordPress 工作先读 /home/luanc/Projects/_knowledge/wordpress/README.md，再读对应 distilled 指南；API 敏感问题按其规则检索本地知识。

## 项目与授权

- 独立 PHP WordPress 插件：WP PANEL Backup，目录 wp-panel-backup；只负责源站备份导出，不提供恢复功能。
- 可安装的开发版已在隔离 WordPress 及局域网源站实测备份。测试环境与历史结果见 docs/acceptance.md 和活动任务卡。正式支持范围以已确认的发布元数据为准。WP Panel 一键恢复及 WordPress.org 公开提交分开验收。
- 原型经验可以复用思路，不能直接复制 /tmp 原型当正式代码或把原型测试当成插件验收。
- 只做已确认需求。不确定的产品政策保留 Q，提出最小建议交用户决定；不能凭“用户同意开发”抹去剩余未决项。
- 不要求重新做 P5/P6 独立原型再开工；在实际模块开发后验证常见流程。

## 多 AI 协作

- 大任务先建立或更新自己的任务卡，说明职责、文件、依赖、授权、进度、验证和交接。
- 读全部活动卡，发现同文件或能力正在被其他 AI 修改，先协调，不覆盖、不重置他人改动。
- 任务卡记录进度，requirements 记录当前产品合同，backup-format 记录跨项目格式状态；各有职责，不靠聊天记录交接。
- 只按实际需求拆分 PHP 函数/类；不预建通用任务引擎、接口注册表、框架、未来恢复器或云存储。
- 不主动启动其他 AI；只有用户明确要求协作分工时再安排。
- 不自动 commit/push、设置远程、公开仓库、打包发布、部署、安装到真实网站。

## 测试范围

- 默认只测常见正常流程和直接相关失败提示，结果满足后停止。
- 不主动增加逐点强杀、全面并发、多重故障或绝对停写证明，不因此扩大实现。
- 修改 PHP 后做 php -l；实际成品完成后做真实 WordPress 正常备份、解压/重组及文件/数据库比对。
- 不重复全部原型；不以首页 200、ZIP 可打开或只有 CRC 代替数据准确性。
- 使用已有本地依赖，安装/联网下载或第三方服务按用户授权；WP-CLI 仅对本任务本地测试站。
- 新建测试环境先读 /home/luanc/Projects/LOCAL-DOCKER-PORTS.md 并检查 docker ps；不占用其他项目端口。

## 两项目边界与材料

- WP Panel 源码在 /home/luanc/Projects/go-panels/wp-panel，是私有开发仓库。只在任务明确授权时修改对方，双方各建任务卡并登记影响。
- 源插件不依赖面板连接、账号、root、数据库 CLI、Assistant 或网络直传。
- 文件格式先由插件项目记录候选，冻结前两端复核；变更须更新关联文档，不能两份 schema 各改各的。
- 不迁移源控制标记/面板授权身份；恢复端生成新配置/权限/身份，具体实现由面板项目负责。
- 备份、SQL、私钥、真实 wp-config、Cookie、密码、运行任务和测试站数据禁止提交。
- 当前文档含私有项目路径，发布安装包时仅允许列出的运行文件，不将整个仓库直接打 ZIP；发布规则另确认。
