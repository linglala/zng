<?php
/**
 * SubGuard 订阅监控台 - 配置文件
 * 上传后第一件事：修改 password！
 */
return [
    // 登录密码（必须修改）
    'password' => 'admin123',

    // 要监控的 Nginx 日志（宝塔默认在 /www/wwwlogs/，可填多个）
    'log_files' => [
        '/www/wwwlogs/139.196.180.104.log',
    ],

    // SQLite 数据文件位置（无需改动，确保 data 目录可写）
    'db' => __DIR__ . '/data/subguard.db',

    'timezone' => 'Asia/Shanghai',

    // 首次运行只导入日志末尾多少字节（太大的日志不要全量导入）
    'first_import_bytes' => 5000000,

    // api 自动扫描的最小间隔（秒）
    'scan_min_interval' => 20,

    // 数据保留天数
    'keep_days' => 7,

    // 异常 UA 特征（命中即标记，不区分大小写）
    'abnormal_ua' => [
        'Go-http-client', 'python-requests', 'python/', 'curl/', 'wget/',
        'okhttp', 'subconverter', 'sub-store', 'aiohttp', 'scrapy',
        'axios', 'undici', 'PostmanRuntime', 'Java/', 'libwww-perl',
        'Go-http-client/1.1', 'Nmap', 'sqlmap', 'masscan',
    ],

    // 判定阈值
    'token_ip_warn' => 5,            // 单 token 当天独立 IP 数 >= 此值标红（转售告警）
    'suspect_ip_tokens' => 3,        // 单 IP 当天拉的 token 数 >= 此值进可疑名单
    'suspect_ip_abnormal' => 5,      // 单 IP 当天异常 UA 命中 >= 此值进可疑名单
    'suspect_ip_hits' => 80,         // 单 IP 当天请求 >= 此值进可疑名单

    // ===== 面板库连接（auto_guard 用，需先搭 SSH 隧道，见 TUNNEL.md）=====
    'panel_db' => [
        'enabled' => false,              // 隧道搭好、MySQL 账号建好后改 true
        'host' => '127.0.0.1',
        'port' => 3307,                  // 隧道本地端口
        'user' => 'subguard',
        'pass' => '这里填你设置的MySQL密码',
        'dbname' => '你的面板库名',
        'table_prefix' => 'v2_',
    ],
];
