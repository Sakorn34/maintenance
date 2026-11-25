<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รอเครื่องสำรอง» — JOIN `transac_repair` + `transac_spare` + `transac` ที่ `trp_status_job = รอเครื่องสำรอง`
 * filter สินค้าหรือค้นหาที่อยู่, บันทึกวันรับคืนสำรองผ่าน `update_wait_spare`
 * Refactor จาก `old_system/status_wait_spare.php` — auth, ธีม mn-*, prepared list query, prefetch tracking, modal แทน `alert`
 *
 * Flow:
 * require_auth → bootstrap → `bootstrap_logistic_recorder` + filter + fetch + prefetch → ปิด `$connect` → render
 *
 * Example:
 * เปิด `status_wait_spare` หรือ `?product=...` / `?addr=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_wait_spare_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$status_label = maintenance_status_wait_spare_status_literal();
$filters = maintenance_status_wait_spare_parse_get_filters($_GET);
$arr_product = request_product($status_label);
$arr_length_product = count($arr_product);

$join_rows = maintenance_status_wait_spare_fetch_join_rows($connect, $filters);

$trp_ids = array();
foreach ($join_rows as $jr) {
    if (!empty($jr['trp_id'])) {
        $trp_ids[] = (int) $jr['trp_id'];
    }
}
$track_by_trp = maintenance_wait_hardware_prefetch_tracking_latest_by_trp_ids($connect, $trp_ids);
maintenance_db_odbc_close($connect);

$row_count = count($join_rows);
$addr_value = maintenance_status_logistic_h($filters['addr']);
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
<link rel="stylesheet" href="css/mn-page-status-wait-spare.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รอเครื่องสำรอง</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/status_wait_spare-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-wait-spare-page">
  <div class="header">
    <?php
    $page = 'status_wait_spare';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-wait-spare-table" width="100%">
      <thead>
      <tr class="mn-status-wait-spare-row-under mn-status-wait-spare-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="30%"><div align="center"><b>บริษัท/ผู้ติดต่อ</b><br />
          <div class="mn-status-wait-spare-search-addr">
            <input type="text" id="search_addr" name="search_addr" size="10" value="<?php echo $addr_value; ?>" placeholder="ค้นหาที่อยู่" />
          </div>
        </div></th>
        <th width="15%"><div align="center"><b>สินค้า</b><br />
          <select class="custom-select" id="head_product" name="head_product" onchange="mnStatusWaitSpareSortProduct(this.value);">
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
        <th width="8%"><div align="center"><b>จำนวนวันที่รอคืน</b></div></th>
        <th width="8%"><div align="center"><b>วันที่คืนสำรอง</b></div></th>
        <th width="8%"><div align="center"><b>ผู้รับคืน</b></div></th>
        <th width="6%"><div align="center"><b>เข้ารับพัสดุ</b></div></th>
        <th width="5%"><div align="center"><b>ติดตาม</b></div></th>
        <th width="5%"><div align="center"><b>edit</b></div></th>
      </tr>
      </thead>
      <tbody>
      <?php
        $i = 0;
        foreach ($join_rows as $result_join) {
            $i += 1;
            $ts_id = (int) (isset($result_join['ts_id']) ? $result_join['ts_id'] : 0);
            $trp_id = (int) (isset($result_join['trp_id']) ? $result_join['trp_id'] : 0);
            $tsp_id = (int) (isset($result_join['tsp_id']) ? $result_join['tsp_id'] : 0);
            $result_th = isset($track_by_trp[$trp_id]) ? $track_by_trp[$trp_id] : array();
            $title_th = maintenance_wait_hardware_tracking_title_attr($result_th);
            $next_due = isset($result_th['th_date_nextdue']) ? (string) $result_th['th_date_nextdue'] : '';
            $detail_html = maintenance_status_wait_spare_contact_block_html($result_join);
            $tsp_product = isset($result_join['tsp_product']) ? (string) $result_join['tsp_product'] : '';
            $tsp_sn = isset($result_join['tsp_sn']) ? (string) $result_join['tsp_sn'] : '';
            $send_date = isset($result_join['trp_send_date']) ? (string) $result_join['trp_send_date'] : '';
            $pick_contact = isset($result_join['trp_contact_pickup']) ? (string) $result_join['trp_contact_pickup'] : '';
            $pick_date = isset($result_join['trp_date_pickup']) ? (string) $result_join['trp_date_pickup'] : '';
            $tracking_product_q = rawurlencode($tsp_product . ' ' . $tsp_sn);
            ?>
      <tr id="row<?php echo (int) $i; ?>" class="mn-status-wait-spare-row-under" style="border-collapse:collapse; background-color:#fff;" onmouseover="ChangeHoverBGColor(this)" onmouseout="RestoreHoverBGColor(this)">
        <td align="center"><?php echo (int) $i; ?></td>
        <td align="left"><a target="_blank" href="detail_job?ts_id=<?php echo $ts_id; ?>"><?php echo $detail_html; ?></a><br /></td>
        <td align="left"><?php echo maintenance_status_logistic_h($tsp_product); ?><br /><?php echo maintenance_status_logistic_h($tsp_sn); ?></td>
        <td align="center"><?php echo cal_wait_date($send_date); ?></td>
        <td align="center"><input type="date" name="tsp_datereturn<?php echo (int) $i; ?>" id="tsp_datereturn<?php echo (int) $i; ?>" value="" /></td>
        <td align="center">
          <input type="hidden" name="tsp_receiveby<?php echo (int) $i; ?>" id="tsp_receiveby<?php echo (int) $i; ?>" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
          <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
        </td>
        <td align="center">
          <a href="pickup_wait_hardware?trp_id=<?php echo (int) $trp_id; ?>" onclick="window.open(this.href,'targetWindow','toolbar=no, location=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=800, height=550'); return false;">
            <?php
            if ($pick_contact === '') {
                ?><i class="fa fa-truck" title="แจ้งเข้ารับพัสดุ"></i>...<?php
            } elseif ($pick_date !== '') {
                ?><span class="mn-status-wait-spare-pickup-done">แจ้งแล้ว</span><?php
            } else {
                ?>รอดำเนินการ<?php
            }
            ?>
          </a>
        </td>
        <td align="center" title="<?php echo maintenance_status_logistic_h($title_th); ?>">
          <a href="tracking_history?ts_id=<?php echo $ts_id; ?>&amp;trp_id=<?php echo (int) $trp_id; ?>&amp;tsp_id=<?php echo (int) $tsp_id; ?>&amp;trp_product=<?php echo $tracking_product_q; ?>&amp;th_status_job=<?php echo rawurlencode('ติดตามเครื่องสำรอง'); ?>"
             onclick="window.open(this.href,'targetWindow','toolbar=no, location=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=800, height=550'); return false;">
            <i class="fa fa-volume-control-phone mn-status-wait-spare-tracking-icon" aria-hidden="true"></i>
          </a><br /><?php echo cal_tracking_due($next_due); ?>
        </td>
        <td align="center">
          <input type="hidden" id="tsp_sn<?php echo (int) $i; ?>" name="tsp_sn<?php echo (int) $i; ?>" value="<?php echo maintenance_status_logistic_h($tsp_sn); ?>" />
          <div id="iconsave<?php echo (int) $i; ?>">
            <a href="javascript:mnStatusWaitSpareSave(<?php echo (int) $tsp_id; ?>,<?php echo (int) $i; ?>);">
              <div class="mn-status-wait-spare-save-icon"><i class="fa fa-save fa-2x"></i></div>
            </a>
          </div>
        </td>
      </tr>
      <tr>
        <td colspan="9"><hr /></td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="9" class="mn-status-wait-spare-summary">รวม <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    <br />
    </div>
  </div>
</body>
</html>
