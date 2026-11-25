<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ฟอร์มกำหนด/เปลี่ยน S/N เครื่องสำรองจาก `detail_job` — เลือก SN เท่านั้น (วันที่ส่ง/ขนส่ง/tracking บันทึกทีหลังที่ detail_job หรือ status_logistic)
 * ใช้ธีม mn-*, ดึงรายการ SN ผ่าน `maintenance_status_logistic_spare_blank_options`, ปิด connection ก่อน render
 * เลย์เอาต์ card + grid ตามกฎโปรเจกต์; ฝั่งเบราว์เซอร์: `mn_modal_assets.php` → `mn-maintenance-flow-modals.js` → `edit_spare-page.js`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$trp_ts_id = maintenance_flow_get_int($_GET, 'trp_ts_id', 0);
$trp_product = isset($_GET['trp_product']) ? trim((string) $_GET['trp_product']) : '';
$tsp_sn_param = isset($_GET['tsp_sn']) ? trim((string) $_GET['tsp_sn']) : '';
$trp_detail_raw = isset($_GET['trp_detail']) ? (string) $_GET['trp_detail'] : '';

$edit_mode = ($tsp_sn_param === '') ? 'Send later' : 'change';
$txt_editmode = ($tsp_sn_param === '')
    ? 'เลือก S/N เครื่องสำรอง :'
    : ('<b>สำรองก่อนหน้า S/N : <span class="mn-edit-spare-sn-prev">' . maintenance_status_logistic_h($tsp_sn_param) . '</span></b> &gt;&gt;&gt; เปลี่ยนเป็น : ');

$spare_opts = maintenance_status_logistic_spare_blank_options($connect, $trp_product);
$sn_list = array();
foreach ($spare_opts as $opt) {
    $sn = isset($opt['sn']) ? (string) $opt['sn'] : '';
    if ($sn !== '' && trim($sn) !== trim($tsp_sn_param)) {
        $sn_list[] = $sn;
    }
}

maintenance_db_odbc_close($connect);

$edit_title = ($tsp_sn_param === '') ? 'เพิ่มเครื่องสำรอง (เลือก S/N)' : 'เปลี่ยนเครื่องสำรอง';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-wait-hardware-flow.css" />
<link rel="stylesheet" href="css/mn-page-edit-spare.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>แก้ไขเครื่องสำรอง</title>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/edit_spare-page.js?v=2"></script>
</head>
<body class="mn-addjob-page mn-wait-hardware-flow-page mn-edit-spare-page">
  <div class="header">
    <?php
    $page = 'status_wait_hardware';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-wait-hardware-flow-inner mn-edit-spare-inner">
      <section class="mn-edit-spare-card" aria-labelledby="mn-edit-spare-title">
        <header class="mn-edit-spare-card__header">
          <h1 id="mn-edit-spare-title" class="mn-edit-spare-card__title"><?php echo maintenance_status_logistic_h($edit_title); ?></h1>
        </header>
        <div class="mn-edit-spare-card__body">
          <div class="mn-edit-spare-two-col">
            <div class="mn-edit-spare-panel">
              <h2 class="mn-edit-spare-panel__heading">ข้อมูลบริษัท</h2>
              <div class="mn-edit-spare-panel__body"><?php echo nl2br(maintenance_status_logistic_h($trp_detail_raw), false); ?></div>
            </div>
            <div class="mn-edit-spare-panel">
              <h2 class="mn-edit-spare-panel__heading">ข้อมูลเครื่องซ่อม</h2>
              <div class="mn-edit-spare-panel__body"><?php echo maintenance_status_logistic_h($trp_product); ?></div>
            </div>
          </div>
          <div class="mn-edit-spare-section-head">
            <span class="mn-edit-spare-ex2"><strong>ข้อมูลเครื่องสำรอง</strong></span>
          </div>
          <div class="mn-edit-spare-row mn-edit-spare-row--sn">
            <div class="mn-edit-spare-sn-line">
              <span class="mn-edit-spare-sn-label"><?php echo $txt_editmode; ?></span>
              <select class="mn-edit-spare-select mn-edit-spare-select--sn" name="tsp_sn" id="tsp_sn">
                <option value="">กรุณาระบุ SN</option>
                <?php
                $sn_count = count($sn_list);
                for ($a = 0; $a < $sn_count; $a++) {
                    $snv = (string) $sn_list[$a];
                    ?>
                <option value="<?php echo maintenance_status_logistic_h($snv); ?>"><?php echo maintenance_status_logistic_h($snv); ?></option>
                    <?php
                }
                ?>
              </select>
            </div>
          </div>
          <p class="mn-edit-spare-sn-only-note" role="note">บันทึกวันที่ส่ง ขนส่ง และหมายเลข Tracking ได้ภายหลังที่หน้ารายละเอียดงานหรือรอส่งพัสดุ</p>
          <footer class="mn-edit-spare-card__footer">
            <a class="mn-edit-spare-save" href="javascript:void(0);" onclick="mnEditSpareSubmit();" title="บันทึก">
              <i class="fa fa-save" aria-hidden="true"></i><span class="mn-edit-spare-save__text">Save</span>
            </a>
            <input type="hidden" id="trp_id" value="<?php echo (int) $trp_id; ?>" />
            <input type="hidden" id="ts_id" value="<?php echo (int) $trp_ts_id; ?>" />
            <input type="hidden" id="tsp_product" value="<?php echo maintenance_status_logistic_h($trp_product); ?>" />
            <input type="hidden" id="tsp_remarks" value="<?php echo maintenance_status_logistic_h($edit_mode); ?>" />
            <?php if ($tsp_sn_param !== '') { ?>
            <input type="hidden" id="tsp_sn_old" value="<?php echo maintenance_status_logistic_h($tsp_sn_param); ?>" />
            <?php } ?>
          </footer>
        </div>
      </section>
    </div>
  </div>
</body>
</html>
