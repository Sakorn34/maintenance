<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายละเอียดใบงานตาม `ts_id` (แบบ B2) — ซ้าย: การ์ดใบงาน (สถานะ + ความคืบหน้าของเครื่องที่เลือก) ลูกค้า ผู้ติดต่อ ที่อยู่
 * ขวา: แท็บแยกเครื่อง แต่ละเครื่องแบ่งเป็นกรอบ (ข้อมูลเครื่อง/รับเครื่อง, ผลประเมิน, ใบเสนอราคา/อนุมัติ, ซ่อม/QC, ส่งคืน, เครื่องสำรอง, Comment)
 * + หน้าต่างประวัติซ่อมของ S/N (ไทม์ไลน์ตามปี)
 * ปุ่มแก้ไขทุกส่วนใช้คลาส/ data-* เดิม (js/detail_job-field-edit-modal.js จับด้วย delegated click) และหัวใบงานใช้ id ช่องเดิม (js/detail_job-page.js)
 *
 * Flow:
 * GET `ts_id` → bootstrap → SELECT join → ตรวจล็อกหัวใบงาน / ล็อกรายการซ่อมรายแถว → เก็บข้อมูลแต่ละเครื่อง → ปิด mysqli → render
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_detail_job_page.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/detail_job_edit_helpers.php';
require_once __DIR__ . '/includes/checklist_helpers.php';
require_once __DIR__ . '/includes/detail_job_view_helpers.php';
require_once __DIR__ . '/includes/job_stamp_helpers.php';
require_once __DIR__ . '/includes/checklist_photo_helpers.php';
require_once __DIR__ . '/includes/spare_flow_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$ts_id = isset($_GET['ts_id']) ? (int) $_GET['ts_id'] : 0;
if ($ts_id <= 0) {
    maintenance_db_odbc_close($connect);
    header('Location: index');
    exit;
}

$sql_main = 'SELECT * FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id '
    . 'WHERE transac_repair.trp_ts_id = ' . $ts_id;
$exe_main = maintenance_db_odbc_exec($connect, $sql_main);
if (!$exe_main) {
    maintenance_db_odbc_close($connect);
    exit('Error Query [' . $sql_main . ']: ' . maintenance_db_odbc_errormsg($connect));
}

$array1 = array();
$sql_pn = 'SELECT pn_name FROM product_name ORDER BY pn_name ASC';
$exe_pn = maintenance_db_odbc_exec($connect, $sql_pn);
if ($exe_pn) {
    while ($rpn = maintenance_db_odbc_fetch_array($exe_pn)) {
        if (is_array($rpn) && isset($rpn['pn_name']) && (string) $rpn['pn_name'] !== '') {
            $array1[] = (string) $rpn['pn_name'];
        }
    }
}
$dj_product_names_utf8 = array();
foreach ($array1 as $pn_raw) {
    $dj_product_names_utf8[] = maintenance_flow_db_text_to_utf8($pn_raw);
}
require_once __DIR__ . '/includes/logistics_helpers.php';
$dj_logistics_utf8 = maintenance_detail_job_list_logistics_utf8($connect);
$dj_logistics_tracking_map = maintenance_logistics_fetch_tracking_url_map($connect);

$dj_header_edit_locked = maintenance_detail_job_header_locked($connect, $ts_id);

$hdr = null;           // ข้อมูลหัวใบงาน (transac)
$machines = array();   // ข้อมูลแต่ละเครื่อง
$i = 0;
$m = 0;
$dj_page_title_mtn = '';

