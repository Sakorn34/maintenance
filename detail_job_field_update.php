<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Endpoint POST (JSON) สำหรับอัปเดตฟิลด์จากหน้า `detail_job` — เริ่มจาก `trp_sn` ขยาย `field_key` อื่นได้ภายหลัง
 *
 * Flow:
 * require_auth → bootstrap → อ่าน POST → `maintenance_detail_job_update_field()` → JSON { ok, error?, unchanged? }
 *
 * Parameters สำคัญจาก POST:
 * - ts_id, trp_id (int), field_key (string), value (string), old_value (string)
 * - field_key=repair_machine: trp_product, trp_sn, trp_repair_inform, trp_buy_date + old_* คู่กัน
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/detail_job_edit_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    maintenance_detail_job_json_response(array('ok' => false, 'error' => 'ต้องใช้เมธอด POST'));
}

$ts_id = maintenance_flow_get_int($_POST, 'ts_id', 0);
$trp_id = maintenance_flow_get_int($_POST, 'trp_id', 0);
$field_key = isset($_POST['field_key']) ? trim((string) $_POST['field_key']) : '';
$new_value = isset($_POST['value']) ? trim((string) $_POST['value']) : '';
$old_value = isset($_POST['old_value']) ? trim((string) $_POST['old_value']) : '';

