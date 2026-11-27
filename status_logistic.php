<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รอส่งพัสดุ» — แสดงงานสถานะรอส่งสำรอง (transac_spare + transac_repair) และรอส่งคืนเครื่องซ่อม
 * Refactor จาก `old_system/status_logistic.php` ให้ใช้ `require_auth`, theme mn-*, modal แทน alert/confirm, URL ไม่มี .php
 *
 * Flow:
 * bootstrap DB + legacy helper → render ตาราง → บันทึกผ่าน `update_logistic` / `update_logistic_repair` / `update_change_spare` (GET)
 * เลย์เอาต์หน้า: โหลด `css/mn-page-addjob.css` ร่วมกับ `css/mn-page-status-logistic.css` และใช้โครงสร้างเดียวกับ add_job (`body.mn-addjob-page` + `.mn-form-centered` + `table#table1`)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_status_logistic_page.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/spare_flow_helpers.php';

$rows_spare_pending = maintenance_status_logistic_fetch_spare_pending_rows($connect);
$rows_repair_return = maintenance_status_logistic_fetch_repair_return_rows($connect);

$strLabel = '';
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
<link rel="stylesheet" href="css/mn-page-status-logistic.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-status-logistic.css'); ?>" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รอส่งพัสดุ</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-transac-address.js"></script>
<script type="text/javascript" src="js/status_logistic-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-logistic-page">
  <div class="header">
    <?php
    $page = 'status_logistic';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-logistic-table" width="100%">
      <thead>
      <tr class="spaceUnder mn-status-logistic-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="15%"><div align="center"><b>Product</b></div></th>
        <th width="20%"><div align="center"><b>Detail</b></div></th>
        <th width="5%"><div align="center"><b>ผู้รับเรื่อง</b></div></th>
        <th width="15%"><div align="center"><b>หมายเหตุ</b></div></th>
        <th width="10%"><div align="center"><b>Date send</b></div></th>
        <th width="5%"><div align="center"><b>Logistics</b></div></th>
        <th width="10%"><div align="center"><b>Tracking</b></div></th>
        <th width="10%"><div align="center"><b>Send by</b></div></th>
        <th colspan="2" width="5%"><div align="center"><b>Edit</b></div></th>
      </tr>
      </thead>
      <tbody>
      <tr class="spaceUnder mn-status-logistic-section">
        <td colspan="11" align="center"><b>เครื่องสำรอง</b></td>
      </tr>
      <?php
        $i = 0;
        foreach ($rows_spare_pending as $result_tsp) {
            $ts_id = (int) maintenance_status_logistic_row($result_tsp, 'trp_ts_id', 0);
            if ($ts_id < 1) {
                $ts_id = (int) maintenance_status_logistic_row($result_tsp, 'tsp_ts_id', 0);
            }
            if ($ts_id < 1) {
                continue;
            }
            $sql_ts = 'SELECT * FROM transac WHERE ts_id = ' . $ts_id;
            $exe_ts = maintenance_db_odbc_exec($connect, $sql_ts);
            if (!$exe_ts) {
                exit('Error Query [' . $sql_ts . ']');
            }
            $result_ts = maintenance_db_odbc_fetch_array($exe_ts);
            if (!is_array($result_ts) || (int) maintenance_status_logistic_row($result_ts, 'ts_id', 0) < 1) {
                continue;
            }
            $i += 1;
            $ts_addr_raw_edit_spare = (string) maintenance_status_logistic_row($result_ts, 'ts_address');
            $detail_html = maintenance_status_logistic_detail_html($result_ts);
            $user_addr = (string) maintenance_status_logistic_row($result_ts, 'ts_report_name');
            $tel_addr = (string) maintenance_status_logistic_row($result_ts, 'ts_report_tel');
            $send_address = (string) maintenance_status_logistic_row($result_ts, 'ts_address');
            if (maintenance_status_logistic_row($result_ts, 'ts_security_com') === '') {
                $send_address = strReplace((string) maintenance_status_logistic_row($result_ts, 'ts_enduser_com')) . ' ' . $send_address;
            }
            $site_addr = (string) maintenance_status_logistic_row($result_ts, 'ts_enduser_com');
            $print_q = maintenance_status_logistic_print_label_query(array(
                'user_addr' => $user_addr,
                'tel_addr' => $tel_addr,
                'send_address' => $send_address,
                'site_addr' => $site_addr,
            ));
            $strLabel .= $user_addr . '$$' . $tel_addr . '$$' . $send_address . '$$' . $site_addr . '***';
            $spare_opts = maintenance_status_logistic_spare_blank_options($connect, (string) maintenance_status_logistic_row($result_tsp, 'tsp_product'));
            $tsp_id = (int) maintenance_status_logistic_row($result_tsp, 'tsp_id', 0);
            $trp_id_spare = (int) maintenance_status_logistic_row($result_tsp, 'tsp_trp_id', 0);
            $tsp_ts_id_spare = (int) maintenance_status_logistic_row($result_tsp, 'tsp_ts_id', 0);
            $tsp_product_spare = (string) maintenance_status_logistic_row($result_tsp, 'tsp_product');
            $other_comment = (string) maintenance_status_logistic_row($result_tsp, 'trp_row_comment');
            ?>
      <tr class="mn-status-logistic-row" onmouseover="mnStatusLogisticHover(this, true)" onmouseout="mnStatusLogisticHover(this, false)">
        <td align="center"><?php echo (int) $i; ?></td>
        <td align="left"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_tsp, 'tsp_product')); ?><br />
          <?php echo mn_spare_resend_badge($connect, (int) maintenance_status_logistic_row($result_tsp, 'tsp_id', 0)); ?>
          <input type="hidden" id="tspTrpId<?php echo (int) $i; ?>" value="<?php echo (int) $trp_id_spare; ?>" />
          <input type="hidden" id="tspTsId<?php echo (int) $i; ?>" value="<?php echo (int) $tsp_ts_id_spare; ?>" />
          <input type="hidden" id="tspProduct<?php echo (int) $i; ?>" value="<?php echo maintenance_status_logistic_h($tsp_product_spare); ?>" />
          <input type="text" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_tsp, 'tsp_sn')); ?>" style="display:none;" id="pd_spareOld<?php echo (int) $i; ?>" />
          <select class="custom-select" id="pd_spareNew<?php echo (int) $i; ?>" name="pd_spareNew<?php echo (int) $i; ?>" disabled="disabled">
            <option value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_tsp, 'tsp_sn')); ?>"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_tsp, 'tsp_sn')); ?></option>
            <?php
            foreach ($spare_opts as $opt) {
                $sn = $opt['sn'];
                ?>
            <option value="<?php echo maintenance_status_logistic_h($sn); ?>"><?php echo maintenance_status_logistic_h($sn); ?></option>
            <?php } ?>
          </select>
          <p><a class="mn-status-logistic-edit" href="javascript:void(0);" onclick="editSNSpare(<?php echo (int) $i; ?>);"><i class="fa fa-edit" id="editSN<?php echo (int) $i; ?>">แก้ไข</i></a>
          <a href="javascript:void(0);" onclick="saveSNSpare(<?php echo (int) $tsp_id; ?>,<?php echo (int) $i; ?>,<?php echo (int) $trp_id_spare; ?>);"><i class="fa fa-save" style="color:#006600; display:none;" id="saveSN<?php echo (int) $i; ?>">บันทึก</i></a> &nbsp;
          <a href="javascript:void(0);" onclick="cancelStatus();"><i class="fa fa-close" style="color:#FF9900; display:none;" id="cancelSN<?php echo (int) $i; ?>">ยกเลิก</i></a></p></td>
        <td align="left"><a target="_blank" href="detail_job?ts_id=<?php echo (int) maintenance_status_logistic_row($result_ts, 'ts_id', 0); ?>"><?php echo $detail_html; ?></a><br />
        <a style="color:#FFCC00" target="_blank" href="print_label_logistic?<?php echo $print_q; ?>"><i class="fa fa-print" aria-hidden="true"></i>พิมพ์ใบปะหน้า</a> &nbsp;
        <a class="mn-status-logistic-edit" href="javascript:void(0);" onclick="editAddress(<?php echo (int) maintenance_status_logistic_row($result_ts, 'ts_id', 0); ?>, <?php echo htmlspecialchars(json_encode($ts_addr_raw_edit_spare, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);"><i class="fa fa-edit" aria-hidden="true"></i> แก้ไขที่อยู่</a>
        </td>
        <td align="center"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_ts, 'ts_user_recive')); ?></td>
        <td align="left"><?php echo maintenance_status_logistic_h($other_comment); ?></td>
        <td align="center"><input type="date" name="logis_date<?php echo (int) $i; ?>" id="logis_date<?php echo (int) $i; ?>" value="<?php echo maintenance_status_logistic_h(date('Y-m-d')); ?>" size="10" disabled="disabled" /></td>
        <td align="center">
          <select class="custom-select" name="logis_logistics<?php echo (int) $i; ?>" id="logis_logistics<?php echo (int) $i; ?>" disabled="disabled">
            <option value="">ขนส่ง..</option>
            <?php
            $arrLan_logis = count($mn_status_logistics_list);
            for ($a = 0; $a < $arrLan_logis; $a++) {
                $optv = $mn_status_logistics_list[$a];
                ?>
            <option value="<?php echo maintenance_status_logistic_h($optv); ?>"><?php echo maintenance_status_logistic_h($optv); ?></option>
            <?php } ?>
          </select>
        </td>
        <td align="center"><input type="text" name="logis_tracking<?php echo (int) $i; ?>" id="logis_tracking<?php echo (int) $i; ?>" autocomplete="off" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_tsp, 'tsp_tracking')); ?>" style="width:95%" disabled="disabled" /></td>
        <td align="center">
          <input type="hidden" name="logis_sendby<?php echo (int) $i; ?>" id="logis_sendby<?php echo (int) $i; ?>" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
          <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
        </td>
        <td align="center" colspan="2"><div id="iconsave<?php echo (int) $i; ?>" style="display:none;"><a href="javascript:void(0);" onclick="saveStatus(<?php echo (int) $tsp_id; ?>,<?php echo (int) $i; ?>);">
            <div style="color:#FF9900;"><i class="fa fa-save fa-2x"></i></div></a></div>
            <div id="iconedit<?php echo (int) $i; ?>" style="display:'';"><a href="javascript:void(0);" onclick="editStatus(<?php echo (int) $tsp_id; ?>,<?php echo (int) $i; ?>);">
            <div class="mn-status-logistic-edit"><i class="fa fa-edit fa-2x"></i></div></a></div>
          <div id="iconcancel<?php echo (int) $i; ?>" style="display:none;"><a href="javascript:void(0);" onclick="cancelStatus();">
            <div style="color:#FF9900;"><i class="fa fa-close fa-2x"></i></div></a></div>
        </td>
      </tr>
      <tr>
        <td colspan="11"><hr /></td>
      </tr>
      <?php } ?>
      <tr class="spaceUnder mn-status-logistic-section2">
        <td colspan="12" align="center"><b>เครื่องซ่อมลูกค้า</b></td>
      </tr>
      <?php
        $s = 0;
        foreach ($rows_repair_return as $result_trp) {
            $ts_id_r = (int) maintenance_status_logistic_row($result_trp, 'trp_ts_id', 0);
            if ($ts_id_r < 1) {
                continue;
            }
            $sql_ts2 = 'SELECT * FROM transac WHERE ts_id = ' . $ts_id_r;
            $exe_ts2 = maintenance_db_odbc_exec($connect, $sql_ts2);
            if (!$exe_ts2) {
                exit('Error Query [' . $sql_ts2 . ']');
            }
            $result_ts2 = maintenance_db_odbc_fetch_array($exe_ts2);
            if (!is_array($result_ts2) || (int) maintenance_status_logistic_row($result_ts2, 'ts_id', 0) < 1) {
                continue;
            }
            $s += 1;
            $rpd = maintenance_status_logistic_h(maintenance_status_logistic_row($result_trp, 'trp_product')) . '<br>' . maintenance_status_logistic_h(maintenance_status_logistic_row($result_trp, 'trp_sn'));
            $ts_addr_raw_edit = (string) maintenance_status_logistic_row($result_ts2, 'ts_address');
            $rdetail_html = maintenance_status_logistic_detail_html($result_ts2);
            $user_addr2 = (string) maintenance_status_logistic_row($result_ts2, 'ts_report_name');
            $tel_addr2 = (string) maintenance_status_logistic_row($result_ts2, 'ts_report_tel');
            $send_address2 = (string) maintenance_status_logistic_row($result_ts2, 'ts_address');
            if (maintenance_status_logistic_row($result_ts2, 'ts_security_com') === '') {
                $send_address2 = strReplace((string) maintenance_status_logistic_row($result_ts2, 'ts_enduser_com')) . ' ' . $send_address2;
            }
            $site_addr2 = (string) maintenance_status_logistic_row($result_ts2, 'ts_enduser_com');
            $print_q2 = maintenance_status_logistic_print_label_query(array(
                'user_addr' => $user_addr2,
                'tel_addr' => $tel_addr2,
                'send_address' => $send_address2,
                'site_addr' => $site_addr2,
            ));
            $strLabel .= $user_addr2 . '$$' . $tel_addr2 . '$$' . $send_address2 . '$$' . $site_addr2 . '***';
            $trp_id = (int) maintenance_status_logistic_row($result_trp, 'trp_id', 0);
            ?>
      <tr class="mn-status-logistic-row">
        <td align="center"><?php echo (int) $s; ?></td>
        <td align="left"><?php echo $rpd; ?></td>
        <td align="left"><a target="_blank" href="detail_job?ts_id=<?php echo (int) maintenance_status_logistic_row($result_ts2, 'ts_id', 0); ?>"><?php echo $rdetail_html; ?></a>
        <a style="color:#FFCC00" target="_blank" href="print_label_logistic?<?php echo $print_q2; ?>"><i class="fa fa-print" aria-hidden="true"></i>พิมพ์ใบปะหน้า</a> &nbsp;
        <a class="mn-status-logistic-edit" href="javascript:void(0);" onclick="editAddress(<?php echo (int) maintenance_status_logistic_row($result_ts2, 'ts_id', 0); ?>, <?php echo htmlspecialchars(json_encode($ts_addr_raw_edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);"><i class="fa fa-edit" aria-hidden="true"></i> แก้ไขที่อยู่</a>
      </td>
        <td align="center"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_ts2, 'ts_user_recive')); ?></td>
        <td align="left"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_trp, 'trp_other_comment')); ?></td>
        <td align="center"><input type="date" name="trp_send_date<?php echo (int) $s; ?>" id="trp_send_date<?php echo (int) $s; ?>" value="<?php echo maintenance_status_logistic_h(date('Y-m-d')); ?>" size="10" disabled="disabled" /></td>
        <td align="center">
          <select class="custom-select" name="trp_logistics<?php echo (int) $s; ?>" id="trp_logistics<?php echo (int) $s; ?>" disabled="disabled">
            <option value="">ขนส่ง..</option>
            <?php
            $arrLan_logis2 = count($mn_status_logistics_list);
            for ($a = 0; $a < $arrLan_logis2; $a++) {
                $optv2 = $mn_status_logistics_list[$a];
                ?>
            <option value="<?php echo maintenance_status_logistic_h($optv2); ?>"><?php echo maintenance_status_logistic_h($optv2); ?></option>
            <?php } ?>
          </select>
        </td>
        <td align="center"><input type="text" name="trp_tracking<?php echo (int) $s; ?>" id="trp_tracking<?php echo (int) $s; ?>" autocomplete="off" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result_trp, 'trp_tracking')); ?>" style="width:95%;" disabled="disabled" /></td>
        <td align="center">
          <input type="hidden" name="trp_sendby<?php echo (int) $s; ?>" id="trp_sendby<?php echo (int) $s; ?>" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
          <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
        </td>
        <td align="center" colspan="2"><div id="riconsave<?php echo (int) $s; ?>" style="display:none;"><a href="javascript:void(0);" onclick="saveStatusR(<?php echo (int) $trp_id; ?>,<?php echo (int) $s; ?>);">
            <div style="color:#FF9900;"><i class="fa fa-save fa-2x"></i></div></a></div>
            <div id="riconedit<?php echo (int) $s; ?>"><a href="javascript:void(0);" onclick="editStatusR(<?php echo (int) $trp_id; ?>,<?php echo (int) $s; ?>);">
            <div class="mn-status-logistic-edit"><i class="fa fa-edit fa-2x"></i></div></a></div>
            <div id="riconcancel<?php echo (int) $s; ?>" style="display:none;"><a href="javascript:void(0);" onclick="cancelStatus();">
            <div style="color:#FF9900;"><i class="fa fa-close fa-2x"></i></div></a></div>
        </td>
      </tr>
      <tr>
        <td colspan="11"><hr /></td>
      </tr>
      <?php } ?>
      </tbody>
    </table>
    <a href="print_label_best?arrData=<?php echo rawurlencode($strLabel); ?>" target="_blank"><div align="right" class="mn-status-logistic-excel-link"><b><i class="fa fa-file-excel-o" aria-hidden="true"></i>&nbsp; ส่งออกข้อมูลเพื่อนำเข้าฟอร์มขนส่ง Best Excel</b></div></a>
    <br />
    </div>
  </div>
</body>
</html>
