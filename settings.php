<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า "ตั้งค่าระบบ" (เมนูโปรไฟล์บนแถบหัว → ตั้งค่าระบบ) — ต้องใส่รหัส (includes/settings_lock_helpers.php)
 * เปิดมาเป็นเมนูการตั้งค่าก่อน กดเมนูไหนค่อยเข้าเรื่องนั้น (?view=)
 * - เมนูไปหน้าตั้งค่าเดิม: checklist_settings, checklist_try, warranty_settings, warranty_registry, manage_logistics
 * - view=conditions เงื่อนไขที่ปรับได้ (cl_setting) | drive สถานะ Google Drive (js/settings-page.js) + ใบงานที่มีรูป | sources แหล่งหลักฐานวันที่ซื้อ
 *   rules เงื่อนไขอัตโนมัติที่ใช้อยู่ | pin เปลี่ยนรหัส | about ข้อมูลระบบ
 *
 * Flow: require_auth → bootstrap → ด่านรหัส → (POST บันทึก → redirect) → ดึงข้อมูล → ปิด connect → render เมนู / หน้าย่อย
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/system_settings_helpers.php';
require_once __DIR__ . '/includes/checklist_photo_helpers.php';
require_once __DIR__ . '/includes/buy_date_suggest_helpers.php';
require_once __DIR__ . '/includes/settings_lock_helpers.php';
require_once __DIR__ . '/includes/checklist_sync_helpers.php';

mn_settings_gate($connect, 'page'); // ต้องใส่รหัสก่อนเข้า

function mn_set_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** แถบรูปของใบงาน (view=drive): กดรูป = ดูใหญ่ (mn-photo-lightbox) | ปุ่มถังขยะ = ลบรูป (js/settings-page.js) */
function mn_set_photo_strip(array $photos, $trp_id)
{
    $items = array();
    foreach ($photos as $p) {
        $items[] = array('url' => 'checklist_photo?id=' . (int) $p['id'] . '&s=f', 'download' => 'checklist_photo?id=' . (int) $p['id'] . '&s=f&dl=1',
            'caption' => $p['label'] !== '' ? $p['label'] : 'รูปประกอบเพิ่มเติม');
    }
    $html = '<div class="mn-photo-strip" data-mn-photos="' . mn_set_h(json_encode($items, JSON_UNESCAPED_UNICODE)) . '">';
    foreach ($photos as $i => $p) {
        $cap = mn_set_h($items[$i]['caption']);
        $html .= '<span class="mn-pj__ph">'
            . '<button type="button" class="mn-photo-strip__img" data-mn-photo="' . $i . '" title="' . $cap . '" aria-label="ดูรูป ' . $cap . '">'
            . '<img src="checklist_photo?id=' . (int) $p['id'] . '&amp;s=t" alt="" loading="lazy" />'
            . (!empty($p['drive']) ? mn_photo_drive_badge() : '') . '</button>'
            . '<button type="button" class="mn-pj__del" data-del-photo="' . (int) $p['id'] . '" data-trp="' . (int) $trp_id . '" data-on-drive="' . (!empty($p['drive']) ? '1' : '0') . '"'
            . ' data-cap="' . $cap . '" title="ลบรูปนี้" aria-label="ลบรูป ' . $cap . '"><i class="fa fa-trash" aria-hidden="true"></i></button>'
            . '</span>';
    }
    $html .= '<a class="mn-photo-strip__zip" href="checklist_photo?action=zip&amp;trp_id=' . (int) $trp_id . '" title="ดาวน์โหลดรูปทั้งหมดของใบงานนี้ (zip)">'
        . '<i class="fa fa-download" aria-hidden="true"></i> ทั้งหมด</a>';
    return $html . '</div>';
}

