<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รอเสนอราคา» — แสดง `transac` + `transac_repair` ที่ `trp_status_job = รอเสนอราคา` และ `trp_insurance = หมดประกัน`
 * พร้อม filter บริษัท/สินค้า และแสดงค่า QV/ราคาแบบอ่านอย่างเดียว — กรอกแก้ไขไปหน้า `edit_offer_price` แล้วบันทึกผ่าน `update_offer_price`
 * Refactor จาก `old_system/status_offer_price.php` ให้ใช้ `require_auth`, ธีม mn-*, prefetch `tracking_history`
 *
 * Flow:
 * require_auth → bootstrap → parse GET → SQL + fetch → prefetch tracking → ปิด `$connect` → render
 *
 * Example:
 * เปิด `status_offer_price` หรือ `status_offer_price?company=...` / `?product=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_offer_price_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$status_label = maintenance_status_offer_price_status_literal();
$filters = maintenance_status_offer_price_parse_get_filters($_GET);
$arr_com = request_company($status_label);
$arr_product = request_product($status_label);
$arr_length_com = count($arr_com);
$arr_length_product = count($arr_product);

$sql_join = maintenance_status_offer_price_build_list_sql($connect, $filters['company'], $filters['product']);
$join_rows = maintenance_wait_hardware_fetch_join_rows($connect, $sql_join);

$trp_ids = array();
foreach ($join_rows as $jr) {
    if (!empty($jr['trp_id'])) {
        $trp_ids[] = (int) $jr['trp_id'];
    }
}
$track_by_trp = maintenance_wait_hardware_prefetch_tracking_latest_by_trp_ids($connect, $trp_ids);
maintenance_db_odbc_close($connect);

$row_count = count($join_rows);
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
<link rel="stylesheet" href="css/mn-page-status-offer-price.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รอเสนอราคา</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/status_offer_price-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-offer-price-page">
  <div class="header">
    <?php
    $page = 'status_offer_price';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-offer-price-table" width="100%">
      <thead>
      <tr class="mn-status-offer-price-row-under mn-status-offer-price-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="5%"><div align="center"><b>วันที่ประเมิน</b></div></th>
        <th width="25%"><div align="center"><b>บริษัท/ผู้ติดต่อ/ที่อยู่</b><br />
          <select class="custom-select" id="mn_op_head_company" name="mn_op_head_company" onchange="mnStatusOfferPriceSortCompany(this.value);">
            <option value="">All</option>
            <?php
            for ($a = 0; $a < $arr_length_com; $a++) {
                $com_opt = (string) $arr_com[$a];
                $com_enc = maintenance_status_logistic_h($com_opt);
                $sel = ($filters['company'] === $com_opt) ? ' selected="selected"' : '';
                ?>
            <option value="<?php echo $com_enc; ?>"<?php echo $sel; ?>><?php echo $com_enc; ?></option>
                <?php
            }
            ?>
          </select>
        </div></th>
        <th width="15%"><div align="center"><b>สินค้า</b><br />
          <select class="custom-select" id="mn_op_head_product" name="mn_op_head_product" onchange="mnStatusOfferPriceSortProduct(this.value);">
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
        <th width="43%"><div align="center"><b>อาการช่างประเมิน / รายการซ่อม</b></div></th>
        <th width="8%"><div align="center"><b>เสนอราคา</b></div></th>
      </tr>
      </thead>
      <tbody>
      <?php
        $i = 0;
        foreach ($join_rows as $result_join) {
            $i += 1;
            $trp_id = (int) (isset($result_join['trp_id']) ? $result_join['trp_id'] : 0);
            $ts_id = (int) (isset($result_join['ts_id']) ? $result_join['ts_id'] : 0);
            $result_th = isset($track_by_trp[$trp_id]) ? $track_by_trp[$trp_id] : array();
            $title_th = maintenance_wait_hardware_tracking_title_attr($result_th);
            $next_due = isset($result_th['th_date_nextdue']) ? (string) $result_th['th_date_nextdue'] : '';
            $detail_html = maintenance_status_offer_price_contact_block_html($result_join);
            $addr = isset($result_join['ts_address']) ? (string) $result_join['ts_address'] : '';
            $list_repair_html = maintenance_status_offer_price_repair_list_html($result_join);
            $mtn = isset($result_join['ts_mtn_number']) ? (string) $result_join['ts_mtn_number'] : '';
            $rate_date = isset($result_join['trp_rate_date']) ? (string) $result_join['trp_rate_date'] : '';
            $trp_product = isset($result_join['trp_product']) ? (string) $result_join['trp_product'] : '';
            $trp_sn = isset($result_join['trp_sn']) ? (string) $result_join['trp_sn'] : '';
            $repair_age = isset($result_join['trp_repair_age']) ? (string) $result_join['trp_repair_age'] : '';
            $insurance_val = isset($result_join['trp_insurance']) ? (string) $result_join['trp_insurance'] : '';
            $tech = isset($result_join['trp_repair_technician']) ? (string) $result_join['trp_repair_technician'] : '';
            $tracking_product_q = rawurlencode($trp_product . ' ' . $trp_sn);
            $qv_d = isset($result_join['trp_qv_date']) ? trim((string) $result_join['trp_qv_date']) : '';
            $qv_s = isset($result_join['trp_qv_send']) ? trim((string) $result_join['trp_qv_send']) : '';
            $qv_p = isset($result_join['trp_qv_price']) ? trim((string) $result_join['trp_qv_price']) : '';
            $qv_n = isset($result_join['trp_qv_number']) ? trim((string) $result_join['trp_qv_number']) : '';
            ?>
      <tr id="row<?php echo (int) $i; ?>" class="mn-status-offer-price-row-under" style="border-collapse:collapse; background-color:#fff;" onmouseover="ChangeHoverBGColor(this)" onmouseout="RestoreHoverBGColor(this)">
        <td width="5%" align="center"><?php echo maintenance_status_logistic_h($mtn); ?></td>
        <td width="5%" align="left"><?php echo maintenance_status_logistic_h($rate_date); ?><br /><?php echo 'รอ ' . cal_wait_date($rate_date); ?></td>
        <td width="25%" align="left">
          <a target="_blank" href="detail_job?ts_id=<?php echo $ts_id; ?>"><?php echo $detail_html; ?></a><br /><?php echo maintenance_status_logistic_h($addr); ?>
          <br /><br />
          <a href="tracking_history?ts_id=<?php echo $ts_id; ?>&amp;trp_id=<?php echo (int) $trp_id; ?>&amp;tsp_id=0&amp;trp_product=<?php echo $tracking_product_q; ?>&amp;th_status_job=<?php echo rawurlencode('ติดตามใบเสนอราคา'); ?>"
             onclick="window.open(this.href,'targetWindow','toolbar=no, location=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=800, height=550'); return false;"
             title="<?php echo maintenance_status_logistic_h($title_th); ?>">
            <i class="fa fa-volume-control-phone mn-status-offer-price-tracking-icon" aria-hidden="true"></i>
            <?php echo cal_tracking_due($next_due); ?></a>
        </td>
        <td width="15%" align="left">
          <?php echo maintenance_status_logistic_h($trp_product); ?> &nbsp; <?php echo maintenance_status_logistic_h($trp_sn); ?> <br />
          <span class="mn-status-offer-price-age">อายุเครื่อง <?php echo maintenance_status_logistic_h($repair_age); ?></span><br />
          <span class="mn-status-offer-price-ins"><?php echo maintenance_status_logistic_h($insurance_val); ?></span></td>
        <td width="43%" align="left"><span class="mn-status-offer-price-tech"><?php echo maintenance_status_logistic_h($tech); ?></span> <br /><b>รายการซ่อม</b><br /><?php echo $list_repair_html; ?><br>
          <a href="update_rate?trp_id=<?php echo (int) $trp_id; ?>"><span class="mn-status-offer-price-edit-rate"><i class="fa fa-wrench"></i> แก้ไขรายการซ่อม</span></a></td>
        <td width="8%" align="center">
          <a class="mn-status-offer-price-edit-link" href="edit_offer_price?trp_id=<?php echo (int) $trp_id; ?>" title="กรอกหรือแก้ไข QV / ราคา">
            <span class="mn-status-offer-price-edit-link__inner"><i class="fa fa-pencil-square-o" aria-hidden="true"></i> แก้ไข</span>
          </a>
        </td>
      </tr>
      <tr>
        <td colspan="10"><hr /></td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="10" class="mn-status-offer-price-summary">รวมเครื่องที่รอเสนอราคา <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    <br />
    </div>
  </div>
</body>
</html>
