<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รูปประกอบการประเมิน (ต้องล็อกอิน) — มือถือที่ไม่ล็อกอินใช้ photo_upload ผ่าน QR แทน
 * - GET  ?id=&s=t|f[&dl=1]        ส่งรูป (t = รูปเล็ก, f = รูปขนาดดู, dl = ดาวน์โหลด ตั้งชื่อตามที่มา)
 * - GET  ?action=list&trp_id=     รายการรูปของใบงาน (JSON)
 * - GET  ?action=zip&trp_id=      ดาวน์โหลดรูปทั้งใบงานเป็น zip
 * - GET  ?action=drive_check | drive_push | drive_rename   ตรวจ / ส่งรูปค้าง + ลบไฟล์ค้างบน Drive / ตั้งชื่อไฟล์บน Drive ใหม่
 * - POST action=upload (multipart: trp_id, ci_id, photo, thumb)  รูปที่เบราว์เซอร์ย่อมาแล้ว
 * - POST action=delete (id, trp_id) | move (id, trp_id, ci_id)
 * - POST action=link_create (trp_id, renew, focus, items) | link_state (trp_id, token, focus, items) | link_revoke (trp_id)
 *   ลิงก์ QR ให้มือถืออัปรูป (includes/checklist_photo_link_helpers.php)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/checklist_photo_helpers.php';
require_once __DIR__ . '/includes/checklist_photo_link_helpers.php';

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
    mn_photo_stream($connect, (int) $_GET['id'], isset($_GET['s']) && $_GET['s'] === 't', !empty($_GET['dl']));
}

$action = $is_post ? (isset($_POST['action']) ? (string) $_POST['action'] : '') : (isset($_GET['action']) ? (string) $_GET['action'] : '');
$trp_id = (int) ($is_post ? (isset($_POST['trp_id']) ? $_POST['trp_id'] : 0) : (isset($_GET['trp_id']) ? $_GET['trp_id'] : 0));

if ($action === 'list') {
    mn_photo_json($connect, array('ok' => true, 'photos' => mn_photo_list($connect, $trp_id)));
}
if ($action === 'zip') {
    mn_photo_zip($connect, $trp_id);
}
// ตรวจการเชื่อม Google Drive (เปิดในเบราว์เซอร์หลังตั้งค่า) + จำนวนรูปที่ยังไม่ขึ้น Drive
if ($action === 'drive_check') {
    require_once __DIR__ . '/includes/google_drive_helpers.php';
    $chk = mn_gd_check();
    $chk['pending'] = mn_photo_pending_count($connect);
    $chk['trash_pending'] = mn_photo_trash_count($connect); // ไฟล์บน Drive ที่ลบจากระบบแล้วแต่ยังย้ายลงถังขยะไม่สำเร็จ
    $chk['ok'] = $chk['token'] && $chk['error'] === '';
    $chk['stats'] = mn_photo_stats($connect); // ตัวเลขบนหน้าตั้งค่า (อัปเดตหลังลบรูป)
    mn_photo_json($connect, $chk);
}
// ส่งรูปที่ค้างขึ้น Drive ทีละชุด (เปิดซ้ำได้จนค้างเป็น 0)
if ($action === 'drive_push') {
    list($sent, $failed, $err) = mn_photo_push_pending($connect, 20);
    $trash_left = mn_photo_trash_count($connect);
    // เก็บกวาดโฟลเดอร์ว่างบน Drive (ใบงานที่ลบรูปหมดแล้ว)
    list($pruned, $perr) = ($failed === 0 && $trash_left === 0) ? mn_photo_drive_prune_all($connect) : array(0, '');
    mn_photo_json($connect, array('ok' => $failed === 0 && $trash_left === 0, 'sent' => $sent, 'pending' => mn_photo_pending_count($connect),
        'trash_pending' => $trash_left, 'pruned' => $pruned, 'error' => $err !== '' ? $err : $perr));
}
// ตั้งชื่อไฟล์บน Drive ใหม่ตามรูปแบบปัจจุบัน ทีละ 30 รูป (?after=id ล่าสุดที่ทำแล้ว — เปิดซ้ำตาม next จนได้ done)
if ($action === 'drive_rename') {
    if (!mn_photo_drive_ready()) { mn_photo_json($connect, array('ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า Google Drive')); }
    mn_photo_ensure_table($connect);
    $after = isset($_GET['after']) ? (int) $_GET['after'] : 0;
    $stmt = mysqli_prepare($connect, 'SELECT cp_id FROM cl_photo WHERE cp_drive_id IS NOT NULL AND cp_id > ? ORDER BY cp_id LIMIT 30');
    mysqli_stmt_bind_param($stmt, 'i', $after);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ids = array();
    while ($res && ($r = mysqli_fetch_row($res))) { $ids[] = (int) $r[0]; }
    mysqli_stmt_close($stmt);
    $done = 0;
    $errors = array();
    foreach ($ids as $id) {
        $err = mn_photo_rename_drive($connect, $id);
        if ($err === '') { $done++; } else { $errors[] = $id . ': ' . $err; }
    }
    mn_photo_json($connect, array('ok' => !$errors, 'renamed' => $done, 'errors' => $errors,
        'next' => count($ids) === 30 ? 'checklist_photo?action=drive_rename&after=' . end($ids) : '', 'done' => count($ids) < 30));
}
if (!$is_post) {
    mn_photo_json($connect, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'));
}
if ($action === 'upload') {
    $photo = isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : array();
    $thumb = isset($_FILES['thumb']) && is_array($_FILES['thumb']) ? $_FILES['thumb'] : array();
    $ci = isset($_POST['ci_id']) ? (int) $_POST['ci_id'] : 0;
    mn_photo_finish_upload($connect, mn_photo_save($connect, $trp_id, $ci, $photo, $thumb, (string) $mn_logistic_recorder_value));
}
if ($action === 'delete') {
    mn_photo_json($connect, mn_photo_delete($connect, isset($_POST['id']) ? (int) $_POST['id'] : 0, $trp_id));
}
if ($action === 'move') {
    mn_photo_json($connect, mn_photo_move($connect, isset($_POST['id']) ? (int) $_POST['id'] : 0, $trp_id, isset($_POST['ci_id']) ? (int) $_POST['ci_id'] : 0));
}
if ($action === 'link_create') {
    list($link, $err) = mn_plink_create($connect, $trp_id, (string) $mn_logistic_recorder_value, !empty($_POST['renew']));
    if (!$link) { mn_photo_json($connect, array('ok' => false, 'error' => $err)); }
    mn_plink_set_state($connect, $link['cpl_token'], $trp_id, isset($_POST['focus']) ? (int) $_POST['focus'] : -1, isset($_POST['items']) ? (string) $_POST['items'] : '[]');
    mn_photo_json($connect, array('ok' => true, 'link' => mn_plink_out($link)));
}
if ($action === 'link_state') {
    $ok = mn_plink_set_state($connect, isset($_POST['token']) ? (string) $_POST['token'] : '', $trp_id,
        isset($_POST['focus']) ? (int) $_POST['focus'] : -1, isset($_POST['items']) ? (string) $_POST['items'] : '[]');
    mn_photo_json($connect, array('ok' => (bool) $ok));
}
if ($action === 'link_revoke') {
    mn_photo_json($connect, array('ok' => mn_plink_revoke($connect, $trp_id)));
}
mn_photo_json($connect, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'));
