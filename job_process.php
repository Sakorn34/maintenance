<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าแสดง timeline ความคืบหน้างานซ่อมแนวตั้ง (อ่านอย่างเดียว) ตาม `trp_id` — ข้อมูลหน่วยงาน/รปภ./ผู้แจ้ง และขั้นตอนตามกฎธุรกิจที่กำหนด
 * รองรับมือถือ (layout เดียวคอลัมน์, ตัวอ่านใหญ่พอสมควร) และงานที่ไม่มีแถว `transac_spare`
 *
 * Flow:
 * GET `trp_id` → prepared SELECT bundle → backfill transac_activate_spare (งานเก่าที่ยังไม่มีประวัติ) → ประกอบ timeline → ปิด mysqli → render HTML + CSS mn-*
 *
 * Example:
 * `/maintenance/job_process?trp_id=123` (ไม่มีนามสกุล .php)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_job_process_page.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/job_process_timeline_helpers.php';
require_once __DIR__ . '/includes/job_process_customer_share_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$bundle = maintenance_job_process_fetch_bundle($connect, $trp_id);
if ($trp_id > 0 && isset($bundle['row']) && is_array($bundle['row'])) {
    require_once __DIR__ . '/includes/transac_activate_spare_helpers.php';
    maintenance_transac_activate_spare_ensure_on_job_process_view($connect, $trp_id, $bundle);
}
$todayYmd = date('Y-m-d');
$timeline = maintenance_job_process_build_timeline($bundle, $todayYmd, $connect);
require_once __DIR__ . '/includes/logistics_helpers.php';
$jp_logistics_tracking_map = maintenance_logistics_fetch_tracking_url_map($connect);
maintenance_db_odbc_close($connect);

$meta = isset($timeline['meta']) && is_array($timeline['meta']) ? $timeline['meta'] : array();
$steps = isset($timeline['steps']) && is_array($timeline['steps']) ? $timeline['steps'] : array();
$jpActivateSpareUi = array(
    'can_add' => false,
    'count' => 0,
    'max' => 0,
    'tsp_id' => 0,
    'trp_id' => $trp_id,
    'flow' => 'inapprove',
);
foreach ($steps as $jpStepRow) {
    if (!is_array($jpStepRow)) {
        continue;
    }
    $jpStepKey = isset($jpStepRow['key']) ? (string) $jpStepRow['key'] : '';
    if ($jpStepKey !== '2' && $jpStepKey !== '4' && $jpStepKey !== '5' && $jpStepKey !== '6' && $jpStepKey !== '8') {
        continue;
    }
    if (!isset($jpStepRow['spare_activate_ui']) || !is_array($jpStepRow['spare_activate_ui'])) {
        continue;
    }
    if (!empty($jpStepRow['spare_activate_ui']['can_add'])) {
        $jpActivateSpareUi = array_merge($jpActivateSpareUi, $jpStepRow['spare_activate_ui']);
        break;
    }
}
$hasRow = isset($bundle['row']) && is_array($bundle['row']);
$pageTitle = $hasRow && isset($meta['ts_mtn_number']) && (string) $meta['ts_mtn_number'] !== ''
    ? 'ความคืบหน้างาน ' . (string) $meta['ts_mtn_number']
    : 'ความคืบหน้างานซ่อม';

$jpCustomerTrackingAbs = $hasRow && $trp_id > 0
    ? maintenance_job_process_customer_tracking_absolute_url($trp_id)
    : '';

$jpSharePlain = $hasRow
    ? maintenance_job_process_customer_share_plaintext($meta, $jpCustomerTrackingAbs)
    : '';

$jpLineShareHref = $hasRow && $jpSharePlain !== ''
    ? 'https://line.me/R/msg/text/?' . rawurlencode($jpSharePlain)
    : '';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-job-process.css" />