while ($outer = maintenance_db_odbc_fetch_array($exe_main)) {
    if ($hdr === null) {
        $hdr = $outer;
        $dj_page_title_mtn = (string) maintenance_status_logistic_row($outer, 'ts_mtn_number');
    }

    $trp_id_outer = (int) maintenance_status_logistic_row($outer, 'trp_id', 0);
    $sqlch_sp = 'SELECT tsp_trp_id FROM transac_spare WHERE tsp_trp_id = ' . $trp_id_outer;
    $exech_sp = maintenance_db_odbc_exec($connect, $sqlch_sp);
    $resultch_sp = $exech_sp ? maintenance_db_odbc_fetch_array($exech_sp) : false;
    $has_spare = is_array($resultch_sp) && isset($resultch_sp['tsp_trp_id']) && (string) $resultch_sp['tsp_trp_id'] !== '';

    $sql2 = 'SELECT * FROM transac_repair WHERE trp_id = ' . $trp_id_outer;
    if ($has_spare) {
        $sql2 = 'SELECT * FROM transac_repair INNER JOIN transac_spare ON transac_repair.trp_id = transac_spare.tsp_trp_id '
            . 'WHERE transac_spare.tsp_trp_id = ' . $trp_id_outer;
    }
    $exe2 = maintenance_db_odbc_exec($connect, $sql2);
    if (!$exe2) {
        continue;
    }

    $temp_spare = '';
    while ($inner = maintenance_db_odbc_fetch_array($exe2)) {
        $i += 1;
        $m += 1;
        $n = (string) $i . (string) $m;
        $trp_product_inner = (string) maintenance_status_logistic_row($inner, 'trp_product');
        if ($temp_spare === $trp_product_inner) {
            continue;
        }
        $temp_spare = $trp_product_inner;
        $trp_id_inner = (int) maintenance_status_logistic_row($inner, 'trp_id', 0);
        $row = function ($k) use ($inner) { return maintenance_status_logistic_row($inner, $k); };

        $dj_trp_status_job = trim(maintenance_flow_db_text_to_utf8($row('trp_status_job')));
        $dj_repair_row_edit_locked = maintenance_detail_job_repair_row_locked_from_status($dj_trp_status_job);
        $dj_trp_qv_number = trim(maintenance_flow_db_text_to_utf8($row('trp_qv_number')));
        $dj_trp_qv_date = trim(maintenance_flow_db_text_to_utf8($row('trp_qv_date')));
        $dj_trp_status_firmma = trim(maintenance_flow_db_text_to_utf8($row('trp_status_firmma')));
        $dj_repair_ma_approved_locked = maintenance_detail_job_repair_ma_approved_locked($row('trp_firm_qv_date'), $dj_trp_status_job, $dj_trp_status_firmma);
        $dj_repair_rate_edit_locked = maintenance_detail_job_repair_quote_offered($dj_trp_qv_number, $dj_trp_qv_date);
        $dj_repair_qc_done_locked = maintenance_detail_job_repair_qc_done_locked($row('trp_qc_date'));
        $dj_repair_returned_locked = maintenance_detail_job_repair_returned_locked($row('trp_send_date'));
        $dj_spare_returned_locked = maintenance_detail_job_spare_returned_locked_db($connect, $trp_id_inner);
        $dj_trp_product_current = trim(maintenance_flow_db_text_to_utf8($trp_product_inner));
        $dj_trp_sn_current = trim(maintenance_flow_db_text_to_utf8($row('trp_sn')));
        $dj_trp_repair_inform_current = maintenance_flow_db_text_to_utf8($row('trp_repair_inform'));
        $dj_rate_has_data = maintenance_detail_job_group_has_rate_data($inner);
        $dj_assess_pdf = $trp_id_inner > 0 && $dj_rate_has_data && maintenance_checklist_product_supported($connect, $trp_product_inner);

        $mc = array(
            'i' => $i, 'n' => $n, 'trp_id' => $trp_id_inner, 'row' => $inner,
            'status' => $dj_trp_status_job, 'product' => $dj_trp_product_current, 'sn' => $dj_trp_sn_current,
            'inform' => $dj_trp_repair_inform_current,
            // อาการที่แจ้ง: ตัดบรรทัดว่างซ้อนออกตอนแสดง
            'inform_view' => trim(preg_replace('/\n[ \t]*\n+/u', "\n", str_replace("\r", '', $dj_trp_repair_inform_current))),
            'locked' => $dj_repair_row_edit_locked,
            'steps' => mn_dj_steps(array_merge($inner, array('ts_date' => maintenance_status_logistic_row($outer, 'ts_date')))),
            'report' => mn_dj_parse_report(maintenance_flow_db_text_to_utf8($row('trp_repair_technician'))),
            'tones' => mn_dj_tone_map($connect, $trp_id_inner),
            'history' => mn_dj_history($connect, $dj_trp_sn_current, $ts_id),
            'qc' => mn_dj_qc_detail($connect, $trp_id_inner),
            'photos' => call_user_func(function () use ($connect, $trp_id_inner) { $p = mn_photo_by_trps($connect, array($trp_id_inner)); return isset($p[$trp_id_inner]) ? $p[$trp_id_inner] : array(); }),
            'stamp' => mn_job_stamp_fetch($connect, $trp_id_inner),
            'rate_has' => $dj_rate_has_data,
            'pdf' => $dj_assess_pdf,
            'firm' => $dj_trp_status_firmma,
            // ปุ่มแก้ไข (เงื่อนไขเดิม)
            'can_machine' => !$dj_repair_row_edit_locked && maintenance_detail_job_group_has_machine_data($dj_trp_product_current, $dj_trp_sn_current, $dj_trp_repair_inform_current),
            'can_rate' => !$dj_repair_row_edit_locked && !$dj_repair_rate_edit_locked && $trp_id_inner > 0 && $dj_rate_has_data,
            'can_rate_qv' => $dj_assess_pdf && $dj_repair_rate_edit_locked && $dj_trp_status_job === 'รออนุมัติซ่อม' && !$dj_repair_ma_approved_locked,
            'can_receive' => !$dj_repair_row_edit_locked && !$dj_repair_rate_edit_locked && $trp_id_inner > 0 && maintenance_detail_job_date_has_data($row('trp_receive_date')),
            'can_qv' => !$dj_repair_row_edit_locked && !$dj_repair_ma_approved_locked && $trp_id_inner > 0 && maintenance_detail_job_group_has_qv_data($dj_trp_qv_number, $dj_trp_qv_date),
            'can_ma' => !$dj_repair_row_edit_locked && !$dj_repair_qc_done_locked && $trp_id_inner > 0 && maintenance_detail_job_date_has_data($row('trp_success_date')),
            'can_qc' => !$dj_repair_row_edit_locked && !$dj_repair_returned_locked && $trp_id_inner > 0 && maintenance_detail_job_group_has_qc_data($row('trp_qc_date'), $row('trp_qc_user')),
            'can_return' => !$dj_repair_row_edit_locked && !$dj_spare_returned_locked && $trp_id_inner > 0 && maintenance_detail_job_date_has_data($row('trp_send_date')),
            // ค่าสำหรับ data-* ของปุ่มแก้ไข
            'buy_input' => maintenance_detail_job_buy_date_for_html_input($row('trp_buy_date')),
            'buy_display' => trim(maintenance_flow_db_text_to_utf8($row('trp_buy_date'))),
            'receive_input' => maintenance_detail_job_buy_date_for_html_input($row('trp_receive_date')),
            'receive_display' => trim(maintenance_flow_db_text_to_utf8($row('trp_receive_date'))),
            'qv_date_input' => maintenance_detail_job_buy_date_for_html_input($row('trp_qv_date')),
            'firm_input' => maintenance_detail_job_buy_date_for_html_input($row('trp_firm_qv_date')),
            'success_input' => maintenance_detail_job_buy_date_for_html_input($row('trp_success_date')),
            'qc_input' => maintenance_detail_job_buy_date_for_html_input($row('trp_qc_date')),
            'send_input' => maintenance_detail_job_buy_date_for_html_input($row('trp_send_date')),
            'spares' => array(),
            'spare_add_href' => null,
            'tsp' => null,
        );

        // เครื่องสำรอง
        $dj_edit_spare_base_q = null;
        if (!$dj_repair_row_edit_locked && !$dj_spare_returned_locked) {
            $dj_spare_detail_plain = maintenance_wait_hardware_contact_plain_for_spare($inner);
            $dj_edit_spare_base_q = 'trp_id=' . (int) $trp_id_inner
                . '&trp_ts_id=' . (int) $ts_id
                . '&trp_product=' . rawurlencode($dj_trp_product_current)
                . '&trp_detail=' . rawurlencode(str_replace(array("\r", "\n"), ' ', $dj_spare_detail_plain));
        }
        $exe3 = maintenance_db_odbc_exec($connect, 'SELECT * FROM transac_spare WHERE tsp_trp_id = ' . $trp_id_inner);
        $sp_any = false;
        // flow เครื่องสำรอง: เหตุการณ์ของทุกเครื่องในรายการซ่อมนี้ (ตีกลับ / ส่งใหม่ / เปลี่ยน / คืน ...)
        $sp_events_all = mn_spare_events_by_trp($connect, $trp_id_inner);
        $sp_need_opts = false;
        if ($exe3) {
            while ($sp = maintenance_db_odbc_fetch_array($exe3)) {
                $sp_any = true;
                $sp_row_tsp_id = (int) maintenance_status_logistic_row($sp, 'tsp_id', 0);
                $sp_datesend = (string) maintenance_status_logistic_row($sp, 'tsp_datesend');
                $sp_datereturn = (string) maintenance_status_logistic_row($sp, 'tsp_datereturn');
                $sp_receiveby = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_receiveby')));
                $sp_sn = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_sn')));
                $sp_send_has = maintenance_detail_job_date_has_data($sp_datesend);
                $sp_returned = maintenance_detail_job_spare_returned_locked($sp_datereturn);
                if ($mc['tsp'] === null) {
                    // สำรองตัวแรก: ข้อมูลส่ง/รับคืน + ปุ่มแก้ไขส่งสำรอง (เหมือนเดิม)
                    $mc['tsp'] = array(
                        'tsp_id' => $sp_row_tsp_id,
                        'datesend' => $sp_datesend,
                        'datesend_input' => maintenance_detail_job_buy_date_for_html_input($sp_datesend),
                        'logistics' => trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_logistics'))),
                        'tracking' => (string) maintenance_status_logistic_row($sp, 'tsp_tracking'),
                        'sendby' => trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_sendby'))),
                        'datereturn' => $sp_datereturn,
                        'receiveby' => $sp_receiveby,
                        'can_send_edit' => !$dj_repair_row_edit_locked && $sp_row_tsp_id > 0 && ($sp_send_has || maintenance_detail_job_text_has_data($sp_sn)),
                        'send_has' => $sp_send_has,
                    );
                }
                $change_href = null;
                if (!$dj_repair_row_edit_locked && $dj_edit_spare_base_q !== null && !$sp_returned && $sp_sn !== '') {
                    $change_href = 'edit_spare?' . $dj_edit_spare_base_q . '&tsp_sn=' . rawurlencode($sp_sn);
                }
                $sp_raw = array(
                    'tsp_sn' => $sp_sn,
                    'tsp_datesend' => $sp_datesend,
                    'tsp_datereturn' => $sp_datereturn,
                    'tsp_logistics' => trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_logistics'))),
                    'tsp_tracking' => (string) maintenance_status_logistic_row($sp, 'tsp_tracking'),
                    'tsp_sendby' => trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_sendby'))),
                    'tsp_receiveby' => $sp_receiveby,
                    'tsp_remarks' => trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_remarks'))),
                );
                $sp_ev = isset($sp_events_all[$sp_row_tsp_id]) ? $sp_events_all[$sp_row_tsp_id] : array();
                $sp_state = mn_spare_state($sp_raw, $sp_ev, $dj_trp_status_job);
                $sp_actions = $dj_repair_row_edit_locked ? array() : mn_spare_actions($sp_state, $sp_raw);
                if (isset($sp_actions['change']) || isset($sp_actions['swap'])) { $sp_need_opts = true; }
                $mc['spares'][] = array(
                    'state' => $sp_state,
                    'timeline' => mn_spare_timeline($sp_raw, $sp_ev, $sp_state),
                    'actions' => $sp_actions,
                    'raw' => $sp_raw,
                    'datesend_input' => maintenance_detail_job_buy_date_for_html_input($sp_datesend),
                    'tsp_id' => $sp_row_tsp_id,
                    'sn' => $sp_sn,
                    'send_has' => $sp_send_has,
                    'returned' => $sp_returned,
                    'change_href' => $change_href,
                    'early' => maintenance_detail_job_should_show_early_spare_return_link($dj_repair_row_edit_locked, $dj_repair_returned_locked, $sp_row_tsp_id, $sp_send_has, $sp_returned),
                    'early_done' => maintenance_detail_job_should_show_early_spare_return_done_notice($dj_repair_row_edit_locked, $dj_repair_returned_locked, $sp_returned),
                    'datereturn_input' => maintenance_detail_job_buy_date_for_html_input($sp_datereturn),
                    'datereturn_display' => trim(maintenance_flow_db_text_to_utf8($sp_datereturn)),
                    'receiveby' => $sp_receiveby,
                );
            }
        }
        $mc['spare_sn_opts'] = array();
        if ($sp_need_opts) {
            foreach (maintenance_status_logistic_spare_blank_options($connect, $dj_trp_product_current) as $o) { $mc['spare_sn_opts'][] = $o['sn']; }
        }
        if (!$sp_any && $dj_edit_spare_base_q !== null) {
            $mc['spare_add_href'] = 'edit_spare?' . $dj_edit_spare_base_q;
        }
        $machines[] = $mc;
    }
}

