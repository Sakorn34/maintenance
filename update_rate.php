<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ฟอร์ม «รายการประเมิน» ตาม `trp_id` (GET) — refactor จาก `old_system/update_rate.php` ใช้ auth, ธีม mn-*, JOIN + prepared สำหรับดึงข้อมูล, เลย์เอาต์คาร์ด + grid (ไม่ใช้ table), ส่งบันทึกไป `save_rate`
 *
 * Flow:
 * require_auth → bootstrap → parse `trp_id` → ดึง bundle + รายการ Rate + `bootstrap_logistic_recorder` → ปิด `$connect` → render หรือ modal redirect
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/update_rate_helpers.php';
require_once __DIR__ . '/includes/detail_job_edit_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/checklist_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$rate_row = null;
$rate_options = array();

$rate_edit_locked = false;
$rate_edit_locked_ts_id = 0;

if ($trp_id > 0) {
    $rate_row = maintenance_update_rate_fetch_bundle($connect, $trp_id);
    if (is_array($rate_row)) {
        $rate_edit_locked_ts_id = (int) (isset($rate_row['trp_ts_id']) ? $rate_row['trp_ts_id'] : 0);
        $rate_edit_locked = maintenance_detail_job_repair_rate_edit_locked($connect, $trp_id);
        if (!$rate_edit_locked) {
            $r_product = maintenance_update_rate_resolve_rate_product(
                isset($rate_row['trp_product']) ? (string) $rate_row['trp_product'] : ''
            );
            $rate_options = maintenance_update_rate_fetch_rate_options($connect, $r_product);
        }
    }
}

$cl_product   = ($trp_id > 0 && is_array($rate_row))
    ? (string) (isset($rate_row['trp_product']) ? $rate_row['trp_product'] : '')
    : '';
$cl_supported = $cl_product !== '' && !$rate_edit_locked && maintenance_checklist_product_supported($cl_product);
$cl_symptoms         = array();
$cl_all_headers      = array();
$cl_saved_symptom_id = 0;
if ($cl_supported) {
    maintenance_checklist_ensure_tables($connect);
    $cl_symptoms    = maintenance_checklist_fetch_symptoms($connect);
    $cl_all_headers = maintenance_checklist_fetch_all_job_headers($connect, $trp_id);
    foreach ($cl_all_headers as $sid => $h) {
        if ($sid > 0) { $cl_saved_symptom_id = $sid; break; }
    }
}

maintenance_db_odbc_close($connect);

$missing = ($trp_id < 1 || !is_array($rate_row));
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-update-rate.css" />
<?php if ($cl_supported): ?>
<link rel="stylesheet" href="css/mn-page-checklist.css" />
<?php endif; ?>
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รายการประเมิน</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/update_rate-page.js"></script>
<?php if ($cl_supported): ?>
<script type="text/javascript">
window.mnClTrpId = <?php echo (int) $trp_id; ?>;
window.mnClProduct = <?php echo json_encode($cl_product, JSON_UNESCAPED_UNICODE); ?>;
window.mnClSavedSymptomId = <?php echo (int) $cl_saved_symptom_id; ?>;
</script>
<script type="text/javascript" src="js/update_rate-checklist.js"></script>
<?php endif; ?>
</head>
<body class="mn-addjob-page mn-update-rate-page">
<?php if ($missing) { ?>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode('ไม่พบข้อมูลหรือไม่ได้ระบุ trp_id', JSON_UNESCAPED_UNICODE); ?>, {
  variant: 'warning',
  onClose: function () { window.location.href = 'status_rate'; }
});
</script>
</body>
</html>
<?php
    exit;
}

if ($rate_edit_locked) {
    $rate_locked_redirect = $rate_edit_locked_ts_id > 0
        ? 'detail_job?ts_id=' . (int) $rate_edit_locked_ts_id
        : 'status_rate';
    ?>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode('ไม่สามารถแก้ไขงานประเมินซ่อมได้ เนื่องจากมีการเสนอราคาซ่อมแล้ว', JSON_UNESCAPED_UNICODE); ?>, {
  variant: 'warning',
  onClose: function () { window.location.href = <?php echo json_encode($rate_locked_redirect, JSON_UNESCAPED_UNICODE); ?>; }
});
</script>
</body>
</html>
<?php
    exit;
}
$insurance = isset($rate_row['trp_insurance']) ? (string) $rate_row['trp_insurance'] : '';
$ins_in = ($insurance === 'อยู่ในประกัน' || $insurance === 'อยู่ในประกันซ่อม');
$detail_plain = trim((string) (isset($rate_row['ts_enduser_com']) ? $rate_row['ts_enduser_com'] : '') . "\n"
    . (isset($rate_row['ts_security_com']) ? $rate_row['ts_security_com'] : ''));
