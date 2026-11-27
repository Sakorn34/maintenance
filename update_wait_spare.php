<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกวันที่รับคืนเครื่องสำรอง (`transac_spare`) คืนสถานะ SN ใน `Spare` เป็น `blank` แล้วตั้ง `transac_repair.trp_status_job = เสร็จสิ้น`
 * Refactor จาก `old_system/update_wait_spare.php` — ใช้ transaction + prepared statement แทน SQL ต่อสตริง และ redirect แทน meta refresh
 *
 * Flow:
 * require_auth → bootstrap → validate GET → transaction (UPDATE spare row → Spare → repair status) → redirect `status_wait_spare`
 *
 * Parameters สำคัญจาก GET:
 * - tsp_id, tsp_datereturn, tsp_sn, tsp_receiveby
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/spare_flow_helpers.php';

$tsp_id = maintenance_flow_get_int($_GET, 'tsp_id', 0);
$tsp_datereturn = isset($_GET['tsp_datereturn']) ? trim((string) $_GET['tsp_datereturn']) : '';
$tsp_sn = isset($_GET['tsp_sn']) ? trim((string) $_GET['tsp_sn']) : '';
$tsp_receiveby = isset($_GET['tsp_receiveby']) ? trim((string) $_GET['tsp_receiveby']) : '';

if ($tsp_id < 1 || $tsp_datereturn === '' || $tsp_receiveby === '' || $tsp_sn === '') {
    maintenance_db_odbc_close($connect);
    header('Content-Type: text/plain; charset=utf-8', true, 400);
    exit('ข้อมูลไม่ครบถ้วน');
}

mysqli_begin_transaction($connect);

$sql1 = 'UPDATE transac_spare SET tsp_datereturn = ?, tsp_receiveby = ? WHERE tsp_id = ?';
$stmt1 = mysqli_prepare($connect, $sql1);
if ($stmt1 === false) {
    mysqli_rollback($connect);
    maintenance_db_odbc_close($connect);
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit('เตรียมคำสั่ง UPDATE transac_spare ไม่สำเร็จ');
}
mysqli_stmt_bind_param($stmt1, 'ssi', $tsp_datereturn, $tsp_receiveby, $tsp_id);
$ok1 = mysqli_stmt_execute($stmt1);
mysqli_stmt_close($stmt1);

$sql_trp = 'SELECT tsp_trp_id FROM transac_spare WHERE tsp_id = ? LIMIT 1';
$stmt_trp = mysqli_prepare($connect, $sql_trp);
$trp_id = 0;
if ($stmt_trp !== false) {
    mysqli_stmt_bind_param($stmt_trp, 'i', $tsp_id);
    mysqli_stmt_execute($stmt_trp);
    $res_trp = mysqli_stmt_get_result($stmt_trp);
    if ($res_trp && ($row_trp = mysqli_fetch_assoc($res_trp))) {
        $trp_id = isset($row_trp['tsp_trp_id']) ? (int) $row_trp['tsp_trp_id'] : 0;
    }
    if ($res_trp) {
        mysqli_free_result($res_trp);
    }
    mysqli_stmt_close($stmt_trp);
}

$sql2 = 'UPDATE spare SET sp_status = ? WHERE sp_sn = ?';
$stmt2 = mysqli_prepare($connect, $sql2);
$blank = 'blank';
$ok2 = false;
if ($stmt2 !== false) {
    mysqli_stmt_bind_param($stmt2, 'ss', $blank, $tsp_sn);
    $ok2 = mysqli_stmt_execute($stmt2);
    mysqli_stmt_close($stmt2);
}

$done_status = 'เสร็จสิ้น';
$sql3 = 'UPDATE transac_repair SET trp_status_job = ? WHERE trp_id = ?';
$stmt3 = mysqli_prepare($connect, $sql3);
$ok3 = false;
if ($stmt3 === false) {
    // ไม่มี statement — ถือว่าล้มเหลว
} elseif ($trp_id < 1) {
    mysqli_stmt_close($stmt3);
} elseif (mn_spare_outstanding_count($connect, $trp_id) > 0) {
    // ยังมีเครื่องสำรองอื่นค้างอยู่ (เช่น สลับเครื่อง) — คงสถานะรอเครื่องสำรองไว้
    mysqli_stmt_close($stmt3);
    $ok3 = true;
} else {
    mysqli_stmt_bind_param($stmt3, 'si', $done_status, $trp_id);
    $ok3 = mysqli_stmt_execute($stmt3);
    mysqli_stmt_close($stmt3);
}

if (!$ok1 || !$ok2 || !$ok3 || $trp_id < 1) {
    mysqli_rollback($connect);
    maintenance_db_odbc_close($connect);
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit('บันทึกไม่สำเร็จ');
}

mysqli_commit($connect);
maintenance_db_odbc_close($connect);

header('Location: status_wait_spare');
exit;
