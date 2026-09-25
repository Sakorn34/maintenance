<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกผล QC จาก query string (เทียบ `old_system/update_wait_qc.php`) — INSERT `logqc_*` + UPDATE `transac_repair` แล้ว redirect ไป `status_wait_qc`
 *
 * Flow:
 * `require_auth` → bootstrap → `maintenance_update_wait_qc_from_get()` → ปิด connection → `Location: status_wait_qc`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/update_wait_qc_helpers.php';

$ok = maintenance_update_wait_qc_from_get($connect, $_GET);
maintenance_db_odbc_close($connect);

if ($ok) {
    header('Location: status_wait_qc', true, 302);
    exit;
}
http_response_code(400);
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="th">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>บันทึก QC ไม่สำเร็จ</title>
</head>
<body>
<p>ไม่สามารถบันทึกข้อมูล QC ได้ (พารามิเตอร์ไม่ครบหรือตารางไม่ถูกต้อง)</p>
<p><a href="status_wait_qc">กลับหน้ารอ QC</a></p>
</body>
</html>
