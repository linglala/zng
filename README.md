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


## 用 Git 管理（推荐）

```bash
cd /www/wwwroot/你的站点目录
git init
git add .
git commit -m "SubGuard v1.3"
git remote add origin git@gitee.com:你的用户名/subguard.git   # 或 github
git push -u origin master
```

注意：`config.php`（含密码）和 `data/`（SQLite 数据）已被 .gitignore 排除，不会进仓库。

**以后更新**：本仓库发了新版后，在站点目录执行 `git pull`，只会更新程序文件，你的配置和数据库原样保留。
