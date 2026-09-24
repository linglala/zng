<?php
/**
 * SubGuard - 命令行扫描器
 * 用法：
 *   php scan.php            增量扫描（计划任务用）
 *   php scan.php --full     全量扫描整个日志（首次导入历史用，日志很大时慎用）
 */
require __DIR__ . '/lib.php';

$full = in_array('--full', $argv);
$r = run_scan($full);

if (isset($r['error'])) {
    echo "ERROR: {$r['error']}\n";
    exit(1);
}
if (!empty($r['locked'])) {
    echo "另一个扫描正在进行，跳过\n";
    exit(0);
}
echo '[' . date('Y-m-d H:i:s') . '] 扫描行数 ' . $r['scanned'] . '，入库 ' . $r['inserted'] . ($full ? '（全量）' : '') . "\n";
