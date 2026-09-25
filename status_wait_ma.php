<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รอซ่อม» — แสดง transac + transac_repair ที่ `trp_status_job = รอซ่อม` พร้อม filter บริษัท/สินค้า
 * Refactor จาก `old_system/status_wait_ma.php` — auth, theme mn-*, prepared statement, URL ไม่มี .php, modal แทน alert/confirm
 *
 * Flow:
 * require_auth → bootstrap → `bootstrap_logistic_recorder` + request_company/product → ดึงรายการ → ปิด connection → render
 *
 * Example:
 * เปิด `/maintenance/status_wait_ma` หรือ `?company=...` / `?product=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_status_wait_ma_page.php';
require_once __DIR__ . '/includes/status_wait_ma_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$status_label = maintenance_wait_ma_status_literal();
$filters = maintenance_wait_ma_parse_get_filters($_GET);
$arr_com = request_company($status_label);
$arr_product = request_product($status_label);
$arr_length_com = count($arr_com);
$arr_length_product = count($arr_product);

$join_rows = maintenance_wait_ma_fetch_join_rows($connect, $filters);
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
<link rel="stylesheet" href="css/mn-page-status-wait-ma.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รอ ซ่อม</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/status_wait_ma-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-wait-ma-page">
  <div class="header">
    <?php
    $page = 'status_wait_ma';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-wait-ma-table" width="100%">
      <thead>
      <tr class="mn-status-wait-ma-row-under mn-status-wait-ma-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="10%"><div align="center"><b>เลขที่ใบซ่อม</b></div></th>
        <th width="20%"><div align="center">
          <b>บริษัท/ผู้ติดต่อ</b><br />
          <select class="custom-select" id="head_company" name="head_company" onchange="mnWaitMaSortCompany(this.value);">
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
          <select class="custom-select" id="head_product" name="head_product" onchange="mnWaitMaSortProduct(this.value);">
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
        <th width="15%"><div align="center"><b>รายการซ่อม</b></div></th>
        <th width="10%"><div align="center"><b>วันที่อนุมัติซ่อม</b></div></th>
        <th width="10%"><div align="center"><b>วันที่ซ่อมเสร็จ</b></div></th>
        <th width="10%"><div align="center"><b>ผู้ซ่อม</b></div></th>
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
            $detail_html = maintenance_wait_ma_contact_html($result_join);
            $list_html = maintenance_wait_ma_repair_list_html($result_join);
            $firm_qv = isset($result_join['trp_firm_qv_date']) ? (string) $result_join['trp_firm_qv_date'] : '';
            $status_firm = isset($result_join['trp_status_firmma']) ? (string) $result_join['trp_status_firmma'] : '';
            $backup = isset($result_join['trp_backup']) ? (string) $result_join['trp_backup'] : '';
            $mtn = isset($result_join['ts_mtn_number']) ? (string) $result_join['ts_mtn_number'] : '';
            ?>
      <tr id="row<?php echo (int) $i; ?>" class="mn-status-wait-ma-row-under" style="border-collapse:collapse; background-color:#fff;" onmouseover="ChangeHoverBGColor(this)" onmouseout="RestoreHoverBGColor(this)">
        <td align="center"><?php echo (int) $i; ?></td>
        <td align="center"><?php echo maintenance_status_logistic_h($mtn); ?><br /><b><?php echo 'รอ ' . cal_wait_date($firm_qv); ?></b></td>
        <td align="left"><a target="_blank" href="detail_job?ts_id=<?php echo $ts_id; ?>"><?php echo $detail_html; ?></a><br /></td>
        <td align="left">
          <?php echo maintenance_status_logistic_h(isset($result_join['trp_product']) ? (string) $result_join['trp_product'] : ''); ?> &nbsp;
          <?php echo maintenance_status_logistic_h(isset($result_join['trp_sn']) ? (string) $result_join['trp_sn'] : ''); ?><br />
          <span style="color:#FF0000">อายุเครื่อง <?php echo maintenance_status_logistic_h(isset($result_join['trp_repair_age']) ? (string) $result_join['trp_repair_age'] : ''); ?> </span><br />
          <span style="color:#0000FF"><?php echo maintenance_status_logistic_h(isset($result_join['trp_insurance']) ? (string) $result_join['trp_insurance'] : ''); ?></span>
        </td>
        <td align="left" class="mn-status-wait-ma-col-repair">
          <div class="mn-status-wait-ma-repair-list"><?php echo $list_html; ?></div>
          <a class="mn-status-wait-ma-detail-link" target="_blank" rel="noopener" href="detail_job?ts_id=<?php echo (int) $ts_id; ?>" title="เปิดรายละเอียดงาน">
            <span class="mn-status-wait-ma-detail-link__inner"><i class="fa fa-info-circle" aria-hidden="true"></i> Info</span>
          </a>
        </td>
        <td align="center">
          <span style="color:#FF0000"><?php echo maintenance_status_logistic_h($firm_qv); ?></span><br />
          <?php echo maintenance_status_logistic_h($status_firm); ?><br /><?php echo maintenance_status_logistic_h($backup); ?>
          <input type="hidden" id="trp_status_firmma<?php echo (int) $i; ?>" value="<?php echo maintenance_status_logistic_h($status_firm); ?>" />
        </td>
        <td align="center"><input type="date" name="trp_success_date<?php echo (int) $i; ?>" id="trp_success_date<?php echo (int) $i; ?>" value="" /></td>
        <td align="center">
          <input type="hidden" name="trp_user_ma<?php echo (int) $i; ?>" id="trp_user_ma<?php echo (int) $i; ?>" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
          <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
        </td>
        <td align="center">
          <a href="javascript:void(0);" onclick="mnWaitMaSaveStatus(<?php echo (int) $trp_id; ?>,<?php echo (int) $i; ?>);"><div style="color:#FF9900;"><i class="fa fa-save fa-2x"></i></div></a>
        </td>
      </tr>
      <tr>
        <td colspan="9"><hr /></td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="9" class="mn-status-wait-ma-footnote">*รูปแบบวันที่ mm / dd / yyyy เช่น วันที่ / เดือน / ปี ค.ศ. เช่น 05/31/2021</td>
      </tr>
      <tr>
        <td colspan="9" style="color:#FF9900">รวม <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    <br />
    </div>
  </div>
</body>
</html>
