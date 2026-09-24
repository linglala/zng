<?php
/**
 * SubGuard - API（页面自动触发增量扫描）
 */
require __DIR__ . '/lib.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['subguard_ok'])) { http_response_code(403); echo json_encode(['error' => 'not logged in']); exit; }

$action = $_GET['action'] ?? 'stats';

if ($action === 'scan_now') {
    echo json_encode(run_scan(false));
    exit;
}

// 距离上次扫描超过 scan_min_interval 秒则自动增量扫描一次（保证页面看到的数据是新的）
$last = (int)state_get('last_scan', 0);
$scan = null;
if (time() - $last > cfg()['scan_min_interval']) $scan = run_scan(false);

if ($action === 'stats') {
    $out = api_stats();
    $out['scan'] = $scan;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} elseif ($action === 'token_detail') {
    echo json_encode(api_token_detail($_GET['token'] ?? ''), JSON_UNESCAPED_UNICODE);
} elseif ($action === 'ip_detail') {
    echo json_encode(api_ip_detail($_GET['ip'] ?? ''), JSON_UNESCAPED_UNICODE);
} elseif ($action === 'geo') {
    echo json_encode(api_geo($_GET['ip'] ?? ''), JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
}