if ($field_key === 'repair_return') {
    $result = maintenance_detail_job_update_repair_return_bundle(
        $connect,
        $ts_id,
        $trp_id,
        isset($_POST['trp_send_date']) ? (string) $_POST['trp_send_date'] : '',
        isset($_POST['trp_logistics']) ? (string) $_POST['trp_logistics'] : '',
        isset($_POST['trp_tracking']) ? (string) $_POST['trp_tracking'] : '',
        isset($_POST['trp_sendby']) ? (string) $_POST['trp_sendby'] : '',
        isset($_POST['old_trp_send_date']) ? (string) $_POST['old_trp_send_date'] : '',
        isset($_POST['old_trp_logistics']) ? (string) $_POST['old_trp_logistics'] : '',
        isset($_POST['old_trp_tracking']) ? (string) $_POST['old_trp_tracking'] : '',
        isset($_POST['old_trp_sendby']) ? (string) $_POST['old_trp_sendby'] : ''
    );
} elseif ($field_key === 'repair_qc') {
    $result = maintenance_detail_job_update_repair_qc_bundle(
        $connect,
        $ts_id,
        $trp_id,
        isset($_POST['trp_qc_date']) ? (string) $_POST['trp_qc_date'] : '',
        isset($_POST['trp_qc_user']) ? (string) $_POST['trp_qc_user'] : '',
        isset($_POST['trp_qc_comment']) ? (string) $_POST['trp_qc_comment'] : '',
        isset($_POST['old_trp_qc_date']) ? (string) $_POST['old_trp_qc_date'] : '',
        isset($_POST['old_trp_qc_user']) ? (string) $_POST['old_trp_qc_user'] : '',
        isset($_POST['old_trp_qc_comment']) ? (string) $_POST['old_trp_qc_comment'] : ''
    );
} elseif ($field_key === 'repair_ma') {
    $result = maintenance_detail_job_update_repair_ma_bundle(
        $connect,
        $ts_id,
        $trp_id,
        isset($_POST['trp_success_date']) ? (string) $_POST['trp_success_date'] : '',
        isset($_POST['trp_user_ma']) ? (string) $_POST['trp_user_ma'] : '',
        isset($_POST['old_trp_success_date']) ? (string) $_POST['old_trp_success_date'] : '',
        isset($_POST['old_trp_user_ma']) ? (string) $_POST['old_trp_user_ma'] : ''
    );
} elseif ($field_key === 'repair_qv') {
    $result = maintenance_detail_job_update_repair_qv_bundle(
        $connect,
        $ts_id,
        $trp_id,
        isset($_POST['trp_qv_number']) ? (string) $_POST['trp_qv_number'] : '',
        isset($_POST['trp_qv_date']) ? (string) $_POST['trp_qv_date'] : '',
        isset($_POST['trp_qv_send']) ? (string) $_POST['trp_qv_send'] : '',
        isset($_POST['trp_qv_price']) ? (string) $_POST['trp_qv_price'] : '',
        isset($_POST['trp_firm_qv_date']) ? (string) $_POST['trp_firm_qv_date'] : '',
        isset($_POST['old_trp_qv_number']) ? (string) $_POST['old_trp_qv_number'] : '',
        isset($_POST['old_trp_qv_date']) ? (string) $_POST['old_trp_qv_date'] : '',
        isset($_POST['old_trp_qv_send']) ? (string) $_POST['old_trp_qv_send'] : '',
        isset($_POST['old_trp_qv_price']) ? (string) $_POST['old_trp_qv_price'] : '',
        isset($_POST['old_trp_firm_qv_date']) ? (string) $_POST['old_trp_firm_qv_date'] : ''
    );
} elseif ($field_key === 'repair_receive') {
    $result = maintenance_detail_job_update_repair_receive_bundle(
        $connect,
        $ts_id,
        $trp_id,
        isset($_POST['trp_receive_date']) ? (string) $_POST['trp_receive_date'] : '',
        isset($_POST['trp_user_recive_ma']) ? (string) $_POST['trp_user_recive_ma'] : '',
        isset($_POST['old_trp_receive_date']) ? (string) $_POST['old_trp_receive_date'] : '',
        isset($_POST['old_trp_user_recive_ma']) ? (string) $_POST['old_trp_user_recive_ma'] : ''
    );
} elseif ($field_key === 'spare_early_return') {
    $result = maintenance_detail_job_update_spare_early_return_bundle(
        $connect,
        $ts_id,
        $trp_id,
        maintenance_flow_get_int($_POST, 'tsp_id', 0),
        isset($_POST['tsp_datereturn']) ? (string) $_POST['tsp_datereturn'] : '',
        isset($_POST['tsp_receiveby']) ? (string) $_POST['tsp_receiveby'] : '',
        isset($_POST['old_tsp_datereturn']) ? (string) $_POST['old_tsp_datereturn'] : '',
        isset($_POST['old_tsp_receiveby']) ? (string) $_POST['old_tsp_receiveby'] : ''
    );
} elseif ($field_key === 'spare_send') {
    $result = maintenance_detail_job_update_spare_send_bundle(
        $connect,
        $ts_id,
        $trp_id,
        maintenance_flow_get_int($_POST, 'tsp_id', 0),
        isset($_POST['tsp_datesend']) ? (string) $_POST['tsp_datesend'] : '',
        isset($_POST['tsp_logistics']) ? (string) $_POST['tsp_logistics'] : '',
        isset($_POST['tsp_sendby']) ? (string) $_POST['tsp_sendby'] : '',
        isset($_POST['old_tsp_datesend']) ? (string) $_POST['old_tsp_datesend'] : '',
        isset($_POST['old_tsp_logistics']) ? (string) $_POST['old_tsp_logistics'] : '',
        isset($_POST['old_tsp_sendby']) ? (string) $_POST['old_tsp_sendby'] : ''
    );
} elseif ($field_key === 'repair_machine') {
    $result = maintenance_detail_job_update_repair_machine_bundle(
        $connect,
        $ts_id,
        $trp_id,
        isset($_POST['trp_product']) ? (string) $_POST['trp_product'] : '',
        isset($_POST['trp_sn']) ? (string) $_POST['trp_sn'] : '',
        isset($_POST['trp_repair_inform']) ? (string) $_POST['trp_repair_inform'] : '',
        isset($_POST['trp_buy_date']) ? (string) $_POST['trp_buy_date'] : '',
        isset($_POST['old_trp_product']) ? (string) $_POST['old_trp_product'] : '',
        isset($_POST['old_trp_sn']) ? (string) $_POST['old_trp_sn'] : '',
        isset($_POST['old_trp_repair_inform']) ? (string) $_POST['old_trp_repair_inform'] : '',
        isset($_POST['old_trp_buy_date']) ? (string) $_POST['old_trp_buy_date'] : ''
    );
} else {
    $result = maintenance_detail_job_update_field($connect, $ts_id, $trp_id, $field_key, $new_value, $old_value);
}
maintenance_db_odbc_close($connect);

maintenance_detail_job_json_response($result);
