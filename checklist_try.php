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
require_once __DIR__ . '/includes/assess_side_panels.php';

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

// ดึงใบงานจริงจาก S/N มาทดลอง (อ่านอย่างเดียว ไม่บันทึกอะไรลงใบงาน)
$try_sn   = isset($_GET['sn']) ? mb_substr(trim((string) $_GET['sn']), 0, 60, 'UTF-8') : '';
$try_pick = 0;
$try_jobs = array();
$try_row  = null;
$try_msg  = '';
if ($try_sn !== '') {
    $stmt = mysqli_prepare($connect,
        'SELECT r.trp_id, r.trp_product, r.trp_receive_date, r.trp_status_job, t.ts_mtn_number'
        . ' FROM transac t INNER JOIN transac_repair r ON t.ts_id = r.trp_ts_id'
        . ' WHERE TRIM(r.trp_sn) = ? ORDER BY r.trp_id DESC LIMIT 1'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $try_sn);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) { $try_jobs[] = $r; }
        mysqli_stmt_close($stmt);
    }
    if (!$try_jobs) {
        $try_msg = 'ไม่พบใบงานของ S/N นี้';
    } else {
        $pick = (int) $try_jobs[0]['trp_id'];
        $row = maintenance_update_rate_fetch_bundle($connect, $pick);
        $job_product = is_array($row) ? trim((string) $row['trp_product']) : '';
        if (!is_array($row)) {
            $try_msg = 'อ่านใบงานไม่สำเร็จ';
        } elseif (!maintenance_checklist_product_supported($connect, $job_product)) {
            $try_msg = 'รุ่น ' . $job_product . ' ยังไม่มีข้อตรวจ ตั้งข้อตรวจก่อนแล้วลองใหม่';
        } else {
            $try_row = $row;
            $try_pick = $pick;
            $product = $job_product;
        }
    }
}
$try_history = $try_row ? mn_ar_fetch_history($connect, (string) $try_row['trp_sn'], (int) $try_row['trp_ts_id']) : array();

$symptoms = array();
$rate_options = array();
$bundles = array();
$reasons = array();
if ($product !== '') {
    foreach (maintenance_checklist_fetch_symptoms($connect, $product) as $s) {
        $symptoms[] = array('id' => (int) $s['cs_id'], 'name' => (string) $s['cs_name']);
    }
    $rate_options = maintenance_update_rate_fetch_rate_options($connect, maintenance_update_rate_resolve_rate_product($product));
    $bundles = maintenance_checklist_fetch_bundles($connect, $product);
    $reasons = maintenance_checklist_fetch_rate_reasons($connect, $product);
}
maintenance_db_odbc_close($connect);

$user_rate = (string) $mn_logistic_recorder_value;
$boot = array(
    'demo'           => true,
    'trpId'          => 0,
    // ใบงานจริงที่ดึงมาทดลอง: ใช้คำนวณข้ออัตโนมัติ (อายุเครื่อง / MA) เท่านั้น
    'refTrpId'       => $try_row ? (int) $try_pick : 0,
    'jobNo'          => $try_row && $try_jobs ? (string) $try_jobs[0]['ts_mtn_number'] : '',
    'product'        => $product,
    'symptoms'       => $symptoms,
    'savedSymptomIds' => array(),
    'rateOptions'    => array_values($rate_options),
    'bundles'        => $bundles,
    'rateReasons'    => (object) $reasons,
    'photos'         => array(),
    'saved'          => array(),
    'userRate'       => $user_rate,
);

function mn_try_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * ฟอร์มดึงใบงานจริงจาก S/N (+ เลือกรุ่นเมื่อยังไม่ได้ดึง) — แสดงในแผงข้าง (จอกว้าง) และขั้นแรก (จอแคบ)
 */
