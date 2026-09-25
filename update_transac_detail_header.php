<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ POST จากหน้า `detail_job.php` แล้วอัปเดตฟิลด์หัว `transac` (วันที่รับเรื่อง, บริษัท, ผู้แจ้ง, ผู้อนุมัติ, ที่อยู่, หมายเหตุ)
 * ห้ามบันทึกเมื่อ `maintenance_detail_job_header_locked()` เป็น true (มีแถว `transac_repair` ใดของ `ts_id` ที่ `trp_status_job = 'เสร็จสิ้น'`)
 *
 * Flow:
 * POST → require_auth → ตรวจล็อก → UPDATE transac → redirect `detail_job?ts_id=`
 *
 * Parameters สำคัญจาก POST:
 * - ts_id: int
 * - ts_date, ts_enduser_com, ts_security_com, ts_report_*, ts_approve_*, ts_address, ts_remarks: string
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

// ─────────────────────────────────────────────────────────────────
// main
// ─────────────────────────────────────────────────────────────────

$ts_id = isset($_POST['ts_id']) ? (int) $_POST['ts_id'] : 0;

if ($ts_id <= 0 || maintenance_detail_job_header_locked($connect, $ts_id)) {
    maintenance_db_odbc_close($connect);
    header('Location: detail_job' . ($ts_id > 0 ? '?ts_id=' . $ts_id : ''));
    exit;
}

$post_keys = array(
    'ts_date',
    'ts_enduser_com',
    'ts_security_com',
    'ts_report_name',
    'ts_report_tel',
    'ts_report_line',
    'ts_report_email',
    'ts_approve_name',
    'ts_approve_tel',
    'ts_approve_line',
    'ts_approve_email',
    'ts_address',
    'ts_remarks',
);
$pv = array();
foreach ($post_keys as $pk) {
    $pv[$pk] = isset($_POST[$pk]) ? trim((string) $_POST[$pk]) : '';
}

$e_date = mysqli_real_escape_string($connect, $pv['ts_date']);
$e_eu = mysqli_real_escape_string($connect, $pv['ts_enduser_com']);
$e_sec = mysqli_real_escape_string($connect, $pv['ts_security_com']);
$e_rn = mysqli_real_escape_string($connect, $pv['ts_report_name']);
$e_rt = mysqli_real_escape_string($connect, $pv['ts_report_tel']);
$e_rl = mysqli_real_escape_string($connect, $pv['ts_report_line']);
$e_re = mysqli_real_escape_string($connect, $pv['ts_report_email']);
$e_an = mysqli_real_escape_string($connect, $pv['ts_approve_name']);
$e_at = mysqli_real_escape_string($connect, $pv['ts_approve_tel']);
$e_al = mysqli_real_escape_string($connect, $pv['ts_approve_line']);
$e_ae = mysqli_real_escape_string($connect, $pv['ts_approve_email']);
$e_addr = mysqli_real_escape_string($connect, $pv['ts_address']);
$e_rm = mysqli_real_escape_string($connect, $pv['ts_remarks']);

$sql_update = 'UPDATE transac SET '
    . "ts_date = '" . $e_date . "', "
    . "ts_enduser_com = '" . $e_eu . "', "
    . "ts_security_com = '" . $e_sec . "', "
    . "ts_report_name = '" . $e_rn . "', "
    . "ts_report_tel = '" . $e_rt . "', "
    . "ts_report_line = '" . $e_rl . "', "
    . "ts_report_email = '" . $e_re . "', "
    . "ts_approve_name = '" . $e_an . "', "
    . "ts_approve_tel = '" . $e_at . "', "
    . "ts_approve_line = '" . $e_al . "', "
    . "ts_approve_email = '" . $e_ae . "', "
    . "ts_address = '" . $e_addr . "', "
    . "ts_remarks = '" . $e_rm . "' "
    . 'WHERE ts_id = ' . $ts_id;

maintenance_db_odbc_exec($connect, $sql_update);
maintenance_db_odbc_close($connect);

header('Location: detail_job?ts_id=' . $ts_id);
exit;
