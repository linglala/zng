<?php
/**
 * SubGuard - 核心库：日志解析、扫描入库、统计查询
 */
function cfg() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}
date_default_timezone_set(cfg()['timezone']);

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg();
    if (!is_dir(dirname($c['db']))) mkdir(dirname($c['db']), 0755, true);
    $pdo = new PDO('sqlite:' . $c['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        ip TEXT NOT NULL,
        token TEXT NOT NULL,
        ua TEXT NOT NULL,
        flag TEXT NOT NULL,
        status INTEGER NOT NULL,
        reason TEXT NOT NULL DEFAULT ""
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_token ON events(token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_ip ON events(ip)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS bl_tokens (token TEXT PRIMARY KEY, note TEXT, ts INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS bl_accounts (email TEXT PRIMARY KEY, note TEXT, ts INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS bl_ips (ip TEXT PRIMARY KEY, note TEXT, ts INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS wl_tokens (token TEXT PRIMARY KEY, note TEXT, ts INTEGER)');
    try { $pdo->exec('ALTER TABLE wl_tokens ADD COLUMN expire INTEGER'); } catch (Throwable $e) {}
    $pdo->exec('CREATE TABLE IF NOT EXISTS wl_ips (ip TEXT PRIMARY KEY, note TEXT, ts INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS plan_locks (user_id INTEGER PRIMARY KEY, plan_id INTEGER, note TEXT, ts INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS actions (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, user_id INTEGER, email TEXT, token TEXT, action TEXT, detail TEXT, status TEXT, reverted INTEGER DEFAULT 0)');
    return $pdo;
}

function state_get($k, $d = null) {
    $st = db()->prepare('SELECT v FROM state WHERE k=?');
    $st->execute([$k]);
    $r = $st->fetchColumn();
    return $r === false ? $d : $r;
}

function state_set($k, $v) {
    $st = db()->prepare('INSERT OR REPLACE INTO state(k,v) VALUES(?,?)');
    $st->execute([$k, (string)$v]);
}

/** 解析一行 Nginx combined 日志，只处理订阅请求 */
function parse_line($line) {
    if (strpos($line, '/api/v1/client/subscribe') === false) return null;
    // IP - - [时间] "METHOD URI HTTP/x" 状态码 ...
    if (!preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"]*) (?:HTTP\/[^"]*)" (\d{3}) /', $line, $m)) return null;
    // 行尾最后一个引号字段 = UA
    if (!preg_match('/"((?:[^"\\\\]|\\\\.)*)"\s*$/', $line, $mu)) return null;

    $qs = parse_url($m[4], PHP_URL_QUERY);
    $q = [];
    if ($qs) parse_str($qs, $q);
    $token = isset($q['token']) ? preg_replace('/[^a-f0-9]/', '', strtolower($q['token'])) : '';
    if (!$token) return null;

    $ua = stripcslashes($mu[1]);
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2], new DateTimeZone('UTC'));
    if (!$dt) return null;

    return [
        'ts'     => $dt->getTimestamp(),
        'ip'     => $m[1],
        'token'  => $token,
        'ua'     => substr($ua, 0, 300),
        'flag'   => isset($q['flag']) ? substr($q['flag'], 0, 30) : '',
        'status' => (int)$m[5],
        'reason' => detect_reason($ua),
    ];
}

function detect_reason($ua) {
    $patterns = cfg()['abnormal_ua'];
    $j = state_get('guard_config');
    if ($j) {
        $g = json_decode($j, true);
        if (!empty($g['custom_ua'])) {
            foreach (preg_split('/[\r\n]+/', $g['custom_ua']) as $p) {
                $p = trim($p);
                if ($p !== '') $patterns[] = $p;
            }
        }
    }
    foreach ($patterns as $p) {
        if (stripos($ua, $p) !== false) return 'abnormal_ua:' . $p;
    }
    return '';
}


function fmt_time($ts) {
    return date('m-d H:i:s', $ts);
}

function flush_batch($batch) {
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO events(ts,ip,token,ua,flag,status,reason) VALUES(?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    foreach ($batch as $e) $st->execute([$e['ts'], $e['ip'], $e['token'], $e['ua'], $e['flag'], $e['status'], $e['reason']]);
    $pdo->commit();
}

/** 扫描日志增量并入库（带文件锁，可安全并发/重复调用） */
function run_scan($full = false) {
    $c = cfg();
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
    $lock = fopen(__DIR__ . '/data/scan.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) return ['locked' => true, 'inserted' => 0, 'scanned' => 0];

    $inserted = 0; $scanned = 0;
    try {
        $files = $c['log_files'];
    $rj = state_get('remote_logs');
    if ($rj) foreach (json_decode($rj, true) as $r) $files[] = $r['local'];
    foreach (array_unique($files) as $path) {
            if (!is_readable($path)) continue;
            clearstatcache(true, $path);
            $size = filesize($path);
            $key = 'offset:' . md5($path);
            $off = $full ? 0 : (int)state_get($key, -1);
            if ($off < 0) $off = max(0, $size - $c['first_import_bytes']); // 首次导入末尾一段
            if ($off > $size) $off = 0; // 日志被切割，从头来

            $fp = fopen($path, 'rb');
            fseek($fp, $off);
            $batch = [];
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line === false) break;
                $scanned++;
                $e = parse_line($line);
                if ($e) { $batch[] = $e; $inserted++; }
                if (count($batch) >= 500) { flush_batch($batch); $batch = []; }
            }
            if ($batch) flush_batch($batch);
            state_set($key, (int)ftell($fp));
            fclose($fp);
        }
        db()->exec('DELETE FROM events WHERE ts < ' . (time() - $c['keep_days'] * 86400));
        state_set('last_scan', time());
    } catch (Throwable $t) {
        flock($lock, LOCK_UN);
        return ['locked' => false, 'inserted' => $inserted, 'scanned' => $scanned, 'error' => $t->getMessage()];
    }
    flock($lock, LOCK_UN);
    return ['locked' => false, 'inserted' => $inserted, 'scanned' => $scanned];
}

function api_stats() {
    $c = cfg();
    $pdo = db();
    $today = time() - 86400; // 最近24小时（避免刚过零点看不到昨天数据）
    $out = ['today' => date('Y-m-d H:i:s'), 'cards' => [], 'ua_rank' => [], 'token_rank' => [], 'suspect_ips' => [], 'abnormal_stream' => [], 'errors' => []];

    // 日志文件可读性检查（宝塔 open_basedir 常导致读不到，这里直接暴露出来）
    $out['log_check'] = [];
    foreach ($c['log_files'] as $p) {
        $out['log_check'][] = ['path' => $p, 'readable' => is_readable($p)];
    }
    // 库内数据总量与时间范围（判断导入是否成功）
    $out['cards']['db_total'] = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $rng = $pdo->query('SELECT MIN(ts) f, MAX(ts) l FROM events')->fetch(PDO::FETCH_ASSOC);
    $out['db_range'] = ($rng && $rng['f']) ? [date('m-d H:i', $rng['f']), date('m-d H:i', $rng['l'])] : null;

    $out['cards']['total']   = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE ts >= $today")->fetchColumn();
    $out['cards']['abnormal']= (int)$pdo->query("SELECT COUNT(*) FROM events WHERE ts >= $today AND reason != ''")->fetchColumn();
    $out['cards']['abnormal_tokens'] = (int)$pdo->query("SELECT COUNT(DISTINCT token) FROM events WHERE ts >= $today AND reason != ''")->fetchColumn();
    $out['cards']['err4xx']  = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE ts >= $today AND status >= 400")->fetchColumn();
    $out['cards']['last_scan'] = (int)state_get('last_scan', 0);
    // 处置统计：24h 动作数 / 当前锁名单人数 / 24h 各动作分布
    $out['cards']['acts_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM actions WHERE ts >= $today AND status = 'done'")->fetchColumn();
    $out['cards']['locks_now'] = (int)$pdo->query('SELECT COUNT(*) FROM plan_locks')->fetchColumn();
    $out['cards']['acts_reverted'] = (int)$pdo->query("SELECT COUNT(*) FROM actions WHERE ts >= $today AND reverted = 1")->fetchColumn();
    $out['act_sum'] = [];
    foreach ($pdo->query("SELECT action, COUNT(*) c FROM actions WHERE ts >= $today AND status = 'done' GROUP BY action") as $r) {
        $out['act_sum'][] = ['action' => $r['action'], 'count' => (int)$r['c']];
    }

    // 异常 UA 排行
    foreach ($pdo->query("SELECT ua, COUNT(*) c FROM events WHERE ts >= $today AND reason != '' GROUP BY ua ORDER BY c DESC LIMIT 20") as $r) {
        $out['ua_rank'][] = ['ua' => $r['ua'], 'count' => (int)$r['c']];
    }

    // 涉异常 token 排行（按异常 UA 命中次数排序）
    $out['abnormal_token_rank'] = [];
    foreach ($pdo->query("SELECT token, COUNT(*) hits, COUNT(DISTINCT ip) ips, COUNT(DISTINCT ua) uas, MAX(ts) last_ts, GROUP_CONCAT(DISTINCT ua) ua_list FROM events WHERE ts >= $today AND reason != '' GROUP BY token ORDER BY hits DESC LIMIT 30") as $r) {
        $out['abnormal_token_rank'][] = [
            'token' => $r['token'], 'hits' => (int)$r['hits'], 'ips' => (int)$r['ips'],
            'uas' => (int)$r['uas'], 'last' => fmt_time($r['last_ts']), 'ua_list' => $r['ua_list'],
        ];
    }

    // token 独立 IP 排行榜（前 50）
    $warn = $c['token_ip_warn'];
    foreach ($pdo->query("SELECT token, COUNT(*) hits, COUNT(DISTINCT CASE WHEN ip NOT IN (SELECT ip FROM wl_ips) THEN ip END) ips, MIN(ts) first_ts, MAX(ts) last_ts FROM events WHERE ts >= $today GROUP BY token ORDER BY ips DESC, hits DESC LIMIT 50") as $r) {
        $ua = $pdo->query("SELECT ua FROM events WHERE token = " . db()->quote($r['token']) . " GROUP BY ua ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
        $out['token_rank'][] = [
            'token' => $r['token'], 'hits' => (int)$r['hits'], 'ips' => (int)$r['ips'],
            'first' => fmt_time($r['first_ts']), 'last' => fmt_time($r['last_ts']),
            'ua' => $ua ?: '-', 'warn' => (int)$r['ips'] >= $warn,
        ];
    }

    // 可疑 IP：多拉 token / 异常UA多 / 高频
    $sql = "SELECT ip, COUNT(*) hits, COUNT(DISTINCT token) tokens,
                   SUM(CASE WHEN reason != '' THEN 1 ELSE 0 END) abnormal, MAX(ts) last_ts
            FROM events WHERE ts >= $today AND ip NOT IN (SELECT ip FROM wl_ips) GROUP BY ip
            HAVING tokens >= {$c['suspect_ip_tokens']} OR abnormal >= {$c['suspect_ip_abnormal']} OR hits >= {$c['suspect_ip_hits']}
            ORDER BY tokens DESC, abnormal DESC, hits DESC LIMIT 100";
    foreach ($pdo->query($sql) as $r) {
        $geo = state_get('geo:' . $r['ip']);
        $out['suspect_ips'][] = [
            'ip' => $r['ip'], 'hits' => (int)$r['hits'], 'tokens' => (int)$r['tokens'],
            'abnormal' => (int)$r['abnormal'], 'last' => fmt_time($r['last_ts']),
            'geo' => $geo ? json_decode($geo, true) : null,
        ];
    }

    // 异常 UA 实时流（最近 50 条）
    $lockedT = function_exists('locked_tokens') ? locked_tokens() : [];
    $exclT = function_exists('active_wl_tokens') ? active_wl_tokens() : [];   // 不隐藏任何记录，仅打标记
    $stSt = $pdo->prepare("SELECT ts, ip, token, flag, ua, reason, status FROM events WHERE ts >= ? AND reason != '' ORDER BY id DESC LIMIT 50");
    $stSt->execute([$today]);
    foreach ($stSt as $r) {
        $out['abnormal_stream'][] = [
            'time' => fmt_time($r['ts']), 'ip' => $r['ip'], 'token' => $r['token'],
            'flag' => $r['flag'], 'ua' => $r['ua'], 'reason' => $r['reason'], 'status' => (int)$r['status'],
            'locked' => in_array($r['token'], $lockedT),
            'exempt' => in_array($r['token'], $exclT),
        ];
    }
    return $out;
}

function api_token_detail($token) {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
    if (!$token) return ['ips' => []];
    $today = strtotime(date('Y-m-d 00:00:00'));
    $st = db()->prepare("SELECT ip, COUNT(*) hits, MAX(ts) last_ts, GROUP_CONCAT(DISTINCT ua) uas, GROUP_CONCAT(DISTINCT flag) flags FROM events WHERE ts >= ? AND token = ? GROUP BY ip ORDER BY hits DESC");
    $st->execute([$today, $token]);
    $ips = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $geo = state_get('geo:' . $r['ip']);
        $ips[] = ['ip' => $r['ip'], 'hits' => (int)$r['hits'], 'last' => fmt_time($r['last_ts']),
                  'uas' => $r['uas'], 'flags' => $r['flags'], 'geo' => $geo ? json_decode($geo, true) : null];
    }
    return ['ips' => $ips];
}

function api_ip_detail($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['tokens' => []];
    $today = strtotime(date('Y-m-d 00:00:00'));
    $st = db()->prepare("SELECT token, COUNT(*) hits, MIN(ts) first_ts, MAX(ts) last_ts, GROUP_CONCAT(DISTINCT ua) uas FROM events WHERE ts >= ? AND ip = ? GROUP BY token ORDER BY hits DESC");
    $st->execute([$today, $ip]);
    $tokens = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tokens[] = ['token' => $r['token'], 'hits' => (int)$r['hits'], 'first' => date('m-d H:i:s', $r['first_ts']), 'last' => fmt_time($r['last_ts']), 'uas' => $r['uas']];
    }
    return ['tokens' => $tokens];
}

/** ipinfo.io 查归属（带 24h 缓存） */
function api_geo($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['error' => 'bad ip'];
    $key = 'geo:' . $ip;
    $raw = state_get($key);
    if ($raw) { $j = json_decode($raw, true); if (!empty($j['time']) && $j['time'] > time() - 86400) return $j; }
    $ctx = stream_context_create(['http' => ['timeout' => 5], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $resp = @file_get_contents('https://ipinfo.io/' . $ip . '/json', false, $ctx);
    if (!$resp) return ['error' => 'lookup failed'];
    $j = json_decode($resp, true) ?: [];
    $j['time'] = time();
    state_set($key, json_encode($j, JSON_UNESCAPED_UNICODE));
    return $j;
}

/** 按当前 UA 规则重算全部历史事件的命中标记 */
function recalc_reasons() {
    $pdo = db();
    $st = $pdo->query('SELECT id, ua FROM events');
    $up = $pdo->prepare('UPDATE events SET reason = ? WHERE id = ?');
    $changed = 0; $batch = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $new = detect_reason($r['ua']);
        $batch[] = [$new, $r['id']];
        if (count($batch) >= 500) {
            $pdo->beginTransaction();
            foreach ($batch as $b) { $up->execute($b); $changed++; }
            $pdo->commit();
            $batch = [];
        }
    }
    if ($batch) {
        $pdo->beginTransaction();
        foreach ($batch as $b) { $up->execute($b); $changed++; }
        $pdo->commit();
    }
    return ['ok' => true, 'scanned' => $changed];
}