if ($hdr === null) {
    maintenance_db_odbc_close($connect);
    ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<title>ไม่พบใบงาน</title>
</head>
<body class="mn-addjob-page mn-detail-job-page">
  <div class="header">
    <?php
    $page = '';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace" style="padding-top:7rem;">
    <p>ไม่พบใบงานสำหรับรหัสนี้</p>
    <p><a href="index">กลับหน้าแรก</a></p>
  </div>
</body>
</html>
    <?php
    exit;
}

maintenance_db_odbc_close($connect);
$connect = null;

// ---------- ตัวช่วยแสดงผล ----------
function dj_h($v)
{
    return maintenance_status_logistic_h((string) $v);
}

function dj_hdr($k)
{
    global $hdr;
    return maintenance_flow_db_text_to_utf8((string) maintenance_status_logistic_row($hdr, $k));
}

function dj_val($v, $empty = '—')
{
    $v = trim((string) $v);
    return $v === '' || $v === '-' ? '<span class="mn-dj2-empty">' . dj_h($empty) . '</span>' : dj_h($v);
}

/** ช่องหัวใบงาน: ข้อความตอนดู + input (id เดิม) ตอนแก้ไข */
function dj_hfield($id, $value, $attrs = '')
{
    return '<span class="mn-dj2-val' . ($id === 'ts_address2' || $id === 'ts_remarks' ? ' mn-dj2-val--long' : '') . '">' . dj_val($id === 'ts_date' ? (mn_dj_date($value) !== '' ? mn_dj_date($value) : $value) : $value) . '</span>'
        . '<input ' . $attrs . ' name="' . dj_h($id) . '" id="' . dj_h($id) . '" class="mn-dj-header-field mn-dj2-input" value="' . dj_h($value) . '" readonly="readonly" />';
}

function dj_kv($label, $value_html, $cls = '')
{
    return '<div class="mn-dj2-kv' . ($cls !== '' ? ' ' . $cls : '') . '"><small>' . dj_h($label) . '</small><b>' . $value_html . '</b></div>';
}

function dj_date_or($raw)
{
    $d = mn_dj_date($raw);
    return $d !== '' ? $d : trim(maintenance_flow_db_text_to_utf8((string) $raw));
}

function dj_steps_html(array $steps)
{
    $html = '<ol class="mn-dj2-vsteps">';
    foreach ($steps as $k => $s) {
        $ic = $s['state'] === 'done' ? '<i class="fa fa-check"></i>' : ($s['state'] === 'no' ? '<i class="fa fa-times"></i>' : '<i>' . ($k + 1) . '</i>');
        $html .= '<li class="is-' . $s['state'] . '">' . $ic . '<span>' . dj_h($s['label']) . '</span><small>' . dj_h($s['sub']) . '</small></li>';
    }
    return $html . '</ol>';
}

/** สถานะกรอบ: done = มีข้อมูล, cur = ขั้นปัจจุบัน, pend = ยังไม่ถึง */
function dj_set_state(array $mc, array $keys, $has)
{
    foreach ($mc['steps'] as $s) {
        if (in_array($s['key'], $keys, true) && $s['state'] === 'now') { return 'cur'; }
    }
    return $has ? 'done' : 'pend';
}

/**
 * ป้ายวันที่ + ผู้บันทึกข้างหัวกรอบ
 * $items = array(array(ป้ายนำ, วันที่ดิบ, ผู้บันทึก, เก็บชื่อผู้บันทึกไหม)) — ไม่มีวันที่ = ป้ายเส้นประ "ยังไม่บันทึก"
 */
function dj_meta(array $items)
{
    $html = '';
    foreach ($items as $it) {
        $lbl = isset($it[0]) ? (string) $it[0] : '';
        $d = mn_dj_date(isset($it[1]) ? $it[1] : '');
        $who = trim((string) (isset($it[2]) ? $it[2] : ''));
        $who_kept = !isset($it[3]) || $it[3];
        if ($d === '') {
            $html .= '<span class="mn-dj2-meta is-none"><i class="fa fa-calendar" aria-hidden="true"></i> ' . dj_h(($lbl !== '' ? $lbl . ' ' : '') . 'ยังไม่บันทึก') . '</span>';
            continue;
        }
        $html .= '<span class="mn-dj2-meta"><i class="fa fa-calendar" aria-hidden="true"></i> ' . dj_h(($lbl !== '' ? $lbl . ' ' : '') . $d) . '</span>';
        if ($who !== '' && $who !== '-') {
            $html .= '<span class="mn-dj2-meta"><i class="fa fa-user" aria-hidden="true"></i> ' . dj_h($who) . '</span>';
        } else {
            $html .= '<span class="mn-dj2-meta is-none"><i class="fa fa-user" aria-hidden="true"></i> ' . ($who_kept ? 'ไม่ระบุชื่อ' : 'ไม่ได้เก็บชื่อผู้บันทึก') . '</span>';
        }
    }
    return $html;
}

function dj_set_open($cls, $icon, $title, $meta, $btns)
{
    return '<section class="mn-dj2-set is-' . $cls . '"><div class="mn-dj2-set-h"><span class="mn-dj2-set-ic"><i class="fa ' . $icon . '" aria-hidden="true"></i></span>'
        . '<b>' . dj_h($title) . '</b>' . $meta . '<span class="mn-dj2-sp"></span>' . $btns . '</div><div class="mn-dj2-set-b">';
}

$TONE_TXT = array('bad' => 'ผิดปกติ', 'warn' => 'ควรพิจารณา', 'good' => 'ปกติ', 'na' => 'N/A');
$TONE_IC = array('bad' => '&times;', 'warn' => '!', 'good' => '&#10003;', 'na' => '&ndash;');