$detail_html = nl2br(maintenance_status_logistic_h($detail_plain));
$trp_rate_date = maintenance_update_rate_rate_date_for_input(isset($rate_row['trp_rate_date']) ? $rate_row['trp_rate_date'] : '');
if ($trp_rate_date === '') {
    $trp_rate_date = date('Y-m-d');
}
$existing_user_rate = trim((string) (isset($rate_row['trp_user_rate']) ? $rate_row['trp_user_rate'] : ''));
$trp_user_rate_value = $existing_user_rate !== '' ? $existing_user_rate : $mn_logistic_recorder_value;
$has_prior_rate = $existing_user_rate !== ''
    || trim((string) (isset($rate_row['trp_repair_technician']) ? $rate_row['trp_repair_technician'] : '')) !== ''
    || maintenance_update_rate_repair_no_selected($rate_row, 1) !== '';
?>
  <div class="header">
    <?php
    $page = 'status_rate';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-update-rate-wrap">
      <article class="mn-update-rate-card" aria-labelledby="mn-update-rate-title">
        <header class="mn-update-rate-card__head">
          <h1 id="mn-update-rate-title" class="mn-update-rate-card__title">รายการประเมิน</h1>
        </header>

        <section class="mn-update-rate-summary" aria-label="ข้อมูลใบซ่อม">
          <input type="hidden" id="trp_status_job" name="trp_status_job" value="<?php echo maintenance_status_logistic_h((string) (isset($rate_row['trp_status_job']) ? $rate_row['trp_status_job'] : '')); ?>" />
          <dl class="mn-update-rate-dl">
            <div class="mn-update-rate-dl__row">
              <dt>วันที่รับซ่อม</dt>
              <dd><?php echo maintenance_status_logistic_h((string) (isset($rate_row['trp_receive_date']) ? $rate_row['trp_receive_date'] : '')); ?></dd>
            </div>
            <div class="mn-update-rate-dl__row">
              <dt>บริษัท</dt>
              <dd><?php echo $detail_html; ?></dd>
            </div>
            <div class="mn-update-rate-dl__row">
              <dt>สินค้า</dt>
              <dd><?php echo maintenance_status_logistic_h(
                  (string) (isset($rate_row['trp_product']) ? $rate_row['trp_product'] : '')
                  . '  [ ' . (isset($rate_row['trp_sn']) ? $rate_row['trp_sn'] : '') . ' ] '
              ); ?></dd>
            </div>
            <div class="mn-update-rate-dl__row">
              <dt>วันที่ซื้อ / อายุเครื่อง</dt>
              <dd><?php echo maintenance_status_logistic_h(
                  (string) (isset($rate_row['trp_buy_date']) ? $rate_row['trp_buy_date'] : '')
              ); ?> <span class="mn-update-rate-dl__sub"><?php echo maintenance_status_logistic_h((string) (isset($rate_row['trp_repair_age']) ? $rate_row['trp_repair_age'] : '')); ?></span></dd>
            </div>
            <div class="mn-update-rate-dl__row">
              <dt>ประกัน</dt>
              <dd>
                <input type="hidden" id="trp_insurance" name="trp_insurance" value="<?php echo maintenance_status_logistic_h($insurance); ?>" />
                <?php if ($ins_in) { ?>
                <span class="mn-update-rate-ins--in"><?php echo maintenance_status_logistic_h($insurance); ?></span>
                <?php } else { ?>
                <span class="mn-update-rate-ins--out"><?php echo maintenance_status_logistic_h($insurance); ?></span>
                <?php } ?>
              </dd>
            </div>
            <div class="mn-update-rate-dl__row mn-update-rate-dl__row--block">
              <dt>อาการที่แจ้ง</dt>
              <dd><?php echo maintenance_status_logistic_h((string) (isset($rate_row['trp_repair_inform']) ? $rate_row['trp_repair_inform'] : '')); ?></dd>
            </div>
            <div class="mn-update-rate-dl__row mn-update-rate-dl__row--block">
              <dt class="mn-update-rate-remark">หมายเหตุใบงาน</dt>
              <dd class="mn-update-rate-remark"><?php echo maintenance_status_logistic_h((string) (isset($rate_row['ts_remarks']) ? $rate_row['ts_remarks'] : '')); ?></dd>
            </div>
          </dl>
        </section>

        <section class="mn-update-rate-history" aria-label="ประวัติการซ่อม">
          <h2 class="mn-update-rate-section-title">ประวัติการซ่อม</h2>
          <div class="mn-update-rate-history__body">
            <?php
            echo check_history_repair(
                isset($rate_row['trp_ts_id']) ? (int) $rate_row['trp_ts_id'] : 0,
                isset($rate_row['trp_sn']) ? (string) $rate_row['trp_sn'] : ''
            );
            ?>
          </div>
        </section>

        <?php if ($cl_supported): ?>
        <section class="mn-cl-section" aria-label="ตรวจสอบก่อนประเมิน">
          <h2 class="mn-update-rate-section-title">ตรวจสอบก่อนประเมิน</h2>
          <div class="mn-cl-inner">
            <div class="mn-cl-subsection mn-cl-subsection--basic">
              <h3 class="mn-cl-subsection__title">การตรวจพื้นฐาน</h3>
              <div id="mn_cl_basic_wrap" class="mn-cl-items-wrap"></div>
            </div>
            <div class="mn-cl-subsection mn-cl-subsection--symptom">
              <h3 class="mn-cl-subsection__title">ตรวจตามอาการ</h3>
              <div class="mn-cl-symptom-row">
                <label class="mn-cl-symptom-label" for="mn_cl_symptom">อาการ</label>
                <select id="mn_cl_symptom" class="mn-cl-symptom-select">
                  <option value="">— เลือกอาการ —</option>
                  <?php foreach ($cl_symptoms as $sym): ?>
                  <option value="<?php echo (int) $sym['cs_id']; ?>"
                    <?php echo ($cl_saved_symptom_id === (int) $sym['cs_id']) ? ' selected="selected"' : ''; ?>>
                    <?php echo htmlspecialchars($sym['cs_name'], ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="mn_cl_items_wrap" class="mn-cl-items-wrap"></div>
            </div>
            <div class="mn-cl-actions" id="mn_cl_actions">
              <button type="button" id="mn_cl_save_btn" class="mn-cl-apply-btn">
                <i class="fa fa-check-circle" aria-hidden="true"></i> บันทึกและเติมรายการประเมิน
              </button>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <section class="mn-update-rate-form" aria-label="ฟอร์มสำหรับช่าง">
          <h2 class="mn-update-rate-form__heading">สำหรับช่าง</h2>
          <?php if ($has_prior_rate) { ?>
          <p class="mn-update-rate-prior-hint" role="status">แสดงข้อมูลประเมินที่บันทึกไว้แล้ว — แก้ไขแล้วกดบันทึกเพื่ออัปเดต</p>
          <?php } ?>

          <div class="mn-update-rate-field mn-update-rate-field--block">
            <label class="mn-update-rate-label" for="trp_repair_technician">อาการที่ประเมิน</label>
            <textarea name="trp_repair_technician" id="trp_repair_technician" class="mn-update-rate-textarea" autocomplete="off" rows="3" cols="30"><?php echo maintenance_status_logistic_h((string) (isset($rate_row['trp_repair_technician']) ? $rate_row['trp_repair_technician'] : '')); ?></textarea>
          </div>

          <div class="mn-update-rate-field mn-update-rate-field--inline">
            <label class="mn-update-rate-label" for="trp_rate_date">วันที่ประเมิน</label>
            <div class="mn-update-rate-field__control">
              <input type="date" name="trp_rate_date" id="trp_rate_date" class="mn-update-rate-input mn-update-rate-input--date" value="<?php echo maintenance_status_logistic_h($trp_rate_date); ?>" />
              <span class="mn-update-rate-hint">*ด/ว/ป</span>
            </div>
          </div>

                <?php
                for ($pr = 0; $pr < 5; $pr++) {
                    $s1 = $pr * 2 + 1;
                    $s2 = $s1 + 1;
                    $v1 = maintenance_update_rate_repair_no_selected($rate_row, $s1);
                    $v2 = maintenance_update_rate_repair_no_selected($rate_row, $s2);
                    ?>
          <div class="mn-update-rate-pair">
            <div class="mn-update-rate-field">
              <label class="mn-update-rate-label" for="rate_list<?php echo (int) $s1; ?>">รายการ <?php echo (int) $s1; ?></label>
              <?php echo maintenance_update_rate_render_rate_select($s1, $rate_options, $v1); ?>
            </div>
            <div class="mn-update-rate-field">
              <label class="mn-update-rate-label" for="rate_list<?php echo (int) $s2; ?>">รายการ <?php echo (int) $s2; ?></label>
              <?php echo maintenance_update_rate_render_rate_select($s2, $rate_options, $v2); ?>
            </div>
          </div>
                    <?php
                }
                ?>

          <div class="mn-update-rate-pair mn-update-rate-pair--checks">
            <label class="mn-update-rate-check">
              <input name="trp_clean" id="trp_clean" type="checkbox" value="ทำความสะอาดแผงบอร์ด"<?php echo maintenance_update_rate_checkbox_checked($rate_row, 'trp_clean') ? ' checked="checked"' : ''; ?> />
              <span>ทำความสะอาดแผงบอร์ด</span>
            </label>
            <label class="mn-update-rate-check">
              <input name="trp_repeat_solder" id="trp_repeat_solder" type="checkbox" value="ย้ำจุดบัดกรีแผงบอร์ด"<?php echo maintenance_update_rate_checkbox_checked($rate_row, 'trp_repeat_solder') ? ' checked="checked"' : ''; ?> />
              <span>ย้ำจุดบัดกรีแผงบอร์ด</span>
            </label>
          </div>

          <div class="mn-update-rate-pair">
            <label class="mn-update-rate-check">
              <input name="trp_spray" id="trp_spray" type="checkbox" value=" ฉีดสเปย์ไล่ความชื้น"<?php echo maintenance_update_rate_checkbox_checked($rate_row, 'trp_spray') ? ' checked="checked"' : ''; ?> />
              <span>ฉีดสเปย์ไล่ความชื้น</span>
            </label>
            <div class="mn-update-rate-field">
              <label class="mn-update-rate-label" for="trp_fw">UP FW</label>
              <input name="trp_fw" id="trp_fw" type="text" class="mn-update-rate-input" value="<?php echo maintenance_status_logistic_h((string) (isset($rate_row['trp_fw']) ? $rate_row['trp_fw'] : '')); ?>" />
            </div>
          </div>

          <div class="mn-update-rate-pair">
            <div class="mn-update-rate-field">
              <label class="mn-update-rate-label" for="trp_user_rate">ผู้ประเมิน</label>
              <input type="hidden" name="trp_user_rate" id="trp_user_rate" value="<?php echo htmlspecialchars($trp_user_rate_value, ENT_QUOTES, 'UTF-8'); ?>" />
              <span class="mn-user-receive-display ex2"><?php echo $trp_user_rate_value !== '' ? htmlspecialchars($trp_user_rate_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
            </div>
            <div class="mn-update-rate-field">
              <label class="mn-update-rate-label" for="trp_repair_remarks">หมายเหตุ</label>
              <textarea name="trp_repair_remarks" id="trp_repair_remarks" class="mn-update-rate-textarea" autocomplete="off" rows="3" cols="30"><?php echo maintenance_status_logistic_h((string) (isset($rate_row['trp_repair_remarks']) ? $rate_row['trp_repair_remarks'] : '')); ?></textarea>
            </div>
          </div>

          <footer class="mn-update-rate-toolbar">
            <a href="status_rate" class="mn-update-rate-toolbar__btn" title="กลับ"><i class="fa fa-reply fa-2x" aria-hidden="true"></i><span class="mn-update-rate-toolbar__txt">กลับ</span></a>
            <a href="javascript:mnUpdateRateSaveStatus(<?php echo (int) $trp_id; ?>,0);" class="mn-update-rate-toolbar__btn mn-update-rate-toolbar__btn--primary" title="บันทึก"><i class="fa fa-save fa-2x" aria-hidden="true"></i><span class="mn-update-rate-toolbar__txt">บันทึก</span></a>
          </footer>
        </section>
      </article>
    </div>
  </div>
</body>
</html>