function mn_try_form($key, array $products, $product, $sn, array $jobs, $pick, $row, $msg)
{
    $h = 'mn_try_h';
    $html = '<form class="mn-ar-try" method="get" action="checklist_try">'
        . '<label class="mn-ar-label" for="mn_try_sn_' . $key . '">ดึงใบงานจริงมาทดลอง</label>'
        . '<div class="mn-ar-try__row">'
        . '<input type="search" name="sn" id="mn_try_sn_' . $key . '" class="mn-ar-input" maxlength="60" placeholder="หมายเลขเครื่อง (S/N)" value="' . $h($sn) . '" autocomplete="off" />'
        . '<button type="submit" class="mn-ar-try__go"><i class="fa fa-download" aria-hidden="true"></i> ดึงข้อมูล</button>'
        . '</div>';
    if ($msg !== '') {
        $html .= '<p class="mn-ar-try__msg"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> ' . $h($msg) . '</p>';
    }
    if ($row && $jobs) {
        // ใช้ใบงานล่าสุดของเครื่องนี้เสมอ
        $j = $jobs[0];
        $html .= '<p class="mn-ar-try__job"><i class="fa fa-file-text-o" aria-hidden="true"></i> ใบงานล่าสุด <b>' . $h($j['ts_mtn_number']) . '</b>'
            . ' รับ ' . $h($j['trp_receive_date']) . ($j['trp_status_job'] !== '' ? ' | ' . $h($j['trp_status_job']) : '') . '</p>';
    }
    if ($row) {
        $html .= '<a class="mn-ar-try__clear" href="checklist_try?product=' . rawurlencode($product) . '"><i class="fa fa-times" aria-hidden="true"></i> ล้าง กลับไปใช้ข้อมูลตัวอย่าง</a>';
    } elseif ($products) {
        $html .= '<label class="mn-ar-label mn-ar-try__or" for="mn_try_product_' . $key . '">หรือเลือกรุ่น (ข้อมูลตัวอย่าง)</label>'
            . '<select id="mn_try_product_' . $key . '" class="mn-ar-input" data-try-product="1">';
        foreach ($products as $p) {
            $html .= '<option value="' . $h($p) . '"' . ($p === $product ? ' selected' : '') . '>' . $h($p) . '</option>';
        }
        $html .= '</select>';
    }
    return $html . '</form>';
}?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-assess-repair.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-assess-repair.css'); ?>" />
<link rel="stylesheet" href="css/mn-photo-lightbox.css?v=<?php echo filemtime(__DIR__ . '/css/mn-photo-lightbox.css'); ?>" />
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
    <?php
    $try_form = mn_try_form('side', $try_products, $product, $try_sn, $try_jobs, $try_pick, $try_row, $try_msg);
    if ($try_row) {
        // ข้อมูลจริงจากใบงานที่ดึงมา (อ่านอย่างเดียว)
        $tf = function ($k) use ($try_row) { return isset($try_row[$k]) ? (string) $try_row[$k] : ''; };
        $try_no = '';
        foreach ($try_jobs as $j) { if ((int) $j['trp_id'] === (int) $try_pick) { $try_no = (string) $j['ts_mtn_number']; } }
        $try_ins = $tf('trp_insurance');
        echo mn_ar_info_panel(array(
            'top_html'  => $try_form,
            'product'   => $product,
            'sn'        => $tf('trp_sn'),
            'receive'   => $tf('trp_receive_date'),
            'company'   => trim($tf('ts_enduser_com') . "\n" . $tf('ts_security_com')),
            'buy'       => $tf('trp_buy_date'),
            'age'       => $tf('trp_repair_age'),
            'insurance' => $try_ins,
            'ins_in'    => $try_ins === 'อยู่ในประกัน' || $try_ins === 'อยู่ในประกันซ่อม',
            'inform'    => $tf('trp_repair_inform'),
            'remarks'   => $tf('ts_remarks'),
            'foot'      => 'ข้อมูลจริงจากใบงาน ' . $try_no . ' ทดลองตอบได้ ไม่บันทึกลงใบงาน',
        ));
        echo mn_ar_history_panel($try_history);
    } else {
    // โหมดทดลองไม่มีใบงานจริง — แสดงข้อมูลตัวอย่างให้เห็นหน้าตาแผงข้างแบบงานจริง
    $demo_buy = date('d/m/Y', strtotime('-3 years -2 months'));
    echo mn_ar_info_panel(array(
        'top_html'  => $try_form,
        'product'   => $product,
        'sn'        => 'ตัวอย่าง-0001',
        'receive'   => date('d/m/Y'),
        'company'   => "บริษัท รักษาความปลอดภัย ตัวอย่าง
อาคารสำนักงานตัวอย่าง",
        'buy'       => $demo_buy,
        'age'       => '3 ปี 2 เดือน',
        'insurance' => 'หมดประกัน',
        'ins_in'    => false,
        'inform'    => 'ทัชสกรีนกดไม่ติดบางจุด เปิดเครื่องช้า',
        'remarks'   => 'ลูกค้าขอใบเสนอราคาก่อนซ่อม',
    ), true);
    echo mn_ar_history_panel(array(
        array('no' => 2, 'date' => date('d/m/Y', strtotime('-8 months')), 'number' => 'MTN-ตัวอย่าง-0002', 'company' => '',
            'inform' => 'กล้องภาพมัว', 'assess' => 'เลนส์กล้องมีฝ้า ทำความสะอาดแล้วภาพชัด',
            'parts' => array('ทำความสะอาดเลนส์กล้อง'), 'remarks' => '', 'rater' => 'ช่างตัวอย่าง', 'by' => 'ช่างตัวอย่าง', 'status' => 'ส่งคืนลูกค้า',
            'firm' => 'อนุมัติซ่อม', 'firm_date' => date('Y-m-d', strtotime('-8 months')), 'qv_no' => 'QV-ตัวอย่าง', 'qv_price' => '1,070', 'firm_note' => 'ซ่อมด่วน'),
        array('no' => 1, 'date' => date('d/m/Y', strtotime('-2 years')), 'number' => 'MTN-ตัวอย่าง-0001', 'company' => '',
            'inform' => 'เปิดไม่ติด', 'assess' => 'Adapter เสีย', 'parts' => array('Adapter 12V 5A'),
            'remarks' => 'เปลี่ยนในประกัน', 'rater' => 'ช่างตัวอย่าง', 'by' => 'ช่างตัวอย่าง', 'status' => 'ส่งคืนลูกค้า',
            'firm' => 'อนุมัติซ่อม', 'firm_date' => date('Y-m-d', strtotime('-2 years')), 'qv_no' => '-', 'qv_price' => '', 'firm_note' => ''),
    ), true);
    }
    ?>
    <main class="mn-ar" id="mn_ar">

      <header class="mn-ar-top">
        <div class="mn-ar-top__row">
          <a class="mn-ar-top__back" href="checklist_settings?product=<?php echo rawurlencode($product); ?>" aria-label="กลับหน้าตั้งค่ารายการตรวจเช็ค"><i class="fa fa-angle-left" aria-hidden="true"></i></a>
          <div class="mn-ar-top__id">
            <span class="mn-ar-top__product"><?php echo mn_try_h($product !== '' ? $product : 'ทดลองใช้งาน'); ?></span>
            <span class="mn-ar-top__sn"><span class="mn-ar-demo-badge">โหมดทดลอง</span> <?php echo $try_row ? 'SN ' . mn_try_h($try_row['trp_sn']) . ' ' : ''; ?>ไม่บันทึกข้อมูล</span>

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
            <?php echo mn_try_form('step', $try_products, $product, $try_sn, $try_jobs, $try_pick, $try_row, $try_msg); ?>
          </div>
          <?php } ?>
          <div class="mn-ar-note">
            <span class="mn-ar-note__lbl">หน้านี้คืออะไร</span>
            <p class="mn-ar-note__txt">หน้าเดียวกับที่ช่างใช้ประเมินเครื่องจริง ลองกดตอบได้เต็มที่ — ไม่มีการบันทึกลงใบงานหรือฐานข้อมูล
