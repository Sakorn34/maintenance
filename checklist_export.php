<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ส่งออกการตั้งค่า checklist (ข้อตรวจ อาการ กลุ่ม ชุดอะไหล่ การใช้ร่วม) เป็น JSON ให้ localhost ดึงไปใช้
 * ไม่ใช้ session login — ยืนยันด้วย header `X-Checklist-Token` ที่ต้องตรงกับ token ใน config/checklist_sync.local.php
 * ไม่มี token ใน config = ปิดการส่งออก (ตอบ 404)
 */

date_default_timezone_set('Asia/Bangkok');
header('Content-type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/includes/checklist_sync_helpers.php';

$cfg = mn_cl_sync_config();
if ($cfg['token'] === '' || strlen($cfg['token']) < 32) {
    http_response_code(404);
    echo json_encode(array('ok' => false));
    exit;
}
$given = isset($_SERVER['HTTP_X_CHECKLIST_TOKEN']) ? (string) $_SERVER['HTTP_X_CHECKLIST_TOKEN'] : '';
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($cfg['token'], $given)) {
    http_response_code(403);
    echo json_encode(array('ok' => false));
    exit;
}

require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/checklist_helpers.php';

maintenance_checklist_ensure_tables($connect);
$out = mn_cl_sync_export($connect);
maintenance_db_odbc_close($connect);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
