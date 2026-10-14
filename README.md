# SubGuard — 订阅异常监控台

专门盯反代订阅日志的小监控：异常 UA 实时流、token 独立 IP 排行榜（转售检测）、可疑 IP 名单、IP 归属查询。
PHP(≥7.2) + SQLite，无需 MySQL、无需 Composer，上传到宝塔即可用。

## 安装（5 分钟）

1. **改配置**：打开 `config.php`，**必须修改 `password`**（登录密码），确认 `log_files` 里的日志路径正确。
2. **建站点**：宝塔 → 网站 → 添加站点：
   - 域名填一个你能访问的（或直接用 `http://服务器IP:端口`，端口自己定）
   - 根目录指向上传后的 `subguard` 文件夹
   - PHP 版本选 7.2~8.x 任意一个
3. **上传**：把 `subguard` 整个文件夹上传到网站根目录（宝塔在线上传 zip 后解压即可），确认 `data` 目录存在且可写（默认 755 即可，www 用户要有写权限）。
4. **访问**：浏览器打开站点 → 输入密码进入。首次打开会自动导入日志末尾约 5MB 的历史数据（约几分钟内的事，取决于量）。

## 可选：计划任务保活

不装也行（打开页面时自动扫描）。想让数据无人值守也实时：

宝塔 → 计划任务 → 添加 Shell 脚本，**每分钟**执行：

```bash
php /www/wwwroot/你的站点目录/scan.php
```

## 可选：nginx 直接挡服务器 UA

在反代订阅的 site 配置里加（宝塔 → 网站 → 设置 → 配置文件，放在 `location /` 同级）：

```nginx
location /api/v1/client/subscribe {
    if ($http_user_agent ~* "Go-http-client|python-requests|subconverter|sub-store|aiohttp|scrapy") {
        return 403;
    }
    proxy_pass http://你的后端;
}
```

注意：别拦 `curl/`（会误伤路由器 fancyss 插件用户，它的 UA 里带 "|curl|" 但那不是裸 curl——上面正则没拦它就没事）。

## 页面说明

- **异常 UA 实时流**：命中爬虫/服务器/转换站 UA 的拉取记录，点 token 可复制，15 秒自动刷新
- **Token 独立 IP 排行榜**：单 token 当天被多少不同 IP 拉，≥5 标红（转售告警），点 token 看 IP 明细
- **可疑 IP 名单**：单 IP 拉多个 token / 异常 UA 高频 / 请求量异常；点 IP 看它拉了哪些 token；"查归属" 走 ipinfo.io（免费额度，别批量猛点）；"复制封禁" 一键复制 iptables 命令
- **4xx 探测**：封禁账号后还有人在试拉，会体现在这个计数里

## 常见问题

- **页面一直"加载中"**：多半是 PHP 没开 sqlite3 扩展（宝塔 → PHP 管理 → 安装扩展 → sqlite3）
- **数据为空**：先看页面顶部有没有红色警告条。最常见两个原因：
  1. **宝塔 open_basedir**：PHP 被锁在站点目录，读不到 `/www/wwwlogs/`。解决：宝塔 → 网站 → 设置 → 网站目录 → 取消勾选「防跨站攻击(open_basedir)」，或把 `/www/wwwlogs/` 加进 open_basedir，重载 PHP。
  2. **日志路径/权限**：SSH 跑 `sudo -u www php -r 'var_dump(is_readable("/www/wwwlogs/你的日志.log"));'` 验证 www 用户可读。
  验证导入：SSH 到站点目录跑 `php scan.php --full`，输出"入库 N 条"说明解析没问题，再查网页端。
- **刚过零点数据归零**：统计窗口是最近 24 小时，不受自然日切换影响；如果库里总条数正常但列表空，看 db_range 时间范围是否覆盖。
- **想清空重扫**：删掉 `data/subguard.db` 和 `data/subguard.db-wal` 即可

## v1.4~v2.1 更新日志

- **v1.8.3**：处置时同步推后 expired_at 防面板到期弹回；处置记录动作名中文化
- v1.8.2：IP 白名单（自家出口豁免统计/引擎）；登录防爆破（5错锁10分）
- v1.8.1：Token/邮箱黑名单改为"锁定套餐"（不封禁）；IP黑名单支持CIDR
- v1.7：远程日志源（rsync 拉取 + 一键生成同步命令）；TG 通知
- **v2.0.7**：系统设置网页化、TG 通知带面板名前缀、蜜罐化措辞
- v2.0.2：主动防御（新注册命中邮箱黑名单立即锁定）
- **v2.1.7**：重新收押"原地复活"模式；v2.1.5 撤销反悔；v2.1.4 异常流全量显示
- v2.1.2：处置记录分页 + 多选批量删除；v2.1.1 修复详情 JSON 尾部字符导致无法恢复套餐

## 新装机快速清单（每站必做）

1. 上传全部文件
2. `cp config.example.php config.php` → **改用户名和密码**
3. 宝塔网站设置 → 网站目录 → **关闭 open_basedir**（或追加 /www/wwwlogs/）
4. 网页登录 → 日志源 → 隧道 → TG → 规则，逐项配

## v2.1.8 ~ v2.2.1 更新日志

- **v2.2.1**：节点哨兵密钥**网页化管理**——「规则设置」页设密钥（node_key 存入 guard_config，两站同一把）；新增 `install_node_sentinel.sh` 节点一键安装脚本（交互输入密钥，从本仓库拉取哨兵）；新增 `engine.php` 引擎独立触发器（cron 每5分钟，不开浏览器也判案，输出 data/engine.log）
- **v2.2.0**：节点层测活防御三件套——`node_sentinel.sh`（增量扫节点日志，"查IP服务占比"判定测活）+ `node_hook.php`（独立接收端，验密钥→UUID对号主→收押+重置→TG战报→处置记录）+ `lock_by_uuid.php`（手动接力，自动双库识别）
- v2.1.10：处置记录多选批量撤销（全选 + 「撤销所选」）
- v2.1.9：修复规则设置保存丢失 auto_defense 字段
- v2.1.8：自动防御——单 IP 24h≥阈值→拉黑+重置+TG。**国内共享出口/CGNAT 环境慎用**，建议保持关闭，用 nginx per-token 限流替代

## 节点测活哨兵部署

1. **面板设密钥**：规则设置页最下方「节点哨兵 密钥」→ 随机生成 → 保存（jk/tk 两站设同一把）
2. **节点一键安装**（每节点机执行）：

```bash
curl -sSL https://raw.githubusercontent.com/linglala/zng/master/install_node_sentinel.sh | bash
```

按提示输入：Hook 地址（回车默认双站）/ NODE_KEY（面板那把）/ TG 回退通道（可跳过）

3. **观察期**：node_hook.php 的 `$DRY_RUN` 保持 `true`，TG 会收到"观察模式"战报；确认无误伤后改 `false` 开真锁

## 引擎无人值守（每站）

`/etc/cron.d/subguard` 或宝塔计划任务（www 用户）：

```
*/1 * * * * www php /www/wwwroot/<站>/scan.php
*/5 * * * * www php /www/wwwroot/<站>/engine.php >> /www/wwwroot/<站>/data/engine.log 2>&1
```

- `scan.php`：日志入库（1分钟）
- `engine.php`：规则引擎判案（5分钟），日志在 `data/engine.log`，随时 tail 自查
- 读写 SQLite 的进程必须都是 www（root 跑会污染属主）
