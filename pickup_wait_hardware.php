<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ฟอร์มแจ้งเข้ารับพัสดุ (popup) — refactor จาก `old_system/pickup_wait_hardware.php`
 * ดึงข้อมูลด้วย JOIN ครั้งเดียว, แสดงรายละเอียดลูกค้าแบบ UTF-8 + escape; เลย์เอาต์ card + grid ตามกฎ mn-*; แจ้งเตือนด้วย modal ผ่าน `mn_modal_assets` + `mn-maintenance-flow-modals.js`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$row = maintenance_flow_fetch_repair_with_transac($connect, $trp_id);
if ($row === null) {
    maintenance_db_odbc_close($connect);
    exit('ไม่พบข้อมูลงานซ่อม');
}

$detail = maintenance_wait_hardware_contact_html($row);
$detail .= '<b>' . maintenance_status_logistic_h(
    'สินค้า -- ' . (isset($row['trp_product']) ? (string) $row['trp_product'] : '')
) . '</b>';

maintenance_db_odbc_close($connect);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-wait-hardware-flow.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>แจ้งเข้ารับพัสดุ</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/pickup_wait_hardware-page.js"></script>
</head>
<body class="mn-addjob-page mn-wait-hardware-flow-page mn-pickup-wait-hardware-page">
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-wait-hardware-flow-inner">
      <section class="mn-flow-popup-card" aria-labelledby="mn-pickup-wait-hw-title">
        <header class="mn-flow-popup-card__header">
          <h1 id="mn-pickup-wait-hw-title" class="mn-flow-popup-card__title">แจ้งเข้ารับพัสดุ</h1>
        </header>
        <div class="mn-flow-popup-card__body">
          <div class="mn-flow-popup-block-row">
            <div class="mn-flow-popup-contact-full"><?php echo $detail; ?></div>
          </div>
          <input type="hidden" id="trp_id" value="<?php echo (int) $trp_id; ?>" />
          <div class="mn-flow-popup-row">
            <label class="mn-flow-popup-label" for="trp_duedate_pickup">วันที่นัดเข้ารับเครื่อง</label>
            <div class="mn-flow-popup-control">
              <input type="date" class="mn-flow-popup-input mn-flow-popup-input--date" name="trp_duedate_pickup" id="trp_duedate_pickup" value="" />
            </div>
          </div>
          <div class="mn-flow-popup-row">
            <label class="mn-flow-popup-label" for="trp_contact_customer">ติดต่อคุณ</label>
            <div class="mn-flow-popup-control">
              <input type="text" class="mn-flow-popup-input" name="trp_contact_customer" id="trp_contact_customer" value="" />
            </div>
          </div>
          <div class="mn-flow-popup-row">
            <label class="mn-flow-popup-label" for="trp_contact_tel">เบอร์โทร</label>
            <div class="mn-flow-popup-control">
              <input type="text" class="mn-flow-popup-input" name="trp_contact_tel" id="trp_contact_tel" value="" />
            </div>
          </div>
          <div class="mn-flow-popup-row">
            <label class="mn-flow-popup-label" for="trp_remarks">รายละเอียดเพิ่มเติม</label>
            <div class="mn-flow-popup-control">
              <textarea class="mn-flow-popup-textarea" name="trp_remarks" id="trp_remarks" autocomplete="off" rows="3"></textarea>
            </div>
          </div>
          <div class="mn-flow-popup-row mn-flow-popup-row--inline-actions">
            <span class="mn-flow-popup-label">ผู้บันทึก</span>
            <div class="mn-flow-popup-control mn-flow-popup-control--with-save">
              <input type="hidden" id="trp_user_inform" name="trp_user_inform" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
              <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
              <a class="mn-flow-popup-save" href="javascript:void(0);" onclick="mnPickupWaitHwSaveInform(<?php echo (int) $trp_id; ?>);" title="บันทึก">
                <i class="fa fa-save fa-2x" aria-hidden="true"></i><span class="mn-flow-popup-save__text">บันทึก</span>
              </a>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</body>
</html>
