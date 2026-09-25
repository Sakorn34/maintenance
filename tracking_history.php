<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ประวัติการติดตาม + ฟอร์มบันทึกครั้งใหม่ — refactor จาก `old_system/tracking_history.php`
 * แยก query ประวัติกับข้อมูล transac, ใช้ prepared สำหรับ transac; เลย์เอาต์ card + grid ตามกฎ mn-*; แจ้งเตือนด้วย modal ผ่าน `mn_modal_assets` + `mn-maintenance-flow-modals.js`
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
$ts_id = maintenance_flow_get_int($_GET, 'ts_id', 0);
$tsp_id = maintenance_flow_get_int($_GET, 'tsp_id', 0);
$th_status_job_raw = isset($_GET['th_status_job']) ? (string) $_GET['th_status_job'] : '';
$trp_product_label = isset($_GET['trp_product']) ? (string) $_GET['trp_product'] : '';

$history_rows = maintenance_flow_fetch_tracking_history_rows($connect, $trp_id);
$row_ts = maintenance_flow_fetch_transac_row($connect, $ts_id);
if ($row_ts === null) {
    maintenance_db_odbc_close($connect);
    exit('ไม่พบข้อมูลใบงาน');
}

$detail = maintenance_wait_hardware_contact_html($row_ts);
if ($th_status_job_raw === 'ติดตามเครื่องสำรอง') {
    $detail .= '<b>' . maintenance_status_logistic_h('เครื่องสำรอง -- ' . $trp_product_label) . '</b>';
} else {
    $detail .= '<b>' . maintenance_status_logistic_h('เครื่องซ่อม -- ' . $trp_product_label) . '</b>';
}

maintenance_db_odbc_close($connect);

$history_count = count($history_rows);
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
<title>ประวัติการติดตาม</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/tracking_history-page.js"></script>
</head>
<body class="mn-addjob-page mn-wait-hardware-flow-page mn-tracking-history-page">
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-wait-hardware-flow-inner">
      <section class="mn-flow-popup-card mn-tracking-history-card" aria-labelledby="mn-tracking-history-title">
        <header class="mn-flow-popup-card__header">
          <h1 id="mn-tracking-history-title" class="mn-flow-popup-card__title">ประวัติการ<?php echo maintenance_status_logistic_h($th_status_job_raw); ?></h1>
        </header>
        <div class="mn-flow-popup-card__body">
          <div class="mn-flow-popup-block-row">
            <div class="mn-flow-popup-contact-full"><?php echo $detail; ?></div>
          </div>
          <div class="mn-tracking-history-hiddens">
            <input type="hidden" id="th_ts_id" value="<?php echo (int) $ts_id; ?>" />
            <input type="hidden" id="th_trp_id" value="<?php echo (int) $trp_id; ?>" />
            <input type="hidden" id="th_tsp_id" value="<?php echo (int) $tsp_id; ?>" />
            <input type="hidden" id="th_status_job" value="<?php echo maintenance_status_logistic_h($th_status_job_raw); ?>" />
          </div>
          <section class="mn-tracking-history-log" aria-label="ข้อมูลการติดตามที่ผ่านมา">
            <h2 class="mn-tracking-history-log__heading">ข้อมูลการติดตาม</h2>
            <?php
            if ($history_count === 0) {
                ?>
            <p class="mn-tracking-history-log__empty">ยังไม่มีประวัติการติดตาม</p>
                <?php
            } else {
                $idx = 1;
                foreach ($history_rows as $result_th) {
                    ?>
            <article class="mn-tracking-history-entry">
              <h3 class="mn-tracking-history-entry__title">ครั้งที่ <?php echo (int) $idx++; ?></h3>
              <dl class="mn-tracking-history-entry__dl">
                <dt class="mn-tracking-history-entry__term">วันที่ติดตาม</dt>
                <dd class="mn-tracking-history-entry__def"><?php echo maintenance_status_logistic_h(isset($result_th['th_date_tracking']) ? (string) $result_th['th_date_tracking'] : ''); ?></dd>
                <dt class="mn-tracking-history-entry__term">Next due</dt>
                <dd class="mn-tracking-history-entry__def"><?php echo maintenance_status_logistic_h(isset($result_th['th_date_nextdue']) ? (string) $result_th['th_date_nextdue'] : ''); ?></dd>
                <dt class="mn-tracking-history-entry__term">สถานะ</dt>
                <dd class="mn-tracking-history-entry__def"><?php echo maintenance_status_logistic_h(isset($result_th['th_status_job']) ? (string) $result_th['th_status_job'] : ''); ?></dd>
                <dt class="mn-tracking-history-entry__term">หมายเหตุ</dt>
                <dd class="mn-tracking-history-entry__def"><?php echo maintenance_status_logistic_h(isset($result_th['th_detail']) ? (string) $result_th['th_detail'] : ''); ?></dd>
                <dt class="mn-tracking-history-entry__term">ผู้ติดตาม</dt>
                <dd class="mn-tracking-history-entry__def"><?php echo maintenance_status_logistic_h(isset($result_th['th_user']) ? (string) $result_th['th_user'] : ''); ?></dd>
              </dl>
            </article>
                    <?php
                }
            }
            ?>
          </section>
          <div class="mn-flow-popup-row">
            <span class="mn-flow-popup-label">สถานะ (Status)</span>
            <div class="mn-flow-popup-control mn-flow-popup-readonly"><?php echo maintenance_status_logistic_h($th_status_job_raw); ?></div>
          </div>
          <div class="mn-flow-popup-row mn-flow-popup-row--two-dates">
            <div class="mn-flow-popup-split">
              <label class="mn-flow-popup-label" for="th_date_tracking">วันที่ติดตาม (Track date)</label>
              <div class="mn-flow-popup-control">
                <input type="date" class="mn-flow-popup-input mn-flow-popup-input--date" name="th_date_tracking" id="th_date_tracking" value="<?php echo maintenance_status_logistic_h(date('Y-m-d')); ?>" />
              </div>
            </div>
            <div class="mn-flow-popup-split">
              <label class="mn-flow-popup-label" for="th_date_nextdue">ครั้งถัดไป (Next due)</label>
              <div class="mn-flow-popup-control">
                <input type="date" class="mn-flow-popup-input mn-flow-popup-input--date" name="th_date_nextdue" id="th_date_nextdue" value="" />
              </div>
            </div>
          </div>
          <div class="mn-flow-popup-row">
            <label class="mn-flow-popup-label" for="th_detail">รายละเอียด (Detail)</label>
            <div class="mn-flow-popup-control">
              <textarea class="mn-flow-popup-textarea" name="th_detail" id="th_detail" autocomplete="off" rows="3"></textarea>
            </div>
          </div>
          <div class="mn-flow-popup-row mn-flow-popup-row--inline-actions">
            <span class="mn-flow-popup-label">ผู้บันทึก (User)</span>
            <div class="mn-flow-popup-control mn-flow-popup-control--with-save">
              <input type="hidden" id="th_user" name="th_user" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
              <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
              <a class="mn-flow-popup-save" href="javascript:void(0);" onclick="mnTrackingHistorySave();" title="บันทึก">
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
