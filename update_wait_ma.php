<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกวันที่ซ่อมเสร็จจากหน้า `status_wait_ma` (พารามิเตอร์ GET) — อัปเดต `transac_repair` แล้ว redirect กลับรายการ
 * รันที่รากด้วย mysqli prepared (ไม่พึ่ง `old_system/`) สำหรับ production ที่ไม่อัปโหลด legacy
 *
 * Parameters สำคัญ (GET):
 * - id_trp: รหัส `trp_id`
 * - trp_success_date: วันที่ซ่อมเสร็จ (input date)
 * - trp_user_ma: ชื่อผู้ซ่อม (hidden จาก session)
 * - trp_status_firmma: สถานะอนุมัติ MA จากแถวงาน (เทียบฟอร์ม `status_wait_firmQV`); ถ้าเป็น «ไม่อนุมัติซ่อม» ให้เคลียร์ฟิลด์ QC จุดยึดแล้วตั้งสถานะเป็น «รอส่งคืนเครื่องซ่อม» มิฉะนั้นตั้งเป็น «รอ QC»
 *
 * Flow:
 * require_auth → bootstrap → validate → UPDATE `transac_repair` → redirect `status_wait_ma`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'id_trp', 0);
if ($trp_id < 1) {
	maintenance_db_odbc_close($connect);
	header('Location: status_wait_ma');
	exit;
}

$trp_success_date = isset($_GET['trp_success_date']) ? trim((string) $_GET['trp_success_date']) : '';
if ($trp_success_date === '') {
	maintenance_db_odbc_close($connect);
	header('Location: status_wait_ma');
	exit;
}

$trp_user_ma = isset($_GET['trp_user_ma']) ? trim((string) $_GET['trp_user_ma']) : '';
if ($trp_user_ma === '') {
	maintenance_db_odbc_close($connect);
	header('Location: status_wait_ma');
	exit;
}

$trp_status_firmma = isset($_GET['trp_status_firmma']) ? trim((string) $_GET['trp_status_firmma']) : '';
$reject_ma = 'ไม่อนุมัติซ่อม';
$status_return = 'รอส่งคืนเครื่องซ่อม';
$status_qc = 'รอ QC';

if ($trp_status_firmma === $reject_ma) {
	$sql = 'UPDATE transac_repair SET trp_success_date = ?, trp_qc_date = ?, trp_qc_user = ?, trp_qc_comment = ?, trp_status_job = ?, trp_user_ma = ? WHERE trp_id = ? LIMIT 1';
	$stmt = mysqli_prepare($connect, $sql);
	if ($stmt === false) {
		maintenance_db_odbc_close($connect);
		header('Content-Type: text/plain; charset=utf-8');
		exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
	}
	$qc_date = '-';
	$qc_user = '-';
	$qc_comment = '';
	mysqli_stmt_bind_param(
		$stmt,
		'ssssss' . 'i',
		$trp_success_date,
		$qc_date,
		$qc_user,
		$qc_comment,
		$status_return,
		$trp_user_ma,
		$trp_id
	);
} else {
	$sql = 'UPDATE transac_repair SET trp_success_date = ?, trp_status_job = ?, trp_user_ma = ? WHERE trp_id = ? LIMIT 1';
	$stmt = mysqli_prepare($connect, $sql);
	if ($stmt === false) {
		maintenance_db_odbc_close($connect);
		header('Content-Type: text/plain; charset=utf-8');
		exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
	}
	mysqli_stmt_bind_param($stmt, 'sssi', $trp_success_date, $status_qc, $trp_user_ma, $trp_id);
}

if (!mysqli_stmt_execute($stmt)) {
	$err = mysqli_stmt_error($stmt);
	mysqli_stmt_close($stmt);
	maintenance_db_odbc_close($connect);
	header('Content-Type: text/plain; charset=utf-8');
	exit('Execute failed: ' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_close($stmt);
maintenance_db_odbc_close($connect);

header('Location: status_wait_ma');
exit;
