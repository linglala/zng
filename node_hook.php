<?php
/**
 * SubGuard 节点测活警报接收端 v1.3
 * 独立文件：不修改、不依赖 api.php 的任何现有代码。
 * 密钥：规则设置页「节点哨兵密钥」（guard_config.node_key），空 = 接口关闭
 * 留痕：每次警报写 action='node_alert'（总览「节点测活」卡片数据源）
 *       真收押另写 'lock' 记录（撤销/解锁兼容）
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/guard.php';
header('Content-Type: application/json; charset=utf-8');

$g        = guard_config();
$NODE_KEY = trim((string)($g['node_key'] ?? ''));   // 面板配置；空=接口关闭
$DRY_RUN  = false;                                  // 线上现状：自动收押（观察期确认无误伤）

// ---- 取参 ----
$p    = $_POST ?: $_GET;
$key  = (string)($p['key'] ?? '');
$uuid = strtolower(trim((string)($p['uuid'] ?? '')));
$node = substr(trim((string)($p['node'] ?? 'unknown')), 0, 40);
$hc   = (int)($p['hc'] ?? 0);
$other= (int)($p['other'] ?? 0);
$from = substr(trim((string)($p['ip'] ?? '')), 0, 45);
$ts   = (int)($p['ts'] ?? 0);

// ---- 校验 ----
if (!$NODE_KEY || !hash_equals($NODE_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad key']);
    exit;
}
if ($ts && abs(time() - $ts) > 600) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'stale ts']);
    exit;
}
if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad uuid']);
    exit;
}

$out = ['ok' => true, 'site' => state_get('panel_name', 'SubGuard')];
if (!(int)$g['plan_quarantine']) {
    echo json_encode(['ok' => false, 'error' => 'plan_quarantine 未配置']); exit;
}
if (!panel_db()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'panel db down']); exit;
}

// ---- 对号主（本站面板库）----
$pre = $g['db_prefix'];
$st = panel_db()->prepare("SELECT id,email,plan_id,uuid,banned,expired_at FROM {$pre}user WHERE uuid = ?");
$st->execute([$uuid]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { $out['found'] = false; echo json_encode($out); exit; }
$out['found'] = true; $out['email'] = $u['email'];

// ---- 统一留痕：任何结局都写 node_alert（总览卡片数据源）----
$alert = function($outcome) use ($u, $node, $uuid, $hc, $other, $from) {
    guard_log($u['id'], $u['email'], '', 'node_alert', json_encode([
        'node' => $node, 'uuid' => $uuid, 'hc' => $hc, 'other' => $other,
        'ip' => $from, 'outcome' => $outcome,
    ], JSON_UNESCAPED_UNICODE), 'done');
};

// ---- 已处置过的跳过（留痕后静默，幂等）----
if ($u['banned']) {
    $alert('banned');
    $out['skipped'] = 'banned';
    echo json_encode($out); exit;
}
if ((int)$u['plan_id'] === (int)$g['plan_quarantine']) {
    $alert('quarantined');
    $out['skipped'] = 'quarantined';
    echo json_encode($out); exit;
}

$reason = "节点测活警报[{$node}] 查IP域名{$hc}种/其他流量{$other} 来源IP:{$from}";
$detail = json_encode(['reason' => $reason, 'old_plan' => (int)$u['plan_id'],
    'old_expire' => (int)($u['expired_at'] ?? 0)], JSON_UNESCAPED_UNICODE);

// ---- 观察模式 ----
if ($DRY_RUN) {
    $alert('dry');
    tg_send("🚨 <b>节点测活警报 · 观察模式</b>\n🖥 节点：{$node}\n👤 用户：<code>{$u['email']}</code>\n📊 查IP域名 {$hc} 种 / 其他流量 {$other}\n🌐 来源IP：<code>{$from}</code>\n⏳ 未锁定（node_hook.php 改 \$DRY_RUN=false 后自动收押）");
    $out['locked'] = false; $out['dry'] = true;
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

// ---- 真锁（复刻 guard_punish 的 lock 路径，外加 token 重置）----
$gid = guard_plan_group($g['plan_quarantine']);
panel_db()->prepare("UPDATE {$pre}user SET plan_id = ?, group_id = ?, uuid = ?, token = ? WHERE id = ?")
    ->execute([(int)$g['plan_quarantine'], $gid, uuid4(), bin2hex(random_bytes(16)), (int)$u['id']]);
db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')
    ->execute([(int)$u['id'], (int)$g['plan_quarantine'], $reason, time()]);
guard_log($u['id'], $u['email'], '', 'lock', $detail . " ;新套餐={$g['plan_quarantine']}（节点哨兵收押）", 'done');
$alert('locked');
tg_send("🚫 <b>节点测活 · 蜜罐收押</b>\n🖥 节点：{$node}\n👤 用户：<code>{$u['email']}</code>\n🎯 查IP域名 {$hc} 种 / 其他流量 {$other}\n🌐 来源IP：<code>{$from}</code>\n📊 已锁定套餐#{$g['plan_quarantine']}，UUID/token 已重置");
$out['locked'] = true;
echo json_encode($out, JSON_UNESCAPED_UNICODE);
