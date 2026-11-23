<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รูปประกอบการประเมิน (ต้องล็อกอิน)
 * - GET  ?id=&s=t|f              ส่งรูป (t = รูปเล็ก, f = รูปขนาดดู)
 * - GET  ?action=list&trp_id=    รายการรูปของใบงาน (JSON)
 * - POST action=upload (multipart: trp_id, ci_id, photo, thumb)  รูปที่เบราว์เซอร์ย่อมาแล้ว
 * - POST action=delete (id, trp_id)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/checklist_photo_helpers.php';

// ไม่ต้องเขียน session ต่อ — ปลดล็อกให้โหลดรูปหลายรูปพร้อมกันได้
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

function mn_photo_json($connect, array $out)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    maintenance_db_odbc_close($connect);
    header('Content-type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

$is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$is_post && isset($_GET['id'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    mn_photo_stream($connect, (int) $_GET['id'], isset($_GET['s']) && $_GET['s'] === 't');
}

$action = $is_post ? (isset($_POST['action']) ? (string) $_POST['action'] : '') : (isset($_GET['action']) ? (string) $_GET['action'] : '');
$trp_id = (int) ($is_post ? (isset($_POST['trp_id']) ? $_POST['trp_id'] : 0) : (isset($_GET['trp_id']) ? $_GET['trp_id'] : 0));

if ($action === 'list') {
    mn_photo_json($connect, array('ok' => true, 'photos' => mn_photo_list($connect, $trp_id)));
}
// ตรวจการเชื่อม Google Drive (เปิดในเบราว์เซอร์หลังตั้งค่า) + จำนวนรูปที่ยังไม่ขึ้น Drive
if ($action === 'drive_check') {
    require_once __DIR__ . '/includes/google_drive_helpers.php';
    $chk = mn_gd_check();
    $chk['pending'] = mn_photo_pending_count($connect);
    $chk['ok'] = $chk['token'] && $chk['error'] === '';
    mn_photo_json($connect, $chk);
}
// ส่งรูปที่ค้างขึ้น Drive ทีละชุด (เปิดซ้ำได้จนค้างเป็น 0)
if ($action === 'drive_push') {
    list($sent, $failed, $err) = mn_photo_push_pending($connect, 20);
    mn_photo_json($connect, array('ok' => $failed === 0, 'sent' => $sent, 'pending' => mn_photo_pending_count($connect), 'error' => $err));
}
if (!$is_post) {
    mn_photo_json($connect, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'));
}
if ($action === 'upload') {
    $photo = isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : array();
    $thumb = isset($_FILES['thumb']) && is_array($_FILES['thumb']) ? $_FILES['thumb'] : array();
    $ci = isset($_POST['ci_id']) ? (int) $_POST['ci_id'] : 0;
    $out = mn_photo_save($connect, $trp_id, $ci, $photo, $thumb, (string) $mn_logistic_recorder_value);
    if (empty($out['ok']) || !mn_photo_drive_ready()) {
        mn_photo_json($connect, $out);
    }
    // ตอบเบราว์เซอร์ก่อน แล้วค่อยส่งรูปขึ้น Drive (ช่างไม่ต้องรอ — ถ้า server ไม่รองรับจะส่งก่อนตอบแทน)
    while (ob_get_level() > 0) { ob_end_clean(); }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) {
        header('Content-type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        echo $json;
        fastcgi_finish_request();
        ignore_user_abort(true);
        mn_photo_push_pending($connect, 5);
        maintenance_db_odbc_close($connect);
        exit;
    }
    mn_photo_push_pending($connect, 3);
    mn_photo_json($connect, $out);
}
if ($action === 'delete') {
    mn_photo_json($connect, mn_photo_delete($connect, isset($_POST['id']) ? (int) $_POST['id'] : 0, $trp_id));
}
mn_photo_json($connect, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'));
