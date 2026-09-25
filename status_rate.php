<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการ «รอประเมิน» — แสดง `transac` + `transac_repair` ที่ `trp_status_job = รอประเมิน` พร้อม filter บริษัท / สินค้า / ประเภทประกัน
 * Refactor จาก `old_system/status_rate.php` ให้ใช้ `require_auth`, ธีม mn-*, mysqli + helper, URL ไม่มี `.php`, prefetch `transac_spare` ต่อ `trp_id`
 *
 * องค์ประกอบหลัก:
 * - `maintenance_status_rate_*` ใน `includes/status_rate_helpers.php`
 * - ดึงแถวร่วมกับ `maintenance_wait_hardware_fetch_join_rows` / `maintenance_wait_hardware_prefetch_spare_by_trp_ids`
 *
 * Flow:
 * require_auth → bootstrap → parse GET → SQL + fetch → prefetch spare → ปิด `$connect` → render ตาราง
 *
 * Example:
 * เปิด `status_rate` หรือ `status_rate?company=...` / `?product=...` / `?trp_insurance=insurance&product=...`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_rate_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$status_label = maintenance_status_rate_status_literal();
$filters = maintenance_status_rate_parse_get_filters($_GET);
$arr_com = request_company($status_label);
$arr_product = request_product($status_label);
$arr_length_com = count($arr_com);
$arr_length_product = count($arr_product);

$sql_join = maintenance_status_rate_build_list_sql(
    $connect,
    $filters['company'],
    $filters['product'],
    $filters['insurance_param'],
    $filters['insurance_db']
);
$join_rows = maintenance_wait_hardware_fetch_join_rows($connect, $sql_join);

$trp_ids = array();
foreach ($join_rows as $jr) {
    if (!empty($jr['trp_id'])) {
        $trp_ids[] = (int) $jr['trp_id'];
    }
}
$spare_by_trp = maintenance_wait_hardware_prefetch_spare_by_trp_ids($connect, $trp_ids);
maintenance_db_odbc_close($connect);