// ดึงการตั้งค่าทั้งหมดจาก server (เครื่องที่ตั้ง url/token ใน config/checklist_sync.local.php — localhost)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_all'])) {
    $res = mn_cl_sync_pull($connect, mn_cl_sync_tables_all());
    maintenance_db_odbc_close($connect);
    if (empty($res['ok'])) {
        header('Location: settings?err=' . rawurlencode('ดึงการตั้งค่าไม่สำเร็จ: ' . $res['error']));
        exit;
    }
    $names = array('cl_product' => 'รุ่นที่ใช้ checklist', 'cl_symptom' => 'อาการ', 'cl_group' => 'กลุ่ม', 'cl_item' => 'ข้อตรวจ',
        'cl_rate_bundle' => 'ชุดอะไหล่', 'cl_rate_reason' => 'เหตุผลการเปลี่ยน', 'cl_setting' => 'เงื่อนไขที่ปรับได้',
        'product_warranty' => 'ประกันรายรุ่น', 'logistics_carrier' => 'รายการขนส่ง');
    $parts = array();
    $missing = array();
    foreach ($names as $t => $label) {
        if (isset($res['counts'][$t])) { $parts[] = $label . ' ' . (int) $res['counts'][$t]; } else { $missing[] = $label; }
    }
    $msg = 'ดึงการตั้งค่าจาก server แล้ว (ข้อมูล ณ ' . $res['exported_at'] . ') ' . implode(', ', $parts)
        . ($missing ? ' | server ยังไม่มี: ' . implode(', ', $missing) . ' (อัปชุดใหม่ขึ้น server ก่อน)' : '')
        . ' | สำรองของเดิมไว้ที่ ' . $res['backup'];
    header('Location: settings?synced=' . rawurlencode($msg));
    exit;
}

if (isset($_GET['lock'])) {
    mn_settings_lock();
    maintenance_db_odbc_close($connect);
    header('Location: index');
    exit;
}
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin_new'])) {
    if ((string) $_POST['pin_new'] !== (isset($_POST['pin_new2']) ? (string) $_POST['pin_new2'] : '')) {
        list($ok, $err) = array(false, 'รหัสใหม่ 2 ช่องไม่ตรงกัน');
    } else {
        list($ok, $err) = mn_settings_change_pin($connect, isset($_POST['pin_old']) ? (string) $_POST['pin_old'] : '', (string) $_POST['pin_new'], (string) $mn_logistic_recorder_value);
    }
    maintenance_db_odbc_close($connect);
    header('Location: settings?view=pin&' . ($ok ? 'pin=1' : 'err=' . rawurlencode($err)));
    exit;
}
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    list($ok, $err) = mn_sys_settings_save($connect, isset($_POST['set']) && is_array($_POST['set']) ? $_POST['set'] : array(), (string) $mn_logistic_recorder_value);
    maintenance_db_odbc_close($connect);
    header('Location: settings?view=conditions&' . ($ok ? 'saved=1' : 'err=' . rawurlencode($err)));
    exit;
}

$views = array(
    'conditions' => array('fa-sliders', 'เงื่อนไขที่ปรับได้'),
    'drive'      => array('fa-cloud-upload', 'รูปประกอบ / Google Drive'),
    'sources'    => array('fa-search', 'แหล่งหลักฐานวันที่ซื้อ'),
    'rules'      => array('fa-cogs', 'เงื่อนไขอัตโนมัติที่ใช้อยู่'),
    'pin'        => array('fa-key', 'รหัสเข้าหน้าตั้งค่า'),
    'about'      => array('fa-info-circle', 'ข้อมูลระบบ'),
);
$view = isset($_GET['view']) && isset($views[$_GET['view']]) ? (string) $_GET['view'] : 'menu';

$defs = mn_sys_setting_defs();
$saved_rows = mn_sys_settings_saved($connect);
$values = array();
foreach ($defs as $k => $d) { $values[$k] = mn_sys_setting($connect, $k); }

mn_photo_ensure_table($connect);
$photo_stat = mn_photo_stats($connect);
$sources = ($view === 'menu' || $view === 'sources') ? mn_bds_source_status($connect) : array();
// view=drive: รายการใบงานที่มีรูป + รูปตัวอย่าง (ค้นด้วย ?q= แบ่งหน้า ?pg=)
$pj_q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$pj_per = 20;
$pj_pg = isset($_GET['pg']) ? max(1, (int) $_GET['pg']) : 1;
$pj = array('rows' => array(), 'total' => 0);
$pj_photos = array();
if ($view === 'drive') {
    require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
    $pj = mn_photo_jobs($connect, $pj_q, ($pj_pg - 1) * $pj_per, $pj_per);
    $pj_pages = max(1, (int) ceil($pj['total'] / $pj_per));
    if ($pj_pg > $pj_pages) {
        $pj_pg = $pj_pages;
        $pj = mn_photo_jobs($connect, $pj_q, ($pj_pg - 1) * $pj_per, $pj_per);
    }
    $pj_photos = mn_photo_by_trps($connect, array_map(function ($r) { return $r['trp_id']; }, $pj['rows']));
}
$pj_pages = max(1, (int) ceil($pj['total'] / $pj_per));
// ป้ายเมนูเทรน: จำนวนคน | ความคืบหน้าเฉลี่ย
require_once __DIR__ . '/includes/training_helpers.php';
$tr_overall = mn_tr_overall($connect);
maintenance_db_odbc_close($connect);

