<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ฟอร์มบันทึกรับเครื่องซ่อมตาม `trp_id` — refactor จาก `old_system/update_wait_hardware.php`
 * แสดงผลแบบ card + grid (ไม่ใช้ตารางจัดเลย์เอาต์แบบ legacy) ใช้ธีม mn-* ตามกฎโปรเจกต์
 *
 * Flow:
 * require_auth → bootstrap → ดึงแถว repair+transac → ประกอบบล็อกติดต่อ (`maintenance_wait_hardware_contact_html`) → แสดงฟอร์ม
 * ฝั่งเบราว์เซอร์: `mn_modal_assets.php` → `js/mn-maintenance-flow-modals.js` → `js/update_wait_hardware-page.js` — แจ้งเตือน/ยืนยันด้วย modal กลาง → บันทึกผ่าน `save_wait_hardware`; ชื่อผู้รับเครื่องจาก `bootstrap_logistic_recorder`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/warranty_settings_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
if ($trp_id < 1) {
    $trp_id = maintenance_flow_get_int($_GET, 'id_trp', 0);
}
$row = maintenance_flow_fetch_repair_with_transac($connect, $trp_id);
if ($row === null) {
    maintenance_db_odbc_close($connect);
    exit('ไม่พบข้อมูลงานซ่อม (trp_id ไม่ถูกต้อง)');
}

$detail = maintenance_wait_hardware_contact_html($row);
$trp_product = maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : '');
$trp_repair_inform = maintenance_flow_db_text_to_utf8(isset($row['trp_repair_inform']) ? $row['trp_repair_inform'] : '');
$trp_sn = maintenance_flow_db_text_to_utf8(isset($row['trp_sn']) ? $row['trp_sn'] : '');
$trp_repair_age = maintenance_flow_db_text_to_utf8(isset($row['trp_repair_age']) ? $row['trp_repair_age'] : '');
$trp_insurance = maintenance_flow_db_text_to_utf8(isset($row['trp_insurance']) ? $row['trp_insurance'] : '');
$trp_insurance_selected = trim($trp_insurance);
$trp_insurance_valid = maintenance_wait_hardware_is_valid_insurance($trp_insurance_selected);
$trp_insurance_saved = $trp_insurance_valid; // ค่าประกันที่บันทึกไว้แล้ว — หน้าไม่เปลี่ยนให้เองตอนโหลด
$trp_insurance_options = maintenance_wait_hardware_allowed_insurance_values();
$trp_backup = maintenance_flow_db_text_to_utf8(isset($row['trp_backup']) ? $row['trp_backup'] : '');
$trp_buy_date = maintenance_flow_db_text_to_utf8(isset($row['trp_buy_date']) ? $row['trp_buy_date'] : '');
// วันที่รับเครื่อง: ใช้ค่าที่บันทึกไว้ ถ้ายังไม่มีใส่วันนี้ให้ก่อน (แก้ได้)
$trp_receive_date = trim((string) maintenance_flow_db_text_to_utf8(isset($row['trp_receive_date']) ? $row['trp_receive_date'] : ''));
$w_date_receive_value = preg_match('/^\d{4}-\d{2}-\d{2}$/', $trp_receive_date) && $trp_receive_date !== '0000-00-00' ? $trp_receive_date : date('Y-m-d');

$warranty_suggestion = maintenance_warranty_suggest_insurance($connect, $trp_product, $trp_buy_date, $trp_sn, $trp_id);
if (!$trp_insurance_valid && $warranty_suggestion['value'] !== null) {
    $trp_insurance_selected = $warranty_suggestion['value'];
    $trp_insurance_valid    = true;
}

