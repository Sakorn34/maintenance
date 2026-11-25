<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ POST จาก `manage_logistics` — เพิ่มหรือแก้ไขแถว `logistics_carrier` แล้ว redirect (ไม่มีการลบ)
 *
 * Flow:
 * POST `mn_action` = insert|update → validate → INSERT/UPDATE → redirect `manage_logistics?mn_flash=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/settings_lock_helpers.php';
mn_settings_gate($connect, 'post'); // หน้าตั้งค่าหลังบ้าน — ต้องใส่รหัส
require_once __DIR__ . '/includes/manage_logistics_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_logistics');
    exit;
}

$result = maintenance_manage_logistics_save_post($connect, $_POST);
maintenance_db_odbc_close($connect);

if ($result['ok']) {
    $msg = (isset($result['action']) && $result['action'] === 'insert')
        ? 'เพิ่มขนส่งแล้ว'
        : 'บันทึกการแก้ไขแล้ว';
    $editId = isset($result['lc_id']) ? (int) $result['lc_id'] : 0;
    header('Location: ' . maintenance_manage_logistics_redirect_url(true, $msg, 0));
    exit;
}

$err = isset($result['error']) ? (string) $result['error'] : 'บันทึกไม่สำเร็จ';
$editId = isset($_POST['lc_id']) ? (int) $_POST['lc_id'] : 0;
header('Location: ' . maintenance_manage_logistics_redirect_url(false, $err, $editId));
exit;
