<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าตรวจเช็คและประเมินเครื่องซ่อมแบบทีละขั้น (มือถือ/แท็บเล็ต) ตาม `trp_id` (GET)
 * ขั้น: ข้อมูลเครื่อง → ตรวจพื้นฐาน → ตรวจตามอาการ → สรุปและอะไหล่ แล้วบันทึก checklist (AJAX) + ส่งต่อ `save_rate`
 *
 * Flow:
 * require_auth → bootstrap → ดึง bundle + rate options + checklist meta → ปิด `$connect` → render หรือ modal redirect
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
require_once __DIR__ . '/includes/assess_side_panels.php';
require_once __DIR__ . '/includes/checklist_photo_helpers.php';

$trp_id       = maintenance_flow_get_int($_GET, 'trp_id', 0);
$ar_row          = null;
$rate_options = array();
$locked       = false;
$locked_ts_id = 0;
$supported    = false;
$symptoms     = array();
$bundles      = array();
$reasons      = array();
$history      = array();
$photos       = array();
$saved_symptom_ids = array();
$checklist_saved   = false;

if ($trp_id > 0) {
    $ar_row = maintenance_update_rate_fetch_bundle($connect, $trp_id);
    if (is_array($ar_row)) {
        $locked_ts_id = (int) (isset($ar_row['trp_ts_id']) ? $ar_row['trp_ts_id'] : 0);
        $history      = mn_ar_fetch_history($connect, isset($ar_row['trp_sn']) ? $ar_row['trp_sn'] : '', $locked_ts_id);
        $photos       = mn_photo_list($connect, $trp_id);
        // รูปของงานซ่อมครั้งก่อน (แสดงในแผงประวัติ)
        $hist_photos  = mn_photo_by_trps($connect, array_map(function ($h) { return isset($h['trp_id']) ? $h['trp_id'] : 0; }, $history));
        foreach ($history as &$h_row) {
            $h_row['photos'] = !empty($h_row['trp_id']) && isset($hist_photos[$h_row['trp_id']]) ? $hist_photos[$h_row['trp_id']] : array();
        }
        unset($h_row);
        $locked       = maintenance_detail_job_repair_rate_edit_locked($connect, $trp_id);
        $product      = (string) (isset($ar_row['trp_product']) ? $ar_row['trp_product'] : '');
        maintenance_checklist_ensure_tables($connect);
        $supported    = maintenance_checklist_product_supported($connect, $product);
        if (!$locked && $supported) {
            $rate_options = maintenance_update_rate_fetch_rate_options(
                $connect,
                maintenance_update_rate_resolve_rate_product($product)
            );
            $symptoms = maintenance_checklist_fetch_symptoms($connect, $product);
            $bundles  = maintenance_checklist_fetch_bundles($connect, $product);
            $reasons  = maintenance_checklist_fetch_rate_reasons($connect, $product);
            foreach (maintenance_checklist_fetch_all_job_headers($connect, $trp_id) as $sid => $h) {
                $checklist_saved = true;
                if ($sid > 0) { $saved_symptom_ids[] = (int) $sid; }
            }
        }
    }
}

maintenance_db_odbc_close($connect);

$missing = ($trp_id < 1 || !is_array($ar_row));

function mn_ar_h($v)
{
    return maintenance_status_logistic_h((string) $v);
}

