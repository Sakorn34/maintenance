<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * API JSON สำหรับ modal เลือกงานซ่อมเดิมบนหน้า add_job (โหมดเพิ่มอุปกรณ์)
 *
 * Flow:
 * GET `term` (optional) → `maintenance_add_job_existing_transac_list()` → JSON `{ ok, items[] }`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/add_job_existing_transac_helpers.php';

$term = isset($_GET['term']) ? trim((string) $_GET['term']) : '';
$rows = maintenance_add_job_existing_transac_list($connect, $term);
$items = array();
foreach ($rows as $row) {
    $items[] = array(
        'ts_id' => isset($row['ts_id']) ? (int) $row['ts_id'] : 0,
        'ts_mtn_number' => isset($row['ts_mtn_number']) ? (string) $row['ts_mtn_number'] : '',
        'ts_enduser_com' => isset($row['ts_enduser_com']) ? (string) $row['ts_enduser_com'] : '',
        'ts_security_com' => isset($row['ts_security_com']) ? (string) $row['ts_security_com'] : '',
        'ts_date' => isset($row['ts_date']) ? (string) $row['ts_date'] : '',
    );
}
maintenance_db_odbc_close($connect);

echo json_encode(
    array(
        'ok' => true,
        'items' => $items,
    ),
    JSON_UNESCAPED_UNICODE
);
exit;
