<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รออนุมัติซ่อม» (หลังเสนอราคา QV) — แสดง transac + transac_repair ที่ `trp_status_job = รออนุมัติซ่อม`
 * Refactor จาก `old_system/status_wait_firmQV.php` — auth, theme mn-*, prepared statement, prefetch spare/tracking, URL ไม่มี .php
 *
 * Flow:
 * require_auth → bootstrap → รายชื่อบริษัท/สินค้า → ดึงรายการ + prefetch → render ตาราง (คอลัมน์ QV อ่านอย่างเดียว + ลิงก์ `detail_job`) → ปิด connection
 *
 * Example:
 * เปิด `/maintenance/status_wait_firmQV` หรือ `?company=...` / `?product=...` / `?qv=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_status_wait_firmQV_page.php';
require_once __DIR__ . '/includes/status_wait_firmQV_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$status_label = maintenance_wait_firmqv_status_literal();
$filters = maintenance_wait_firmqv_parse_get_filters($_GET);
$arr_com = request_company($status_label);
$arr_product = request_product($status_label);
$arr_length_com = count($arr_com);
$arr_length_product = count($arr_product);

$join_rows = maintenance_wait_firmqv_fetch_join_rows($connect, $filters);

$trp_ids = array();
foreach ($join_rows as $jr) {
    if (!empty($jr['trp_id'])) {
        $trp_ids[] = (int) $jr['trp_id'];
    }
}
$spare_by_trp = maintenance_wait_hardware_prefetch_spare_by_trp_ids($connect, $trp_ids);
$track_by_trp = maintenance_wait_hardware_prefetch_tracking_latest_by_trp_ids($connect, $trp_ids);
// รุ่นที่มีแบบประเมินใหม่ (checklist) — แสดงลิงก์ "แก้ผลประเมิน" (ต้องใส่รหัสตั้งค่าในหน้า assess_repair)
require_once __DIR__ . '/includes/checklist_helpers.php';
$assess_ok = array();
foreach ($join_rows as $jr) {
    $p = isset($jr['trp_product']) ? (string) $jr['trp_product'] : '';
    if ($p !== '' && !isset($assess_ok[$p])) {
        $assess_ok[$p] = maintenance_checklist_product_supported($connect, $p);
    }
}
maintenance_db_odbc_close($connect);

$row_count = count($join_rows);
$qv_input_value = isset($_GET['qv']) ? (string) $_GET['qv'] : '';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-status-wait-firmQV.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รอ อนุมัติใบเสนอราคา</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/status_wait_firmQV-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-wait-firmQV-page">
  <div class="header">
    <?php
    $page = 'status_wait_firmQV';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-wait-firmQV-table" width="100%">
      <thead>
      <tr class="mn-status-wait-firmQV-row-under mn-status-wait-firmQV-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="15%"><div align="center">
          <b>บริษัท/ผู้ติดต่อ</b><br />
          <select class="custom-select" id="head_company" name="head_company" onchange="mnWaitFirmQvSortCompany(this.value);">
            <option value="">All</option>
            <?php
            for ($a = 0; $a < $arr_length_com; $a++) {
                $com_opt = (string) $arr_com[$a];
                $com_disp = strReplace($com_opt);
                $com_enc = maintenance_status_logistic_h($com_opt);
                $sel = ($filters['company'] === $com_opt) ? ' selected="selected"' : '';
                ?>
            <option value="<?php echo $com_enc; ?>"<?php echo $sel; ?>><?php echo maintenance_status_logistic_h($com_disp); ?></option>
                <?php
            }
            ?>
          </select>
        </div></th>
        <th width="15%"><div align="center">
          <b>สินค้า</b><br />
          <select class="custom-select" id="head_product" name="head_product" onchange="mnWaitFirmQvSortProduct(this.value);">
            <option value="">All</option>
            <?php
            for ($a = 0; $a < $arr_length_product; $a++) {
                $prod_opt = (string) $arr_product[$a];
                $prod_enc = maintenance_status_logistic_h($prod_opt);
                $selp = ($filters['product'] === $prod_opt) ? ' selected="selected"' : '';
                ?>
            <option value="<?php echo $prod_enc; ?>"<?php echo $selp; ?>><?php echo $prod_enc; ?></option>
                <?php
            }
            ?>
          </select>
        </div></th>
        <th width="10%" align="center">
          <b>ข้อมูลเสนอราคา</b><br />
          <div class="search-box-qv">
            <input type="text" id="search_qv" name="search_qv" size="10" value="<?php echo maintenance_status_logistic_h($qv_input_value); ?>" placeholder="หมายเลข QV" />
            <div class="result"></div>
          </div>
        </th>
        <th width="5%"><div align="center"><b>รอ</b></div></th>
        <th width="7%"><div align="center"><b>สถานะอนุมัติ</b></div></th>
        <th width="10%"><div align="center"><b>หมายเหตุ</b></div></th>
        <th width="8%"><div align="center"><b>วันที่อนุมัติ</b></div></th>
        <th width="5%"><div align="center"><b>ติดตาม</b></div></th>
        <th width="5%"><div align="center"><b>edit</b></div></th>
      </tr>
      </thead>
      <tbody>
      <?php
        $i = 0;
        foreach ($join_rows as $result_join) {
            $i += 1;
            $trp_id = (int) (isset($result_join['trp_id']) ? $result_join['trp_id'] : 0);
            $ts_id = (int) (isset($result_join['ts_id']) ? $result_join['ts_id'] : 0);
            $result_s = isset($spare_by_trp[$trp_id]) ? $spare_by_trp[$trp_id] : array();
            $has_spare = $result_s !== array();
            $detail_html = maintenance_wait_firmqv_contact_html($result_join);
            $tooltip_plain = maintenance_wait_firmqv_repair_tooltip_plain($result_join);
            $tooltip_title = maintenance_status_logistic_h($tooltip_plain);
            $result_th = isset($track_by_trp[$trp_id]) ? $track_by_trp[$trp_id] : array();
            $title_th = maintenance_wait_hardware_tracking_title_attr($result_th);
            $next_due = isset($result_th['th_date_nextdue']) ? $result_th['th_date_nextdue'] : '';
            $trp_product = isset($result_join['trp_product']) ? (string) $result_join['trp_product'] : '';
            $trp_sn = isset($result_join['trp_sn']) ? (string) $result_join['trp_sn'] : '';
            $tracking_product_q = rawurlencode($trp_product . ' ' . $trp_sn);
            $qv_date = isset($result_join['trp_qv_date']) ? (string) $result_join['trp_qv_date'] : '';
            $qv_send = isset($result_join['trp_qv_send']) ? (string) $result_join['trp_qv_send'] : '';
            $qv_price = isset($result_join['trp_qv_price']) ? (string) $result_join['trp_qv_price'] : '';
            $qv_number = isset($result_join['trp_qv_number']) ? (string) $result_join['trp_qv_number'] : '';
            ?>
      <tr id="row<?php echo (int) $i; ?>" title="<?php echo $tooltip_title; ?>" class="mn-status-wait-firmQV-row-under" style="border-collapse:collapse; background-color:#fff;" onmouseover="ChangeHoverBGColor(this)" onmouseout="RestoreHoverBGColor(this)">
        <td width="5%" align="center"><?php echo maintenance_status_logistic_h(isset($result_join['ts_mtn_number']) ? (string) $result_join['ts_mtn_number'] : ''); ?></td>
        <td width="20%" align="left"><a target="_blank" href="detail_job?ts_id=<?php echo $ts_id; ?>"><?php echo $detail_html; ?></a><br />
          <?php if ($trp_id > 0 && !empty($assess_ok[$trp_product])) { ?>
          <a class="mn-wfq-assess" href="assess_repair?trp_id=<?php echo (int) $trp_id; ?>" title="แก้ผลประเมินด้วยแบบประเมินใหม่ ต้องใส่รหัสตั้งค่า ใบเสนอราคาเดิมไม่เปลี่ยน"><i class="fa fa-lock" aria-hidden="true"></i> แก้ผลประเมิน</a>
          <?php } ?>
        </td>
        <td width="15%" align="left">
          <?php if ($has_spare) { ?>
          <div style="color: #FF0000;"><u><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> มีสำรอง </u></div>
          <?php } else { ?>
          <div style="color: darkgreen;">ไม่มีสำรอง</div>
          <?php } ?>
          <?php echo maintenance_status_logistic_h($trp_product); ?> &nbsp; <?php echo maintenance_status_logistic_h($trp_sn); ?><br />
          <span style="color:#FF0000">อายุเครื่อง <?php echo maintenance_status_logistic_h(isset($result_join['trp_repair_age']) ? (string) $result_join['trp_repair_age'] : ''); ?> </span><br />
          <span style="color:#0000FF"><?php echo maintenance_status_logistic_h(isset($result_join['trp_insurance']) ? (string) $result_join['trp_insurance'] : ''); ?></span>
        </td>
        <td width="8%" align="left"><?php echo maintenance_wait_firmqv_qv_read_cell_html($qv_date, $qv_send, $qv_price, $qv_number, $ts_id); ?></td>
        <td width="5%" align="center"><b><?php echo cal_wait_date($qv_date); ?></b></td>
        <td width="5%" align="center">
          <select class="custom-select" id="trp_status_firmma<?php echo (int) $i; ?>" name="trp_status_firmma<?php echo (int) $i; ?>">
            <option value="อนุมัติซ่อม" selected="selected">อนุมัติซ่อม</option>
            <option value="ไม่อนุมัติซ่อม">ไม่อนุมัติซ่อม</option>
          </select>
        </td>
        <td width="5%" align="center"><textarea name="trp_backup<?php echo (int) $i; ?>" id="trp_backup<?php echo (int) $i; ?>" rows="2" style="width:100%" placeholder="หมายเหตุ"></textarea></td>
        <td width="7%" align="center"><input type="date" name="trp_firm_qv_date<?php echo (int) $i; ?>" id="trp_firm_qv_date<?php echo (int) $i; ?>" value="" /></td>
        <td align="center" title="<?php echo maintenance_status_logistic_h($title_th); ?>">
          <a href="tracking_history?ts_id=<?php echo $ts_id; ?>&amp;trp_id=<?php echo (int) $trp_id; ?>&amp;tsp_id=0&amp;trp_product=<?php echo $tracking_product_q; ?>&amp;th_status_job=<?php echo rawurlencode('ติดตามใบเสนอราคา'); ?>"
             onclick="window.open(this.href,'targetWindow','toolbar=no, location=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=800, height=550'); return false;">
            <i class="fa fa-volume-control-phone" style="color:#0033FF;"></i>
          </a><br /><?php echo cal_tracking_due($next_due); ?>
        </td>
        <td width="5%" align="center">
          <a href="javascript:void(0);" onclick="mnWaitFirmQvSaveStatus(<?php echo (int) $trp_id; ?>,<?php echo (int) $i; ?>);"><div style="color:#FF9900;"><i class="fa fa-save fa-2x"></i></div></a>
        </td>
      </tr>
      <tr>
        <td colspan="10"><hr /></td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="10" style="color:#FF9900">รวมรายการ confirm ซ่อม <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    <br />
    </div>
  </div>
</body>
</html>
