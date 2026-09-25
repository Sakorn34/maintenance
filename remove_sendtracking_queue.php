<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * API ลบแถวคิว `sendtracking_history` หลัง staff คัดลอกข้อความส่งลูกค้าสำเร็จจากหน้า `wait_sendtracking` — คืน JSON
 *
 * Flow:
 * POST `sth_id` → `maintenance_sendtracking_history_delete_by_id` → `{"ok":true|false}`
 *
 * Example:
 * POST `/maintenance/remove_sendtracking_queue` body `sth_id=42`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/sendtracking_history_helpers.php';

$sth_id = maintenance_flow_get_int($_POST, 'sth_id', 0);
if ($sth_id < 1) {
    $sth_id = maintenance_flow_get_int($_GET, 'sth_id', 0);
}

$deleted = $sth_id > 0 && maintenance_sendtracking_history_delete_by_id($connect, $sth_id);
maintenance_db_odbc_close($connect);

echo json_encode(
    array('ok' => (bool) $deleted),
    JSON_UNESCAPED_UNICODE
);