$hdr_status_rows = array();
$spare_total = 0;
$spare_out = 0;
foreach ($machines as $mc) {
    foreach ($mc['spares'] as $sp) {
        $spare_total++;
        if (in_array($sp['state'], array('ship', 'cust', 'wait'), true)) { $spare_out++; }
    }
}
$spare_text = $spare_total === 0 ? 'ไม่มี' : ($spare_out > 0 ? 'อยู่กับลูกค้า ' . $spare_out . ' เครื่อง' : $spare_total . ' เครื่อง ไม่ได้อยู่กับลูกค้า');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-detail-job.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-detail-job.css'); ?>" />
<link rel="stylesheet" href="css/mn-photo-lightbox.css?v=<?php echo filemtime(__DIR__ . '/css/mn-photo-lightbox.css'); ?>" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title><?php echo dj_h($dj_page_title_mtn !== '' ? 'งาน ' . $dj_page_title_mtn . ' — รายละเอียด' : 'รายละเอียดใบงาน'); ?></title>
<script type="text/javascript">
window.MN_DETAIL_JOB = <?php echo json_encode(
    array(
        'tsId' => $ts_id,
        'headerEditLocked' => $dj_header_edit_locked,
        'productNames' => $dj_product_names_utf8,
        'logisticsNames' => $dj_logistics_utf8,
        'qvSendOptions' => array('Mail', 'Line', 'Fax'),
        'logisticRecorder' => isset($mn_logistic_recorder_value) ? (string) $mn_logistic_recorder_value : '',
        'flashMessage' => (isset($_GET['mn_flash']) && $_GET['mn_flash'] === 'spare_sn_saved')
            ? 'บันทึก S/N เครื่องสำรองแล้ว — บันทึกวันที่ส่ง/ขนส่งได้ภายหลังจากปุ่มในกรอบเครื่องสำรอง'
            : '',
    ),
    JSON_UNESCAPED_UNICODE
); ?>;
</script>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<?php
$mnLogisticsTrackingMap = $dj_logistics_tracking_map;
$mnLogisticsTrackingAssetPrefix = '';
require_once __DIR__ . '/includes/mn_logistics_tracking_assets.php';
?>
<script type="text/javascript" src="js/detail_job-page.js?v=<?php echo filemtime(__DIR__ . '/js/detail_job-page.js'); ?>"></script>
<script type="text/javascript" src="js/detail_job-field-edit-modal.js?v=<?php echo filemtime(__DIR__ . '/js/detail_job-field-edit-modal.js'); ?>"></script>
<script type="text/javascript">
jQuery(document).ready(function ($) {
	$('.search-box-user input[type="text"]').on("keyup input", function () {
		var inputVal = $(this).val();
		var resultDropdown = $(this).siblings(".result");
		if (inputVal.length) {
			$.get("search_cusname", { term: inputVal }).done(function (data) {
				resultDropdown.html(data);
			});
		} else {
			resultDropdown.empty();
		}
	});
	$(document).on("click", ".result p", function () {
		$(this).parents(".search-box-user").find('input[type="text"]').val($(this).text());
		$(this).parent(".result").empty();
	});
	$('.search-box-sec input[type="text"]').on("keyup input", function () {
		var inputVal = $(this).val();
		var resultDropdown = $(this).siblings(".result_sec");
		if (inputVal.length) {
			$.get("search_cusname", { term: inputVal }).done(function (data) {
				resultDropdown.html(data);
			});
		} else {
			resultDropdown.empty();
		}
	});
	$(document).on("click", ".result_sec p", function () {
		$(this).parents(".search-box-sec").find('input[type="text"]').val($(this).text());
		$(this).parent(".result_sec").empty();
	});
	$('.search-box-tambon input').on("keyup input", function () {
		var inputVal = $(this).val();
		var resultDropdown = $(this).siblings(".result_tambon");
		if (inputVal.length) {
			$.get("search_address", { term: inputVal }).done(function (data) {
				resultDropdown.html(data);
			});
		} else {
			resultDropdown.empty();
		}
	});
	$(document).on("click", ".result_tambon p", function () {
		$(this).parents(".search-box-tambon").find('input').val($(this).text());
		$(this).parent(".result_tambon").empty();
	});
});
</script>
</head>
<body class="mn-addjob-page mn-detail-job-page">
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
    <div class="mn-dj2">

      <!-- ===== คอลัมน์ซ้าย ===== -->
      <aside class="mn-dj2-side">
        <div class="mn-dj2-card mn-dj2-job">
          <div class="mn-dj2-job-top">
            <div class="mn-dj2-job-t">ใบงาน</div>
            <div class="mn-dj2-job-h">
              <h1><?php echo dj_h(dj_hdr('ts_mtn_number')); ?></h1>
              <?php foreach ($machines as $k => $mc) { ?>
              <span class="mn-dj2-status is-<?php echo mn_dj_status_tone($mc['status']); ?>" data-dj-m="<?php echo (int) $k; ?>"<?php echo $k > 0 ? ' hidden' : ''; ?>><i class="fa <?php echo $mc['status'] === 'เสร็จสิ้น' ? 'fa-check' : 'fa-hourglass-half'; ?>" aria-hidden="true"></i> <?php echo dj_h($mc['status'] !== '' ? $mc['status'] : 'ไม่ระบุสถานะ'); ?></span>
              <?php } ?>
            </div>
          </div>
          <div class="mn-dj2-job-rows">
            <div class="mn-dj2-row"><span>รับเรื่อง</span><b><?php echo dj_hfield('ts_date', dj_hdr('ts_date'), 'type="date"'); ?><span class="mn-dj2-by"> โดย <?php echo dj_val(dj_hdr('ts_user_recive')); ?></span></b></div>
            <div class="mn-dj2-row"><span>เครื่องในงาน</span><b><?php echo count($machines); ?> เครื่อง</b></div>
            <div class="mn-dj2-row"><span>เครื่องสำรอง</span><b><?php echo dj_h($spare_text); ?></b></div>
          </div>
          <?php foreach ($machines as $k => $mc) { ?>
          <div class="mn-dj2-job-prog" data-dj-m="<?php echo (int) $k; ?>"<?php echo $k > 0 ? ' hidden' : ''; ?>>
            <div class="mn-dj2-prog-t"><i class="fa fa-tasks" aria-hidden="true"></i> ความคืบหน้า <small>เครื่อง <?php echo (int) ($k + 1); ?> <?php echo dj_h($mc['product']); ?></small></div>
            <?php echo dj_steps_html($mc['steps']); ?>
          </div>
          <?php } ?>
        </div>

        <div class="mn-dj2-card mn-dj2-c">
          <h2><i class="fa fa-building" aria-hidden="true"></i> ลูกค้า <span class="mn-dj2-sp"></span>
            <?php if (!$dj_header_edit_locked) { ?>
            <span id="mn-dj-header-actions-wrap" class="mn-dj-header-actions-wrap">
              <button type="button" id="mn-dj-header-edit-btn" class="mn-dj-header-edit-btn"><i class="fa fa-edit" aria-hidden="true"></i> แก้ไขข้อมูล</button>
              <span id="mn-dj-header-edit-actions" class="mn-dj-header-edit-actions" aria-hidden="true">
                <button type="button" id="mn-dj-header-save-btn" class="mn-dj-header-save-btn"><i class="fa fa-save" aria-hidden="true"></i> บันทึก</button>
                <button type="button" id="mn-dj-header-cancel-btn" class="mn-dj-header-cancel-btn"><i class="fa fa-times" aria-hidden="true"></i> ยกเลิก</button>
              </span>
            </span>
            <?php } ?>
          </h2>
          <?php if ($dj_header_edit_locked) { ?>
          <p class="mn-dj2-locked"><i class="fa fa-lock" aria-hidden="true"></i> งานซ่อมสถานะ "เสร็จสิ้น" แล้ว แก้ข้อมูลหัวใบงานไม่ได้</p>
          <?php } ?>
          <div class="mn-dj2-fields">
            <div class="mn-dj2-f search-box-user"><small>บริษัท End user / สถานที่</small><?php echo dj_hfield('ts_enduser_com', dj_hdr('ts_enduser_com'), 'type="text" autocomplete="off"'); ?><div class="result"></div></div>
            <div class="mn-dj2-f search-box-sec"><small>บริษัท รปภ.</small><?php echo dj_hfield('ts_security_com', dj_hdr('ts_security_com'), 'type="text" autocomplete="off"'); ?><div class="result_sec"></div></div>
          </div>
        </div>

        <div class="mn-dj2-card mn-dj2-c">
          <h2><i class="fa fa-user" aria-hidden="true"></i> ผู้ติดต่อ</h2>
          <?php foreach (array('report' => 'ผู้แจ้ง', 'approve' => 'ผู้อนุมัติ') as $pk => $plabel) { ?>
          <?php // ผู้อนุมัติ: ไม่มีขั้นตอนให้ลง ซ่อนไว้ แต่คงช่องไว้ให้ detail_job-page.js ส่งค่าเดิมกลับตอนบันทึก (ไม่ล้างข้อมูลเก่า) ?>
          <div class="mn-dj2-person"<?php echo $pk === 'approve' ? ' hidden' : ''; ?>>
            <div class="mn-dj2-person-t"><?php echo dj_h($plabel); ?></div>
            <div class="mn-dj2-fields mn-dj2-fields--2">
              <div class="mn-dj2-f"><small>ชื่อ</small><?php echo dj_hfield('ts_' . $pk . '_name', dj_hdr('ts_' . $pk . '_name'), 'type="text"'); ?></div>
              <div class="mn-dj2-f"><small>โทร</small><?php echo dj_hfield('ts_' . $pk . '_tel', dj_hdr('ts_' . $pk . '_tel'), 'type="text"'); ?></div>
              <div class="mn-dj2-f"><small>Line</small><?php echo dj_hfield('ts_' . $pk . '_line', dj_hdr('ts_' . $pk . '_line'), 'type="text"'); ?></div>
              <div class="mn-dj2-f"><small>Email</small><?php echo dj_hfield('ts_' . $pk . '_email', dj_hdr('ts_' . $pk . '_email'), 'type="text"'); ?></div>
            </div>
          </div>
          <?php } ?>
        </div>

        <div class="mn-dj2-card mn-dj2-c">
          <h2><i class="fa fa-map-marker" aria-hidden="true"></i> ที่อยู่จัดส่ง</h2>
          <div class="mn-dj2-f search-box-tambon"><?php echo dj_hfield('ts_address2', dj_hdr('ts_address'), 'type="text" placeholder="ค้นหา ตำบล"'); ?><div class="result_tambon"></div></div>
          <h2 class="mn-dj2-h2-gap"><i class="fa fa-sticky-note-o" aria-hidden="true"></i> หมายเหตุ</h2>
          <div class="mn-dj2-f"><?php echo dj_hfield('ts_remarks', dj_hdr('ts_remarks'), 'type="text"'); ?></div>
        </div>

        <a class="mn-dj2-back" href="index"><i class="fa fa-angle-left" aria-hidden="true"></i> กลับหน้าแรก</a>
      </aside>

      <!-- ===== คอลัมน์ขวา: แท็บเครื่อง ===== -->
      <main class="mn-dj2-main">
        <div class="mn-dj2-tabs" role="tablist"<?php echo count($machines) < 2 ? ' data-single="1"' : ''; ?>>
          <?php foreach ($machines as $k => $mc) { ?>
          <button type="button" role="tab" class="mn-dj2-tab<?php echo $k === 0 ? ' is-on' : ''; ?>" data-dj-tab="<?php echo (int) $k; ?>" aria-selected="<?php echo $k === 0 ? 'true' : 'false'; ?>">เครื่อง <?php echo (int) ($k + 1); ?> <?php echo dj_h($mc['product']); ?></button>
          <?php } ?>
        </div>

        <?php foreach ($machines as $k => $mc) {
            $r = $mc['row'];
            $f = function ($key) use ($r) { return trim(maintenance_flow_db_text_to_utf8((string) maintenance_status_logistic_row($r, $key))); };
            $ins = $f('trp_insurance');
            $ins_in = $ins === 'อยู่ในประกัน' || $ins === 'อยู่ในประกันซ่อม';
            $hist = $mc['history'];
            $rep = $mc['report'];
            ?>
        <div class="mn-dj2-card mn-dj2-machine" data-dj-m="<?php echo (int) $k; ?>"<?php echo $k > 0 ? ' hidden' : ''; ?>>
          <div class="mn-dj2-mh">
            <span class="mn-dj2-pn"><?php echo dj_h($mc['product']); ?></span>
            <span class="mn-dj2-sn">S/N <?php echo dj_val($mc['sn']); ?></span>
            <?php if ($ins !== '') { ?><span class="mn-dj2-chip <?php echo $ins_in ? 'is-blue' : 'is-gray'; ?>"><?php echo dj_h($ins); ?></span><?php } ?>
            <span class="mn-dj2-sp"></span>
            <button type="button" class="mn-dj2-btn is-hist" data-dj-hist="<?php echo (int) $k; ?>"<?php echo $hist ? '' : ' disabled="disabled" title="ไม่พบประวัติซ่อมของ S/N นี้"'; ?>><i class="fa fa-history" aria-hidden="true"></i> ประวัติซ่อม <b><?php echo count($hist); ?></b></button>
            <a class="mn-dj2-btn is-blue mn-dj-process-link" href="job_process?trp_id=<?php echo (int) $mc['trp_id']; ?>" title="ดูความคืบหน้าแนวตั้ง"><i class="fa fa-list-ol" aria-hidden="true"></i> ความคืบหน้า</a>
            <?php if ($mc['pdf']) { ?><a class="mn-dj2-btn is-pdf" href="assess_repair?trp_id=<?php echo (int) $mc['trp_id']; ?>&amp;pdf=1" title="เปิดใบประเมินสภาพเครื่อง แก้ข้อความได้ก่อนบันทึกเป็น PDF"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> ใบประเมิน PDF</a><?php } ?>
          </div>

          <div class="mn-dj2-sets">
            <?php
            // 1) ข้อมูลเครื่อง / รับเครื่อง
            $btns = '';
            if ($mc['can_machine']) {
                $btns .= '<button type="button" class="mn-dj-repair-machine-edit-btn" data-ts-id="' . (int) $ts_id . '" data-trp-id="' . (int) $mc['trp_id'] . '"'
                    . ' data-product="' . dj_h($mc['product']) . '" data-sn="' . dj_h($mc['sn']) . '" data-inform="' . dj_h($mc['inform']) . '"'
                    . ' data-buy-date="' . dj_h($mc['buy_input']) . '" data-buy-date-display="' . dj_h($mc['buy_display']) . '"><i class="fa fa-edit"></i> แก้ไขข้อมูลเครื่อง</button>';
            }
            if ($mc['can_receive']) {
                $btns .= '<button type="button" class="mn-dj-repair-receive-edit-btn" data-ts-id="' . (int) $ts_id . '" data-trp-id="' . (int) $mc['trp_id'] . '"'
                    . ' data-receive-date="' . dj_h($mc['receive_input']) . '" data-receive-date-display="' . dj_h($mc['receive_display']) . '"'
                    . ' data-user-recive-ma="' . dj_h($f('trp_user_recive_ma')) . '"><i class="fa fa-edit"></i> แก้ไขวันที่รับ</button>';
            }
            $recv = mn_dj_date($f('trp_receive_date'));
            echo dj_set_open(dj_set_state($mc, array('receive'), $recv !== ''), 'fa-desktop', 'ข้อมูลเครื่อง / รับเครื่อง', dj_meta(array(array('รับเครื่อง', $f('trp_receive_date'), $f('trp_user_recive_ma')))), $btns);
            echo '<div class="mn-dj2-grid">'
                . dj_kv('วันที่ซื้อ', dj_val(dj_date_or($f('trp_buy_date'))))
                . dj_kv('อายุเครื่อง', dj_val(trim($f('trp_repair_age') . ' ' . $ins)))
                . dj_kv('อาการที่แจ้ง', '<span class="mn-dj2-pre">' . dj_val($mc['inform_view']) . '</span>', 'is-w4')
                . '</div></div></section>';

            // 1.1) เครื่องสำรอง (ต่อจากข้อมูลเครื่อง/รับเครื่อง) — แบบ S1: แต่ละเครื่องมีสถานะ + ไทม์ไลน์ + ปุ่มตามสถานะ
            $btns = '';
            $chip = '';
            $units = $mc['spares'];
            // เครื่องที่ยังใช้งาน (ไม่จบ) ขึ้นก่อน แล้วเรียงใหม่ → เก่า
            usort($units, function ($x, $y) {
                // ลำดับ: เครื่องที่ใช้งานอยู่ (มี S/N จริง) → แถวรอระบุ S/N → เครื่องที่จบแล้ว
                $rank = function ($u) {
                    if (in_array($u['state'], array('back', 'cancel', 'lost'), true)) { return 2; }
                    return ($u['sn'] === '' || stripos($u['sn'], 'Waiting') === 0) ? 1 : 0;
                };
                $dx = $rank($x);
                $dy = $rank($y);
                if ($dx !== $dy) { return $dx - $dy; }
                return $y['tsp_id'] - $x['tsp_id'];
            });
            if ($units) {
                $cur = $units[0];
                $cm = mn_spare_state_meta($cur['state']);
                $chip = '<span class="mn-dj2-chip mn-sp-st is-' . dj_h($cur['state']) . '"><i class="fa ' . $cm[1] . '" aria-hidden="true"></i> ' . dj_h($cm[0]) . '</span>';
                $n_wait = 0;
                foreach ($units as $k => $u) { if ($k > 0 && $u['state'] === 'wait') { $n_wait++; } }
                if ($n_wait > 0) { $chip .= '<span class="mn-dj2-chip mn-sp-st is-wait">รอรับคืน ' . $n_wait . '</span>'; }
                if (count($units) > 1) { $chip .= '<span class="mn-dj2-chip is-gray">' . count($units) . ' เครื่อง</span>'; }
            } elseif ($mc['spare_add_href'] !== null) {
                $btns .= '<a class="mn-dj-edit-spare-btn" href="' . dj_h($mc['spare_add_href']) . '" title="เพิ่มเครื่องสำรองให้รายการซ่อมนี้"><i class="fa fa-plus"></i> เพิ่มเครื่องสำรอง</a>';
            }
            echo '<section class="mn-dj2-set is-spare" data-sp-trp="' . (int) $mc['trp_id'] . '" data-sp-product="' . dj_h($mc['product']) . '" data-sp-opts="' . dj_h(json_encode($mc['spare_sn_opts'], JSON_UNESCAPED_UNICODE)) . '">'
                . '<div class="mn-dj2-set-h"><span class="mn-dj2-set-ic"><i class="fa fa-exchange" aria-hidden="true"></i></span><b>เครื่องสำรอง</b>' . $chip
                . '<span class="mn-dj2-sp"></span>' . $btns . '</div><div class="mn-dj2-set-b">';
            if (!$units) {
                echo '<p class="mn-dj2-pendtxt">ไม่มีเครื่องสำรอง</p>';
            } else {
                $ACT_IC = array('send' => 'fa-truck', 'pickup' => 'fa-user', 'delivered' => 'fa-check', 'bounce' => 'fa-reply', 'change' => 'fa-exchange', 'swap' => 'fa-exchange',
                    'return' => 'fa-undo', 'cancel' => 'fa-ban', 'lost' => 'fa-question');
                foreach ($units as $k => $u) {
                    $um = mn_spare_state_meta($u['state']);
                    $sn_wait = $u['sn'] === '' || stripos($u['sn'], 'Waiting') === 0;
                    echo '<div class="mn-sp-unit' . ($k === 0 ? ' is-cur' : '') . ' is-' . dj_h($u['state']) . '">'
                        . '<div class="mn-sp-u1"><span class="mn-sp-sn">' . ($sn_wait ? '<span class="mn-dj2-empty">ยังไม่ระบุ S/N</span>' : dj_h($u['sn'])) . '</span>'
                        . '<span class="mn-dj2-chip mn-sp-st is-' . dj_h($u['state']) . '"><i class="fa ' . $um[1] . '" aria-hidden="true"></i> ' . dj_h($um[0]) . '</span></div>';
                    if ($u['timeline']) {
                        echo '<ul class="mn-sp-ev">';
                        foreach ($u['timeline'] as $ti => $it) {
                            $txt = dj_h($it['text']);
                            if (!empty($it['tracking'])) {
                                $tid = 'tracking_' . (int) $mc['i'] . '_' . (int) $u['tsp_id'] . '_' . $ti;
                                $txt = dj_h($it['logistics']) . ' <input id="' . $tid . '" class="mn-dj2-track" value="' . dj_h($it['tracking']) . '" onclick="copy_text(this.id, '
                                    . dj_h(json_encode($it['logistics'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)) . ');" title="คลิกเพื่อ copy และเปิดหน้า track ขนส่ง" readonly="readonly" />';
                            }
                            echo '<li class="is-' . dj_h($it['tone']) . '"><b>' . dj_h($it['title']) . '</b> ' . $txt
                                . (($it['date'] !== '' || $it['by'] !== '') ? ' <span class="mn-sp-w">' . dj_h(trim($it['date'] . ' ' . $it['by'])) . '</span>' : '') . '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p class="mn-sp-none">ยังไม่ส่ง</p>';
                    }
                    $can_edit_send = !$dj_repair_row_edit_locked && ($u['state'] === 'ship' || $u['state'] === 'cust') && strcasecmp($u['raw']['tsp_logistics'], 'Customer') !== 0;
                    if ($u['actions'] || $can_edit_send) {
                        echo '<div class="mn-sp-acts">';
                        foreach ($u['actions'] as $ak => $al) {
                            if ($ak === 'change' && $sn_wait) { $al = 'เลือก S/N'; }
                            if (($ak === 'send' || $ak === 'pickup') && $sn_wait) { continue; }
                            echo '<button type="button" class="mn-sp-act' . ($ak === 'send' || ($ak === 'change' && $sn_wait) ? ' is-pri' : '') . '" data-sp-act="' . dj_h($ak) . '" data-sp-tsp="' . (int) $u['tsp_id'] . '"'
                                . ' data-sp-sn="' . dj_h($u['sn']) . '" data-sp-state="' . dj_h($u['state']) . '"><i class="fa ' . $ACT_IC[$ak] . '" aria-hidden="true"></i> ' . dj_h($al) . '</button>';
                        }
                        if ($can_edit_send) {
                            // แก้ข้อมูลการส่งครั้งล่าสุด (modal เดิม)
                            echo '<button type="button" class="mn-sp-act is-quiet mn-dj-spare-send-edit-btn" data-ts-id="' . (int) $ts_id . '" data-trp-id="' . (int) $mc['trp_id'] . '" data-tsp-id="' . (int) $u['tsp_id'] . '"'
                                . ' data-datesend="' . dj_h($u['datesend_input']) . '" data-datesend-display="' . dj_h(trim(maintenance_flow_db_text_to_utf8($u['raw']['tsp_datesend']))) . '"'
                                . ' data-logistics="' . dj_h($u['raw']['tsp_logistics']) . '" data-sendby="' . dj_h($u['raw']['tsp_sendby'] !== '' ? $u['raw']['tsp_sendby'] : $mn_logistic_recorder_value) . '"><i class="fa fa-edit" aria-hidden="true"></i> แก้ข้อมูลส่ง</button>';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                }
            }
            echo '</div></section>';

            // 2) ผลประเมิน
            $btns = '';
            if ($mc['can_rate']) {
                $btns .= '<a class="mn-dj-edit-rate-btn" href="update_rate?trp_id=' . (int) $mc['trp_id'] . '"><i class="fa fa-edit"></i> แก้ไขงานประเมิน</a>';
            } elseif ($mc['can_rate_qv']) {
                $btns .= '<a class="mn-dj-edit-rate-btn" href="assess_repair?trp_id=' . (int) $mc['trp_id'] . '" title="เสนอราคาแล้ว ต้องใส่รหัสตั้งค่า ใบเสนอราคาเดิมไม่เปลี่ยน"><i class="fa fa-lock"></i> แก้ไขงานประเมิน (ใส่รหัส)</a>';
            }
            $rate_date = mn_dj_date($f('trp_rate_date'));
            echo dj_set_open(dj_set_state($mc, array('rate'), $mc['rate_has']), 'fa-stethoscope', 'ผลประเมิน', dj_meta(array(array('', $f('trp_rate_date'), $f('trp_user_rate')))), $btns);
            if (!$mc['rate_has']) {
                echo '<p class="mn-dj2-pendtxt">ยังไม่ได้ประเมิน</p>';
            } else {
                if ($rep['ok']) {
                    echo '<table class="mn-dj2-rt"><thead><tr><th>ข้อตรวจ</th><th>ผลที่พบ</th><th>สถานะ</th></tr></thead><tbody>';
                    foreach ($rep['groups'] as $gi => $g) {
                        echo '<tr class="is-g"><td colspan="3">' . ($gi + 1) . '. ' . dj_h($g['name']) . '</td></tr>';
                        foreach ($g['lines'] as $ln) {
                            $tone = mn_dj_line_tone($mc['tones'], $ln['label'], $ln['text']);
                            echo '<tr><td>' . dj_h($ln['label']) . '</td><td class="is-' . ($tone !== '' ? $tone : 'none') . '">' . dj_h($ln['text']) . '</td><td class="mn-dj2-st">'
                                . ($tone !== '' ? '<span class="mn-dj2-ic is-' . $tone . '">' . $TONE_IC[$tone] . '</span>' . $TONE_TXT[$tone] : '') . '</td></tr>';
                        }
                        if ($g['fix'] !== '') {
                            echo '<tr class="is-p"><td colspan="3"><i class="fa fa-wrench" aria-hidden="true"></i> แนวทางการแก้ไข: ' . dj_h($g['fix']) . '</td></tr>';
                        }
                    }
                    echo '</tbody></table>';
                    if ($rep['comment'] !== '') {
                        echo '<div class="mn-dj2-sub">ความเห็นช่าง</div><div class="mn-dj2-note mn-dj2-pre">' . dj_h($rep['comment']) . '</div>';
                    }
                } elseif ($rep['raw'] !== '') {
                    echo '<div class="mn-dj2-sub">ข้อมูลช่างประเมิน</div><div class="mn-dj2-note mn-dj2-pre">' . dj_h($rep['raw']) . '</div>';
                }
                // รูปที่แนบตอนประเมิน (cl_photo) กดดูใหญ่/ดาวน์โหลดได้
                if (!empty($mc['photos'])) {
                    echo '<div class="mn-dj2-sub">รูปประกอบการประเมิน ' . count($mc['photos']) . ' รูป</div><div class="mn-dj2-photos">' . mn_photo_strip_html($mc['photos'], 12, (int) $mc['trp_id']) . '</div>';
                }
                $parts = array();
                for ($pi = 1; $pi <= 10; $pi++) {
                    $p = $f('trp_repair_no' . $pi);
                    if ($p !== '') { $parts[] = $p; }
                }
                $extra = array();
                foreach (array('trp_clean', 'trp_repeat_solder', 'trp_spray', 'trp_fw') as $ek) {
                    if ($f($ek) !== '') { $extra[] = $f($ek); }
                }
                if ($parts || $extra) {
                    echo '<div class="mn-dj2-sub">รายการที่เสนอเปลี่ยน/ซ่อม ' . count($parts) . ' รายการ</div><div class="mn-dj2-parts">';
                    foreach ($parts as $p) { echo '<span>' . dj_h($p) . '</span>'; }
                    foreach ($extra as $p) { echo '<span class="is-x">' . dj_h($p) . '</span>'; }
                    echo '</div>';
                }
                if ($f('trp_repair_remarks') !== '') {
                    echo '<div class="mn-dj2-sub">หมายเหตุซ่อม</div><div class="mn-dj2-note mn-dj2-pre">' . dj_h($f('trp_repair_remarks')) . '</div>';
                }
            }
            echo '</div></section>';

            // 3) ใบเสนอราคา / อนุมัติ
            $btns = '';
            if ($mc['can_qv']) {
                $btns .= '<button type="button" class="mn-dj-repair-qv-edit-btn" data-ts-id="' . (int) $ts_id . '" data-trp-id="' . (int) $mc['trp_id'] . '"'
                    . ' data-qv-number="' . dj_h($f('trp_qv_number')) . '" data-qv-date="' . dj_h($mc['qv_date_input']) . '" data-qv-date-display="' . dj_h($f('trp_qv_date')) . '"'
                    . ' data-qv-price="' . dj_h($f('trp_qv_price')) . '" data-qv-send="' . dj_h($f('trp_qv_send')) . '"'
                    . ' data-firm-qv-date="' . dj_h($mc['firm_input']) . '" data-firm-qv-date-display="' . dj_h($f('trp_firm_qv_date')) . '"><i class="fa fa-edit"></i> แก้ไขใบเสนอราคา</button>';
            }
            $qv_no = $f('trp_qv_number');
            $qv_has = $qv_no !== '' || mn_dj_date($f('trp_qv_date')) !== '';
            $firm = $mc['firm'];
            $firm_html = $firm === '' ? dj_val('') : '<span class="mn-dj2-chip ' . ($firm === 'อนุมัติซ่อม' ? 'is-ok' : 'is-no') . '">' . dj_h($firm) . '</span>';
            echo dj_set_open(dj_set_state($mc, array('qv', 'firm'), $qv_has), 'fa-file-text-o', 'ใบเสนอราคา / อนุมัติ', dj_meta(array(
                array('เสนอราคา', $f('trp_qv_date'), isset($mc['stamp']['qv']) ? $mc['stamp']['qv']['by'] : '', false),
                array('อนุมัติ', $f('trp_firm_qv_date'), isset($mc['stamp']['firm']) ? $mc['stamp']['firm']['by'] : '', false))), $btns);
            if (!$qv_has) {
                echo '<p class="mn-dj2-pendtxt">ยังไม่ถึงขั้นนี้</p>';
            } else {
                echo '<div class="mn-dj2-grid">'
                    . dj_kv('เลขที่', dj_val($qv_no === '-' ? 'ไม่เสนอราคา (ในประกัน)' : $qv_no))
                    . dj_kv('จำนวนเงิน', dj_val($f('trp_qv_price') !== '' && $f('trp_qv_price') !== '0' ? $f('trp_qv_price') . ' บาท' : ''))
                    . dj_kv('ส่งทาง', dj_val($f('trp_qv_send')))
                    . dj_kv('ผลอนุมัติ', $firm_html)
                    . ($f('trp_backup') !== '' ? dj_kv('หมายเหตุอนุมัติ', dj_h($f('trp_backup')), 'is-w2') : '')
                    . '</div>';
            }
            echo '</div></section>';

            // 4) ซ่อม
            $btns = '';
            if ($mc['can_ma']) {
                $btns .= '<button type="button" class="mn-dj-repair-ma-edit-btn" data-ts-id="' . (int) $ts_id . '" data-trp-id="' . (int) $mc['trp_id'] . '"'
                    . ' data-success-date="' . dj_h($mc['success_input']) . '" data-success-date-display="' . dj_h($f('trp_success_date')) . '" data-user-ma="' . dj_h($f('trp_user_ma')) . '"><i class="fa fa-edit"></i> แก้ไขวันที่ซ่อม/ช่างซ่อม</button>';
            }
            $ma_has = mn_dj_date($f('trp_success_date')) !== '';
            // ขั้นซ่อมเก็บแค่วันที่ + ช่าง → แสดงเป็นหัวกรอบอย่างเดียว ไม่มีเนื้อกรอบ
            $ma_head = dj_set_open(dj_set_state($mc, array('ma'), $ma_has), 'fa-wrench', 'ซ่อม', dj_meta(array(array('', $f('trp_success_date'), $f('trp_user_ma')))), $btns);
            echo str_replace(array('<section class="mn-dj2-set ', '<div class="mn-dj2-set-b">'), array('<section class="mn-dj2-set is-compact ', ''), $ma_head) . '</section>';

            // 4.1) QC (รายละเอียดจากตาราง logqc_* ตามประเภทสินค้า)
            $btns = '';
            if ($mc['can_qc']) {
                $btns .= '<button type="button" class="mn-dj-repair-qc-edit-btn" data-ts-id="' . (int) $ts_id . '" data-trp-id="' . (int) $mc['trp_id'] . '"'
                    . ' data-qc-date="' . dj_h($mc['qc_input']) . '" data-qc-date-display="' . dj_h($f('trp_qc_date')) . '" data-qc-user="' . dj_h($f('trp_qc_user')) . '"'
                    . ' data-qc-comment="' . dj_h(maintenance_flow_db_text_to_utf8((string) maintenance_status_logistic_row($r, 'trp_qc_comment'))) . '"><i class="fa fa-edit"></i> แก้ไขข้อมูล QC</button>';
            }
            $qc = $mc['qc'];
            $qc_date_raw = $f('trp_qc_date');
            $qc_skip = $qc_date_raw === '-';
            $qc_has = mn_dj_date($qc_date_raw) !== '' || $qc !== null || $qc_skip;
            echo str_replace('<section class="mn-dj2-set ', '<section class="mn-dj2-set is-qc ', dj_set_open(dj_set_state($mc, array('qc'), $qc_has), 'fa-check-square-o', 'QC', $qc_skip ? '<span class="mn-dj2-meta is-none">ไม่ได้ QC</span>' : dj_meta(array(array('', $qc_date_raw, $f('trp_qc_user')))), $btns));
            if (!$qc_has) {
                echo '<p class="mn-dj2-pendtxt">ยังไม่ถึงขั้นนี้</p>';
            } elseif ($qc_skip) {
                echo '<p class="mn-dj2-pendtxt">ขั้นนี้ไม่ได้ตรวจ QC (บันทึกเป็น "-")</p>';
            } else {
                $qc_comment = $f('trp_qc_comment') !== '' ? $f('trp_qc_comment') : ($qc ? $qc['comment'] : '');
                if ($qc && $qc['checked'] > 0) {
                    $pass = $qc['bad'] === 0;
                    echo '<div class="mn-dj2-qc-sum"><span class="mn-dj2-qc-pass' . ($pass ? '' : ' is-bad') . '"><i class="fa ' . ($pass ? 'fa-check-circle' : 'fa-times-circle') . '" aria-hidden="true"></i> '
                        . ($pass ? 'ผ่าน QC' : 'ไม่ผ่าน ' . (int) $qc['bad'] . ' ข้อ') . '</span><span class="mn-dj2-qc-count">ตรวจ ' . (int) $qc['checked'] . ' ข้อ</span></div>';
                }
                if ($qc) {
                    foreach ($qc['groups'] as $g) {
                        echo '<div class="mn-dj2-qc-g">' . dj_h($g[0]) . '</div><div class="mn-dj2-qc">';
                        foreach ($g[1] as $it) {
                            echo '<div class="' . ($it[2] !== '' ? 'is-' . $it[2] : '') . '"><small>' . dj_h($it[0]) . '</small><b>' . ($it[1] !== '' ? dj_h($it[1]) : '<span class="mn-dj2-empty">ไม่ได้กรอก</span>') . '</b></div>';
                        }
                        echo '</div>';
                    }
                } else {
                    echo '<p class="mn-dj2-pendtxt">ไม่พบรายละเอียดผลตรวจ QC ของรายการนี้</p>';
                }
                if ($qc_comment !== '') {
                    echo '<div class="mn-dj2-sub">Comment QC</div><div class="mn-dj2-note mn-dj2-pre">' . dj_h($qc_comment) . '</div>';
                }
            }
            echo '</div></section>';

            // 5) ส่งคืนลูกค้า
            $btns = '';
            if ($mc['can_return']) {
                $btns .= '<button type="button" class="mn-dj-repair-return-edit-btn" data-ts-id="' . (int) $ts_id . '" data-trp-id="' . (int) $mc['trp_id'] . '"'
                    . ' data-send-date="' . dj_h($mc['send_input']) . '" data-send-date-display="' . dj_h($f('trp_send_date')) . '" data-logistics="' . dj_h($f('trp_logistics')) . '"'
                    . ' data-tracking="' . dj_h($f('trp_tracking')) . '" data-sendby="' . dj_h($f('trp_sendby')) . '"><i class="fa fa-edit"></i> แก้ไขข้อมูลส่งคืน</button>';
            }
            $send_has = mn_dj_date($f('trp_send_date')) !== '';
            echo dj_set_open(dj_set_state($mc, array('send'), $send_has), 'fa-truck', 'ส่งคืนลูกค้า', dj_meta(array(array('', $f('trp_send_date'), $f('trp_sendby')))), $btns);
            if (!$send_has) {
                echo '<p class="mn-dj2-pendtxt">ยังไม่ถึงขั้นนี้</p>';
            } else {
                $trk_raw = (string) maintenance_status_logistic_row($r, 'trp_tracking');
                $trk = trim($trk_raw) !== '' ? '<input id="trackingMA_' . (int) $mc['i'] . '" class="mn-dj2-track" value="' . dj_h($trk_raw) . '" onclick="copy_text(this.id, ' . dj_h(json_encode((string) maintenance_status_logistic_row($r, 'trp_logistics'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)) . ');" title="คลิกเพื่อ copy และเปิดหน้า track ขนส่ง" readonly="readonly" />' : dj_val('');
                echo '<div class="mn-dj2-grid">'
                    . dj_kv('ขนส่ง', dj_val($f('trp_logistics')))
                    . dj_kv('Tracking', $trk)
                    . '</div>';
            }
            echo '</div></section>';

            // 7) Comment (id/ฟังก์ชันเดิม)
            ?>
            <section class="mn-dj2-set is-note">
              <div class="mn-dj2-set-h"><span class="mn-dj2-set-ic"><i class="fa fa-comment-o" aria-hidden="true"></i></span><b>Comment</b><?php
                if (isset($mc['stamp']['comment'])) {
                    echo dj_meta(array(array('', $mc['stamp']['comment']['at'], $mc['stamp']['comment']['by'])));
                } elseif (trim(maintenance_flow_db_text_to_utf8((string) maintenance_status_logistic_row($r, 'trp_other_comment'))) !== '') {
                    echo '<span class="mn-dj2-meta is-none"><i class="fa fa-info-circle" aria-hidden="true"></i> บันทึกก่อนเริ่มเก็บชื่อผู้บันทึก</span>';
                } ?></div>
              <div class="mn-dj2-set-b">
                <textarea name="trp_other_comment<?php echo dj_h($mc['n']); ?>" id="trp_other_comment<?php echo dj_h($mc['n']); ?>" class="mn-dj2-comment" rows="3" placeholder="บันทึกเพิ่มเติมของเครื่องนี้"><?php echo dj_h(maintenance_flow_db_text_to_utf8((string) maintenance_status_logistic_row($r, 'trp_other_comment'))); ?></textarea>
                <div class="mn-dj2-comment-act"><a href="javascript:void(0);" class="mn-dj2-btn is-solid" onclick="saveComment(<?php echo (int) $mc['trp_id']; ?>, <?php echo (int) $ts_id; ?>, <?php echo (int) $mc['n']; ?>);" title="บันทึก Comment"><i class="fa fa-save" aria-hidden="true"></i> บันทึก Comment</a></div>
              </div>
            </section>
          </div>

          <?php if ($hist) { ?>
          <!-- หน้าต่างประวัติซ่อม (ไทม์ไลน์ตามปี) -->
          <div class="mn-dj2-hp" data-dj-hp="<?php echo (int) $k; ?>" hidden="hidden" role="dialog" aria-modal="true" aria-label="ประวัติการซ่อม">
            <div class="mn-dj2-hp-box">
              <div class="mn-dj2-hp-head">
                <span class="mn-dj2-hp-ic"><i class="fa fa-history" aria-hidden="true"></i></span>
                <div><h2>ประวัติการซ่อม <?php echo count($hist); ?> ครั้ง</h2><small><?php echo dj_h($mc['product']); ?> S/N <?php echo dj_h($mc['sn']); ?> | ไม่รวมใบงานนี้</small></div>
                <span class="mn-dj2-sp"></span>
                <button type="button" class="mn-dj2-hp-x" data-dj-hp-close="1" aria-label="ปิด">&times;</button>
              </div>
              <div class="mn-dj2-hp-body"><div class="mn-dj2-tl">
                <?php
                $yr = '';
                foreach ($hist as $h) {
                    if ($h['year'] !== $yr) {
                        $yr = $h['year'];
                        echo '<div class="mn-dj2-tl-yr">ปี ' . dj_h($yr !== '' ? $yr : 'ไม่ทราบ') . '</div>';
                    }
                    $ap = $h['approve'];
                    $no = $ap === 'ไม่อนุมัติซ่อม';
                    $ap_html = $h['old'] ? '<span class="mn-dj2-chip is-gray">ข้อมูลระบบเดิม</span>'
                        : ($ap === '' ? '<span class="mn-dj2-chip is-gray">' . dj_h($h['status'] !== '' ? $h['status'] : 'ยังไม่อนุมัติ') . '</span>'
                        : '<span class="mn-dj2-chip ' . ($no ? 'is-no' : 'is-ok') . '"><i class="fa ' . ($no ? 'fa-times' : 'fa-check') . '"></i> ' . dj_h($ap) . '</span>');
                    $ins_h = $h['ins'] !== '' ? '<span class="mn-dj2-chip ' . (strpos($h['ins'], 'อยู่ใน') === 0 ? 'is-blue' : 'is-gray') . '">' . dj_h($h['ins']) . '</span>' : '';
                    echo '<div class="mn-dj2-hc' . ($no ? ' is-no' : '') . '"><div class="mn-dj2-hc-h"><span class="mn-dj2-hc-n">ครั้งที่ ' . (int) $h['n'] . '</span>'
                        . ($h['ts_id'] > 0 ? '<a href="detail_job?ts_id=' . (int) $h['ts_id'] . '" target="_blank">' . dj_h($h['no']) . '</a>' : '<b>' . dj_h($h['no']) . '</b>')
                        . '<span class="mn-dj2-hc-d">รับเครื่อง ' . dj_h($h['recv'] !== '' ? $h['recv'] : '—') . '</span><span class="mn-dj2-sp"></span>' . $ins_h . $ap_html . '</div><dl class="mn-dj2-hc-b">';
                    if ($h['company'] !== '') { echo '<dt>ลูกค้า</dt><dd>' . dj_h($h['company']) . '</dd>'; }
                    echo '<dt>อาการที่แจ้ง</dt><dd>' . dj_val($h['inform']) . '</dd>';
                    if ($h['tech'] !== '') { echo '<dt>ผลประเมิน</dt><dd><div class="mn-dj2-clamp mn-dj2-pre">' . dj_h($h['tech']) . '</div><button type="button" class="mn-dj2-more" data-dj-more="1">ดูทั้งหมด</button></dd>'; }
                    if ($h['parts'] || $h['extra']) {
                        echo '<dt>' . ($no ? 'ที่เสนอ (ไม่ได้ซ่อม)' : 'ซ่อม/เปลี่ยน') . '</dt><dd><div class="mn-dj2-parts">';
                        foreach ($h['parts'] as $p) { echo '<span' . ($no ? ' class="is-strike"' : '') . '>' . dj_h($p) . '</span>'; }
                        foreach ($h['extra'] as $p) { echo '<span class="is-x">' . dj_h($p) . '</span>'; }
                        echo '</div></dd>';
                    }
                    if ($h['qv'] !== '') { echo '<dt>ใบเสนอราคา</dt><dd>' . dj_h($h['qv']) . '</dd>'; }
                    if ($h['note'] !== '') { echo '<dt>หมายเหตุอนุมัติ</dt><dd><span class="mn-dj2-apnote">' . dj_h($h['note']) . '</span></dd>'; }
                    echo '</dl>';
                    $foot = array();
                    if ($h['rate'] !== '') { $foot[] = 'ประเมิน <b>' . dj_h($h['rate']) . '</b>'; }
                    if ($h['fix'] !== '') { $foot[] = ($h['old'] ? 'ผู้ซ่อม' : ($no ? 'ปิดงาน' : 'ซ่อมเสร็จ')) . ' <b>' . dj_h($h['fix']) . '</b>'; }
                    if ($h['send'] !== '') { $foot[] = 'ส่งคืน <b>' . dj_h($h['send']) . '</b>'; }
                    if ($foot) { echo '<div class="mn-dj2-hc-f"><span>' . implode('</span><span>', $foot) . '</span></div>'; }
                    echo '</div>';
                }
                ?>
              </div></div>
            </div>
          </div>
          <?php } ?>
        </div>
        <?php } ?>
      </main>
    </div>
  </div>
<script type="text/javascript" src="js/detail_job-layout.js?v=<?php echo filemtime(__DIR__ . '/js/detail_job-layout.js'); ?>"></script>
<script type="text/javascript" src="js/mn-photo-lightbox.js?v=<?php echo filemtime(__DIR__ . '/js/mn-photo-lightbox.js'); ?>"></script>
<script type="text/javascript" src="js/detail_job-spare.js?v=<?php echo filemtime(__DIR__ . '/js/detail_job-spare.js'); ?>"></script>
</body>
</html>
