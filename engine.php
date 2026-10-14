<?php
/**
 * SubGuard 引擎独立触发器 v1.0
 * 作用: 不开浏览器也能跑规则引擎（= 处置中心「立即执行一次」）
 * 只允许 CLI: 防止公网 HTTP 触发
 * cron: */5 * * * * www php /www/wwwroot/<站>/engine.php >> /www/wwwroot/<站>/data/engine.log 2>&1
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/guard.php';

$fp = fopen(__DIR__ . '/data/engine.lock', 'c');
if (!flock($fp, LOCK_EX | LOCK_NB)) { echo "引擎已被占用，跳过\n"; exit(0); }

$r = guard_run();
echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
