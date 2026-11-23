<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ทดลองใช้หน้าตรวจเช็คและประเมินเครื่องแบบที่ช่างเห็นจริง โดยไม่ต้องมีใบงานและไม่บันทึกข้อมูลใด ๆ
 * ใช้ markup + js/assess_repair-page.js ชุดเดียวกับ assess_repair (โหมด demo)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/update_rate_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/checklist_helpers.php';

maintenance_checklist_ensure_tables($connect);

$try_products = array();
$res = mysqli_query($connect, 'SELECT pn_name FROM product_name ORDER BY pn_name');
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $name = (string) $r['pn_name'];
    if ($name !== '' && maintenance_checklist_product_supported($connect, $name)) { $try_products[] = $name; }
}

$product = isset($_GET['product']) ? trim((string) $_GET['product']) : '';
if (!in_array($product, $try_products, true)) {
    $product = $try_products ? $try_products[0] : '';
}

$symptoms = array();
$rate_options = array();
if ($product !== '') {
    foreach (maintenance_checklist_fetch_symptoms($connect, $product) as $s) {
        $symptoms[] = array('id' => (int) $s['cs_id'], 'name' => (string) $s['cs_name']);
    }
    $rate_options = maintenance_update_rate_fetch_rate_options($connect, maintenance_update_rate_resolve_rate_product($product));
}
maintenance_db_odbc_close($connect);

$user_rate = (string) $mn_logistic_recorder_value;
$boot = array(
    'demo'           => true,
    'trpId'          => 0,
    'product'        => $product,
    'symptoms'       => $symptoms,
    'savedSymptomId' => 0,
    'rateOptions'    => array_values($rate_options),
    'saved'          => array(),
    'userRate'       => $user_rate,
);

function mn_try_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>ทดลองใช้งาน — ตรวจเช็คและประเมินเครื่อง</title>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
</head>
<body class="mn-addjob-page mn-assess-repair-page mn-assess-repair-page--demo">
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
    <main class="mn-ar" id="mn_ar">

      <header class="mn-ar-top">
        <div class="mn-ar-top__row">
          <a class="mn-ar-top__back" href="checklist_settings?product=<?php echo rawurlencode($product); ?>" aria-label="กลับหน้าตั้งค่ารายการตรวจเช็ค"><i class="fa fa-angle-left" aria-hidden="true"></i></a>
          <div class="mn-ar-top__id">
            <span class="mn-ar-top__product"><?php echo mn_try_h($product !== '' ? $product : 'ทดลองใช้งาน'); ?></span>
            <span class="mn-ar-top__sn"><span class="mn-ar-demo-badge">โหมดทดลอง</span> ไม่บันทึกข้อมูล</span>
          </div>
          <span class="mn-ar-top__count" id="mn_ar_stepcount">1/4</span>
        </div>
        <ol class="mn-ar-steps" id="mn_ar_steps" aria-label="ขั้นตอน">
          <li class="mn-ar-steps__seg is-active" data-step="1"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">เลือกรุ่น</span></li>
          <li class="mn-ar-steps__seg" data-step="2"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">ตรวจพื้นฐาน</span></li>
          <li class="mn-ar-steps__seg" data-step="3"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">ตามอาการ</span></li>
          <li class="mn-ar-steps__seg" data-step="4"><span class="mn-ar-steps__fill"></span><span class="mn-ar-steps__lbl">สรุปผล</span></li>
        </ol>
      </header>

      <div class="mn-ar-stage">

        <section class="mn-ar-step is-active" data-step="1" aria-label="เลือกรุ่น">
          <h1 class="mn-ar-h">ทดลองใช้งาน</h1>
          <?php if ($product === '') { ?>
          <div class="mn-ar-note mn-ar-note--warn">
            <span class="mn-ar-note__lbl">ยังไม่มีรุ่นที่ตั้งข้อตรวจ</span>
            <p class="mn-ar-note__txt">เพิ่มข้อตรวจในหน้าตั้งค่าก่อน แล้วกลับมาทดลองใช้งาน</p>
          </div>
          <?php } else { ?>
          <div class="mn-ar-card">
            <label class="mn-ar-label" for="mn_ar_demo_product">รุ่นสินค้า</label>
            <select id="mn_ar_demo_product" class="mn-ar-input">
              <?php foreach ($try_products as $p) { ?>
              <option value="<?php echo mn_try_h($p); ?>"<?php echo $p === $product ? ' selected' : ''; ?>><?php echo mn_try_h($p); ?></option>
              <?php } ?>
            </select>
          </div>
          <?php } ?>
          <div class="mn-ar-note">
            <span class="mn-ar-note__lbl">หน้านี้คืออะไร</span>
            <p class="mn-ar-note__txt">หน้าเดียวกับที่ช่างใช้ประเมินเครื่องจริง ลองกดตอบได้เต็มที่ — ไม่มีการบันทึกลงใบงานหรือฐานข้อมูล
