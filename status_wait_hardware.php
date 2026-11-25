<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รอเครื่องซ่อม» — แสดง transac + transac_repair ที่ `trp_status_job = รอเครื่องซ่อม` พร้อม filter บริษัท/สินค้า
 * Refactor จาก `old_system/status_wait_hardware.php` ให้ใช้ `require_auth`, theme mn-*, mysqli + helper, URL ไม่มี .php
 * ฝั่งเบราว์เซอร์: `mn-maintenance-flow-modals.js` ก่อน `status_wait_hardware-page.js` สำหรับ modal แทน `alert`/`confirm` บนแถวบันทึกด่วน
 *
 * Flow:
 * require_auth → bootstrap → ดึงรายชื่อบริษัท/สินค้า → ประกอบ SQL + prefetch spare/tracking → render ตาราง
 *
 * Example:
 * เปิด `/maintenance/status_wait_hardware` หรือ `?company=...` / `?product=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_status_wait_hardware_page.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$status_label = maintenance_wait_hardware_status_literal();
$filters = maintenance_wait_hardware_parse_get_filters($_GET);
$arr_com = request_company($status_label);
$arr_product = request_product($status_label);
$arr_length_com = count($arr_com);
$arr_length_product = count($arr_product);

$sql_join = maintenance_wait_hardware_build_list_sql($connect, $filters['company'], $filters['product']);
$join_rows = maintenance_wait_hardware_fetch_join_rows($connect, $sql_join);

$trp_ids = array();
foreach ($join_rows as $jr) {
    if (!empty($jr['trp_id'])) {
        $trp_ids[] = (int) $jr['trp_id'];
    }
}
$spare_by_trp = maintenance_wait_hardware_prefetch_spare_by_trp_ids($connect, $trp_ids);
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
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-status-wait-hardware.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รอ รับเครื่องซ่อม</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/status_wait_hardware-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-wait-hardware-page">
  <div class="header">
    <?php
    $color = 'orange';
    $page = 'status_wait_hardware';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-wait-hardware-table" width="100%">
      <thead>
      <tr class="mn-status-wait-hardware-row-under mn-status-wait-hardware-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="20%"><div align="center">
          <b>บริษัท</b><br />
          <select class="custom-select" id="head_company" name="head_company" onchange="mnWaitHwSortCompany(this.value);">
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
        <th width="10%"><div align="center"><b>สินค้า</b><br />
          <select class="custom-select" id="head_product" name="head_product" onchange="mnWaitHwSortProduct(this.value);">
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
        <th width="20%"><div align="center"><b>อาการ</b></div></th>
        <th width="10%"><div align="center"><b>สำรอง</b></div></th>
        <th width="10%"><div align="center"><b>ระยะเวลา</b></div></th>
        <th width="5%"><div align="center"><b>ผู้รับเรื่อง</b></div></th>
        <th width="10%"><div align="center"><b>เข้ารับพัสดุ</b></div></th>
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
            $tsp_id_for_link = isset($result_s['tsp_id']) ? (int) $result_s['tsp_id'] : 0;
            $detail_html = maintenance_wait_hardware_contact_html($result_join);
            $result_th = isset($track_by_trp[$trp_id]) ? $track_by_trp[$trp_id] : array();
            $title_th = maintenance_wait_hardware_tracking_title_attr($result_th);
            $next_due = isset($result_th['th_date_nextdue']) ? $result_th['th_date_nextdue'] : '';
            $trp_product = maintenance_flow_db_text_to_utf8(isset($result_join['trp_product']) ? $result_join['trp_product'] : '');
            $trp_sn = maintenance_flow_db_text_to_utf8(isset($result_join['trp_sn']) ? $result_join['trp_sn'] : '');
            $trp_repair_inform = maintenance_flow_db_text_to_utf8(isset($result_join['trp_repair_inform']) ? $result_join['trp_repair_inform'] : '');
            $tracking_product_q = rawurlencode($trp_product . ' ' . $trp_sn);
            ?>
      <tr id="row<?php echo (int) $i; ?>" class="mn-status-wait-hardware-row-under" style="border-collapse:collapse; background-color:#fff;" onmouseover="ChangeHoverBGColor(this)" onmouseout="RestoreHoverBGColor(this)">
        <td align="center"><?php echo maintenance_status_logistic_h(isset($result_join['ts_mtn_number']) ? $result_join['ts_mtn_number'] : ''); ?></td>
        <td align="left"><a target="_blank" href="detail_job?ts_id=<?php echo $ts_id; ?>"><?php echo $detail_html; ?></a></td>
        <td align="center"><?php echo maintenance_status_logistic_h($trp_product) . '<br />' . maintenance_status_logistic_h($trp_sn); ?></td>
        <td align="left"><?php echo maintenance_status_logistic_h($trp_repair_inform); ?></td>
        <td align="center">
          <?php if ($has_spare) { ?>
          <div class="mn-wait-hw-spare-label mn-wait-hw-spare-label--has">มีสำรอง</div>
          <?php } else { ?>
          <div class="mn-wait-hw-spare-label mn-wait-hw-spare-label--none">ไม่มีสำรอง</div>
          <?php } ?>
          <a class="mn-wait-hw-spare-detail-link" target="_blank" href="detail_job?ts_id=<?php echo (int) $ts_id; ?>" title="แก้ไขเครื่องสำรอง — หน้ารายละเอียดงาน"><i class="fa fa-edit"></i></a>
        </td>
        <td align="center"><?php echo cal_wait_date(isset($result_join['ts_date']) ? $result_join['ts_date'] : ''); ?></td>
        <td align="center"><?php echo maintenance_status_logistic_h(isset($result_join['ts_user_recive']) ? $result_join['ts_user_recive'] : ''); ?></td>
        <td align="center">
          <a href="pickup_wait_hardware?trp_id=<?php echo (int) $trp_id; ?>" onclick="window.open(this.href,'targetWindow','toolbar=no, location=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=800, height=550'); return false;">
            <?php
            $pick_contact = isset($result_join['trp_contact_pickup']) ? (string) $result_join['trp_contact_pickup'] : '';
            $pick_date = isset($result_join['trp_date_pickup']) ? (string) $result_join['trp_date_pickup'] : '';
            if ($pick_contact === '') {
                ?><i class="fa fa-truck"></i>...<?php
            } elseif ($pick_date !== '') {
                ?><span style="color:#006600;">แจ้งแล้ว</span><?php
            } else {
                ?>รอดำเนินการ<?php
            }
            ?>
          </a>
        </td>
        <td align="center" title="<?php echo maintenance_status_logistic_h($title_th); ?>">
          <a href="tracking_history?ts_id=<?php echo $ts_id; ?>&amp;trp_id=<?php echo (int) $trp_id; ?>&amp;tsp_id=<?php echo (int) $tsp_id_for_link; ?>&amp;trp_product=<?php echo $tracking_product_q; ?>&amp;th_status_job=<?php echo rawurlencode('ติดตามเครื่องซ่อม'); ?>"
             onclick="window.open(this.href,'targetWindow','toolbar=no, location=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=800, height=550'); return false;">
            <i class="fa fa-volume-control-phone" style="color:#0033FF;"></i>
          </a><br /><?php echo cal_tracking_due($next_due); ?>
        </td>
        <td align="center"><a href="javascript:void(0);" onclick="mnWaitHwEditStatus(<?php echo (int) $trp_id; ?>,<?php echo (int) $i; ?>);"><div style="color:#FF9900;"><i class="fa fa-edit"></i></div></a></td>
      </tr>
      <tr>
        <td colspan="10"><hr /></td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="10" style="color:#FF9900">รวมเครื่องซ่อมที่รอ <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    <br />
    </div>
  </div>
</body>
</html>
