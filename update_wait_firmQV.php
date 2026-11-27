<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกอนุมัติ QV ฝั่ง MA จากหน้า `status_wait_firmQV` (พารามิเตอร์ GET) — อัปเดต `transac_repair` แล้ว redirect กลับรายการ
 * รันที่รากโปรเจกต์ด้วย mysqli prepared (ไม่พึ่ง `old_system/`) เพื่อกัน HTTP 503 เมื่อโฮสต์ไม่ deploy โฟลเดอร์ legacy
 *
 * Parameters สำคัญ (GET):
 * - id_trp: รหัส `trp_id`
 * - trp_firm_qv_date: วันที่อนุมัติ (รูปแบบ input date)
 * - trp_backup: ข้อความช่องสำรอง/ซื้อเครื่องใหม่ ฯลฯ
 * - trp_status_firmma: สถานะอนุมัติ MA จาก select
 *
 * Flow:
 * require_auth → bootstrap → validate → UPDATE `transac_repair` (ตั้ง `trp_status_job` เป็น «รอซ่อม» สอดคล้องรายการ `status_wait_ma`) → redirect `status_wait_firmQV`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'id_trp', 0);
if ($trp_id < 1) {
	maintenance_db_odbc_close($connect);
	header('Location: status_wait_firmQV');
	exit;
}

$trp_firm_qv_date = isset($_GET['trp_firm_qv_date']) ? trim((string) $_GET['trp_firm_qv_date']) : '';
if ($trp_firm_qv_date === '') {
	maintenance_db_odbc_close($connect);
	header('Location: status_wait_firmQV');
	exit;
}

$trp_backup = isset($_GET['trp_backup']) ? (string) $_GET['trp_backup'] : '';
$trp_status_firmma = isset($_GET['trp_status_firmma']) ? (string) $_GET['trp_status_firmma'] : '';
$next_status_job = 'รอซ่อม';

$sql = 'UPDATE transac_repair SET trp_backup = ?, trp_firm_qv_date = ?, trp_status_job = ?, trp_status_firmma = ? WHERE trp_id = ? LIMIT 1';
$stmt = mysqli_prepare($connect, $sql);
if ($stmt === false) {
	maintenance_db_odbc_close($connect);
	header('Content-Type: text/plain; charset=utf-8');
	exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
}

mysqli_stmt_bind_param($stmt, 'ssssi', $trp_backup, $trp_firm_qv_date, $next_status_job, $trp_status_firmma, $trp_id);
if (!mysqli_stmt_execute($stmt)) {
	$err = mysqli_stmt_error($stmt);
	mysqli_stmt_close($stmt);
	maintenance_db_odbc_close($connect);
	header('Content-Type: text/plain; charset=utf-8');
	exit('Execute failed: ' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_close($stmt);

// ผู้บันทึกการอนุมัติ
require_once __DIR__ . '/includes/job_stamp_helpers.php';
mn_job_stamp_set($connect, array($trp_id), 'firm');

maintenance_db_odbc_close($connect);

header('Location: status_wait_firmQV');
exit;
