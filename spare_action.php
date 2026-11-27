<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Endpoint POST (JSON) ของ flow เครื่องสำรองในหน้า `detail_job` — ส่ง / ส่งใหม่ / ลูกค้ามารับเอง / ถึงลูกค้า / ตีกลับ /
 * เปลี่ยน S/N / สลับเครื่อง / รับคืน / ยกเลิก / สูญหาย (logic อยู่ใน includes/spare_flow_helpers.php)
 *
 * Flow: require_auth → bootstrap → อ่าน POST → mn_spare_do_action() → JSON { ok, error? }
 *
 * Parameters จาก POST: tsp_id, action, date, logistics, tracking, sendby, reason, person, new_sn
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/detail_job_edit_helpers.php';
require_once __DIR__ . '/includes/sendtracking_history_helpers.php';
require_once __DIR__ . '/includes/job_stamp_helpers.php';
require_once __DIR__ . '/includes/activity_log_helpers.php';
require_once __DIR__ . '/includes/spare_flow_helpers.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('ok' => false, 'error' => 'ต้องใช้เมธอด POST'), JSON_UNESCAPED_UNICODE);
    exit;
}

$in = array();
foreach (array('tsp_id', 'action', 'date', 'logistics', 'tracking', 'sendby', 'reason', 'person', 'new_sn') as $k) {
    $in[$k] = isset($_POST[$k]) ? (string) $_POST[$k] : '';
}
$result = mn_spare_do_action($connect, $in, mn_job_stamp_who($connect));
maintenance_db_odbc_close($connect);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