function mn_ar_f($row, $key)
{
    return is_array($row) && isset($row[$key]) ? (string) $row[$key] : '';
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-assess-repair.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-assess-repair.css'); ?>" />
<link rel="stylesheet" href="css/mn-photo-lightbox.css?v=<?php echo filemtime(__DIR__ . '/css/mn-photo-lightbox.css'); ?>" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>ตรวจเช็คและประเมินเครื่อง</title>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
</head>
<body class="mn-addjob-page mn-assess-repair-page">
<?php
$redirect_msg = '';
$redirect_to  = 'status_rate';
if ($missing) {
    $redirect_msg = 'ไม่พบข้อมูลหรือไม่ได้ระบุ trp_id';
} elseif ($locked) {
    $redirect_msg = 'ไม่สามารถแก้ไขงานประเมินซ่อมได้ เนื่องจากมีการเสนอราคาซ่อมแล้ว';
    $redirect_to  = $locked_ts_id > 0 ? 'detail_job?ts_id=' . $locked_ts_id : 'status_rate';
} elseif (!$supported) {
    $redirect_msg = 'สินค้ารุ่นนี้ยังไม่มีรายการตรวจเช็ค — ใช้หน้ารายการประเมินแบบเดิม';
    $redirect_to  = 'update_rate?trp_id=' . $trp_id;
}
if ($redirect_msg !== '') {
    ?>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($redirect_msg, JSON_UNESCAPED_UNICODE); ?>, {
  variant: 'warning',
  onClose: function () { window.location.href = <?php echo json_encode($redirect_to, JSON_UNESCAPED_UNICODE); ?>; }
});
</script>
</body>
</html>
<?php
    exit;
}

