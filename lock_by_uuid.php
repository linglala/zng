<?php
// ============================================================
// SubGuard 节点警报锁定工具 v1.0（香港机使用，独立 CLI）
// 用法: php lock_by_uuid.php <uuid> [tk|jk]
// 动作: 套餐锁收押 + 重置 UUID/token（节点连接与订阅同步同时掐死）
// ============================================================
$uuid = $argv[1] ?? '';
$site = ($argv[2] ?? 'tk') === 'jk' ? 'jk' : 'tk';
if (!$uuid) { fwrite(STDERR, "用法: php lock_by_uuid.php <uuid> [tk|jk]\n"); exit(1); }

$base = "/www/wwwroot/{$site}.098757.xyz";
require $base . '/lib.php';
require $base . '/guard.php';
$pre = guard_config()['db_prefix'];

$st = panel_db()->prepare("SELECT id,email FROM {$pre}user WHERE uuid = ?");
$st->execute([$uuid]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { echo "查无此人: $uuid\n"; exit(0); }

$g = guard_config();
panel_db()->prepare("UPDATE {$pre}user SET token = ?, uuid = ? WHERE id = ?")
    ->execute([bin2hex(random_bytes(16)), uuid4(), (int)$u['id']]);
db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')
    ->execute([(int)$u['id'], (int)$g['plan_quarantine'], '节点测活哨兵警报 ' . date('m-d H:i'), time()]);

echo "已收押 #{$u['id']} {$u['email']} → 套餐{$g['plan_quarantine']}（{$site}站）\n";
