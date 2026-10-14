<?php
/**
 * SubGuard 节点警报锁定工具 v1.1
 * 用法:
 *   php lock_by_uuid.php <uuid>       自动在 jk/tk 两库查找并收押
 *   php lock_by_uuid.php <uuid> tk    指定站点
 * 动作: 蜜罐套餐锁 + 重置 UUID/token（节点连接与订阅同步同时作废）
 */
$uuid = strtolower(trim($argv[1] ?? ''));
$site = $argv[2] ?? '';

if (!$uuid) { fwrite(STDERR, "用法: php lock_by_uuid.php <uuid> [tk|jk]\n"); exit(1); }

// 自动模式：逐站轮询（子进程隔离，避免两站配置互相污染）
if (!$site) {
    if (!function_exists('shell_exec')) {
        fwrite(STDERR, "shell_exec 被禁用，请带站点参数: php lock_by_uuid.php <uuid> tk|jk\n");
        exit(1);
    }
    foreach (['jk', 'tk'] as $s) {
        $out = shell_exec('php ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($uuid) . ' ' . $s . ' 2>/dev/null');
        if ($out && strpos($out, '查无此人') === false) {
            echo "[{$s} 站] " . trim($out) . "\n";
            exit(0);
        }
    }
    echo "jk/tk 双库均无此 UUID: {$uuid}\n";
    exit(0);
}

$base = "/www/wwwroot/{$site}.098757.xyz";
if (!file_exists($base . '/lib.php')) { echo "站点目录不存在: {$base}\n"; exit(1); }
require $base . '/lib.php';
require $base . '/guard.php';
$pre = guard_config()['db_prefix'];

$st = panel_db()->prepare("SELECT id,email FROM {$pre}user WHERE uuid = ?");
$st->execute([$uuid]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { echo "查无此人: {$uuid}\n"; exit(0); }

$g = guard_config();
panel_db()->prepare("UPDATE {$pre}user SET token = ?, uuid = ? WHERE id = ?")
    ->execute([bin2hex(random_bytes(16)), uuid4(), (int)$u['id']]);
db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')
    ->execute([(int)$u['id'], (int)$g['plan_quarantine'], '节点测活哨兵警报 ' . date('m-d H:i'), time()]);

echo "已收押 #{$u['id']} {$u['email']} → 套餐{$g['plan_quarantine']}\n";
