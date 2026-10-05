<?php
/**
 * SubGuard - API 路由 v1.5.1
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/guard.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

const SG_VERSION = '1.5.1';

if (empty($_SESSION['subguard_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'not logged in', 'v' => SG_VERSION]);
    exit;
}

$action = $_GET['action'] ?? 'stats';

// 自动增量扫描（超过最小间隔才扫）
$scan = null;
$last = (int)state_get('last_scan', 0);
if ($action !== 'scan_now' && time() - $last > cfg()['scan_min_interval']) {
    $scan = run_scan(false);
}

switch ($action) {
    case 'scan_now':
        echo json_encode(run_scan(false), JSON_UNESCAPED_UNICODE);
        break;

    case 'recalc':
        echo json_encode(recalc_reasons(), JSON_UNESCAPED_UNICODE);
        break;

    case 'sys_get':
        echo json_encode(api_sys_get(), JSON_UNESCAPED_UNICODE);
        break;

    case 'sys_save_name':
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(api_sys_save_name($in['name'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'sys_save_user':
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(api_sys_save_user($in['user'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'sys_change_pwd':
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(api_sys_change_pwd($in['pwd'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'stats':
        $out = api_stats();
        $out['scan'] = $scan;
        $out['v'] = SG_VERSION;
        if (!empty(guard_config()['enabled'])) {
            $out['guard'] = guard_run(false);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        break;

    case 'token_detail':
        echo json_encode(api_token_detail($_GET['token'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'ip_detail':
        echo json_encode(api_ip_detail($_GET['ip'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'geo':
        echo json_encode(api_geo($_GET['ip'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    /* ===== auto_guard ===== */
    case 'guard_config_get':
        $gc = guard_config();
        $gc['tunnel_ok'] = (bool)panel_db();
        $gc['v'] = SG_VERSION;
        echo json_encode($gc, JSON_UNESCAPED_UNICODE);
        break;

    case 'guard_config_save':
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(guard_save_config($in), JSON_UNESCAPED_UNICODE);
        break;

    case 'guard_test':
        echo json_encode(panel_db_test(), JSON_UNESCAPED_UNICODE);
        break;

    case 'logsrc_list':
        echo json_encode(api_logsrc_list(), JSON_UNESCAPED_UNICODE);
        break;

    case 'logsrc_add':
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(api_logsrc_add($in), JSON_UNESCAPED_UNICODE);
        break;

    case 'logsrc_del':
        echo json_encode(api_logsrc_del($_GET['id'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'logsrc_script':
        echo json_encode(api_logsrc_script($_GET['id'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'tg_test':
        echo json_encode(tg_test(), JSON_UNESCAPED_UNICODE);
        break;

    case 'guard_run':
        echo json_encode(guard_run(true), JSON_UNESCAPED_UNICODE);
        break;

    case 'bl_add':
        echo json_encode(api_bl_add($_GET['type'] ?? '', file_get_contents('php://input')), JSON_UNESCAPED_UNICODE);
        break;

    case 'bl_list':
        echo json_encode(api_bl_list($_GET['type'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'bl_del':
        echo json_encode(api_bl_del($_GET['type'] ?? '', $_GET['val'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'locks_list':
        echo json_encode(api_locks_list($_GET['q'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'lock_add':
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(api_lock_add($in['input'] ?? '', $in['plan_id'] ?? 0, $in['note'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;

    case 'lock_del':
        echo json_encode(api_lock_del($_GET['user_id'] ?? 0), JSON_UNESCAPED_UNICODE);
        break;

    case 'actions_list':
        echo json_encode(api_actions_list($_GET['q'] ?? '', $_GET['page'] ?? 1), JSON_UNESCAPED_UNICODE);
        break;

    case 'relock':
        echo json_encode(api_relock($_GET['email'] ?? '', (int)($_GET['aid'] ?? 0)), JSON_UNESCAPED_UNICODE);
        break;

    case 'actions_del':
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(guard_actions_del($in['ids'] ?? []), JSON_UNESCAPED_UNICODE);
        break;

    case 'action_revert':
        echo json_encode(guard_revert((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action', 'v' => SG_VERSION]);
}