maintenance_db_odbc_close($connect);
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
<link rel="stylesheet" href="css/mn-page-wait-hardware-flow.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-wait-hardware-flow.css'); ?>" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รอ รับเครื่องซ่อม</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/update_wait_hardware-page.js?v=<?php echo filemtime(__DIR__ . '/js/update_wait_hardware-page.js'); ?>"></script>
<script type="text/javascript" src="js/warranty-timeline.js?v=<?php echo filemtime(__DIR__ . '/js/warranty-timeline.js'); ?>"></script>
<script type="text/javascript" src="js/buy-date-suggest.js?v=<?php echo filemtime(__DIR__ . '/js/buy-date-suggest.js'); ?>"></script>
<script type="text/javascript">
window.mnWarrantyMonths = <?php echo json_encode($warranty_suggestion['product_months']); ?>;
window.mnRepairWarrantyActive = <?php echo ($warranty_suggestion['repair_warranty'] !== null) ? 'true' : 'false'; ?>;
</script>
</head>
<body class="mn-addjob-page mn-wait-hardware-flow-page mn-update-wait-hardware-page">
  <div class="header">
    <?php
    $page = 'status_wait_hardware';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-wait-hardware-flow-inner">
      <section class="mn-update-wait-hw-card" aria-labelledby="mn-update-wait-hw-title">
        <header class="mn-update-wait-hw-card__header">
          <h1 id="mn-update-wait-hw-title" class="mn-update-wait-hw-card__title">บันทึกรับเครื่องซ่อม</h1>
        </header>
        <div class="mn-update-wait-hw-card__body">
          <div class="mn-update-wait-hw-row">
            <label class="mn-update-wait-hw-label" for="w_date_receive">วันที่รับเครื่อง</label>
            <div class="mn-update-wait-hw-control">
              <input type="date" class="mn-update-wait-hw-date-input" name="w_date_receive" id="w_date_receive" value="<?php echo maintenance_status_logistic_h($w_date_receive_value); ?>" />
            </div>
          </div>
          <div class="mn-update-wait-hw-row mn-update-wait-hw-row--block">
            <span class="mn-update-wait-hw-label">บริษัท / ผู้ติดต่อ</span>
            <div class="mn-update-wait-hw-control mn-update-wait-hw-contact"><?php echo $detail; ?></div>
          </div>
          <div class="mn-update-wait-hw-row">
            <label class="mn-update-wait-hw-label" for="w_repair_inform">อาการที่แจ้ง</label>
            <div class="mn-update-wait-hw-control">
              <textarea class="mn-update-wait-hw-textarea" name="w_repair_inform" id="w_repair_inform" autocomplete="off" rows="4"><?php echo maintenance_status_logistic_h($trp_repair_inform); ?></textarea>
            </div>
          </div>
          <div class="mn-update-wait-hw-row">
            <span class="mn-update-wait-hw-label">รุ่นสินค้า</span>
            <div class="mn-update-wait-hw-control mn-update-wait-hw-readonly"><?php echo maintenance_status_logistic_h($trp_product); ?></div>
          </div>
          <div class="mn-update-wait-hw-row">
            <label class="mn-update-wait-hw-label" for="w_sn">S/N เครื่องซ่อม</label>
            <div class="mn-update-wait-hw-control">
              <input type="text" class="mn-update-wait-hw-sn-input" name="w_sn" id="w_sn" value="<?php echo maintenance_status_logistic_h($trp_sn); ?>" onkeypress="mnUpdateWaitHwCheckSnFirstChar(event);" />
            </div>
          </div>
          <div class="mn-update-wait-hw-row">
            <label class="mn-update-wait-hw-label" for="w_date_buy">วันที่ซื้อ</label>
            <div class="mn-update-wait-hw-control">
              <input type="date" class="mn-update-wait-hw-date-input" name="w_date_buy" id="w_date_buy" onchange="mnUpdateWaitHwCalAgeUse();" value="<?php echo maintenance_status_logistic_h($trp_buy_date); ?>" />
              <div class="mn-bds" id="w_buy_suggest" aria-live="polite" data-trp="<?php echo (int) $trp_id; ?>"></div>
            </div>
          </div>
          <div class="mn-update-wait-hw-row">
            <label class="mn-update-wait-hw-label" for="w_repair_age">อายุเครื่อง</label>
            <div class="mn-update-wait-hw-control mn-update-wait-hw-age-cell">
              <input type="text" class="mn-update-wait-hw-age-input" name="w_repair_age" id="w_repair_age" disabled="disabled" value="<?php echo maintenance_status_logistic_h($trp_repair_age); ?>" />
            </div>
          </div>
          <div class="mn-update-wait-hw-row">
            <label class="mn-update-wait-hw-label" for="w_insurance">ประกัน</label>
            <div class="mn-update-wait-hw-control">
              <select class="mn-update-wait-hw-select" name="w_insurance" id="w_insurance" required="required">
                <option value=""<?php echo $trp_insurance_valid ? '' : ' selected="selected"'; ?>>— กรุณาเลือก —</option>
                <?php foreach ($trp_insurance_options as $insurance_option) { ?>
                <option value="<?php echo maintenance_status_logistic_h($insurance_option); ?>"<?php echo ($trp_insurance_valid && $trp_insurance_selected === $insurance_option) ? ' selected="selected"' : ''; ?>><?php echo maintenance_status_logistic_h(maintenance_wait_hardware_insurance_option_label($insurance_option)); ?></option>
                <?php } ?>
              </select>
              <?php if ($warranty_suggestion['hint'] !== ''): ?>
              <div class="mn-update-wait-hw-insurance-hint" id="mn_insurance_hint"><?php echo htmlspecialchars($warranty_suggestion['hint'], ENT_QUOTES, 'UTF-8'); ?></div>
              <?php else: ?>
              <div class="mn-update-wait-hw-insurance-hint" id="mn_insurance_hint" style="display:none;"></div>
              <?php endif; ?>
              <div class="mn-wr" id="mn_wr_box" aria-live="polite" data-trp="<?php echo (int) $trp_id; ?>" data-product="<?php echo maintenance_status_logistic_h($trp_product); ?>" data-saved="<?php echo $trp_insurance_saved ? '1' : '0'; ?>"></div>
              <div class="mn-wr-links">
                <a class="mn-wr-link" id="mn_wr_reg_link" href="warranty_registry?sn=<?php echo rawurlencode($trp_sn); ?>" target="_blank" rel="noopener" title="เปิดแท็บใหม่ (ข้อมูลที่กรอกในหน้านี้ไม่หาย)"><i class="fa fa-certificate" aria-hidden="true"></i> ทะเบียนประกันเครื่องนี้</a>
                <a class="mn-wr-link" href="warranty_settings" target="_blank" rel="noopener" title="เปิดแท็บใหม่"><i class="fa fa-shield" aria-hidden="true"></i> ระยะเวลาประกันรายรุ่น</a>
              </div>
            </div>
          </div>
          <div class="mn-update-wait-hw-row">
            <label class="mn-update-wait-hw-label" for="w_backup">หมายเหตุ</label>
            <div class="mn-update-wait-hw-control">
              <input class="mn-update-wait-hw-backup-input" name="w_backup" id="w_backup" type="text" value="<?php echo maintenance_status_logistic_h($trp_backup); ?>" />
            </div>
          </div>
          <div class="mn-update-wait-hw-row">
            <span class="mn-update-wait-hw-label">ผู้รับเครื่องซ่อม</span>
            <div class="mn-update-wait-hw-control">
              <input type="hidden" id="trp_user_recive_ma" name="trp_user_recive_ma" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
              <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
            </div>
          </div>
        </div>
        <footer class="mn-update-wait-hw-card__footer">
          <p class="mn-update-wait-hw-footnote">รูปแบบวันที่ในระบบ: เดือน / วันที่ / ปี ค.ศ. (ตัวอย่าง 05/31/2021) — ช่องวันที่ใช้ตัวเลือกของเบราว์เซอร์</p>
          <div class="mn-update-wait-hw-actions">
            <a class="mn-update-wait-hw-back" href="status_wait_hardware" title="กลับ">
              <i class="fa fa-reply fa-2x" aria-hidden="true"></i><span class="mn-update-wait-hw-back__text">กลับ</span>
            </a>
            <a class="mn-update-wait-hw-save mn-update-wait-hw-save--primary" href="javascript:void(0);" onclick="mnUpdateWaitHwSaveStatus(<?php echo (int) $trp_id; ?>);" title="บันทึก">
              <i class="fa fa-save fa-2x" aria-hidden="true"></i><span class="mn-update-wait-hw-save__text">บันทึก</span>
            </a>
          </div>
        </footer>
      </section>
    </div>
  </div>
</body>
</html>
