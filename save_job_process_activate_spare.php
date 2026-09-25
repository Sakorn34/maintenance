<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * API บันทึกรหัสต่ออายุเครื่องสำรองจากหน้า `job_process.php` ขั้นรออนุมัติซ่อม — คืน JSON
 *
 * Flow:
 * POST trp_id, tsp_id, tas_request_*, activate_flow (`inapprove` | `wait_spare` | `wait_hardware` | `offer_price` | `wait_repair`) → ตรวจสิทธิ์และจำนวนครั้ง → insert `transac_activate_spare`
 *
 * Example:
 * POST `/maintenance/save_job_process_activate_spare` body ฟิลด์ฟอร์ม modal
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/transac_activate_spare_helpers.php';

$trp_id = maintenance_flow_get_int($_POST, 'trp_id', 0);
$tsp_id = maintenance_flow_get_int($_POST, 'tsp_id', 0);
$request_name = isset($_POST['tas_request_name']) ? (string) $_POST['tas_request_name'] : '';
$request_contact = isset($_POST['tas_request_contact']) ? (string) $_POST['tas_request_contact'] : '';
$request_reason = isset($_POST['tas_request_reason']) ? (string) $_POST['tas_request_reason'] : '';
$expire_date = isset($_POST['tas_expire_date']) ? (string) $_POST['tas_expire_date'] : '';

$staff_user = maintenance_logistic_recorder_resolve_staff_name($connect);

$activate_flow = isset($_POST['activate_flow']) ? trim((string) $_POST['activate_flow']) : 'inapprove';

if ($activate_flow === 'wait_spare') {
    $result = maintenance_transac_activate_spare_insert_wait_spare_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user
    );
} elseif ($activate_flow === 'wait_hardware') {
    $result = maintenance_transac_activate_spare_insert_wait_hardware_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user,
        $expire_date
    );
} elseif ($activate_flow === 'offer_price') {
    $result = maintenance_transac_activate_spare_insert_offer_price_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user
    );
} elseif ($activate_flow === 'wait_repair') {
    $result = maintenance_transac_activate_spare_insert_wait_repair_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user
    );
} else {
    $result = maintenance_transac_activate_spare_insert_inapprove_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user,
        $expire_date
    );
}

maintenance_db_odbc_close($connect);

echo json_encode(
    array(
        'ok' => !empty($result['ok']),
        'message' => isset($result['message']) ? (string) $result['message'] : '',
        'tas_id' => isset($result['tas_id']) ? (int) $result['tas_id'] : 0,
    ),
    JSON_UNESCAPED_UNICODE
);