$row_count = count($join_rows);
$insurance_param = $filters['insurance_param'];
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
<link rel="stylesheet" href="css/mn-page-status-rate.css" />
<title>รอประเมิน</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/status_rate-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-rate-page">
  <div class="header">
    <?php
    $page = 'status_rate';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-rate-table" width="100%">
      <thead>
      <tr class="mn-status-rate-row-under mn-status-rate-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="10%"><div align="center"><b>เลข / วันที่รับ</b></div></th>
        <th width="20%"><div align="center">
          <b>บริษัท</b><br />
          <select class="custom-select" id="mn_rate_head_company" name="mn_rate_head_company" onchange="mnStatusRateSortCompany(this.value);">
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
        <th width="10%"><div align="center">
          <b>Product</b><br />
          <input type="hidden" id="mn_rate_txt_head_product" name="mn_rate_txt_head_product" value="<?php echo htmlspecialchars($filters['product'], ENT_QUOTES, 'UTF-8'); ?>" />
          <select class="custom-select" id="mn_rate_head_product" name="mn_rate_head_product" onchange="mnStatusRateSortProduct(this.value);">
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
        <th width="10%"><div align="center"><b>ประกัน</b><br />
          <select class="custom-select" id="mn_rate_head_insurance" name="mn_rate_head_insurance" onchange="mnStatusRateSortInsurance(this.value);">
            <option value="All"<?php echo $insurance_param === '' ? ' selected="selected"' : ''; ?>>All</option>
            <option value="insurance"<?php echo $insurance_param === 'insurance' ? ' selected="selected"' : ''; ?>>อยู่ในประกัน</option>
            <option value="exp_insurance"<?php echo $insurance_param === 'exp_insurance' ? ' selected="selected"' : ''; ?>>หมดประกัน</option>
            <option value="ma_insurance"<?php echo $insurance_param === 'ma_insurance' ? ' selected="selected"' : ''; ?>>อยู่ในประกันซ่อม 3 เดือน</option>
          </select>
        </div></th>
        <th width="10%"><div align="center"><b>เครื่องสำรอง</b></div></th>
        <th width="15%"><div align="center"><b>อาการที่แจ้ง</b></div></th>
        <th width="5%"><div align="center"><b>ผู้รับเรื่อง</b></div></th>
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
            $has_spare = isset($spare_by_trp[$trp_id]);
            $detail_html = maintenance_wait_hardware_contact_html($result_join);
            $insurance_val = isset($result_join['trp_insurance']) ? (string) $result_join['trp_insurance'] : '';
            $ins_class = maintenance_status_rate_insurance_cell_class($insurance_val);
            $spare_class = $has_spare ? 'mn-status-rate-spare--yes' : 'mn-status-rate-spare--no';
            $mtn = isset($result_join['ts_mtn_number']) ? (string) $result_join['ts_mtn_number'] : '';
            $recv = isset($result_join['trp_receive_date']) ? (string) $result_join['trp_receive_date'] : '';
            $trp_product = isset($result_join['trp_product']) ? (string) $result_join['trp_product'] : '';
            $trp_sn = isset($result_join['trp_sn']) ? (string) $result_join['trp_sn'] : '';
            $inform = isset($result_join['trp_repair_inform']) ? (string) $result_join['trp_repair_inform'] : '';
            $backup = isset($result_join['trp_backup']) ? (string) $result_join['trp_backup'] : '';
            ?>
      <tr id="rowA<?php echo (int) $i; ?>" class="mn-status-rate-row-under" style="border-collapse:collapse; background-color:#fff;" onmouseover="ChangeHoverBGColor(this)" onmouseout="RestoreHoverBGColor(this)">
        <td align="center"><?php echo (int) $i; ?></td>
        <td align="left"><?php echo 'เลขที่งานซ่อม ' . maintenance_status_logistic_h($mtn) . '<br />'; ?>
          <?php echo 'วันที่รับซ่อม ' . maintenance_status_logistic_h($recv) . '<br />'; ?>
          <?php echo 'รอประเมิน ' . cal_wait_date($recv); ?></td>
        <td align="left"><a target="_blank" href="detail_job?ts_id=<?php echo $ts_id; ?>"><?php echo $detail_html; ?></a></td>
        <td align="left"><?php echo maintenance_status_logistic_h($trp_product) . '<br />' . maintenance_status_logistic_h($trp_sn); ?></td>
        <td align="center"><div class="<?php echo htmlspecialchars($ins_class, ENT_QUOTES, 'UTF-8'); ?>"><?php echo maintenance_status_logistic_h($insurance_val); ?></div></td>
        <td align="center">
          <?php if ($has_spare) { ?>
          <div class="<?php echo htmlspecialchars($spare_class, ENT_QUOTES, 'UTF-8'); ?>">มีสำรอง</div>
          <?php } else { ?>
          <div class="<?php echo htmlspecialchars($spare_class, ENT_QUOTES, 'UTF-8'); ?>">ไม่มีสำรอง</div>
          <?php } ?>
        </td>
        <td align="left"><?php echo maintenance_status_logistic_h($inform); ?><br /><?php echo maintenance_status_logistic_h($backup); ?></td>
        <td align="center"><?php echo maintenance_status_logistic_h(isset($result_join['ts_user_recive']) ? $result_join['ts_user_recive'] : ''); ?></td>
        <td align="center">
          <a href="javascript:void(0);" onclick="mnStatusRateEditStatus(<?php echo (int) $trp_id; ?>,<?php echo (int) $i; ?>);"><div class="mn-status-rate-edit"><i class="fa fa-edit fa-2x"></i></div></a>
        </td>
      </tr>
      <tr>
        <td colspan="9"><hr /></td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="9" class="mn-status-rate-summary">รวมงานที่รอประเมิน <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    <br />
    </div>
  </div>
</body>
</html>
