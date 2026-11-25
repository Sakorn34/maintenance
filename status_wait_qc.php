<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รอ QC» — แสดง transac + transac_repair ที่ `trp_status_job = รอ QC` พร้อมแถบตัวกรองด้านบนตาราง (สินค้า, select ชื่อจาก `ts_enduser_com` / `ts_security_com` เฉพาะงานที่รอ QC — เลือกแล้ว redirect ทันที)
 * Refactor จาก `old_system/status_wait_qc.php` — auth, theme mn-*, prepared statement, URL ไม่มี .php
 *
 * Flow:
 * require_auth → bootstrap → request_product → ดึงรายชื่อรอ QC (datalist) + ดึงรายการตาราง → ปิด connection → render
 *
 * Example:
 * เปิด `/maintenance/status_wait_qc` หรือ `?product=...&company=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_status_wait_qc_page.php';
require_once __DIR__ . '/includes/status_wait_qc_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$status_label = maintenance_wait_qc_status_literal();
$filters = maintenance_wait_qc_parse_get_filters($_GET);
$arr_product = request_product($status_label);
$arr_length_product = count($arr_product);

$join_rows = maintenance_wait_qc_fetch_join_rows($connect, $filters);
$wait_qc_company_names = maintenance_wait_qc_fetch_wait_qc_company_name_list($connect, $filters);
maintenance_db_odbc_close($connect);

$row_count = count($join_rows);
$exp_ins = maintenance_wait_qc_insurance_expired_literal();
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
<link rel="stylesheet" href="css/mn-page-status-wait-qc.css" />
<title>รอ QC</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/status_wait_qc-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-wait-qc-page">
  <div class="header">
    <?php
    $page = 'status_wait_qc';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <section class="mn-status-wait-qc-filters" aria-label="ตัวกรองรายการรอ QC">
      <div class="mn-status-wait-qc-filters__row">
        <div class="mn-status-wait-qc-filters__group mn-status-wait-qc-filters__group--company">
          <label for="mn_wait_qc_company" class="mn-status-wait-qc-company-label">เลือกชื่อ (รอ QC)</label>
          <select class="custom-select mn-status-wait-qc-company-select" id="mn_wait_qc_company" name="company" onchange="mnWaitQcSortProduct(document.getElementById('head_product').value);">
            <option value=""<?php echo $filters['company'] === '' ? ' selected="selected"' : ''; ?>>ทั้งหมด</option>
            <?php
            foreach ($wait_qc_company_names as $cn) {
                $cn = (string) $cn;
                if (trim($cn) === '') {
                    continue;
                }
                $cn_enc = maintenance_status_logistic_h($cn);
                $selc = ($filters['company'] === trim($cn)) ? ' selected="selected"' : '';
                ?>
            <option value="<?php echo $cn_enc; ?>"<?php echo $selc; ?>><?php echo $cn_enc; ?></option>
                <?php
            }
            ?>
          </select>
        </div>
        <div class="mn-status-wait-qc-filters__group mn-status-wait-qc-filters__group--product">
          <label for="head_product" class="mn-status-wait-qc-product-label">เลือกสินค้า</label>
          <select class="custom-select mn-status-wait-qc-product-select" id="head_product" name="head_product" onchange="mnWaitQcSortProduct(this.value);">
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
        </div>
      </div>
    </section>
    <table id="table1" class="mn-status-wait-qc-table" width="100%">
      <thead>
      <tr class="mn-status-wait-qc-row-under mn-status-wait-qc-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="20%"><div align="center"><b>บริษัท/ผู้ติดต่อ</b></div></th>
        <th width="20%"><div align="center"><b>การแจ้งซ่อม</b></div></th>
        <th width="30%"><div align="center"><b>รายการซ่อม</b></div></th>
        <th width="20%"><div align="center"><b>วันที่ซ่อมเสร็จ</b></div></th>
        <th width="5%"><div align="center"><b>QC</b></div></th>
      </tr>
      </thead>
      <tbody>
      <?php
        $i = 0;
        foreach ($join_rows as $result_join) {
            $i += 1;
            $ts_id = (int) (isset($result_join['ts_id']) ? $result_join['ts_id'] : 0);
            $trp_id = (int) (isset($result_join['trp_id']) ? $result_join['trp_id'] : 0);
            $detail_html = maintenance_wait_qc_contact_html($result_join);
            $list_html = maintenance_wait_qc_repair_list_html($result_join);
            $ins = isset($result_join['trp_insurance']) ? (string) $result_join['trp_insurance'] : '';
            $success_date = isset($result_join['trp_success_date']) ? (string) $result_join['trp_success_date'] : '';
            ?>
      <tr id="row<?php echo (int) $i; ?>" class="mn-status-wait-qc-row-under" style="border-collapse:collapse; background-color:#fff;" onmouseover="ChangeHoverBGColor(this)" onmouseout="RestoreHoverBGColor(this)">
        <td align="center"><?php echo maintenance_status_logistic_h(isset($result_join['ts_mtn_number']) ? (string) $result_join['ts_mtn_number'] : ''); ?></td>
        <td align="left">
          <a target="_blank" href="detail_job?ts_id=<?php echo $ts_id; ?>"><?php echo $detail_html; ?></a><br />
          <?php echo maintenance_status_logistic_h(isset($result_join['trp_product']) ? (string) $result_join['trp_product'] : ''); ?> &nbsp;
          <?php echo maintenance_status_logistic_h(isset($result_join['trp_sn']) ? (string) $result_join['trp_sn'] : ''); ?><br />
          อายุเครื่อง <?php echo maintenance_status_logistic_h(isset($result_join['trp_repair_age']) ? (string) $result_join['trp_repair_age'] : ''); ?><br />
          <?php if ($ins === $exp_ins) { ?>
          <span style="color:#FF0000"><?php echo maintenance_status_logistic_h($ins); ?></span>
          <?php } else { ?>
          <span style="color:#008000"><?php echo maintenance_status_logistic_h($ins); ?></span>
          <?php } ?>
        </td>
        <td align="left"><?php echo maintenance_status_logistic_h(isset($result_join['trp_repair_inform']) ? (string) $result_join['trp_repair_inform'] : ''); ?><br /><br /></td>
        <td align="left">
          อาการที่ช่างประเมิน : <br /><?php echo maintenance_status_logistic_h(isset($result_join['trp_repair_technician']) ? (string) $result_join['trp_repair_technician'] : ''); ?><br /><br />
          รายการที่เปลี่ยน/ซ่อม<br />
          <?php echo $list_html; ?>
        </td>
        <td align="center">
          <?php echo maintenance_status_logistic_h($success_date); ?><br />
          <?php echo 'รอ QC ' . cal_wait_date($success_date); ?>
        </td>
        <td align="center">
          <a href="qc_checklist?trp_id=<?php echo (int) $trp_id; ?>" rel="noopener noreferrer" class="mn-status-wait-qc-checklist-link" title="QC checklist">
            <i class="fa fa-clipboard fa-2x" aria-hidden="true"></i>
          </a>
        </td>
      </tr>
      <tr>
        <td colspan="6"><hr /></td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="6" style="color:#FF9900">รวมเครื่องที่รอ QC <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    <br />
    </div>
  </div>
</body>
</html>
