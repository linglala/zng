<?php
/**
 * SubGuard - auto_guard 处置引擎 + 管理接口 v1.5.2
 */

/** 生成 UUID v4（无扩展依赖） */
function uuid4() {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** 面板库连接（走 SSH 隧道 127.0.0.1:3307） */
function panel_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $g = guard_config();
    if (empty($g['db_enabled'])) return null;
    try {
        $pdo = new PDO("mysql:host={$g['db_host']};port={$g['db_port']};dbname={$g['db_name']};charset=utf8mb4", $g['db_user'], $g['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->query('SELECT 1');
        return $pdo;
    } catch (Throwable $t) {
        return null;
    }
}

/** 测试连接（失败返回错误信息） */
function panel_db_test() {
    $g = guard_config();
    try {
        $pdo = new PDO("mysql:host={$g['db_host']};port={$g['db_port']};dbname={$g['db_name']};charset=utf8mb4", $g['db_user'], $g['db_pass'], [PDO::ATTR_TIMEOUT => 5]);
        $pdo->query('SELECT 1');
        return ['ok' => true];
    } catch (Throwable $t) {
        return ['ok' => false, 'msg' => $t->getMessage()];
    }
}


/** TG 通知 */
function tg_send($text) {
    $g = guard_config();
    if (empty($g['tg_enabled']) || empty($g['tg_token']) || empty($g['tg_chatid'])) return false;
    $url = "https://api.telegram.org/bot{$g['tg_token']}/sendMessage";
    $payload = http_build_query(['chat_id' => $g['tg_chatid'], 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $payload, 'timeout' => 8], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r !== false;
}

function tg_test() {
    $ok = tg_send("✅ SubGuard TG 通知测试成功\n时间：" . date('Y-m-d H:i:s'));
    return $ok ? ['ok' => true] : ['ok' => false, 'msg' => '发送失败：检查 Token/ChatID，或本机无法访问 api.telegram.org'];
}

function guard_config() {
    $d = [
        'enabled' => false, 'dry_run' => true,
        'plan_quarantine' => 0,
        'rule_abnormal_hits' => 100,
        'rule_token_ips' => 10,
        'rule_ban_hits' => 300,
        'reset_uuid_on_move' => true,
        'reset_token_on_ban' => true,
        'interval' => 300,
        'db_enabled' => false, 'db_host' => '127.0.0.1', 'db_port' => 3307,
        'db_user' => 'subguard', 'db_pass' => '', 'db_name' => '', 'db_prefix' => 'v2_',
        'custom_ua' => '',
        'tg_enabled' => false, 'tg_token' => '', 'tg_chatid' => '',
        'auto_lock' => false,
    ];
    $j = state_get('guard_config');
    return $j ? array_merge($d, json_decode($j, true)) : $d;
}

function guard_save_config($arr) {
    $d = guard_config();
    foreach ($d as $k => $v) {
        if (!isset($arr[$k])) continue;
        if (is_bool($v)) $d[$k] = (bool)$arr[$k];
        elseif (is_int($v)) $d[$k] = (int)$arr[$k];
        else $d[$k] = trim((string)$arr[$k]);
    }
    state_set('guard_config', json_encode($d));
    return $d;
}

function guard_log($user_id, $email, $token, $action, $detail, $status) {
    $st = db()->prepare('INSERT INTO actions(ts,user_id,email,token,action,detail,status) VALUES(?,?,?,?,?,?,?)');
    $st->execute([time(), $user_id, $email, $token, $action, $detail, $status]);
}

function guard_token_info($token) {
    $pre = guard_config()['db_prefix'];
    $st = panel_db()->prepare("SELECT id,email,plan_id,uuid,banned,expired_at FROM {$pre}user WHERE token = ?");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function guard_plan_group($plan_id) {
    $pre = guard_config()['db_prefix'];
    $st = panel_db()->prepare("SELECT group_id FROM {$pre}plan WHERE id = ?");
    $st->execute([$plan_id]);
    return (int)$st->fetchColumn();
}

function guard_exec($sql, $params) {
    if (guard_config()['dry_run']) return true;
    $st = panel_db()->prepare($sql);
    return $st->execute($params);
}

function guard_status() {
    return guard_config()['dry_run'] ? 'dry' : 'done';
}

/** 处置一个用户：action = ban|move */
function guard_punish($u, $token, $action, $reason) {
    $g = guard_config();
    $pre = $g['db_prefix'];
    $detail = json_encode(['reason' => $reason, 'old_plan' => (int)$u['plan_id'], 'old_expire' => (int)($u['expired_at'] ?? 0)], JSON_UNESCAPED_UNICODE);
    if ($action === 'ban') {
        $new_token = $g['reset_token_on_ban'] ? bin2hex(random_bytes(16)) : null;
        $sql = "UPDATE {$pre}user SET banned = 1" . ($new_token ? ", token = ?" : "") . " WHERE id = ?";
        $params = $new_token ? [$new_token, $u['id']] : [$u['id']];
        guard_exec($sql, $params);
        guard_log($u['id'], $u['email'], $token, 'ban', $detail . ($new_token ? ' ;token已重置' : ''), guard_status());
    if (!$g['dry_run']) tg_send("🚫 <b>SubGuard 封禁</b>\n用户：{$u['email']}\n原因：{$reason}");
    } elseif ($action === 'lock') {
        $gid = guard_plan_group($g['plan_quarantine']);
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ?, expired_at = ? WHERE id = ?", [$g['plan_quarantine'], $gid, time() + 315360000, $u['id']]);
        if ($g['reset_uuid_on_move']) {
            guard_exec("UPDATE {$pre}user SET uuid = ? WHERE id = ?", [uuid4(), $u['id']]);
        }
        db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')->execute([(int)$u['id'], (int)$g['plan_quarantine'], '黑名单自动锁定：' . $reason, time()]);
        guard_log($u['id'], $u['email'], $token, 'lock', $detail . " ;新套餐={$g['plan_quarantine']}（已加入套餐锁名单）", guard_status());
        if (!$g['dry_run']) tg_send("🔒 <b>SubGuard 黑名单锁定</b>\n用户：{$u['email']}\n原因：{$reason}\n已挪入套餐{$g['plan_quarantine']}并写死");
    } else {
        $gid = guard_plan_group($g['plan_quarantine']);
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ?, expired_at = ? WHERE id = ?", [$g['plan_quarantine'], $gid, time() + 315360000, $u['id']]);
        if ($g['reset_uuid_on_move']) {
            guard_exec("UPDATE {$pre}user SET uuid = ? WHERE id = ?", [uuid4(), $u['id']]);
        }
        guard_log($u['id'], $u['email'], $token, 'move', $detail . " ;新套餐={$g['plan_quarantine']}", guard_status());
        if (!empty($g['auto_lock'])) {
            db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')->execute([(int)$u['id'], (int)$g['plan_quarantine'], 'auto：' . $reason, time()]);
        }
        if (!$g['dry_run']) tg_send("⚠️ <b>SubGuard 移入观察套餐</b>\n用户：{$u['email']}\n原因：{$reason}");
    }
}

/** 引擎主流程 */
function guard_run($force = false) {
    $g = guard_config();
    if (empty($g['enabled'])) return ['ok' => false, 'msg' => '引擎未启用'];
    if (!$force && time() - (int)state_get('guard_last_run', 0) < $g['interval']) return ['ok' => false, 'msg' => '间隔未到'];
    state_set('guard_last_run', time());
    if (!panel_db()) return ['ok' => false, 'msg' => '面板库连不上（检查隧道）'];

    $pdo = db();
    $today = time() - 86400;
    $candidates = [];

    $st = $pdo->prepare("SELECT token, COUNT(*) c FROM events WHERE ts >= ? AND reason != '' GROUP BY token HAVING c >= ?");
    $st->execute([$today, $g['rule_abnormal_hits']]);
    foreach ($st as $r) $candidates[$r['token']] = "异常UA命中{$r['c']}次";

    $st = $pdo->prepare("SELECT token, COUNT(DISTINCT ip) ips FROM events WHERE ts >= ? AND ip NOT IN (SELECT ip FROM wl_ips) GROUP BY token HAVING ips >= ?");
    $st->execute([$today, $g['rule_token_ips']]);
    foreach ($st as $r) if (!isset($candidates[$r['token']])) $candidates[$r['token']] = "独立IP {$r['ips']} 个";

    $st = $pdo->prepare("SELECT DISTINCT e.token FROM events e JOIN bl_tokens b ON b.token = e.token WHERE e.ts >= ?");
    $st->execute([$today]);
    foreach ($st as $r) $candidates[$r['token']] = '★黑名单';

    $done = $dry = $skip = 0;
    foreach ($candidates as $token => $reason) {
        $w = $pdo->prepare('SELECT 1 FROM wl_tokens WHERE token = ?');
        $w->execute([$token]);
        if ($w->fetchColumn()) { $skip++; continue; }
        $u = guard_token_info($token);
        if (!$u) { $skip++; continue; }
        if ($u['banned']) { $skip++; continue; }
        $b = $pdo->prepare('SELECT 1 FROM bl_accounts WHERE email = ?');
        $b->execute([$u['email']]);
        if ($b->fetchColumn()) {
            guard_punish($u, $token, 'lock', '命中账号黑名单');
            $g['dry_run'] ? $dry++ : $done++;
            continue;
        }
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM events WHERE ts >= ? AND token = ? AND reason != ''");
        $st2->execute([$today, $token]);
        $hits = (int)$st2->fetchColumn();
        if (strpos($reason, '黑名单') !== false) {
            guard_punish($u, $token, 'lock', $reason);
        } elseif ($hits >= $g['rule_ban_hits']) {
            guard_punish($u, $token, 'ban', $reason);
        } elseif ($g['plan_quarantine'] > 0) {
            guard_punish($u, $token, 'move', $reason);
        } else {
            $skip++; continue;
        }
        $g['dry_run'] ? $dry++ : $done++;
    }

    $locks = $pdo->query('SELECT * FROM plan_locks')->fetchAll(PDO::FETCH_ASSOC);
    $locked = 0;
    foreach ($locks as $l) {
        $pre = $g['db_prefix'];
        $st = panel_db()->prepare("SELECT id,email,plan_id,expired_at FROM {$pre}user WHERE id = ?");
        $st->execute([$l['user_id']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) continue;
        if ((int)$u['plan_id'] === (int)$l['plan_id']) continue;
        $detail = json_encode(['forced_plan' => (int)$l['plan_id'], 'bought_plan' => (int)$u['plan_id'], 'old_plan' => (int)$u['plan_id'], 'old_expire' => (int)($u['expired_at'] ?? 0)], JSON_UNESCAPED_UNICODE);
        $gid = guard_plan_group($l['plan_id']);
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ?, expired_at = ? WHERE id = ?", [$l['plan_id'], $gid, time() + 315360000, $l['user_id']]);
        guard_log($l['user_id'], $u['email'], '', 'plan_lock', $detail, guard_status());
        if (!$g['dry_run']) tg_send("🔒 <b>SubGuard 套餐锁拉回</b>\n用户：{$u['email']}\n买了套餐 {$u['plan_id']} → 强制拉回 {$l['plan_id']}");
        $g['dry_run'] ? $dry++ : $done++;
        $locked++;
    }

    return ['ok' => true, 'done' => $done, 'dry' => $dry, 'skip' => $skip, 'locks' => $locked, 'dry_run' => $g['dry_run']];
}

/** 撤销处置 */
function guard_revert($id) {
    if (!panel_db()) return ['ok' => false, 'msg' => '面板库连不上'];
    $st = db()->prepare('SELECT * FROM actions WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['ok' => false, 'msg' => '记录不存在'];
    if ($a['reverted']) return ['ok' => false, 'msg' => '已撤销过'];
    $d = json_decode($a['detail'], true);
    $pre = guard_config()['db_prefix'];

    if ($a['action'] === 'ban') {
        guard_exec("UPDATE {$pre}user SET banned = 0 WHERE id = ?", [$a['user_id']]);
    } elseif (in_array($a['action'], ['move', 'plan_lock', 'lock']) && isset($d['old_plan'])) {
        $gid = guard_plan_group($d['old_plan']);
        $exp = !empty($d['old_expire']) ? (int)$d['old_expire'] : time() + 31536000;
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ?, expired_at = ? WHERE id = ?", [$d['old_plan'], $gid, $exp, $a['user_id']]);
    }
    db()->prepare('UPDATE actions SET reverted = 1 WHERE id = ?')->execute([$id]);
    return ['ok' => true, 'dry' => guard_config()['dry_run']];
}

/* ===================== 管理接口 ===================== */

function bl_table($type) {
    return in_array($type, ['bl_tokens', 'bl_accounts', 'bl_ips', 'wl_tokens', 'wl_ips']) ? $type : null;
}

function api_bl_add($type, $lines) {
    $t = bl_table($type);
    if (!$t) return ['ok' => false];
    $n = 0; $skip = 0;
    $st = db()->prepare("INSERT OR REPLACE INTO $t VALUES(?, ?, ?)");
    foreach (preg_split('/[\r\n]+/', trim($lines)) as $line) {
        $line = trim($line);
        if (!$line) continue;
        $parts = preg_split('/\s+/', $line, 2);
        $val = $parts[0];
        $note = isset($parts[1]) ? $parts[1] : '';
        if ($t === 'bl_ips' && strpos($val, '/') === false && !filter_var($val, FILTER_VALIDATE_IP)) { $skip++; continue; }
        if ($t === 'wl_ips' && !filter_var($val, FILTER_VALIDATE_IP)) { $skip++; continue; }
        $st->execute([$val, $note, time()]);
        $n++;
    }
    return ['ok' => true, 'added' => $n, 'skipped' => $skip];
}

function api_bl_list($type) {
    $t = bl_table($type);
    if (!$t) return [];
    $out = [];
    $col = ['bl_tokens' => 'token', 'bl_accounts' => 'email', 'bl_ips' => 'ip', 'wl_tokens' => 'token', 'wl_ips' => 'ip'][$t];
    foreach (db()->query("SELECT $col AS val, note, ts FROM $t ORDER BY ts DESC LIMIT 500") as $r) {
        $out[] = ['val' => $r['val'], 'note' => $r['note'], 'ts' => (int)$r['ts']];
    }
    return $out;
}

function api_bl_del($type, $val) {
    $t = bl_table($type);
    if (!$t) return ['ok' => false];
    $col = ['bl_tokens' => 'token', 'bl_accounts' => 'email', 'bl_ips' => 'ip', 'wl_tokens' => 'token'][$t];
    db()->prepare("DELETE FROM $t WHERE $col = ?")->execute([$val]);
    return ['ok' => true];
}

function api_locks_list() {
    $out = [];
    foreach (db()->query('SELECT * FROM plan_locks ORDER BY ts DESC') as $r) {
        $email = '';
        if (panel_db()) {
            $pre = guard_config()['db_prefix'];
            $st = panel_db()->prepare("SELECT email FROM {$pre}user WHERE id = ?");
            $st->execute([$r['user_id']]);
            $email = $st->fetchColumn() ?: '(查无此人)';
        }
        $out[] = ['user_id' => (int)$r['user_id'], 'plan_id' => (int)$r['plan_id'], 'note' => $r['note'], 'email' => $email, 'ts' => (int)$r['ts']];
    }
    return $out;
}

function api_lock_add($input, $plan_id, $note) {
    $plan_id = (int)$plan_id;
    if (!$plan_id || !panel_db()) return ['ok' => false, 'msg' => '参数错误或面板库未连接'];
    $pre = guard_config()['db_prefix'];
    if (is_numeric($input)) {
        $st = panel_db()->prepare("SELECT id,email FROM {$pre}user WHERE id = ?");
    } else {
        $st = panel_db()->prepare("SELECT id,email FROM {$pre}user WHERE email = ?");
    }
    $st->execute([$input]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return ['ok' => false, 'msg' => '用户不存在'];
    db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')->execute([(int)$u['id'], $plan_id, $note, time()]);
    return ['ok' => true, 'user_id' => (int)$u['id'], 'email' => $u['email']];
}

function api_lock_del($user_id) {
    db()->prepare('DELETE FROM plan_locks WHERE user_id = ?')->execute([(int)$user_id]);
    return ['ok' => true];
}

function api_actions_list() {
    $out = [];
    foreach (db()->query('SELECT * FROM actions ORDER BY id DESC LIMIT 100') as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'time' => fmt_time((int)$r['ts']),
            'email' => $r['email'] ?: '-', 'action' => $r['action'],
            'detail' => $r['detail'], 'status' => $r['status'], 'reverted' => (int)$r['reverted'],
        ];
    }
    return $out;
}

/* ===================== 远程日志源 ===================== */

function api_logsrc_list() {
    $j = state_get('remote_logs');
    return $j ? json_decode($j, true) : [];
}

function api_logsrc_add($in) {
    $host = trim($in['host'] ?? ''); $user = trim($in['user'] ?: 'root');
    $port = (int)($in['port'] ?: 22); $rpath = trim($in['rpath'] ?? '');
    $mins = max(1, (int)($in['mins'] ?: 1));
    $name = trim($in['name'] ?: ($host . ' 日志'));
    if (!$host || !$rpath) return ['ok' => false, 'msg' => 'IP和远程路径必填'];
    $id = substr(md5($host . $rpath), 0, 8);
    $local = '/www/wwwlogs/remote-' . $id . '.log';
    $list = api_logsrc_list();
    $list[$id] = ['id' => $id, 'name' => $name, 'host' => $host, 'user' => $user, 'port' => $port, 'rpath' => $rpath, 'mins' => $mins, 'local' => $local, 'ts' => time()];
    state_set('remote_logs', json_encode($list, JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'id' => $id, 'local' => $local];
}

function api_logsrc_del($id) {
    $list = api_logsrc_list();
    unset($list[$id]);
    state_set('remote_logs', json_encode($list, JSON_UNESCAPED_UNICODE));
    return ['ok' => true];
}

/** 生成一次性同步配置命令（在香港机上执行） */
function api_logsrc_script($id) {
    $list = api_logsrc_list();
    if (empty($list[$id])) return ['ok' => false, 'msg' => '源不存在'];
    $r = $list[$id];
    $cronline = '*/' . $r['mins'] . ' * * * * rsync -az --append --chmod=D755,F644 -e "ssh -i /root/.ssh/logsrc_key -p ' . $r['port'] . ' -o StrictHostKeyChecking=accept-new" ' . $r['user'] . '@' . $r['host'] . ':' . $r['rpath'] . ' ' . $r['local'];
    $script = "ssh-keygen -t ed25519 -N '' -f /root/.ssh/logsrc_key -q
"
        . "ssh-copy-id -i /root/.ssh/logsrc_key.pub -p {$r['port']} {$r['user']}@{$r['host']}
"
        . "(crontab -l 2>/dev/null | grep -v '{$r['local']}'; echo '" . $cronline . "') | crontab -
"
        . "echo 同步任务已配置：每 {$r['mins']} 分钟拉取 {$r['rpath']}
"
        . "echo 稍等 {$r['mins']} 分钟后，SubGuard 自动开始监控 {$r['local']}";
    return ['ok' => true, 'script' => $script];
}