ข้ออัตโนมัติ (อายุเครื่อง / ประวัติ MA) งานจริงระบบคำนวณจากใบงาน ในโหมดทดลองให้กดเลือกผลเอง</p>
          </div>
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
          <p class="mn-ar-sub">เลือกอาการที่พบ หรือกด “ไม่มีอาการเฉพาะ” เพื่อข้าม</p>
          <div class="mn-ar-chips" id="mn_ar_symptoms" role="radiogroup" aria-label="อาการ"></div>
          <ul class="mn-ar-items" id="mn_ar_sym_items"></ul>
        </section>

        <section class="mn-ar-step" data-step="4" aria-label="สรุปผลและอะไหล่">
          <h1 class="mn-ar-h">สรุปผล</h1>

          <div class="mn-ar-card mn-ar-findings" id="mn_ar_findings"></div>

          <div class="mn-ar-field">
            <div class="mn-ar-field__head">
              <label class="mn-ar-label" for="mn_ar_tech">อาการที่ประเมิน</label>
              <button type="button" class="mn-ar-link" id="mn_ar_regen"><i class="fa fa-refresh" aria-hidden="true"></i> สรุปใหม่จากผลตรวจ</button>
            </div>
            <textarea id="mn_ar_tech" class="mn-ar-input mn-ar-textarea" rows="3"></textarea>
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

          <div class="mn-ar-grid2">
            <div class="mn-ar-field">
              <label class="mn-ar-label" for="trp_fw">UP FW</label>
              <input type="text" id="trp_fw" class="mn-ar-input" />
            </div>
            <div class="mn-ar-field">
              <label class="mn-ar-label" for="trp_rate_date">วันที่ประเมิน</label>
              <input type="date" id="trp_rate_date" class="mn-ar-input" value="<?php echo date('Y-m-d'); ?>" />
            </div>
          </div>

          <div class="mn-ar-field">
            <label class="mn-ar-label" for="trp_repair_remarks">หมายเหตุ</label>
            <textarea id="trp_repair_remarks" class="mn-ar-input mn-ar-textarea" rows="2"></textarea>
          </div>

          <p class="mn-ar-assessor">ผู้ประเมิน <strong><?php echo mn_try_h($user_rate !== '' ? $user_rate : '—'); ?></strong></p>
        </section>

      </div>

      <footer class="mn-ar-foot">
        <button type="button" class="mn-ar-btn mn-ar-btn--ghost" id="mn_ar_prev"><i class="fa fa-angle-left" aria-hidden="true"></i> ย้อน</button>
        <button type="button" class="mn-ar-btn mn-ar-btn--primary" id="mn_ar_next"<?php echo $product === '' ? ' disabled' : ''; ?>><span class="mn-ar-btn__txt">เริ่มตรวจ</span> <i class="fa fa-angle-right" aria-hidden="true"></i></button>
      </footer>

    </main>
  </div>
<script type="text/javascript">
window.mnAr = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script type="text/javascript" src="js/assess_repair-page.js?v=<?php echo filemtime(__DIR__ . '/js/assess_repair-page.js'); ?>"></script>
</body>
</html>
