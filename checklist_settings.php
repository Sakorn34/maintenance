<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าตั้งค่ารายการตรวจเช็คต่อรุ่นสินค้า (ข้อตรวจ / อาการ / กลุ่มชิ้นส่วน / ใช้ร่วมกับรุ่น / ดูประวัติซ่อม)
 * ข้อมูลโหลดและบันทึกผ่าน checklist_settings_api (AJAX)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/checklist_helpers.php';
require_once __DIR__ . '/includes/checklist_sync_helpers.php';

maintenance_checklist_ensure_tables($connect);
maintenance_db_odbc_close($connect);

$initial = isset($_GET['product']) ? trim((string) $_GET['product']) : '';
$sync_cfg = mn_cl_sync_config();
$can_pull = $sync_cfg['url'] !== '' && $sync_cfg['token'] !== '';
$pull_host = $can_pull ? (string) parse_url($sync_cfg['url'], PHP_URL_HOST) : '';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-checklist-settings.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-checklist-settings.css'); ?>" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>ตั้งค่ารายการตรวจเช็ค</title>
</head>
<body class="mn-addjob-page mn-checklist-settings-page">
  <div class="header">
    <?php
    $page = 'checklist_settings';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <main class="mn-cls" id="mn_cls">

      <aside class="mn-cls-col mn-cls-products" aria-label="รุ่นสินค้า">
        <div class="mn-cls-col__head">
          <h1 class="mn-cls-title">ตั้งค่ารายการตรวจเช็ค</h1>
          <?php if ($can_pull) { ?>
          <button type="button" class="mn-cls-btn mn-cls-btn--sync" id="mn_cls_sync" title="ดึงการตั้งค่าจาก <?php echo htmlspecialchars($pull_host, ENT_QUOTES, 'UTF-8'); ?> มาแทนที่ในเครื่องนี้"><i class="fa fa-cloud-download" aria-hidden="true"></i> ดึงค่าจาก server</button>
          <?php } ?>
          <div class="mn-cls-search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input type="search" id="mn_cls_search" class="mn-cls-input" placeholder="ค้นหารุ่น" autocomplete="off" />
          </div>
        </div>
        <ul class="mn-cls-plist" id="mn_cls_plist"><li class="mn-cls-skel"></li><li class="mn-cls-skel"></li><li class="mn-cls-skel"></li></ul>
        <p class="mn-cls-muted mn-cls-plist__foot">เรียงตามจำนวนงานซ่อม</p>
      </aside>

      <section class="mn-cls-col mn-cls-main" aria-label="รายการข้อตรวจ">
        <div class="mn-cls-empty" id="mn_cls_main_empty">
          <i class="fa fa-list-ul" aria-hidden="true"></i>
          <p>เลือกรุ่นสินค้าทางซ้ายเพื่อตั้งค่ารายการตรวจเช็ค</p>
        </div>
        <div id="mn_cls_main" hidden>
          <div class="mn-cls-main__head">
            <div>
              <h2 class="mn-cls-h2" id="mn_cls_pname"></h2>
              <p class="mn-cls-muted" id="mn_cls_pmeta"></p>
            </div>
            <div class="mn-cls-main__acts">
              <div class="mn-cls-tabs" role="tablist">
                <button type="button" class="mn-cls-tab is-on" data-tab="items" role="tab">ข้อตรวจ</button>
                <button type="button" class="mn-cls-tab" data-tab="setup" role="tab">อาการ / กลุ่ม</button>
                <button type="button" class="mn-cls-tab" data-tab="bundles" role="tab">ชุดอะไหล่</button>
                <button type="button" class="mn-cls-tab" data-tab="reasons" role="tab">เหตุผลเปลี่ยนอะไหล่</button>
                <button type="button" class="mn-cls-tab" data-tab="history" role="tab">ประวัติซ่อม</button>
              </div>
              <a class="mn-cls-btn mn-cls-btn--try" id="mn_cls_try" href="checklist_try" target="_blank" rel="noopener" title="เปิดหน้าที่ช่างใช้ประเมินเครื่อง (ไม่บันทึกข้อมูล)"><i class="fa fa-play-circle" aria-hidden="true"></i> ทดลองใช้งาน</a>
            </div>
          </div>
          <div class="mn-cls-share" id="mn_cls_share"></div>
          <div class="mn-cls-pane" data-pane="items" id="mn_cls_pane_items"></div>
          <div class="mn-cls-pane" data-pane="setup" id="mn_cls_pane_setup" hidden></div>
          <div class="mn-cls-pane" data-pane="bundles" id="mn_cls_pane_bundles" hidden></div>
          <div class="mn-cls-pane" data-pane="reasons" id="mn_cls_pane_reasons" hidden></div>
          <div class="mn-cls-pane" data-pane="history" id="mn_cls_pane_history" hidden></div>
        </div>
      </section>

      <aside class="mn-cls-col mn-cls-editor" id="mn_cls_editor" aria-label="แก้ไขข้อตรวจ">
        <div class="mn-cls-empty">
          <i class="fa fa-pencil-square-o" aria-hidden="true"></i>
          <p>เลือกข้อตรวจเพื่อแก้ไข หรือกด “เพิ่มข้อตรวจ”</p>
        </div>
      </aside>

    </main>
  </div>
  <div class="mn-cls-toast" id="mn_cls_toast" role="status" aria-live="polite"></div>
<script type="text/javascript">
window.mnClsInitial = <?php echo json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/checklist_settings-page.js?v=<?php echo filemtime(__DIR__ . '/js/checklist_settings-page.js'); ?>"></script>
</body>
</html>