$insurance = mn_ar_f($ar_row, 'trp_insurance');
$ins_in    = ($insurance === 'อยู่ในประกัน' || $insurance === 'อยู่ในประกันซ่อม');
$company   = trim(mn_ar_f($ar_row, 'ts_enduser_com') . "\n" . mn_ar_f($ar_row, 'ts_security_com'));
$rate_date = maintenance_update_rate_rate_date_for_input(mn_ar_f($ar_row, 'trp_rate_date'));
if ($rate_date === '') {
    $rate_date = date('Y-m-d');
}
$user_rate = trim(mn_ar_f($ar_row, 'trp_user_rate'));
if ($user_rate === '') {
    $user_rate = (string) $mn_logistic_recorder_value;
}
$saved_parts = array();
for ($i = 1; $i <= 10; $i++) {
    $v = maintenance_update_rate_repair_no_selected($ar_row, $i);
    if ($v !== '') { $saved_parts[] = $v; }
}
$sym_out = array();
foreach ($symptoms as $s) {
    $sym_out[] = array('id' => (int) $s['cs_id'], 'name' => (string) $s['cs_name']);
}
$boot = array(
    'trpId'          => $trp_id,
    'product'        => mn_ar_f($ar_row, 'trp_product'),
    'symptoms'       => $sym_out,
    'savedSymptomIds' => $saved_symptom_ids,
    'checklistSaved' => $checklist_saved,
    'rateOptions'    => array_values($rate_options),
    'bundles'        => $bundles,
    'rateReasons'    => (object) $reasons,
    'photos'         => $photos,
    'saved'          => array(
        'tech'    => mn_ar_f($ar_row, 'trp_repair_technician'),
        'parts'   => $saved_parts,
        'clean'   => maintenance_update_rate_checkbox_checked($ar_row, 'trp_clean'),
        'solder'  => maintenance_update_rate_checkbox_checked($ar_row, 'trp_repeat_solder'),
        'spray'   => maintenance_update_rate_checkbox_checked($ar_row, 'trp_spray'),
        'fw'      => mn_ar_f($ar_row, 'trp_fw'),
        'remarks' => mn_ar_f($ar_row, 'trp_repair_remarks'),
    ),
    'userRate'       => $user_rate,
    'insurance'      => $insurance,
    'statusJob'      => mn_ar_f($ar_row, 'trp_status_job'),
);
$sn = mn_ar_f($ar_row, 'trp_sn');
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
    <?php
    echo mn_ar_info_panel(array(
        'product'   => $boot['product'],
        'sn'        => $sn,
        'receive'   => mn_ar_f($ar_row, 'trp_receive_date'),
        'company'   => $company,
        'buy'       => mn_ar_f($ar_row, 'trp_buy_date'),
        'age'       => mn_ar_f($ar_row, 'trp_repair_age'),
        'insurance' => $insurance,
        'ins_in'    => $ins_in,
        'inform'    => mn_ar_f($ar_row, 'trp_repair_inform'),
        'remarks'   => mn_ar_f($ar_row, 'ts_remarks'),
    ));
    echo mn_ar_history_panel($history);
    ?>
    <main class="mn-ar" id="mn_ar">

      <header class="mn-ar-top">
        <div class="mn-ar-top__row">
          <a class="mn-ar-top__back" href="update_rate?trp_id=<?php echo (int) $trp_id; ?>" aria-label="กลับหน้ารายการประเมิน"><i class="fa fa-angle-left" aria-hidden="true"></i></a>
          <div class="mn-ar-top__id">
            <span class="mn-ar-top__product"><?php echo mn_ar_h($boot['product']); ?></span>
            <span class="mn-ar-top__sn">SN <?php echo mn_ar_h($sn !== '' ? $sn : '—'); ?></span>
          </div>
          <span class="mn-ar-top__count" id="mn_ar_stepcount">1/4</span>
          <a class="mn-ar-top__back" href="checklist_settings?product=<?php echo rawurlencode($boot['product']); ?>" aria-label="ตั้งค่ารายการตรวจเช็คของรุ่นนี้" title="ตั้งค่ารายการตรวจเช็ค"><i class="fa fa-sliders" aria-hidden="true" style="font-size:16px"></i></a>
        </div>
        <ol class="mn-ar-steps" id="mn_ar_steps" aria-label="ขั้นตอน">
          <li class="mn-ar-steps__seg is-active" data-step="1"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">ข้อมูลเครื่อง</span></li>
          <li class="mn-ar-steps__seg" data-step="2"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">ตรวจพื้นฐาน</span></li>
          <li class="mn-ar-steps__seg" data-step="3"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">ตามอาการ</span></li>
          <li class="mn-ar-steps__seg" data-step="4"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">สรุปผล</span></li>
        </ol>
      </header>

      <div class="mn-ar-stage">

        <section class="mn-ar-step is-active" data-step="1" aria-label="ข้อมูลเครื่อง">
          <h1 class="mn-ar-h">ข้อมูลเครื่อง</h1>
          <div class="mn-ar-card">
            <dl class="mn-ar-dl">
              <div><dt>วันที่รับซ่อม</dt><dd><?php echo mn_ar_h(mn_ar_f($ar_row, 'trp_receive_date')); ?></dd></div>
              <div><dt>บริษัท</dt><dd><?php echo nl2br(mn_ar_h($company)); ?></dd></div>
              <div><dt>วันที่ซื้อ</dt><dd><?php echo mn_ar_h(mn_ar_f($ar_row, 'trp_buy_date')); ?> <span class="mn-ar-muted"><?php echo mn_ar_h(mn_ar_f($ar_row, 'trp_repair_age')); ?></span></dd></div>
              <div><dt>ประกัน</dt><dd><span class="mn-ar-tag <?php echo $ins_in ? 'mn-ar-tag--ok' : 'mn-ar-tag--off'; ?>"><?php echo mn_ar_h($insurance !== '' ? $insurance : '—'); ?></span></dd></div>
            </dl>
          </div>
          <div class="mn-ar-note">
            <span class="mn-ar-note__lbl">อาการที่แจ้ง</span>
            <p class="mn-ar-note__txt"><?php echo nl2br(mn_ar_h(mn_ar_f($ar_row, 'trp_repair_inform') !== '' ? mn_ar_f($ar_row, 'trp_repair_inform') : '—')); ?></p>
          </div>
          <?php if (trim(mn_ar_f($ar_row, 'ts_remarks')) !== '') { ?>
          <div class="mn-ar-note mn-ar-note--warn">
            <span class="mn-ar-note__lbl">หมายเหตุใบงาน</span>
            <p class="mn-ar-note__txt"><?php echo nl2br(mn_ar_h(mn_ar_f($ar_row, 'ts_remarks'))); ?></p>
          </div>
          <?php } ?>
          <details class="mn-ar-history">
            <summary>ประวัติการซ่อม (<?php echo count($history); ?>) <i class="fa fa-angle-down" aria-hidden="true"></i></summary>
            <div class="mn-ar-history__body">
              <?php echo mn_ar_history_list($history); ?>
            </div>
          </details>
        </section>

        <section class="mn-ar-step" data-step="2" aria-label="ตรวจพื้นฐาน">
          <div class="mn-ar-h-row">
            <h1 class="mn-ar-h">ตรวจพื้นฐาน</h1>
            <span class="mn-ar-progress" id="mn_ar_basic_progress"></span>
          </div>
          <ul class="mn-ar-items" id="mn_ar_basic"><li class="mn-ar-skeleton"></li><li class="mn-ar-skeleton"></li><li class="mn-ar-skeleton"></li></ul>
        </section>

        <section class="mn-ar-step" data-step="3" aria-label="ตรวจตามอาการ">
          <div class="mn-ar-h-row">
            <h1 class="mn-ar-h">ตรวจตามอาการ</h1>
            <span class="mn-ar-progress" id="mn_ar_sym_progress"></span>
          </div>
          <p class="mn-ar-sub">เลือกอาการที่พบ <b>เลือกได้หลายอาการ</b> หรือกด “ไม่มีอาการเฉพาะ” เพื่อข้าม <span class="mn-ar-sub__count" id="mn_ar_sym_count"></span></p>
          <div class="mn-ar-chips" id="mn_ar_symptoms" role="group" aria-label="อาการ (เลือกได้หลายอาการ)"></div>
          <ul class="mn-ar-items" id="mn_ar_sym_items"></ul>
        </section>

        <section class="mn-ar-step" data-step="4" aria-label="สรุปผลและอะไหล่">
          <h1 class="mn-ar-h">สรุปผล</h1>

          <div class="mn-ar-card mn-ar-findings" id="mn_ar_findings"></div>

          <div class="mn-ar-field">
            <div class="mn-ar-field__head">
              <span class="mn-ar-label">สรุปการประเมิน</span>
              <span class="mn-ar-field__hint">ส่งให้ฝ่ายเสนอราคา | ระบบเขียนจากผลตรวจและรายการซ่อม</span>
            </div>
            <div class="mn-ar-report" id="mn_ar_report" aria-live="polite"></div>
          </div>

          <div class="mn-ar-field">
            <div class="mn-ar-field__head">
              <label class="mn-ar-label" for="mn_ar_tech">ความเห็นช่าง / เหตุผลการเสนอเปลี่ยนอะไหล่</label>
              <button type="button" class="mn-ar-link" id="mn_ar_regen_comment"><i class="fa fa-refresh" aria-hidden="true"></i> ร่างใหม่จากผลตรวจ</button>
              <span class="mn-ar-field__hint">ระบบร่างให้จากสิ่งที่ตรวจพบและรายการซ่อม แก้ไขหรือเติมได้ | บันทึกต่อท้ายสรุปการประเมิน</span>
            </div>
            <textarea id="mn_ar_tech" class="mn-ar-input mn-ar-textarea mn-ar-textarea--comment" rows="6" placeholder="อธิบายเหตุผลการเสนอเปลี่ยนอะไหล่ สาเหตุที่น่าจะเป็น หรือสิ่งที่ควรแจ้งลูกค้า"></textarea>
          </div>

          <div class="mn-ar-field">
            <div class="mn-ar-field__head">
              <span class="mn-ar-label">รายการซ่อม / อะไหล่</span>
              <span class="mn-ar-muted" id="mn_ar_parts_count">0/10</span>
            </div>
            <ol class="mn-ar-parts" id="mn_ar_parts"></ol>
            <div class="mn-ar-search">
              <i class="fa fa-search" aria-hidden="true"></i>
              <input type="search" id="mn_ar_part_search" class="mn-ar-input" placeholder="ค้นหารายการซ่อม" autocomplete="off" />
            </div>
            <ul class="mn-ar-search__list" id="mn_ar_part_results" role="listbox"></ul>
            <div class="mn-ar-suggest" id="mn_ar_suggest_wrap">
              <span class="mn-ar-suggest__lbl">แนะนำ</span>
              <div class="mn-ar-chips mn-ar-chips--sm" id="mn_ar_suggest"></div>
            </div>
          </div>

          <div class="mn-ar-field">
            <span class="mn-ar-label">งานเพิ่มเติม</span>
            <div class="mn-ar-toggles">
              <label class="mn-ar-toggle"><input type="checkbox" id="trp_clean" value="ทำความสะอาดแผงบอร์ด" /><span>ทำความสะอาดแผงบอร์ด</span></label>
              <label class="mn-ar-toggle"><input type="checkbox" id="trp_repeat_solder" value="ย้ำจุดบัดกรีแผงบอร์ด" /><span>ย้ำจุดบัดกรีแผงบอร์ด</span></label>
              <label class="mn-ar-toggle"><input type="checkbox" id="trp_spray" value=" ฉีดสเปย์ไล่ความชื้น" /><span>ฉีดสเปรย์ไล่ความชื้น</span></label>
            </div>
          </div>

          <div class="mn-ar-field">
            <div class="mn-ar-field__head">
              <span class="mn-ar-label">รูปประกอบเพิ่มเติม</span>
              <span class="mn-ar-field__hint">รูปที่ไม่เจาะจงข้อตรวจ เช่น สภาพเครื่องโดยรวม | แนบรายข้อได้ที่ปุ่มกล้องใต้แต่ละข้อ</span>
            </div>
            <div class="mn-ar-photos mn-ar-photos--general" data-ci="0"></div>
          </div>

          <details class="mn-ar-more">
            <summary><span>เพิ่มเติม</span> <small>UP FW | วันที่ประเมิน | หมายเหตุ</small> <i class="fa fa-angle-down" aria-hidden="true"></i></summary>
            <div class="mn-ar-more__body">
            <div class="mn-ar-grid2">
              <div class="mn-ar-field">
                <label class="mn-ar-label" for="trp_fw">UP FW</label>
                <input type="text" id="trp_fw" class="mn-ar-input" />
              </div>
              <div class="mn-ar-field">
                <label class="mn-ar-label" for="trp_rate_date">วันที่ประเมิน</label>
                <input type="date" id="trp_rate_date" class="mn-ar-input" value="<?php echo mn_ar_h($rate_date); ?>" />
              </div>
            </div>

            <div class="mn-ar-field">
              <label class="mn-ar-label" for="trp_repair_remarks">หมายเหตุ</label>
              <textarea id="trp_repair_remarks" class="mn-ar-input mn-ar-textarea" rows="2"></textarea>
            </div>
            </div>
          </details>

          <p class="mn-ar-assessor">ผู้ประเมิน <strong><?php echo mn_ar_h($user_rate !== '' ? $user_rate : '—'); ?></strong></p>
        </section>

      </div>

      <footer class="mn-ar-foot">
        <button type="button" class="mn-ar-btn mn-ar-btn--ghost" id="mn_ar_prev"><i class="fa fa-angle-left" aria-hidden="true"></i> ย้อน</button>
        <button type="button" class="mn-ar-btn mn-ar-btn--primary" id="mn_ar_next"><span class="mn-ar-btn__txt">เริ่มตรวจ</span> <i class="fa fa-angle-right" aria-hidden="true"></i></button>
      </footer>

    </main>
  </div>
<script type="text/javascript">
window.mnAr = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script type="text/javascript" src="js/mn-photo-lightbox.js?v=<?php echo filemtime(__DIR__ . '/js/mn-photo-lightbox.js'); ?>"></script>
<script type="text/javascript" src="js/assess_repair-page.js?v=<?php echo filemtime(__DIR__ . '/js/assess_repair-page.js'); ?>"></script>
</body>
</html>