$sources_ok = 1;
foreach ($sources as $s) { if ($s['ok']) { $sources_ok++; } }
$version = is_file(__DIR__ . '/VERSION.txt') ? trim((string) file_get_contents(__DIR__ . '/VERSION.txt')) : '';
$banner_file = __DIR__ . '/config/site_banner.local.php';
$banner = is_file($banner_file) ? (string) (require $banner_file) : '';
$is_prod = function_exists('maintenance_new_app_env_is_production') && maintenance_new_app_env_is_production();
$flash_ok = isset($_GET['saved']) || isset($_GET['pin']) || isset($_GET['synced']);
$flash_msg = isset($_GET['pin']) ? 'เปลี่ยนรหัสเข้าหน้าตั้งค่าแล้ว' : 'บันทึกการตั้งค่าแล้ว';
$flash_err = isset($_GET['err']) ? (string) $_GET['err'] : '';
if (isset($_GET['synced'])) { $flash_msg = (string) $_GET['synced']; }
$sync_cfg = mn_cl_sync_config();
$can_sync = $sync_cfg['url'] !== '' && $sync_cfg['token'] !== '';
$sync_host = $can_sync ? (string) parse_url($sync_cfg['url'], PHP_URL_HOST) . rtrim(dirname((string) parse_url($sync_cfg['url'], PHP_URL_PATH)), '/\\') : '';

