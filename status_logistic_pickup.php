<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการแจ้งเข้ารับพัสดุหน้างาน — งานที่มี `trp_contact_pickup` แล้วแต่ `trp_date_pickup` ยังว่าง
 * Refactor จาก `old_system/status_logistic_pickup.php` — auth, ธีม mn-*, JOIN ครั้งเดียว, URL ไม่มี `.php`, modal แทน `alert`/`confirm`
 *
 * Flow:
 * require_auth → bootstrap → ดึงรายการ → ปิด `$connect` → render ตาราง → บันทึกผ่าน `save_pickup_wait_hardware`
 *
 * Example:
 * เปิด `status_logistic_pickup` แล้วกรอกวันที่รับ / ขนส่ง / tracking / ผู้บันทึก แล้วกด Save
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_logistic_pickup_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$rows = maintenance_status_logistic_pickup_fetch_rows($connect);
$arr_logis = logistisc();
$arr_len_logis = count($arr_logis);
maintenance_db_odbc_close($connect);

$row_count = count($rows);
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
<link rel="stylesheet" href="css/mn-page-status-logistic-pickup.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>แจ้งเข้ารับพัสดุ</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/status_logistic_pickup-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-logistic-pickup-page">
  <div class="header">
    <?php
    $page = 'status_logistic_pickup';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
    <table id="table1" class="mn-status-logistic-pickup-table" width="100%">
      <thead>
      <tr class="mn-status-logistic-pickup-row-under mn-status-logistic-pickup-thead">
        <th width="5%"><div align="center"><b>No</b></div></th>
        <th width="20%"><div align="center"><b>บริษัท/ที่อยู่</b></div></th>
        <th width="10%"><div align="center"><b>สินค้า</b></div></th>
        <th width="15%"><div align="center"><b>ข้อมูลคนติดต่อหน้างาน</b></div></th>
        <th width="10%"><div align="center"><b>วันที่เข้ารับ</b></div></th>
        <th width="10%"><div align="center"><b>Logistics</b></div></th>
        <th width="10%"><div align="center"><b>Tracking</b></div></th>
        <th width="10%"><div align="center"><b>ผู้แจ้งบันทึก</b></div></th>
        <th width="10%"><div align="center"><b>Edit</b></div></th>
      </tr>
      </thead>
      <tbody>
      <?php
        $i = 0;
        foreach ($rows as $result_trp) {
            $i += 1;
            $trp_id = (int) (isset($result_trp['trp_id']) ? $result_trp['trp_id'] : 0);
            $prod = isset($result_trp['trp_product']) ? (string) $result_trp['trp_product'] : '';
            $sn = isset($result_trp['trp_sn']) ? trim((string) $result_trp['trp_sn']) : '';
            $pd = maintenance_status_logistic_h($prod);
            if ($sn !== '') {
                $pd .= ' -- ' . maintenance_status_logistic_h($sn);
            }
            $detail_html = maintenance_status_logistic_pickup_contact_html($result_trp);
            $contact_pickup = isset($result_trp['trp_contact_pickup']) ? (string) $result_trp['trp_contact_pickup'] : '';
            $def_track = isset($result_trp['spare_tsp_tracking']) && $result_trp['spare_tsp_tracking'] !== null
                ? (string) $result_trp['spare_tsp_tracking'] : '';
            ?>
      <tr class="mn-status-logistic-pickup-row-under">
        <td align="center"><?php echo (int) $i; ?></td>
        <td align="left"><?php echo $detail_html; ?></td>
        <td align="left"><?php echo $pd; ?></td>
        <td align="left" class="mn-status-logistic-pickup-contact"><?php echo maintenance_status_logistic_h($contact_pickup); ?></td>
        <td align="center"><input type="date" name="trp_date_pickup<?php echo (int) $i; ?>" id="trp_date_pickup<?php echo (int) $i; ?>" value="" /></td>
        <td align="center">
          <select class="custom-select" name="trp_logistics_pickup<?php echo (int) $i; ?>" id="trp_logistics_pickup<?php echo (int) $i; ?>">
            <option value="">ขนส่ง..</option>
            <?php
            for ($a = 0; $a < $arr_len_logis; $a++) {
                $opt = (string) $arr_logis[$a];
                $opt_enc = maintenance_status_logistic_h($opt);
                ?>
            <option value="<?php echo $opt_enc; ?>"><?php echo $opt_enc; ?></option>
                <?php
            }
            ?>
          </select>
        </td>
        <td align="center">
          <input type="text" name="trp_tracking_pickup<?php echo (int) $i; ?>" id="trp_tracking_pickup<?php echo (int) $i; ?>" autocomplete="off" value="<?php echo maintenance_status_logistic_h($def_track); ?>" class="mn-status-logistic-pickup-tracking" onkeypress="return (event.charCode >= 65 &amp;&amp; event.charCode &lt;= 90) || (event.charCode >= 97 &amp;&amp; event.charCode &lt;= 122) || (event.charCode >= 48 &amp;&amp; event.charCode &lt;= 57)" />
        </td>
        <td align="center">
          <input type="hidden" id="trp_user_inform_logistics<?php echo (int) $i; ?>" name="trp_user_inform_logistics<?php echo (int) $i; ?>" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
          <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
        </td>
        <td align="center">
          <div id="iconsave<?php echo (int) $i; ?>">
            <a href="javascript:mnStatusLogisticPickupSave(<?php echo (int) $trp_id; ?>,<?php echo (int) $i; ?>);">
              <div class="mn-status-logistic-pickup-save"><i class="fa fa-save fa-2x"></i></div>
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
        <td colspan="9" class="mn-status-logistic-pickup-summary">รวม <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    </div>
  </div>
</body>
</html>