<?php echo $try_row
    ? 'ดึงใบงานล่าสุดของเครื่องนี้มาแล้ว ข้ออัตโนมัติ (อายุเครื่อง / ประวัติ MA) ระบบคำนวณจากใบงานจริง'
    : 'ข้ออัตโนมัติ (อายุเครื่อง / ประวัติ MA) งานจริงระบบคำนวณจากใบงาน ในโหมดทดลองให้กดเลือกผลเอง หรือกรอก S/N เพื่อดึงใบงานจริงมาทดลอง'; ?></p>
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
          <p class="mn-ar-sub">เลือกอาการที่พบ <b>เลือกได้หลายอาการ</b> หรือกด “ไม่มีอาการเฉพาะ” เพื่อข้าม <span class="mn-ar-sub__count" id="mn_ar_sym_count"></span></p>
          <div class="mn-ar-chips" id="mn_ar_symptoms" role="group" aria-label="อาการ (เลือกได้หลายอาการ)"></div>
          <ul class="mn-ar-items" id="mn_ar_sym_items"></ul>
        </section>

        <section class="mn-ar-step" data-step="4" aria-label="สรุปผลและอะไหล่">
          <div class="mn-ar-h-row">
            <h1 class="mn-ar-h">สรุปผล</h1>
            <button type="button" class="mn-ar-pdf" id="mn_ar_pdf" title="เปิดตัวอย่างใบประเมิน A4 แก้ข้อความได้ก่อนบันทึกเป็น PDF"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> ส่งออก PDF</button>
          </div>

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
                <input type="date" id="trp_rate_date" class="mn-ar-input" value="<?php echo date('Y-m-d'); ?>" />
              </div>
            </div>

            <div class="mn-ar-field">
              <label class="mn-ar-label" for="trp_repair_remarks">หมายเหตุ</label>
              <textarea id="trp_repair_remarks" class="mn-ar-input mn-ar-textarea" rows="2"></textarea>
            </div>
            </div>
          </details>

          <p class="mn-ar-assessor">ผู้ประเมิน <strong><?php echo mn_try_h($user_rate !== '' ? $user_rate : '—'); ?></strong></p>
        </section>

      </div>

      <footer class="mn-ar-foot">
        <button type="button" class="mn-ar-btn mn-ar-btn--ghost" id="mn_ar_prev"><i class="fa fa-angle-left" aria-hidden="true"></i> ย้อน</button>
        <span class="mn-ar-foot__left" id="mn_ar_left" hidden></span>
        <button type="button" class="mn-ar-btn mn-ar-btn--primary" id="mn_ar_next"<?php echo $product === '' ? ' disabled' : ''; ?>><span class="mn-ar-btn__txt">เริ่มตรวจ</span> <i class="fa fa-angle-right" aria-hidden="true"></i></button>
      </footer>

    </main>
  </div>
<script type="text/javascript">
window.mnAr = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
// เลือกรุ่น (ข้อมูลตัวอย่าง) → เปิดหน้าทดลองของรุ่นนั้น
document.addEventListener('change', function (e) {
  var sel = e.target.closest ? e.target.closest('[data-try-product]') : null;
  if (sel) { window.location.href = 'checklist_try?product=' + encodeURIComponent(sel.value); }
});
</script>
<script type="text/javascript" src="js/mn-photo-lightbox.js?v=<?php echo filemtime(__DIR__ . '/js/mn-photo-lightbox.js'); ?>"></script>
<script type="text/javascript" src="js/assess_repair-page.js?v=<?php echo filemtime(__DIR__ . '/js/assess_repair-page.js'); ?>"></script>
</body>
</html>
