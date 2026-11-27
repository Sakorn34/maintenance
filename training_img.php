<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ส่งรูปที่เปลี่ยนเองของหน้า "เทรนการใช้งาน" จาก storage/training/ (โฟลเดอร์ storage ปิดการเข้าถึงตรง)
 * GET: k = <topic>-<step>
 */
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/training_helpers.php';

$f = mn_tr_custom_file(isset($_GET['k']) ? (string) $_GET['k'] : '');
if ($f === '') {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (substr($f, -4) === '.png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($f));
header('Cache-Control: private, max-age=86400');
readfile($f);
