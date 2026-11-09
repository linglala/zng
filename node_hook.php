<?php
/**
 * SubGuard 节点测活警报接收端 v1.4
 * 密钥：规则设置页「节点哨兵密钥」（guard_config.node_key），空=接口关闭
 * 处置：规则设置页「⑥ 节点哨兵」下拉（guard_config.rule_node_alert_action）
 *   dry=仅记录+TG观察（默认） / move=打入蜜罐 / lock=蜜罐+套餐锁 / reset=仅重置token+UUID
 * 留痕：任何结局都写 node_alert（总览「节点测活」卡片数据源）
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/guard.php';
header('Content-Type: application/json; charset=utf-8');

$g        = guard_config();
$NODE_KEY = trim((string)($g['node_key'] ?? ''));   // 面板配置；空=接口关闭
$ACT      = (string)($g['rule_node_alert_action'] ?? 'dry');   // 处置方式

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

// ---- 统一留痕：任何结局都写 node_alert（卡片数据源）----
$alert = function($outcome) use ($u, $node, $uuid, $hc, $other, $from) {
    guard_log($u['id'], $u['email'], '', 'node_alert', json_encode([
        'node' => $node, 'uuid' => $uuid, 'hc' => $hc, 'other' => $other,
        'ip' => $from, 'outcome' => $outcome,
    ], JSON_UNESCAPED_UNICODE), 'done');
};

// ---- 已处置过的跳过（幂等）----
if ($u['banned'])                          { $alert('banned');       $out['skipped'] = 'banned';       echo json_encode($out); exit; }
if ((int)$u['plan_id'] === (int)$g['plan_quarantine']) { $alert('quarantined'); $out['skipped'] = 'quarantined'; echo json_encode($out); exit; }

$reason = "节点测活警报[{$node}] 查IP域名{$hc}种/其他流量{$other} 来源IP:{$from}";
$detail = json_encode(['reason' => $reason, 'old_plan' => (int)$u['plan_id'],
    'old_expire' => (int)($u['expired_at'] ?? 0)], JSON_UNESCAPED_UNICODE);
$uid = (int)$u['id'];

if ($ACT === 'reset') {
    guard_exec("UPDATE {$pre}user SET token = ?, uuid = ? WHERE id = ?", [bin2hex(random_bytes(16)), uuid4(), $uid]);
    guard_log($uid, $u['email'], '', 'reset', $detail . ' ;token+UUID已重置（节点哨兵）', 'done');
    $alert('reset');
    tg_send("♻️ <b>节点测活 · 重置订阅</b>\n🖥 节点：{$node}\n👤 用户：<code>{$u['email']}</code>\n🎯 查IP域名 {$hc} 种 / 其他流量 {$other}\n🌐 来源IP：<code>{$from}</code>\n🔑 token+UUID 已换新");
    $out['locked'] = true; $out['action'] = 'reset';
} elseif ($ACT === 'move') {
    $gid = guard_plan_group($g['plan_quarantine']);
    guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ? WHERE id = ?", [(int)$g['plan_quarantine'], $gid, $uid]);
    guard_log($uid, $u['email'], '', 'move', $detail . " ;新套餐={$g['plan_quarantine']}（节点哨兵）", 'done');
    $alert('move');
    tg_send("🎯 <b>节点测活 · 打入蜜罐</b>\n🖥 节点：{$node}\n👤 用户：<code>{$u['email']}</code>\n🎯 查IP域名 {$hc} 种 / 其他流量 {$other}\n🌐 来源IP：<code>{$from}</code>\n📊 已移入套餐#{$g['plan_quarantine']}");
    $out['locked'] = true; $out['action'] = 'move';
} elseif ($ACT === 'lock') {
    $gid = guard_plan_group($g['plan_quarantine']);
    guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ?, uuid = ?, token = ? WHERE id = ?", [(int)$g['plan_quarantine'], $gid, uuid4(), bin2hex(random_bytes(16)), $uid]);
    db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')->execute([$uid, (int)$g['plan_quarantine'], $reason, time()]);
    guard_log($uid, $u['email'], '', 'lock', $detail . " ;新套餐={$g['plan_quarantine']}（节点哨兵收押）", 'done');
    $alert('lock');
    tg_send("🚫 <b>节点测活 · 蜜罐收押</b>\n🖥 节点：{$node}\n👤 用户：<code>{$u['email']}</code>\n🎯 查IP域名 {$hc} 种 / 其他流量 {$other}\n🌐 来源IP：<code>{$from}</code>\n📊 已锁定套餐#{$g['plan_quarantine']}，UUID/token 已重置");
    $out['locked'] = true; $out['action'] = 'lock';
} else {   // dry = 仅记录观察（默认，安全）
    $alert('dry');
    tg_send("👀 <b>节点测活警报 · 观察模式</b>\n🖥 节点：{$node}\n👤 用户：<code>{$u['email']}</code>\n📊 查IP域名 {$hc} 种 / 其他流量 {$other}\n🌐 来源IP：<code>{$from}</code>\n⏳ 未处置（规则设置页「⑥ 节点哨兵」可改处置方式）");
    $out['locked'] = false; $out['dry'] = true;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
