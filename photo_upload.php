<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ามือถือสำหรับถ่าย/อัปรูปประกอบการประเมิน — เปิดจาก QR ในหน้า assess_repair ไม่ต้องล็อกอิน
 * สิทธิ์มาจากรหัสใน QR (?t=) เท่านั้น: ใช้ได้กับใบงานเดียว, หมดอายุ 3 ชั่วโมง / ปิดลิงก์ / บันทึกการประเมิน / เสนอราคาแล้ว
 * - ?t=&ci=     QR ประจำข้อ (รูปเข้าข้อนั้นทันที)
 * - ?t=         QR ตามหน้าจอ (มือถือเปลี่ยนข้อตามที่เปิดอยู่บนคอม)
 * - GET  ?t=&action=state[&ci=]    ข้อที่แนบรูปได้ + ข้อที่คอมเปิดอยู่ + รูปของใบงาน (JSON)
 * - GET  ?t=&img=&s=t|f[&dl=1]     รูปของใบงานนี้
 * - POST action=upload (t, ci_id, photo, thumb) | delete (t, id) | move (t, id, ci_id)
 *
 * Flow: bootstrap (ไม่ require_auth) → ตรวจรหัส → JSON / รูป / หน้า HTML (js/photo_upload-page.js)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/detail_job_edit_helpers.php';
require_once __DIR__ . '/includes/checklist_photo_helpers.php';
require_once __DIR__ . '/includes/checklist_photo_link_helpers.php';

// รหัสอยู่ใน URL — ห้ามส่งต่อไปเว็บอื่น (Referer) และห้ามค้นเจอใน search engine
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

$is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$token = $is_post ? (isset($_POST['t']) ? (string) $_POST['t'] : '') : (isset($_GET['t']) ? (string) $_GET['t'] : '');
list($link, $link_err) = mn_plink_get($connect, $token);
$trp_id = $link ? (int) $link['cpl_trp_id'] : 0;

