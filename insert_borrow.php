<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึก `Borrow` — INSERT จากฟอร์ม `add_borrow` หรือ UPDATE จากหน้า `status_borrow` — refactor จาก `old_system/insert_borrow.php`
 * ใช้ prepared statement แล้ว redirect กลับ `status_borrow`
 *
 * Flow:
 * require_auth → bootstrap → แยก INSERT (มี `b_date` + `b_user_receive`) กับ UPDATE (`b_id`) → execute → redirect
 *
 * Parameters สำคัญจาก GET:
 * - INSERT: b_date, b_user_receive, b_enduser_com, b_security_com, b_borrow_name, b_borrow_tel, b_borrow_line, b_borrow_email, b_address, b_borrow_hardware, b_status
 * - UPDATE: b_id, b_status, b_tracking, b_date_return (ใช้เมื่อสถานะ `3-ส่งคืนแล้ว`)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_borrow_helpers.php';

$b_date = isset($_GET['b_date']) ? trim((string) $_GET['b_date']) : '';
$b_user_receive = isset($_GET['b_user_receive']) ? trim((string) $_GET['b_user_receive']) : '';

if ($b_date !== '' && $b_user_receive !== '') {
    $b_enduser_com = isset($_GET['b_enduser_com']) ? trim((string) $_GET['b_enduser_com']) : '';
    $b_security_com = isset($_GET['b_security_com']) ? trim((string) $_GET['b_security_com']) : '';
    $b_borrow_name = isset($_GET['b_borrow_name']) ? trim((string) $_GET['b_borrow_name']) : '';
    $b_borrow_tel = isset($_GET['b_borrow_tel']) ? trim((string) $_GET['b_borrow_tel']) : '';
    $b_borrow_line = isset($_GET['b_borrow_line']) ? trim((string) $_GET['b_borrow_line']) : '';
    $b_borrow_email = isset($_GET['b_borrow_email']) ? trim((string) $_GET['b_borrow_email']) : '';
    $b_address = isset($_GET['b_address']) ? trim((string) $_GET['b_address']) : '';
    $b_borrow_hardware = isset($_GET['b_borrow_hardware']) ? trim((string) $_GET['b_borrow_hardware']) : '';
    $b_status = isset($_GET['b_status']) ? trim((string) $_GET['b_status']) : '';
    $allowedIns = array('1-รอส่งพัสดุ', '2-รอส่งคืน');
    if (!in_array($b_status, $allowedIns, true)) {
        maintenance_db_odbc_close($connect);
        header('Content-Type: text/plain; charset=utf-8', true, 400);
        exit('สถานะไม่ถูกต้อง');
    }
    $sql = 'INSERT INTO borrow (b_date, b_user_receive, b_enduser_com, b_security_com, b_borrow_name, b_borrow_tel, '
        . 'b_borrow_line, b_borrow_email, b_address, b_borrow_hardware, b_status) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt === false) {
        maintenance_db_odbc_close($connect);
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        exit('เตรียมคำสั่ง INSERT ไม่สำเร็จ');
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssss',
        $b_date,
        $b_user_receive,
        $b_enduser_com,
        $b_security_com,
        $b_borrow_name,
        $b_borrow_tel,
        $b_borrow_line,
        $b_borrow_email,
        $b_address,
        $b_borrow_hardware,
        $b_status
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    maintenance_db_odbc_close($connect);
    if (!$ok) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        exit('บันทึกไม่สำเร็จ');
    }
    header('Location: status_borrow');
    exit;
}

$b_id = maintenance_flow_get_int($_GET, 'b_id', 0);
$b_status = isset($_GET['b_status']) ? trim((string) $_GET['b_status']) : '';
$b_tracking = isset($_GET['b_tracking']) ? trim((string) $_GET['b_tracking']) : '';
$b_date_return = isset($_GET['b_date_return']) ? trim((string) $_GET['b_date_return']) : '';

$allowed = maintenance_status_borrow_status_options();
if ($b_id < 1 || !in_array($b_status, $allowed, true)) {
    maintenance_db_odbc_close($connect);
    header('Content-Type: text/plain; charset=utf-8', true, 400);
    exit('ข้อมูลไม่ถูกต้อง');
}

$dateVal = ($b_status === '3-ส่งคืนแล้ว') ? $b_date_return : '';

$sql = 'UPDATE borrow SET b_status = ?, b_tracking = ?, b_date_return = ? WHERE b_id = ?';
$stmt = mysqli_prepare($connect, $sql);
if ($stmt === false) {
    maintenance_db_odbc_close($connect);
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit('เตรียมคำสั่ง UPDATE ไม่สำเร็จ');
}
mysqli_stmt_bind_param($stmt, 'sssi', $b_status, $b_tracking, $dateVal, $b_id);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
maintenance_db_odbc_close($connect);

if (!$ok) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit('บันทึกไม่สำเร็จ');
}

header('Location: status_borrow');
exit;
