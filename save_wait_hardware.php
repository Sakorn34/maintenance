<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกรับเครื่องซ่อม (UPDATE transac_repair) แล้ว redirect กลับหน้ารายการรอเครื่อง
 * Refactor จาก `old_system/save_wait_hardware.php` — mysqli prepared, สถานะถัดไปเป็น «รอประเมิน»
 * บังคับ `w_insurance` ต้องเป็นค่าที่อนุญาต (ห้ามว่าง)
 *
 * Flow:
 * validate GET → UPDATE → redirect `status_wait_hardware` หรือกลับ `update_wait_hardware?err=insurance`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
if ($trp_id < 1) {
    header('Location: status_wait_hardware');
    exit;
}

$w_date_receive = isset($_GET['w_date_receive']) ? (string) $_GET['w_date_receive'] : '';
$w_sn = isset($_GET['w_sn']) ? (string) $_GET['w_sn'] : '';
$w_date_buy = isset($_GET['w_date_buy']) ? (string) $_GET['w_date_buy'] : '';
$w_repair_age = isset($_GET['w_repair_age']) ? (string) $_GET['w_repair_age'] : '';
$w_insurance = isset($_GET['w_insurance']) ? trim((string) $_GET['w_insurance']) : '';
$w_repair_inform = isset($_GET['w_repair_inform']) ? (string) $_GET['w_repair_inform'] : '';
$trp_user_recive_ma = isset($_GET['trp_user_recive_ma']) ? (string) $_GET['trp_user_recive_ma'] : '';
$next_status = 'รอประเมิน';

if (!maintenance_wait_hardware_is_valid_insurance($w_insurance)) {
    maintenance_db_odbc_close($connect);
    header('Location: update_wait_hardware?trp_id=' . $trp_id . '&err=insurance', true, 302);
    exit;
}

$sql = 'UPDATE transac_repair SET trp_receive_date = ?, trp_sn = ?, trp_buy_date = ?, trp_repair_age = ?, '
    . 'trp_insurance = ?, trp_repair_inform = ?, trp_user_recive_ma = ?, trp_status_job = ? WHERE trp_id = ?';
$stmt = mysqli_prepare($connect, $sql);
if ($stmt === false) {
    maintenance_db_odbc_close($connect);
    exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param(
    $stmt,
    'ssssssssi',
    $w_date_receive,
    $w_sn,
    $w_date_buy,
    $w_repair_age,
    $w_insurance,
    $w_repair_inform,
    $trp_user_recive_ma,
    $next_status,
    $trp_id
);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
maintenance_db_odbc_close($connect);

header('Location: status_wait_hardware');
exit;
