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
    $name = state_get('panel_name', 'SubGuard');
    $text = "【{$name}】\n" . $text;
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
        'reset_uuid_on_move' => true,
        'reset_token_on_ban' => true,
        'interval' => 300,
        'db_enabled' => false, 'db_host' => '127.0.0.1', 'db_port' => 3307,
        'db_user' => 'subguard', 'db_pass' => '', 'db_name' => '', 'db_prefix' => 'v2_',
        'custom_ua' => '',
        'tg_enabled' => false, 'tg_token' => '', 'tg_chatid' => '',
        'auto_lock' => false, 'grace_hours' => 24,
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


/** 容错解析处置详情（兼容历史记录 JSON 尾部带中文的情况） */
function parse_action_detail($str) {
    $d = json_decode($str, true);
    if (is_array($d) && isset($d['old_plan'])) return $d;
    $out = [];
    if (preg_match('/"old_plan":(\d+)/', $str, $m)) $out['old_plan'] = (int)$m[1];
    if (preg_match('/"old_expire":(\d+)/', $str, $m)) $out['old_expire'] = (int)$m[1];
    return $out;
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
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ? WHERE id = ?", [$g['plan_quarantine'], $gid, $u['id']]);
        if ($g['reset_uuid_on_move']) {
            guard_exec("UPDATE {$pre}user SET uuid = ? WHERE id = ?", [uuid4(), $u['id']]);
        }
        db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')->execute([(int)$u['id'], (int)$g['plan_quarantine'], '黑名单自动锁定：' . $reason, time()]);
        guard_log($u['id'], $u['email'], $token, 'lock', $detail . " ;新套餐={$g['plan_quarantine']}（已加入套餐锁名单）", guard_status());
        if (!$g['dry_run']) tg_send("🚫 <b>黑名单触发 · 蜜罐收押</b>\n👤 用户：<code>{$u['email']}</code>\n🎯 原因：{$reason}\n📊 状态：已第一时间打入蜜罐套餐#{$g['plan_quarantine']}并锁死");
    } else {
        $gid = guard_plan_group($g['plan_quarantine']);
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ? WHERE id = ?", [$g['plan_quarantine'], $gid, $u['id']]);
        if ($g['reset_uuid_on_move']) {
            guard_exec("UPDATE {$pre}user SET uuid = ? WHERE id = ?", [uuid4(), $u['id']]);
        }
        guard_log($u['id'], $u['email'], $token, 'move', $detail . " ;新套餐={$g['plan_quarantine']}", guard_status());
        if (!empty($g['auto_lock'])) {
            db()->prepare('INSERT OR REPLACE INTO plan_locks VALUES(?,?,?,?)')->execute([(int)$u['id'], (int)$g['plan_quarantine'], 'auto：' . $reason, time()]);
        }
        if (!$g['dry_run']) tg_send("⚠️ <b>蜜罐收押</b>\n👤 用户：<code>{$u['email']}</code>\n🎯 原因：{$reason}\n📊 状态：已打入蜜罐套餐#{$g['plan_quarantine']}");
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

    $st = $pdo->prepare("SELECT token, COUNT(*) c FROM events WHERE ts >= ? AND reason != '' GROUP BY token");
    $st->execute([$today]);
    foreach ($st as $r) if ($r['c'] >= $g['rule_abnormal_hits']) $candidates[$r['token']] = "异常UA命中{$r['c']}次";

    $st = $pdo->prepare("SELECT token, COUNT(DISTINCT ip) ips FROM events WHERE ts >= ? AND ip NOT IN (SELECT ip FROM wl_ips) GROUP BY token");
    $st->execute([$today]);
    foreach ($st as $r) if ($r['ips'] >= $g['rule_token_ips'] && !isset($candidates[$r['token']])) $candidates[$r['token']] = "独立IP {$r['ips']} 个";

    $st = $pdo->prepare("SELECT DISTINCT e.token FROM events e JOIN bl_tokens b ON b.token = e.token WHERE e.ts >= ?");
    $st->execute([$today]);
    foreach ($st as $r) $candidates[$r['token']] = '★黑名单';

    // IP黑名单（支持CIDR段）：24h内从这些IP访问过的token全部进候选
    $bip_ranges = [];
    foreach ($pdo->query('SELECT ip FROM bl_ips') as $r) {
        $c = $r['ip'];
        $a = explode('/', $c); $mask = isset($a[1]) ? (int)$a[1] : 32;
        if ($mask < 0 || $mask > 32) continue;
        $ba = @ip2long($a[0]);
        if ($ba === false) continue;
        $bip_ranges[] = [$ba, $mask == 0 ? 0 : (~((1 << (32 - $mask)) - 1)) & 0xFFFFFFFF];
    }
    if ($bip_ranges) {
        foreach ($pdo->query("SELECT DISTINCT ip, token FROM events WHERE ts >= $today") as $r) {
            $l = @ip2long($r['ip']);
            if ($l === false) continue;
            foreach ($bip_ranges as $br) {
                if (($l & $br[1]) === ($br[0] & $br[1])) {
                    if (!isset($candidates[$r['token']])) $candidates[$r['token']] = '命中IP黑名单(' . $r['ip'] . ')';
                    break;
                }
            }
        }
    }

    $lt = locked_tokens();
    if ($lt) foreach ($lt as $t) unset($candidates[$t]);   // 已锁定的不再重复处置
    $done = $dry = $skip = 0;
    $skip_detail = [];
    foreach ($candidates as $token => $reason) {
        $w = $pdo->prepare('SELECT 1 FROM wl_tokens WHERE token = ? AND (expire IS NULL OR expire > ?)');
        $w->execute([$token, time()]);
        if ($w->fetchColumn()) { $skip++; $skip_detail[] = substr($token,0,10) . '：白名单/豁免期内'; continue; }
        $u = guard_token_info($token);
        if (!$u) { $skip++; $skip_detail[] = substr($token,0,10) . '：面板查无此token'; continue; }
        if ($u['banned']) { $skip++; $skip_detail[] = substr($token,0,10) . '：已封禁'; continue; }
        if ((int)$u['plan_id'] === (int)$g['plan_quarantine']) { $skip++; $skip_detail[] = substr($token,0,10) . '：已在蜜罐套餐'; continue; }
        $b = $pdo->prepare('SELECT 1 FROM bl_accounts WHERE email = ?');
        $b->execute([$u['email']]);
        if ($b->fetchColumn()) {
            guard_punish($u, $token, 'lock', '命中账号黑名单');
            $g['dry_run'] ? $dry++ : $done++;
            continue;
        }
        if (strpos($reason, '黑名单') !== false) {
            guard_punish($u, $token, 'lock', $reason);
        } elseif ($g['plan_quarantine'] > 0) {
            guard_punish($u, $token, 'move', $reason);
        } else {
            $skip++; continue;
        }
        $g['dry_run'] ? $dry++ : $done++;
    }

    // ===== 主动防御：新注册账号命中邮箱黑名单 → 立即锁定 =====
    $pre = $g['db_prefix'];
    $proactive = 0;
    $max_id = (int)state_get('bl_scan_maxid', 0);
    $stn = panel_db()->prepare("SELECT id, email, plan_id, uuid, banned FROM {$pre}user WHERE id > ? ORDER BY id LIMIT 500");
    $stn->execute([$max_id]);
    foreach ($stn as $nu) {
        $max_id = max($max_id, (int)$nu['id']);
        if ($nu['banned']) continue;
        if ((int)$nu['plan_id'] === (int)$g['plan_quarantine']) continue;
        $be = $pdo->prepare('SELECT 1 FROM bl_accounts WHERE email = ?');
        $be->execute([$nu['email']]);
        if ($be->fetchColumn()) {
            guard_punish($nu, '', 'lock', '注册即命中邮箱黑名单');
            $g['dry_run'] ? $dry++ : $done++;
            $proactive++;
        }
    }
    state_set('bl_scan_maxid', $max_id);

    $locks = $pdo->query('SELECT * FROM plan_locks')->fetchAll(PDO::FETCH_ASSOC);
    $locked = 0;
    foreach ($locks as $l) {
        $pre = $g['db_prefix'];
        $st = panel_db()->prepare("SELECT id,email,plan_id,expired_at FROM {$pre}user WHERE id = ?");
        $st->execute([$l['user_id']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) continue;
        if ((int)$u['plan_id'] === (int)$l['plan_id']) continue;
        $detail = json_encode(['forced_plan' => (int)$l['plan_id'], 'bought_plan' => (int)$u['plan_id'], 'old_plan' => (int)$u['plan_id'], 'old_expire' => $cur_exp], JSON_UNESCAPED_UNICODE);
        $gid = guard_plan_group($l['plan_id']);
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ? WHERE id = ?", [$l['plan_id'], $gid, $l['user_id']]);
        guard_log($l['user_id'], $u['email'], '', 'plan_lock', $detail, guard_status());
        if (!$g['dry_run']) tg_send("🔒 <b>蜜罐拉回</b>\n👤 用户：<code>{$u['email']}</code>\n⚡ 动作：购买套餐 #{$u['plan_id']}\n📊 状态：已强制拉回蜜罐套餐#{$l['plan_id']}");
        $g['dry_run'] ? $dry++ : $done++;
        $locked++;
    }

    return ['ok' => true, 'done' => $done, 'dry' => $dry, 'skip' => $skip, 'locks' => $locked, 'dry_run' => $g['dry_run'], 'candidates' => count($candidates), 'skip_detail' => array_slice($skip_detail, 0, 30), 'proactive' => $proactive];
}

/** 撤销处置 */
function guard_revert($id) {
    if (!panel_db()) return ['ok' => false, 'msg' => '面板库连不上'];
    $st = db()->prepare('SELECT * FROM actions WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['ok' => false, 'msg' => '记录不存在'];
    if ($a['reverted']) return ['ok' => false, 'msg' => '已撤销过'];
    $d = parse_action_detail($a['detail']);
    $pre = guard_config()['db_prefix'];

    if ($a['action'] === 'ban') {
        guard_exec("UPDATE {$pre}user SET banned = 0 WHERE id = ?", [$a['user_id']]);
    } elseif (in_array($a['action'], ['move', 'plan_lock', 'lock']) && isset($d['old_plan'])) {
        $gid = guard_plan_group($d['old_plan']);
        guard_exec("UPDATE {$pre}user SET plan_id = ?, group_id = ? WHERE id = ?", [$d['old_plan'], $gid, $a['user_id']]);
    }
    db()->prepare('UPDATE actions SET reverted = 1 WHERE id = ?')->execute([$id]);
    // 撤销必须同时拆除套餐锁 + 限时豁免，否则几分钟后会被强制拉回
    db()->prepare('DELETE FROM plan_locks WHERE user_id = ?')->execute([(int)$a['user_id']]);
    $g2 = guard_config();
    if (!$g2['dry_run'] && panel_db()) {
        $tk = panel_db()->prepare("SELECT token, email FROM {$pre}user WHERE id = ?");
        $tk->execute([(int)$a['user_id']]);
        if ($u = $tk->fetch(PDO::FETCH_ASSOC)) {
            db()->prepare('INSERT OR REPLACE INTO wl_tokens VALUES(?,?,?,?)')->execute([$u['token'], '撤销豁免' . $g2['grace_hours'] . 'h ' . ($u['email'] ?: ''), time(), time() + $g2['grace_hours'] * 3600]);
        }
    }
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


/** 当前锁名单对应的 token 列表（用于豁免显示/统计） */
function locked_tokens() {
    $ids = [];
    foreach (db()->query('SELECT user_id FROM plan_locks') as $r) $ids[] = (int)$r['user_id'];
    if (!$ids || !panel_db()) return [];
    $pre = guard_config()['db_prefix'];
    $in = implode(',', $ids);
    $out = [];
    foreach (panel_db()->query("SELECT token FROM {$pre}user WHERE id IN ($in)") as $r) $out[] = $r['token'];
    return $out;
}

/** 当前生效的白名单 token（含限时豁免期内的） */
function active_wl_tokens() {
    $out = [];
    foreach (db()->query('SELECT token FROM wl_tokens WHERE expire IS NULL OR expire > ' . time()) as $r) $out[] = $r['token'];
    return $out;
}

/** 解锁并恢复原套餐（依据该用户最近的处置记录） */
function restore_user_plan($uid) {
    if (!panel_db()) return false;
    $pre = guard_config()['db_prefix'];
    $st = db()->prepare("SELECT * FROM actions WHERE user_id = ? AND action IN ('move','plan_lock','lock') ORDER BY id DESC LIMIT 20");
    $st->execute([$uid]);
    $a = null; $d = null;
    foreach ($st as $row) {
        $dd = parse_action_detail($row['detail']);
        if (!empty($dd['old_plan'])) { $a = $row; $d = $dd; break; }
    }
    if (!$a) { db()->prepare('DELETE FROM plan_locks WHERE user_id = ?')->execute([$uid]); return false; }
    $gid = guard_plan_group($d['old_plan']);
    $exp = !empty($d['old_expire']) ? (int)$d['old_expire'] : time() + 2592000;
    if (!guard_config()['dry_run']) {
        $st2 = panel_db()->prepare("UPDATE {$pre}user SET plan_id = ?, group_id = ? WHERE id = ?");
        $st2->execute([(int)$d['old_plan'], $gid, (int)$uid]);
        // 加入白名单，防止几分钟后被旧记录重新锁定
        $tk = panel_db()->prepare("SELECT token, email FROM {$pre}user WHERE id = ?");
        $tk->execute([(int)$uid]);
        if ($u = $tk->fetch(PDO::FETCH_ASSOC)) {
            db()->prepare('INSERT OR REPLACE INTO wl_tokens VALUES(?,?,?,?)')->execute([$u['token'], '解锁豁免' . $g['grace_hours'] . 'h ' . ($u['email'] ?: ''), time(), time() + $g['grace_hours'] * 3600]);
        }
    }
    db()->prepare('UPDATE actions SET reverted = 1 WHERE user_id = ?')->execute([$a['id']]);
    db()->prepare('DELETE FROM plan_locks WHERE user_id = ?')->execute([(int)$uid]);
    return true;
}

function api_locks_list($q = '') {
    $out = [];
    $q = trim((string)$q);
    $rows = $q !== ''
        ? call_user_func(function() use ($q) {
            $st = db()->prepare("SELECT * FROM plan_locks WHERE user_id LIKE ? OR note LIKE ? ORDER BY ts DESC");
            $like = "%{$q}%";
            $st->execute([$like, $like]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        })
        : db()->query('SELECT * FROM plan_locks ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
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
    return ['ok' => true, 'restored' => restore_user_plan((int)$user_id)];
}

function api_actions_list($q = '', $page = 1) {
    $out = ['total' => 0, 'page' => max(1, (int)$page), 'pages' => 1, 'list' => []];
    $per = 50;
    $q = trim((string)$q);
    if ($q !== '') {
        $like = "%{$q}%";
        $st = db()->prepare("SELECT COUNT(*) FROM actions WHERE email LIKE ? OR token LIKE ? OR detail LIKE ? OR action LIKE ?");
        $st->execute([$like, $like, $like, $like]);
        $out['total'] = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT * FROM actions WHERE email LIKE ? OR token LIKE ? OR detail LIKE ? OR action LIKE ? ORDER BY id DESC LIMIT $per OFFSET " . (($out['page'] - 1) * $per));
        $st->execute([$like, $like, $like, $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $out['total'] = (int)db()->query('SELECT COUNT(*) FROM actions')->fetchColumn();
        $rows = db()->query('SELECT * FROM actions ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($out['page'] - 1) * $per))->fetchAll(PDO::FETCH_ASSOC);
    }
    $out['pages'] = max(1, ceil($out['total'] / $per));
    foreach ($rows as $r) {
        $out['list'][] = [
            'id' => (int)$r['id'], 'time' => fmt_time((int)$r['ts']),
            'email' => $r['email'] ?: '-', 'action' => $r['action'],
            'detail' => $r['detail'], 'status' => $r['status'], 'reverted' => (int)$r['reverted'],
        ];
    }
    return $out;
}

/** 批量删除处置记录（只删日志，不影响用户套餐） */
function guard_actions_del($ids) {
    $ids = array_filter(array_map('intval', (array)$ids));
    if (!$ids) return ['ok' => false, 'msg' => '未选择记录'];
    $in = implode(',', $ids);
    db()->exec("DELETE FROM actions WHERE id IN ($in)");
    return ['ok' => true, 'deleted' => count($ids)];
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

/* ===================== 系统设置 ===================== */
function api_sys_get() {
    return ['ok' => true, 'panel_name' => state_get('panel_name', 'SubGuard')];
}
function api_sys_save_name($name) {
    $name = trim((string)$name);
    if ($name === '') return ['ok' => false, 'msg' => '名字不能为空'];
    state_set('panel_name', mb_substr($name, 0, 30));
    return ['ok' => true];
}
function api_sys_change_pwd($new) {
    $new = trim((string)$new);
    if (strlen($new) < 8) return ['ok' => false, 'msg' => '密码至少 8 位'];
    if (strpos($new, "'") !== false || strpos($new, '\\') !== false) return ['ok' => false, 'msg' => '密码不能含单引号或反斜杠'];
    $p = __DIR__ . '/config.php';
    $c = @file_get_contents($p);
    if ($c === false) return ['ok' => false, 'msg' => 'config.php 不可读'];
    $c2 = preg_replace("/'password'\s*=>\s*'[^']*'/", "'password' => '{$new}'", $c, 1);
    if ($c2 === $c || $c2 === null) return ['ok' => false, 'msg' => '写入失败：config.php 格式异常'];
    if (@file_put_contents($p, $c2) === false) return ['ok' => false, 'msg' => 'config.php 不可写（检查 www 权限）'];
    return ['ok' => true];
}

function api_sys_save_user($user) {
    $user = trim((string)$user);
    if ($user === '' || strlen($user) > 30) return ['ok' => false, 'msg' => '用户名1-30字符'];
    if (strpos($user, "'") !== false || strpos($user, '\\') !== false) return ['ok' => false, 'msg' => '用户名不能含单引号或反斜杠'];
    $p = __DIR__ . '/config.php';
    $c = @file_get_contents($p);
    if ($c === false) return ['ok' => false, 'msg' => 'config.php 不可读'];
    if (preg_match("/'username'\s*=>/", $c)) {
        $c2 = preg_replace("/'username'\s*=>\s*'[^']*'/", "'username' => '{$user}'", $c, 1);
    } else {
        $c2 = preg_replace("/('password'\s*=>)/", "'username' => '{$user}',
    $1", $c, 1);
    }
    if ($c2 === $c || $c2 === null) return ['ok' => false, 'msg' => '写入失败'];
    if (@file_put_contents($p, $c2) === false) return ['ok' => false, 'msg' => 'config.php 不可写'];
    return ['ok' => true];
}

/** 重新收押（撤销后的反悔）：执行锁定后，把原记录"原地复活"而非生成新记录 */
function api_relock($email, $action_id) {
    $pre = guard_config()['db_prefix'];
    $st = panel_db()->prepare("SELECT id, email, plan_id, uuid, banned FROM {$pre}user WHERE email = ?");
    $st->execute([trim((string)$email)]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return ['ok' => false, 'msg' => '用户不存在'];
    if ($u['banned']) return ['ok' => false, 'msg' => '该用户已封禁'];
    guard_punish($u, '', 'lock', '手动重新收押');
    // 删除刚生成的新记录，把原记录复活（保留原 old_plan 供将来撤销/解锁）
    $new_id = (int)db()->query('SELECT MAX(id) FROM actions WHERE user_id = ' . (int)$u['id'])->fetchColumn();
    db()->exec("DELETE FROM actions WHERE id = $new_id");
    $orig = db()->prepare('SELECT detail FROM actions WHERE id = ?');
    $orig->execute([(int)$action_id]);
    $d = parse_action_detail($orig->fetchColumn());
    $new_detail = json_encode([
        'reason' => '手动重新收押',
        'old_plan' => (int)($d['old_plan'] ?? $u['plan_id']),
        'old_expire' => (int)($d['old_expire'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    db()->prepare('UPDATE actions SET reverted = 0, ts = ?, status = ?, detail = ?, action = ? WHERE id = ?')
        ->execute([time(), guard_config()['dry_run'] ? 'dry' : 'done', $new_detail, 'lock', (int)$action_id]);
    return ['ok' => true, 'email' => $u['email']];
}