<title><?php echo maintenance_status_logistic_h($pageTitle); ?></title>
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<?php
$mnLogisticsTrackingMap = $jp_logistics_tracking_map;
$mnLogisticsTrackingAssetPrefix = '';
require_once __DIR__ . '/includes/mn_logistics_tracking_assets.php';
?>
<script type="text/javascript" src="js/job_process-page.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
</head>
<body class="mn-addjob-page mn-job-process-page">
  <div class="header">
    <?php
    $page = '';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-jp-wrap">
      <?php if (!$hasRow) { ?>
        <section class="mn-jp-card mn-jp-card--alert" aria-live="polite">
          <h1 class="mn-jp-title">ไม่พบงานซ่อม</h1>
          <p><a class="mn-jp-back" href="index"><i class="fa fa-arrow-left" aria-hidden="true"></i> กลับหน้าแรก</a></p>
        </section>
      <?php } else { ?>
        <section class="mn-jp-card" aria-label="ข้อมูลผู้แจ้งและหน่วยงาน">
          <div class="mn-jp-card-head">
            <img class="mn-jp-logo" src="_img/BIT-logo-252x50.png" width="126" height="25" alt="BIT COMBINE CO., LTD" />
          </div>
          <?php
          $prod = trim((string) (isset($meta['trp_product']) ? $meta['trp_product'] : ''));
          $sn = trim((string) (isset($meta['trp_sn']) ? $meta['trp_sn'] : ''));
          ?>
          <h2 class="mn-jp-card-title mn-jp-card-title--job">
            <span class="mn-jp-card-title__label">ข้อมูลงานซ่อม</span><?php
            if ($prod !== '' || $sn !== '') {
                ?><span class="mn-jp-card-title__detail"><span class="mn-jp-card-title__sep"> -- </span><?php
                echo maintenance_status_logistic_h($prod !== '' ? $prod : '—');
                if ($sn !== '') {
                    ?><span class="mn-jp-sn"> [ <?php echo maintenance_status_logistic_h($sn); ?> ]</span><?php
                }
                ?></span><?php
            }
            ?>
          </h2>
          <dl class="mn-jp-dl">
            <?php
            $jpSecurityCom = trim((string) (isset($meta['ts_security_com']) ? $meta['ts_security_com'] : ''));
            ?>
            <div class="mn-jp-dl__row">
              <dt>หน่วยงาน</dt>
              <dd>
                <?php echo maintenance_status_logistic_h((string) (isset($meta['ts_enduser_com']) ? $meta['ts_enduser_com'] : '')); ?>
                <?php if ($jpSecurityCom !== '') { ?>
                <span class="mn-jp-provider">
                  <?php echo maintenance_status_logistic_h($jpSecurityCom); ?>
                </span>
                <?php } ?>
              </dd>
            </div>
            <div class="mn-jp-dl__row">
              <dt>ผู้แจ้ง</dt>
              <dd><?php
                $rn = trim((string) (isset($meta['ts_report_name']) ? $meta['ts_report_name'] : ''));
                echo maintenance_status_logistic_h($rn !== '' ? $rn : '—');
                ?></dd>
            </div>
            <div class="mn-jp-dl__row">
              <dt>สถานะงานปัจจุบัน</dt>
              <dd><span class="mn-jp-status-pill"><?php echo maintenance_status_logistic_h((string) (isset($meta['trp_status_job']) ? $meta['trp_status_job'] : '')); ?></span></dd>
            </div>
            <?php
            $jpDuration = maintenance_job_process_row_duration_text($bundle['row']);
            ?>
            <div class="mn-jp-dl__row">
              <dt>ระยะเวลาดำเนินการ</dt>
              <dd>
                <?php if ($jpDuration !== '') { ?>
                <span class="mn-jp-duration-pill"><?php echo maintenance_status_logistic_h($jpDuration); ?></span>
                <?php } else { ?>
                <span class="mn-jp-duration-pill mn-jp-duration-pill--empty">—</span>
                <?php } ?>
              </dd>
            </div>

          </dl>

          <?php if ($jpSharePlain !== '') { ?>
          <div class="mn-jp-share" aria-label="ส่งความคืบหน้าให้ลูกค้า">
            <p class="mn-jp-share__hint" role="note">
              <i class="fa fa-comments-o" aria-hidden="true"></i>
              <span>คัดลอกข้อความพร้อมลิงก์ให้ลูกค้ากดเข้ามาดูความคืบหน้าได้เลย</span>
            </p>
            <div class="mn-jp-share__actions">
              <button type="button" class="mn-jp-share__btn mn-jp-share__btn--copy" id="mn-jp-share-copy" title="คัดลอกไป Clipboard">
                <i class="fa fa-clipboard" aria-hidden="true"></i>
                คัดลอกข้อความ + ลิงก์
              </button>
            </div>
            <label for="mn-jp-share-plain" class="mn-jp-share__lbl">ตัวอย่างข้อความที่ลูกค้าได้รับ</label>
            <textarea id="mn-jp-share-plain" class="mn-jp-share__scratch" readonly rows="11" cols="72"><?php echo htmlspecialchars($jpSharePlain, ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>
          <?php } ?>
        </section>

        <section class="mn-jp-timeline" aria-label="สถานะงานซ่อม">
          <h2 class="mn-jp-card-title">สถานะงานซ่อม</h2>
          <ol class="mn-jp-steps">
            <?php foreach ($steps as $st) {
                $stState = isset($st['state']) ? (string) $st['state'] : 'upcoming';
                ?>
            <li class="mn-jp-step mn-jp-step--<?php echo maintenance_status_logistic_h($stState); ?>">
              <div class="mn-jp-step__marker" aria-hidden="true"></div>
              <div class="mn-jp-step__body">
                <div class="mn-jp-step__head">
                  <h3 class="mn-jp-step__title"><?php maintenance_job_process_echo_step_title($st); ?></h3>
                  <?php maintenance_job_process_echo_step_spare_activate_add($st); ?>
                </div>
                <?php if (!empty($st['lines']) && is_array($st['lines'])) { ?>
                <ul class="mn-jp-lines mn-jp-lines--step">
                  <?php foreach ($st['lines'] as $ln) { ?>
                  <li><?php maintenance_job_process_render_timeline_line($ln); ?></li>
                  <?php } ?>
                </ul>
                <?php } ?>
              </div>
            </li>
            <?php } ?>
          </ol>
        </section>

        <section class="mn-jp-card mn-jp-contact" aria-label="ช่องทางการติดต่อ">
          <h2 class="mn-jp-card-title mn-jp-card-title--compact">ช่องทางการติดต่อ</h2>
          <div class="mn-jp-contact-channels">
            <a class="mn-jp-contact-channel" href="tel:+66863771050" title="โทร 086-377-1050">
              <i class="fa fa-phone" aria-hidden="true"></i>
              <span>086-377-1050</span>
            </a>
            <a class="mn-jp-contact-channel mn-jp-contact-channel--line" href="https://lin.ee/3pBA3baz" target="_blank" rel="noopener noreferrer" title="LINE @bitcombine">
              <svg class="mn-jp-icon-line" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .631.283.631.63 0 .344-.282.63-.63.63h-2.386c-.345 0-.627-.286-.627-.63V8.108c0-.345.282-.63.63-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
              </svg>
              <span>@bitcombine</span>
            </a>
          </div>
          <p class="mn-jp-contact-hours">เวลาทำการ 08:30–17:30 จ.–ส. ยกเว้นวันหยุดบริษัท หรือวันหยุดนักขัตฤกษ์</p>
        </section>
      <?php } ?>
    </div>
  </div>
<?php if ($hasRow && !empty($jpActivateSpareUi['can_add'])) { ?>
  <div id="mn-jp-activate-overlay" class="mn-modal-overlay mn-jp-activate-overlay" role="dialog" aria-modal="true" aria-labelledby="mn-jp-activate-title" hidden>
    <div class="mn-modal-dialog mn-modal mn-modal--info mn-jp-activate-dialog">
      <div class="mn-modal-header">
        <span class="mn-modal-header__icon fa fa-key" aria-hidden="true"></span>
        <span id="mn-jp-activate-title">เพิ่มรหัสต่ออายุเครื่องสำรอง</span>
      </div>
      <form id="mn-jp-activate-form" class="mn-modal-body mn-jp-activate-form" method="post" action="save_job_process_activate_spare" autocomplete="off">
        <input type="hidden" name="trp_id" id="mn-jp-activate-trp-id" value="<?php echo (int) $jpActivateSpareUi['trp_id']; ?>" />
        <input type="hidden" name="tsp_id" id="mn-jp-activate-tsp-id" value="<?php echo (int) $jpActivateSpareUi['tsp_id']; ?>" />
        <input type="hidden" name="activate_flow" id="mn-jp-activate-flow" value="<?php
        $jpActivateFlow = isset($jpActivateSpareUi['flow']) ? trim((string) $jpActivateSpareUi['flow']) : 'inapprove';
        echo maintenance_status_logistic_h($jpActivateFlow !== '' ? $jpActivateFlow : 'inapprove');
        ?>" />
        <?php
        $jpActivateHistory = isset($jpActivateSpareUi['history']) && is_array($jpActivateSpareUi['history'])
            ? $jpActivateSpareUi['history']
            : array();
        if ($jpActivateHistory !== array()) {
            ?>
        <section class="mn-jp-activate-history" aria-label="ประวัติการต่ออายุก่อนหน้า">
          <h4 class="mn-jp-activate-history__title">ประวัติการต่ออายุก่อนหน้า</h4>
          <ul class="mn-jp-activate-history__list">
            <?php foreach ($jpActivateHistory as $jpHist) {
                if (!is_array($jpHist)) {
                    continue;
                }
                $jpHistActivate = isset($jpHist['activate_display']) ? (string) $jpHist['activate_display'] : '';
                $jpHistExpire = isset($jpHist['expire_display']) ? (string) $jpHist['expire_display'] : '';
                $jpHistCode = isset($jpHist['code']) ? (string) $jpHist['code'] : '';
                $jpHistStatus = isset($jpHist['status_job']) ? trim((string) $jpHist['status_job']) : '';
                ?>
            <li class="mn-jp-activate-history__item">
              <span class="mn-jp-activate-history__line"><?php
                if ($jpHistStatus !== '') {
                    echo '<span class="mn-jp-activate-history__step">';
                    echo maintenance_status_logistic_h($jpHistStatus);
                    echo '</span>';
                }
                $jpHistSeq = isset($jpHist['sequence_no']) ? (int) $jpHist['sequence_no'] : 0;
                $jpHistExpired = !empty($jpHist['is_expired']);
                if ($jpHistExpired && $jpHistSeq > 0) {
                    echo 'ครั้งที่ ' . (int) $jpHistSeq . ' ต่ออายุสำรองใช้ได้ถึง ';
                    echo maintenance_status_logistic_h($jpHistExpire !== '' ? $jpHistExpire : '—');
                } else {
                    echo '<span class="mn-jp-spare-activate-code">รหัสต่ออายุเครื่องสำรอง ';
                    echo maintenance_status_logistic_h($jpHistCode !== '' ? $jpHistCode : '—');
                    echo '</span> (ใช้ได้ถึง ';
                    echo maintenance_status_logistic_h($jpHistExpire !== '' ? $jpHistExpire : '—');
                    if ($jpHistActivate !== '') {
                        echo ' · ต่ออายุเมื่อ ';
                        echo maintenance_status_logistic_h($jpHistActivate);
                    }
                    echo ')';
                }
                ?></span>
            </li>
            <?php } ?>
          </ul>
        </section>
        <?php } ?>
        <label class="mn-jp-activate-field mn-jp-activate-field--expire" for="mn-jp-activate-expire" id="mn-jp-activate-expire-wrap">
          <span class="mn-jp-activate-field__lbl">วันที่หมดอายุเครื่องสำรอง</span>
          <input type="date" name="tas_expire_date" id="mn-jp-activate-expire"
            value="<?php
            $jpExpireValue = isset($jpActivateSpareUi['suggested_expire_ymd'])
                ? trim((string) $jpActivateSpareUi['suggested_expire_ymd'])
                : '';
            echo $jpExpireValue !== '' ? maintenance_status_logistic_h($jpExpireValue) : '';
            ?>" />
          <span class="mn-jp-activate-hint" id="mn-jp-activate-expire-hint"></span>
        </label>
        <label class="mn-jp-activate-field" for="mn-jp-activate-name">
          <span class="mn-jp-activate-field__lbl">ชื่อผู้ร้องขอ</span>
          <input type="text" name="tas_request_name" id="mn-jp-activate-name" maxlength="200" required />
        </label>
        <label class="mn-jp-activate-field" for="mn-jp-activate-contact">
          <span class="mn-jp-activate-field__lbl">เบอร์โทร / LINE</span>
          <input type="text" name="tas_request_contact" id="mn-jp-activate-contact" maxlength="200" required />
        </label>
        <label class="mn-jp-activate-field" for="mn-jp-activate-reason">
          <span class="mn-jp-activate-field__lbl">เหตุผลที่ขอต่ออายุ</span>
          <textarea name="tas_request_reason" id="mn-jp-activate-reason" rows="3" maxlength="500" required></textarea>
        </label>
        <div class="mn-modal-footer mn-jp-activate-footer">
          <button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-jp-activate-cancel">ยกเลิก</button>
          <button type="submit" class="mn-modal-btn mn-modal-btn--primary" id="mn-jp-activate-submit">บันทึก</button>
        </div>
      </form>
    </div>
  </div>
  <?php
    $jpActivateJsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_HEX_AMP')) {
        $jpActivateJsonFlags |= JSON_HEX_AMP;
    }
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $jpActivateJsonFlags |= JSON_UNESCAPED_UNICODE;
    }
    ?>
  <script type="application/json" id="mn-jp-activate-config"><?php echo json_encode($jpActivateSpareUi, $jpActivateJsonFlags); ?></script>
<?php } ?>
</body>
</html>