function mn_pu_json($connect, array $out)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    maintenance_db_odbc_close($connect);
    header('Content-type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function mn_pu_item_ok($connect, $ci)
{
    $ci = (int) $ci;
    if ($ci === 0) { return true; }
    if ($ci < 0) { return false; }
    $stmt = mysqli_prepare($connect, 'SELECT ci_id FROM cl_item WHERE ci_id = ?');
    if (!$stmt) { return false; }
    mysqli_stmt_bind_param($stmt, 'i', $ci);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $ok = $r && mysqli_fetch_row($r);
    mysqli_stmt_close($stmt);
    return (bool) $ok;
}

// ---------- รูป ----------
if (!$is_post && isset($_GET['img'])) {
    $row = $link ? mn_photo_row($connect, (int) $_GET['img']) : null;
    if (!$row || (int) $row['cp_trp_id'] !== $trp_id) {
        http_response_code(404);
        exit;
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    mn_photo_stream($connect, (int) $row['cp_id'], isset($_GET['s']) && $_GET['s'] === 't', !empty($_GET['dl']));
}

// ---------- JSON ----------
$action = $is_post ? (isset($_POST['action']) ? (string) $_POST['action'] : '') : (isset($_GET['action']) ? (string) $_GET['action'] : '');
if ($action !== '') {
    if (!$link) { mn_pu_json($connect, array('ok' => false, 'expired' => true, 'error' => $link_err)); }
    if ($action === 'state') {
        $photos = mn_photo_list($connect, $trp_id);
        $want = isset($_GET['ci']) ? (int) $_GET['ci'] : 0;
        $out = array();
        foreach ($photos as $p) { $out[] = array('id' => $p['id'], 'ci' => $p['ci']); }
        mn_pu_json($connect, array('ok' => true, 'focus' => (int) $link['cpl_focus'], 'items' => mn_plink_items($connect, $link, $photos, $want),
            'photos' => $out, 'expiresText' => date('H:i', strtotime((string) $link['cpl_expires']))));
    }
    if (!$is_post) { mn_pu_json($connect, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง')); }
    if ($action === 'upload') {
        $ci = isset($_POST['ci_id']) ? (int) $_POST['ci_id'] : 0;
        if (!mn_pu_item_ok($connect, $ci)) { mn_pu_json($connect, array('ok' => false, 'error' => 'ไม่พบข้อตรวจนี้')); }
        $photo = isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : array();
        $thumb = isset($_FILES['thumb']) && is_array($_FILES['thumb']) ? $_FILES['thumb'] : array();
        $by = trim((string) $link['cpl_by']);
        $res = mn_photo_save($connect, $trp_id, $ci, $photo, $thumb, ($by !== '' ? $by . ' ' : '') . '(มือถือ)');
        if (!empty($res['ok'])) { $res['photo'] = array('id' => $res['photo']['id'], 'ci' => $res['photo']['ci']); }
        mn_photo_finish_upload($connect, $res);
    }
    if ($action === 'delete') {
        mn_pu_json($connect, mn_photo_delete($connect, isset($_POST['id']) ? (int) $_POST['id'] : 0, $trp_id));
    }
    if ($action === 'move') {
        mn_pu_json($connect, mn_photo_move($connect, isset($_POST['id']) ? (int) $_POST['id'] : 0, $trp_id, isset($_POST['ci_id']) ? (int) $_POST['ci_id'] : 0));
    }
    mn_pu_json($connect, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'));
}

// ---------- หน้า HTML ----------
maintenance_db_odbc_close($connect);
header('Content-type: text/html; charset=utf-8');
header('Cache-Control: no-store');

function mn_pu_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$boot = null;
if ($link) {
    $boot = array(
        'token' => (string) $link['cpl_token'],
        'ci'    => isset($_GET['ci']) && $_GET['ci'] !== '' ? max(0, (int) $_GET['ci']) : null,
        'job'   => array('mtn' => (string) $link['mtn'], 'product' => (string) $link['product'], 'sn' => (string) $link['sn']),
    );
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="referrer" content="no-referrer" />
<meta name="robots" content="noindex, nofollow" />
<meta name="theme-color" content="#0369a1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-photo-lightbox.css?v=<?php echo filemtime(__DIR__ . '/css/mn-photo-lightbox.css'); ?>" />
<link rel="stylesheet" href="css/mn-page-photo-upload.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-photo-upload.css'); ?>" />
<title>ถ่ายรูปประกอบการประเมิน</title>
</head>
<body class="mn-addjob-page mn-photo-upload-page">
<?php if (!$link) { ?>
  <main class="mn-pu mn-pu--msg">
    <div class="mn-pu-msg">
      <i class="fa fa-chain-broken" aria-hidden="true"></i>
      <h1><?php echo mn_pu_h($link_err); ?></h1>
      <p>เปิดหน้าประเมินบนคอม แล้วกดปุ่ม QR เพื่อสร้างลิงก์ใหม่</p>
    </div>
  </main>
<?php } else { ?>
  <main class="mn-pu" id="mn_pu">
    <header class="mn-pu-top">
      <div class="mn-pu-top__job">
        <strong><?php echo mn_pu_h($boot['job']['product'] !== '' ? $boot['job']['product'] : 'ใบงาน'); ?></strong>
        <span>SN <?php echo mn_pu_h($boot['job']['sn'] !== '' ? $boot['job']['sn'] : '—'); ?><?php if ($boot['job']['mtn'] !== '') { ?> | งาน <?php echo mn_pu_h($boot['job']['mtn']); ?><?php } ?></span>
      </div>
      <span class="mn-pu-top__exp" id="mn_pu_exp"></span>
    </header>
    <div class="mn-pu-body" id="mn_pu_body">
      <p class="mn-pu-loading"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> กำลังโหลด</p>
    </div>
    <div class="mn-pu-toast" id="mn_pu_toast" role="status" aria-live="polite"></div>
  </main>
  <input type="file" id="mn_pu_cam" accept="image/*" capture="environment" hidden />
  <input type="file" id="mn_pu_lib" accept="image/*" multiple hidden />
<script type="text/javascript">
window.mnPu = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script type="text/javascript" src="js/mn-photo-lightbox.js?v=<?php echo filemtime(__DIR__ . '/js/mn-photo-lightbox.js'); ?>"></script>
<script type="text/javascript" src="js/photo_upload-page.js?v=<?php echo filemtime(__DIR__ . '/js/photo_upload-page.js'); ?>"></script>
<?php } ?>
</body>
</html>