// เมนู: href, ไอคอน, ชื่อ, คำอธิบาย, ป้ายสถานะ
$menu = array(
    'งานประเมิน' => array(
        array('checklist_settings', 'fa-list-alt', 'รายการตรวจเช็ครายรุ่น', 'ข้อตรวจ อาการ กลุ่ม ตัวเลือกคำตอบ ชุดอะไหล่ เหตุผลการเปลี่ยน', ''),
        array('checklist_try', 'fa-flask', 'ทดลองใช้งานหน้าประเมิน', 'ลองตอบข้อตรวจ ไม่มีใบงาน ไม่บันทึกข้อมูล', ''),
        array('settings?view=conditions', 'fa-sliders', 'เงื่อนไขที่ปรับได้', 'ใกล้ครบ 2 ปี, ประกันเริ่มต้น, ประกันงานซ่อม, แพ็กเกจประกัน, QR มือถือ, จำนวนรูป', ''),
    ),
    'ประกัน' => array(
        array('warranty_settings', 'fa-shield', 'ระยะเวลาประกันรายรุ่น', 'จำนวนเดือนประกันเครื่องใหม่ของแต่ละรุ่น', ''),
        array('warranty_registry', 'fa-certificate', 'ทะเบียนประกัน', 'ประวัติประกันต่อ S/N, บันทึก/ยกเลิกประกันซื้อเพิ่ม', ''),
    ),
    'รูปและข้อมูล' => array(
        array('settings?view=drive', 'fa-cloud-upload', 'รูปประกอบ / Google Drive', 'รูปรายใบงาน, สถานะการเชื่อมต่อ, ส่งรูปค้าง, ตั้งชื่อไฟล์ใหม่', number_format((int) $photo_stat['n']) . ' รูป'),
        array('settings?view=sources', 'fa-search', 'แหล่งหลักฐานวันที่ซื้อ', 'ประวัติซ่อม, คลังเครื่อง, ติดตั้ง, ส่งมอบ', $sources_ok . '/' . (count($sources) + 1) . ' เชื่อมแล้ว'),
    ),
    'การเทรน' => array(
        array('training', 'fa-graduation-cap', 'เทรนการใช้งาน', 'คู่มือทีละหัวข้อ Admin / ช่าง / DDT พร้อมรูปตัวอย่าง ติดตามว่าเทรนถึงไหน และข้อเสนอแนะจากผู้ใช้งาน',
            $tr_overall[0] . '/' . $tr_overall[1] . ' หัวข้อ' . ($tr_overall[2] > 0 ? ' | ข้อเสนอแนะ ' . $tr_overall[2] : '')),
    ),
    'ระบบ' => array(
        array('manage_logistics', 'fa-truck', 'รายการขนส่ง', 'ชื่อขนส่ง, ลิงก์ตรวจ tracking, เปิด/ปิดใช้งาน', ''),
        array('settings?view=rules', 'fa-cogs', 'เงื่อนไขอัตโนมัติที่ใช้อยู่', 'สรุปกฎทั้งหมดของระบบ', ''),
        array('settings?view=pin', 'fa-key', 'รหัสเข้าหน้าตั้งค่า', 'เปลี่ยนรหัสล็อกหน้าหลังบ้าน', ''),
        array('settings?view=about', 'fa-info-circle', 'ข้อมูลระบบ', 'เวอร์ชัน, โหมด, ป้ายระบบทดลอง', $version),
    ),
);
$page = 'settings';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-settings.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-settings.css'); ?>" />
<?php if ($view === 'drive') { ?>
<link rel="stylesheet" href="css/mn-photo-lightbox.css?v=<?php echo filemtime(__DIR__ . '/css/mn-photo-lightbox.css'); ?>" />
<?php } ?>
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title><?php echo $view === 'menu' ? 'ตั้งค่าระบบ' : mn_set_h($views[$view][1]) . ' | ตั้งค่าระบบ'; ?></title>
</head>
<body class="mn-addjob-page mn-settings-page">
  <div class="header">
    <?php require_once __DIR__ . '/includes/title-h.php'; ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-set">
      <header class="mn-set__head">
        <?php if ($view === 'menu') { ?>
        <h1><i class="fa fa-cog" aria-hidden="true"></i> ตั้งค่าระบบ</h1>
        <p>เลือกเรื่องที่ต้องการตั้งค่า <a class="mn-set__lockbtn" href="settings?lock=1" title="ล็อกหน้าตั้งค่าทันที"><i class="fa fa-lock" aria-hidden="true"></i> ล็อกหน้าตั้งค่า</a></p>
        <?php if ($can_sync) { ?>
        <form method="post" action="settings" class="mn-set__sync" id="mn_set_sync">
          <input type="hidden" name="sync_all" value="1" />
          <button type="submit" class="mn-set__btn" id="mn_set_sync_btn" title="ดึงจาก <?php echo mn_set_h($sync_host); ?>"><i class="fa fa-cloud-download" aria-hidden="true"></i> ดึงการตั้งค่าทั้งหมดจาก server</button>
          <small>จาก <?php echo mn_set_h($sync_host); ?></small>
        </form>
        <?php } ?>
        <?php } else { ?>
        <a class="mn-set__back" href="settings"><i class="fa fa-angle-left" aria-hidden="true"></i> ตั้งค่าระบบ</a>
        <h1><i class="fa <?php echo mn_set_h($views[$view][0]); ?>" aria-hidden="true"></i> <?php echo mn_set_h($views[$view][1]); ?></h1>
        <?php } ?>
      </header>

      <?php if ($flash_ok) { ?>
      <div class="mn-set__alert is-ok" role="status"><i class="fa fa-check-circle" aria-hidden="true"></i> <?php echo mn_set_h($flash_msg); ?></div>
      <?php } elseif ($flash_err !== '') { ?>
      <div class="mn-set__alert is-bad" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <?php echo mn_set_h($flash_err); ?></div>
      <?php } ?>

      <?php if ($view === 'menu') { ?>
        <?php foreach ($menu as $group => $items) { ?>
      <section class="mn-set__group">
        <h2 class="mn-set__group-title"><?php echo mn_set_h($group); ?></h2>
        <div class="mn-set__menu">
          <?php foreach ($items as $l) { ?>
          <a class="mn-set__tile" href="<?php echo mn_set_h($l[0]); ?>">
            <span class="mn-set__tile-ico"><i class="fa <?php echo mn_set_h($l[1]); ?>" aria-hidden="true"></i></span>
            <span class="mn-set__tile-txt"><b><?php echo mn_set_h($l[2]); ?></b><small><?php echo mn_set_h($l[3]); ?></small></span>
            <?php if ($l[4] !== '') { ?><span class="mn-set__tile-badge"><?php echo mn_set_h($l[4]); ?></span><?php } ?>
            <i class="fa fa-angle-right mn-set__go" aria-hidden="true"></i>
          </a>
          <?php } ?>
        </div>
      </section>
        <?php } ?>
      <?php } ?>

      <?php if ($view === 'conditions') { ?>
      <section class="mn-set__card">
        <form method="post" action="settings" class="mn-set__form">
          <?php foreach ($defs as $k => $d) {
              $last = isset($saved_rows[$k]) ? $saved_rows[$k] : null; ?>
          <div class="mn-set__field">
            <label for="set_<?php echo mn_set_h($k); ?>"><?php echo mn_set_h($d['label']); ?></label>
            <div class="mn-set__control">
              <?php if (isset($d['type']) && $d['type'] === 'list') { ?>
              <input type="text" id="set_<?php echo mn_set_h($k); ?>" name="set[<?php echo mn_set_h($k); ?>]" value="<?php echo mn_set_h(implode(', ', (array) $values[$k])); ?>" class="mn-set__list" />
              <span class="mn-set__unit"><?php echo mn_set_h($d['unit']); ?></span>
              <?php } elseif (isset($d['format']) && $d['format'] === 'months') { ?>
              <select id="set_<?php echo mn_set_h($k); ?>" name="set[<?php echo mn_set_h($k); ?>]">
                <?php for ($m = $d['min']; $m <= $d['max']; $m++) { ?>
                <option value="<?php echo $m; ?>"<?php echo $m === (int) $values[$k] ? ' selected="selected"' : ''; ?>><?php echo mn_set_h(mn_sys_months_text($m)); ?><?php echo $m === (int) $d['default'] ? ' (ค่าเริ่มต้น)' : ''; ?></option>
                <?php } ?>
              </select>
              <?php } else { ?>
              <input type="number" id="set_<?php echo mn_set_h($k); ?>" name="set[<?php echo mn_set_h($k); ?>]" min="<?php echo (int) $d['min']; ?>" max="<?php echo (int) $d['max']; ?>" value="<?php echo (int) $values[$k]; ?>" />
              <span class="mn-set__unit"><?php echo mn_set_h($d['unit']); ?></span>
              <?php } ?>
            </div>
            <p class="mn-set__help"><?php echo mn_set_h($d['help']); ?>
              <?php if ($last) { ?><span class="mn-set__last">แก้ล่าสุด <?php echo mn_set_h($last[2] ? date('d/m/Y H:i', strtotime($last[2])) : ''); ?><?php echo $last[1] !== '' ? ' โดย ' . mn_set_h($last[1]) : ''; ?></span><?php } ?>
            </p>
          </div>
          <?php } ?>
          <div class="mn-set__actions"><button type="submit" class="mn-set__btn is-primary"><i class="fa fa-check" aria-hidden="true"></i> บันทึกการตั้งค่า</button></div>
        </form>
      </section>
      <?php } ?>

      <?php if ($view === 'drive') { ?>
      <section class="mn-set__card">
        <dl class="mn-set__stats">
          <div><dt>รูปทั้งหมด</dt><dd data-stat="n"><?php echo number_format((int) $photo_stat['n']); ?></dd></div>
          <div><dt>ขึ้น Drive แล้ว</dt><dd data-stat="on_drive"><?php echo number_format((int) $photo_stat['on_drive']); ?></dd></div>
          <div><dt>พื้นที่บน server</dt><dd data-stat="bytes"><?php echo number_format((int) $photo_stat['bytes'] / 1048576, 1); ?> MB</dd></div>
        </dl>
        <div class="mn-set__drive" id="mn_set_drive" aria-live="polite"><p class="mn-set__muted"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> กำลังตรวจการเชื่อมต่อ Google Drive</p></div>
        <div class="mn-set__actions">
          <button type="button" class="mn-set__btn" data-drive="check"><i class="fa fa-refresh" aria-hidden="true"></i> ตรวจอีกครั้ง</button>
          <button type="button" class="mn-set__btn" data-drive="push"><i class="fa fa-upload" aria-hidden="true"></i> ส่งรูปที่ค้างขึ้น Drive</button>
          <button type="button" class="mn-set__btn" data-drive="rename" title="ตั้งชื่อไฟล์และโฟลเดอร์ใบงานตามรูปแบบปัจจุบัน ใช้หลังเปลี่ยนรูปแบบชื่อ หรือย้ายข้อตรวจ"><i class="fa fa-i-cursor" aria-hidden="true"></i> ตั้งชื่อไฟล์บน Drive ใหม่</button>
        </div>
        <p class="mn-set__help">ตั้งค่าการเชื่อม Drive ที่ไฟล์ config/google_drive.local.php บน server (ดูขั้นตอนใน google_drive.example.php)</p>
      </section>

      <section class="mn-set__card" id="jobs">
        <div class="mn-pj__head">
          <h2><i class="fa fa-file-image-o" aria-hidden="true"></i> ใบงานที่มีรูป <span class="mn-pj__count" data-total="<?php echo (int) $pj['total']; ?>"><?php echo number_format((int) $pj['total']); ?> ใบงาน</span></h2>
          <form method="get" action="settings#jobs" class="mn-pj__search" role="search">
            <input type="hidden" name="view" value="drive" />
            <input type="search" name="q" value="<?php echo mn_set_h($pj_q); ?>" placeholder="ค้นเลขงาน S/N รุ่น ลูกค้า" aria-label="ค้นใบงาน" />
            <button type="submit" class="mn-set__btn"><i class="fa fa-search" aria-hidden="true"></i> ค้น</button>
            <?php if ($pj_q !== '') { ?><a class="mn-pj__clear" href="settings?view=drive#jobs">ล้าง</a><?php } ?>
          </form>
        </div>
        <?php if (!$pj['rows']) { ?>
        <p class="mn-set__muted mn-pj__empty"><?php echo $pj_q !== '' ? 'ไม่พบใบงานที่ตรงกับ "' . mn_set_h($pj_q) . '"' : 'ยังไม่มีใบงานที่แนบรูป'; ?></p>
        <?php } else { ?>
        <p class="mn-set__muted mn-pj__empty" hidden="hidden">ไม่มีรูปเหลือในรายการนี้</p>
        <ul class="mn-pj">
          <?php foreach ($pj['rows'] as $r) {
              $ph = isset($pj_photos[$r['trp_id']]) ? $pj_photos[$r['trp_id']] : array();
              $wait = $r['n'] - $r['on_drive'];
          ?>
          <li class="mn-pj__row">
            <div class="mn-pj__job">
              <?php if ($r['ts_id'] > 0) { ?>
              <a class="mn-pj__mtn" href="detail_job?ts_id=<?php echo (int) $r['ts_id']; ?>" title="เปิดใบงาน"><?php echo mn_set_h($r['mtn'] !== '' ? $r['mtn'] : 'ใบงาน'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a>
              <?php } else { ?>
              <span class="mn-pj__mtn is-gone">ไม่พบใบงาน (รายการ <?php echo (int) $r['trp_id']; ?>)</span>
              <?php } ?>
              <?php if ($r['status'] !== '') { ?><span class="mn-pj__status"><?php echo mn_set_h($r['status']); ?></span><?php } ?>
              <span class="mn-pj__prod"><?php echo mn_set_h($r['product']); ?><?php if ($r['sn'] !== '') { ?> <small>SN <?php echo mn_set_h($r['sn']); ?></small><?php } ?></span>
              <?php if ($r['customer'] !== '') { ?><span class="mn-pj__cus"><?php echo mn_set_h($r['customer']); ?></span><?php } ?>
              <span class="mn-pj__meta">
                <b class="mn-pj__n"><?php echo (int) $r['n']; ?> รูป</b>
                <?php if ($wait > 0) { ?><span class="mn-pj__wait"><i class="fa fa-clock-o" aria-hidden="true"></i> รอขึ้น Drive <?php echo $wait; ?></span><?php } else { ?><span class="mn-pj__ok"><i class="fa fa-check" aria-hidden="true"></i> ขึ้น Drive ครบ</span><?php } ?>
                <?php if ($r['last_at'] !== '') { ?><span>ล่าสุด <?php echo mn_set_h(date('d/m/Y H:i', strtotime($r['last_at']))); ?></span><?php } ?>
              </span>
            </div>
            <div class="mn-pj__photos"><?php echo mn_set_photo_strip($ph, $r['trp_id']); ?></div>
          </li>
          <?php } ?>
        </ul>
        <?php if ($pj_pages > 1) { ?>
        <nav class="mn-pj__pager" aria-label="หน้ารายการ">
          <?php $qs = 'settings?view=drive' . ($pj_q !== '' ? '&q=' . rawurlencode($pj_q) : '') . '&pg='; ?>
          <?php if ($pj_pg > 1) { ?><a class="mn-set__btn" href="<?php echo mn_set_h($qs . ($pj_pg - 1)); ?>#jobs"><i class="fa fa-angle-left" aria-hidden="true"></i> ก่อนหน้า</a><?php } ?>
          <span>หน้า <?php echo $pj_pg; ?> / <?php echo $pj_pages; ?></span>
          <?php if ($pj_pg < $pj_pages) { ?><a class="mn-set__btn" href="<?php echo mn_set_h($qs . ($pj_pg + 1)); ?>#jobs">ถัดไป <i class="fa fa-angle-right" aria-hidden="true"></i></a><?php } ?>
        </nav>
        <?php } ?>
        <?php } ?>
        <p class="mn-set__help">กดรูปเพื่อดูขนาดใหญ่และดาวน์โหลด | ติ๊กถูกมุมรูป = ขึ้น Google Drive แล้ว | "ทั้งหมด" = ดาวน์โหลดรูปของใบงานเป็น zip | ถังขยะ = ลบรูป ไฟล์บน Drive ย้ายลงถังขยะพร้อมกัน (กู้คืนได้ 30 วัน)</p>
      </section>
      <?php } ?>

      <?php if ($view === 'sources') { ?>
      <section class="mn-set__card">
        <p class="mn-set__help" style="margin-top:0">ใช้ในหน้ารับเครื่อง เสนอวันที่ซื้อจากหลักฐานซื้อขาย-ซ่อมจริง</p>
        <ul class="mn-set__status">
          <li class="is-ok"><i class="fa fa-check-circle" aria-hidden="true"></i><span><b>ประวัติซ่อม</b><small>ใบงานซ่อมเก่าของ S/N เดียวกัน</small></span></li>
          <?php foreach ($sources as $s) { ?>
          <li class="<?php echo $s['ok'] ? 'is-ok' : 'is-bad'; ?>"><i class="fa <?php echo $s['ok'] ? 'fa-check-circle' : 'fa-times-circle'; ?>" aria-hidden="true"></i>
            <span><b><?php echo mn_set_h($s['label']); ?></b><small><?php echo mn_set_h($s['db']); ?><?php echo $s['ok'] ? ' เชื่อมต่อแล้ว' : ' | ' . mn_set_h($s['note']); ?></small></span></li>
          <?php } ?>
        </ul>
        <p class="mn-set__help">ตั้งบัญชีอ่านอย่างเดียวในบล็อก stockparts_mysql / production_mysql / setup_mysql ของ config/env.local.php (ตัวอย่างใน env.local.example.php)</p>
      </section>
      <?php } ?>

      <?php if ($view === 'rules') { ?>
      <section class="mn-set__card">
        <ul class="mn-set__rules">
          <li>อายุเครื่อง และ MA เปลี่ยนอะไหล่พื้นฐานล่าสุด <b>ครบ 2 ปีขึ้นไป</b> ใส่ "MA เปลียนอะไหล่พื้นฐาน" ในรายการซ่อมให้อัตโนมัติ</li>
          <li>อายุ <b><?php echo mn_set_h(mn_sys_months_text($values['near_2y_months'])); ?></b> ถึงก่อนครบ 2 ปี แสดงสีส้ม "ใกล้ครบ 2 ปี" และแนะนำพิจารณา MA (ช่างกดเพิ่มเอง)</li>
          <li>นับอายุตามปฏิทินถึง <b>วันที่รับซ่อม</b> | MA ล่าสุดนับเฉพาะใบงานที่ลูกค้า <b>อนุมัติซ่อม</b></li>
          <li>เครื่องอายุไม่ถึง 2 ปี ข้อ MA ล่าสุดเป็น N/A</li>
          <li>ชุดอะไหล่ (ตั้งในรายการตรวจเช็ค): มีรายการชุดในใบงานแล้ว ไม่ส่งรายการย่อยแยก</li>
          <li>รูปจากคอมและมือถือย่อเหลือด้านยาว 1600px ก่อนส่ง | ขึ้น Drive แล้วลบรูปขนาดดูบน server เก็บรูปเล็กไว้</li>
          <li>โฟลเดอร์บน Drive: ปี-เดือน / เลขงาน_ลูกค้า_รุ่น_SN</li>
          <li>ลบรูป = ย้ายไฟล์บน Drive ลงถังขยะ (กู้คืนได้ 30 วัน) ลบไม่สำเร็จจะลองซ้ำเอง | ลบจนใบงานไม่เหลือรูป โฟลเดอร์ใบงานลงถังขยะด้วย เดือนที่ว่างก็ลบด้วย</li>
          <li>QR มือถือใช้ได้ <b><?php echo (int) $values['qr_hours']; ?> ชั่วโมง</b> ปิดเองเมื่อบันทึกการประเมิน หรือใบงานเสนอราคาแล้ว</li>
          <li>แก้ไขงานประเมินได้จนกว่าจะออกเลขเสนอราคา</li>
          <li>ประกันเครื่องใหม่ใช้เดือนตามรุ่น รุ่นที่ยังไม่ได้ตั้งใช้ค่าเริ่มต้น <b><?php echo (int) $values['default_warranty_months']; ?> เดือน</b> | ไม่มีวันที่ซื้อ = ให้เลือกเอง</li>
          <li>ประกันนับถึง <b>วันรับเครื่อง</b>: เครื่องใหม่หรือซื้อเพิ่มยังเหลือ = อยู่ในประกัน | เหลือแค่ประกันงานซ่อม <b><?php echo (int) $values['repair_warranty_days']; ?> วัน</b> = อยู่ในประกันซ่อม</li>
          <li>ประกันซื้อเพิ่ม (<?php echo mn_set_h(implode(' / ', (array) $values['warranty_packages'])); ?> เดือน) เริ่ม <b>ต่อท้ายประกันเครื่องใหม่/ซื้อเพิ่มที่ยังเหลือ</b> (ไม่นับประกันงานซ่อม) ถ้าหมดแล้วเริ่มวันที่ซื้อ</li>
        </ul>
      </section>
      <?php } ?>

      <?php if ($view === 'pin') { ?>
      <section class="mn-set__card">
        <form method="post" action="settings" class="mn-set__pinform" autocomplete="off">
          <label>รหัสเดิม <input type="password" name="pin_old" inputmode="numeric" maxlength="8" required="required" /></label>
          <label>รหัสใหม่ <input type="password" name="pin_new" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" required="required" /></label>
          <label>ยืนยันรหัสใหม่ <input type="password" name="pin_new2" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" required="required" /></label>
          <button type="submit" class="mn-set__btn"><i class="fa fa-check" aria-hidden="true"></i> เปลี่ยนรหัส</button>
        </form>
        <p class="mn-set__help">ตัวเลข 4–8 หลัก ใช้ล็อกหน้าตั้งค่าระบบ, รายการตรวจเช็ค, ประกันรายรุ่น, ทะเบียนประกัน และรายการขนส่ง ปลดล็อกแล้วใช้ได้ 30 นาทีนับจากใช้งานล่าสุด</p>
      </section>
      <?php } ?>

      <?php if ($view === 'about') { ?>
      <section class="mn-set__card">
        <dl class="mn-set__info">
          <div><dt>เวอร์ชัน</dt><dd><?php echo mn_set_h($version !== '' ? $version : 'ไม่ระบุ (ระบบหลัก)'); ?></dd></div>
          <div><dt>โหมด</dt><dd><?php echo $is_prod ? 'production' : 'development'; ?></dd></div>
          <div><dt>ป้ายบนแถบเมนู</dt><dd><?php echo mn_set_h(trim($banner) !== '' ? trim($banner) : 'ไม่มี (ระบบหลัก)'); ?></dd></div>
        </dl>
      </section>
      <?php } ?>
    </div>
  </div>
<script type="text/javascript">
// ยืนยันก่อนดึงการตั้งค่าทั้งหมด — ใช้ modal ของระบบ (confirm() ของเบราว์เซอร์ถูกปิดได้ในบางแอป)
(function () {
  var form = document.getElementById('mn_set_sync');
  if (!form) { return; }
  var btn = document.getElementById('mn_set_sync_btn');
  var go = function () {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> กำลังดึงการตั้งค่าจาก server';
    form.submit();
  };
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = 'รายการตรวจเช็ค ชุดอะไหล่ เหตุผลการเปลี่ยน เงื่อนไขที่ปรับได้ ประกันรายรุ่น และรายการขนส่ง ในเครื่องนี้ จะถูกแทนที่ด้วยของ server\n\n'
      + 'รหัสเข้าหน้าตั้งค่าของเครื่องนี้ไม่เปลี่ยน | ระบบสำรองของเดิมเป็นไฟล์ไว้ก่อน | ข้อมูลงาน (ใบงาน ผลตรวจ รูป ทะเบียนประกัน) ไม่ถูกแตะ';
    if (typeof window.mnModalConfirm === 'function') {
      window.mnModalConfirm(msg, { title: 'ดึงการตั้งค่าทั้งหมดจาก server', variant: 'warning', okLabel: 'ดึงค่า', cancelLabel: 'ยกเลิก', onConfirm: go });
    } else {
      go();
    }
  });
}());
</script>
<?php if ($view === 'drive') { ?>
<script type="text/javascript" src="js/settings-page.js?v=<?php echo filemtime(__DIR__ . '/js/settings-page.js'); ?>"></script>
<script type="text/javascript" src="js/mn-photo-lightbox.js?v=<?php echo filemtime(__DIR__ . '/js/mn-photo-lightbox.js'); ?>"></script>
<?php } ?>
</body>
</html>
