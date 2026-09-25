<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Autocomplete เลข QV สำหรับหน้า `status_wait_firmQV` — คืน HTML แท็ก `<p>` ต่อหนึ่งเลข QV (สูงสุด 8 แถว)
 * ใช้ mysqli + prepared statement กับฐาน maintenance (ไม่พึ่ง `old_system/` หรือ Access ODBC)
 *
 * Parameters สำคัญ:
 * - $_REQUEST['term']: ข้อความค้นหา (บางส่วนของ `trp_qv_number`)
 *
 * Flow:
 * require_auth → `mysql_odbc_compat` → SELECT จาก `transac_repair` ที่ `trp_status_job = รออนุมัติซ่อม` และ LIKE term → echo `<p>...</p>`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';

$term = isset($_REQUEST['term']) ? trim((string) $_REQUEST['term']) : '';
if ($term === '') {
	maintenance_db_odbc_close($connect);
	exit;
}

$status_job = 'รออนุมัติซ่อม';
$like = '%' . $term . '%';
$sql = 'SELECT trp_qv_number FROM transac_repair WHERE trp_status_job = ? AND trp_qv_number IS NOT NULL AND TRIM(trp_qv_number) <> \'\' AND trp_qv_number LIKE ? ORDER BY trp_id DESC LIMIT 8';
$stmt = mysqli_prepare($connect, $sql);
if ($stmt === false) {
	maintenance_db_odbc_close($connect);
	header('Content-Type: text/plain; charset=utf-8');
	exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
}

mysqli_stmt_bind_param($stmt, 'ss', $status_job, $like);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if ($res) {
	$n = 0;
	while (($row = mysqli_fetch_assoc($res)) && $n < 8) {
		$qv = isset($row['trp_qv_number']) ? (string) $row['trp_qv_number'] : '';
		if ($qv !== '') {
			echo '<p>' . htmlspecialchars($qv, ENT_QUOTES, 'UTF-8') . '</p>';
			$n++;
		}
	}
	mysqli_free_result($res);
}
mysqli_stmt_close($stmt);
maintenance_db_odbc_close($connect);
