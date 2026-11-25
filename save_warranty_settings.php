<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกระยะเวลาประกันรายรุ่น (GET form จาก warranty_settings) แล้ว redirect กลับ
 *
 * Flow:
 * require_auth → validate → upsert → redirect warranty_settings?saved=1
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/settings_lock_helpers.php';
mn_settings_gate($connect, 'post'); // หน้าตั้งค่าหลังบ้าน — ต้องใส่รหัส
require_once __DIR__ . '/includes/warranty_settings_helpers.php';

$pw_product    = isset($_GET['pw_product']) ? trim((string) $_GET['pw_product']) : '';
$pw_years      = isset($_GET['pw_years']) ? (int) $_GET['pw_years'] : 0;
$pw_add_months = isset($_GET['pw_add_months']) ? (int) $_GET['pw_add_months'] : 0;

if ($pw_product === '') {
    maintenance_db_odbc_close($connect);
    header('Location: warranty_settings?err=invalid', true, 302);
    exit;
}

$total_months = ($pw_years * 12) + $pw_add_months;
if ($total_months < 1 || $total_months > 600) {
    maintenance_db_odbc_close($connect);
    header('Location: warranty_settings?err=invalid&edit=' . rawurlencode($pw_product), true, 302);
    exit;
}

$ok = maintenance_warranty_upsert($connect, $pw_product, $total_months);
maintenance_db_odbc_close($connect);

if (!$ok) {
    header('Location: warranty_settings?err=invalid&edit=' . rawurlencode($pw_product), true, 302);
    exit;
}

header('Location: warranty_settings?saved=1', true, 302);
exit;
