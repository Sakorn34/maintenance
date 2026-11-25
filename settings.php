<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า "ตั้งค่าระบบ" (เมนูโปรไฟล์บนแถบหัว → ตั้งค่าระบบ) — ต้องใส่รหัส (includes/settings_lock_helpers.php)
 * เปิดมาเป็นเมนูการตั้งค่าก่อน กดเมนูไหนค่อยเข้าเรื่องนั้น (?view=)
 * - เมนูไปหน้าตั้งค่าเดิม: checklist_settings, checklist_try, warranty_settings, warranty_registry, manage_logistics
 * - view=conditions เงื่อนไขที่ปรับได้ (cl_setting) | drive สถานะ Google Drive (js/settings-page.js) | sources แหล่งหลักฐานวันที่ซื้อ
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

mn_settings_gate($connect, 'page'); // ต้องใส่รหัสก่อนเข้า

function mn_set_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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
$ps = mysqli_query($connect, 'SELECT COUNT(*) AS n, COALESCE(SUM(cp_drive_id IS NOT NULL), 0) AS on_drive, COALESCE(SUM(cp_size + cp_thumb_size), 0) AS bytes FROM cl_photo');
$photo_stat = $ps ? mysqli_fetch_assoc($ps) : array('n' => 0, 'on_drive' => 0, 'bytes' => 0);
$sources = ($view === 'menu' || $view === 'sources') ? mn_bds_source_status($connect) : array();
maintenance_db_odbc_close($connect);

$sources_ok = 1;
foreach ($sources as $s) { if ($s['ok']) { $sources_ok++; } }
$version = is_file(__DIR__ . '/VERSION.txt') ? trim((string) file_get_contents(__DIR__ . '/VERSION.txt')) : '';
$banner_file = __DIR__ . '/config/site_banner.local.php';
$banner = is_file($banner_file) ? (string) (require $banner_file) : '';
$is_prod = function_exists('maintenance_new_app_env_is_production') && maintenance_new_app_env_is_production();
$flash_ok = isset($_GET['saved']) || isset($_GET['pin']);
$flash_msg = isset($_GET['pin']) ? 'เปลี่ยนรหัสเข้าหน้าตั้งค่าแล้ว' : 'บันทึกการตั้งค่าแล้ว';
$flash_err = isset($_GET['err']) ? (string) $_GET['err'] : '';

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
        array('settings?view=drive', 'fa-cloud-upload', 'รูปประกอบ / Google Drive', 'สถานะการเชื่อมต่อ, ส่งรูปค้าง, ตั้งชื่อไฟล์ใหม่', number_format((int) $photo_stat['n']) . ' รูป'),
        array('settings?view=sources', 'fa-search', 'แหล่งหลักฐานวันที่ซื้อ', 'ประวัติซ่อม, คลังเครื่อง, ติดตั้ง, ส่งมอบ', $sources_ok . '/' . (count($sources) + 1) . ' เชื่อมแล้ว'),
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
          <div><dt>รูปทั้งหมด</dt><dd><?php echo number_format((int) $photo_stat['n']); ?></dd></div>
          <div><dt>ขึ้น Drive แล้ว</dt><dd><?php echo number_format((int) $photo_stat['on_drive']); ?></dd></div>
          <div><dt>พื้นที่บน server</dt><dd><?php echo number_format((int) $photo_stat['bytes'] / 1048576, 1); ?> MB</dd></div>
        </dl>
        <div class="mn-set__drive" id="mn_set_drive" aria-live="polite"><p class="mn-set__muted"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> กำลังตรวจการเชื่อมต่อ Google Drive</p></div>
        <div class="mn-set__actions">
          <button type="button" class="mn-set__btn" data-drive="check"><i class="fa fa-refresh" aria-hidden="true"></i> ตรวจอีกครั้ง</button>
          <button type="button" class="mn-set__btn" data-drive="push"><i class="fa fa-upload" aria-hidden="true"></i> ส่งรูปที่ค้างขึ้น Drive</button>
          <button type="button" class="mn-set__btn" data-drive="rename" title="ใช้หลังเปลี่ยนรูปแบบชื่อไฟล์ หรือย้ายข้อตรวจ"><i class="fa fa-i-cursor" aria-hidden="true"></i> ตั้งชื่อไฟล์บน Drive ใหม่</button>
        </div>
        <p class="mn-set__help">ตั้งค่าการเชื่อม Drive ที่ไฟล์ config/google_drive.local.php บน server (ดูขั้นตอนใน google_drive.example.php)</p>
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
          <li>ลบรูป = ย้ายไฟล์บน Drive ลงถังขยะ (กู้คืนได้ 30 วัน) ลบไม่สำเร็จจะลองซ้ำเอง</li>
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
<?php if ($view === 'drive') { ?>
<script type="text/javascript" src="js/settings-page.js?v=<?php echo filemtime(__DIR__ . '/js/settings-page.js'); ?>"></script>
<?php } ?>
</body>
</html>
