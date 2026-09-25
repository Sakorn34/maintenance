<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายการยืมอุปกรณ์ — แสดง `Borrow` จำกัด 100 แถว เรียงตามสถานะ พร้อมอัปเดตสถานะ / tracking / วันที่ส่งคืน
 * Refactor จาก `old_system/status_borrow.php` — auth, ธีม mn-*, MySQL `LIMIT` แทน `TOP`, modal แทน `confirm`, ลิงก์ `add_borrow` ไม่มี `.php`
 *
 * Flow:
 * require_auth → bootstrap → ดึงรายการ → ปิด `$connect` → render → บันทึกผ่าน `insert_borrow`
 *
 * Example:
 * เปิด `status_borrow` แก้สถานะแล้วกด Save
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_borrow_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$borrow_rows = maintenance_status_borrow_fetch_rows($connect);
maintenance_db_odbc_close($connect);

$status_opts = maintenance_status_borrow_status_options();
$row_count = count($borrow_rows);
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
<link rel="stylesheet" href="css/mn-page-status-borrow.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>รายการยืมอุปกรณ์</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/status_borrow-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-borrow-page">
  <div class="header">
    <?php
    $page = 'status_borrow';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-status-borrow-inner">
    <table class="mn-status-borrow-toplink" width="100%">
      <tr>
        <td colspan="8"><a href="add_borrow" class="mn-status-borrow-addlink"><i class="fa fa-plus-circle" aria-hidden="true"></i> เพิ่มรายการยืมอุปกรณ์</a></td>
      </tr>
    </table>
    <hr />
    <table id="table1" class="mn-status-borrow-table" width="100%">
      <thead>
      <tr class="mn-status-borrow-headrow">
        <th align="center" style="width:50px;"><b>ลำดับ</b></th>
        <th align="center" style="width:120px;"><b>วันที่ยืม</b></th>
        <th align="center" style="width:120px;"><b>ระยะเวลายืม</b></th>
        <th align="center" style="width:300px;"><b>บริษัท</b></th>
        <th align="center" style="width:300px;"><b>รายการยืม</b></th>
        <th align="center" style="width:200px;"><b>ข้อมูลผู้ยืม</b></th>
        <th align="center" style="width:120px;"><b>ผู้รับเรื่อง</b></th>
        <th align="center" style="width:120px;"><b>สถานะ</b></th>
        <th align="center" style="width:200px;"><b>สถานะติดตาม</b></th>
        <th align="center" style="width:50px;"></th>
      </tr>
      </thead>
      <tbody>
      <?php
        $i = 0;
        foreach ($borrow_rows as $result) {
            $i += 1;
            $b_id = (int) (isset($result['b_id']) ? $result['b_id'] : 0);
            $cur_status = isset($result['b_status']) ? trim((string) $result['b_status']) : '';
            $b_date = isset($result['b_date']) ? (string) $result['b_date'] : '';
            $hw = isset($result['b_borrow_hardware']) ? (string) $result['b_borrow_hardware'] : '';
            $name = isset($result['b_borrow_name']) ? (string) $result['b_borrow_name'] : '';
            $tel = isset($result['b_borrow_tel']) ? (string) $result['b_borrow_tel'] : '';
            $line = isset($result['b_borrow_line']) ? (string) $result['b_borrow_line'] : '';
            $recv = isset($result['b_user_receive']) ? (string) $result['b_user_receive'] : '';
            $track = isset($result['b_tracking']) ? (string) $result['b_tracking'] : '';
            $detail_co = maintenance_status_borrow_company_html($result);
            $borrower_block = maintenance_status_logistic_h($name) . ' ' . maintenance_status_logistic_h($tel)
                . '<br>' . maintenance_status_logistic_h($line);
            ?>
      <tr class="mn-status-borrow-datarow" valign="top">
        <td align="center" valign="middle"><?php echo (int) $i; ?></td>
        <td align="center" valign="middle"><?php echo maintenance_status_logistic_h($b_date); ?></td>
        <td align="center" valign="middle"><?php echo cal_wait_date($b_date); ?></td>
        <td valign="middle"><?php echo $detail_co; ?></td>
        <td valign="middle"><?php echo maintenance_status_logistic_h($hw); ?></td>
        <td valign="middle"><?php echo $borrower_block; ?></td>
        <td align="center" valign="middle"><?php echo maintenance_status_logistic_h($recv); ?></td>
        <td align="center" valign="middle">
          <select class="custom-select mn-status-borrow-statussel" id="b_status<?php echo (int) $i; ?>" name="b_status<?php echo (int) $i; ?>" data-row="<?php echo (int) $i; ?>">
            <?php
            foreach ($status_opts as $opt) {
                $sel = ($cur_status === $opt) ? ' selected="selected"' : '';
                ?>
            <option value="<?php echo maintenance_status_logistic_h($opt); ?>"<?php echo $sel; ?>><?php echo maintenance_status_logistic_h($opt); ?></option>
                <?php
            }
            ?>
          </select>
          <input type="date" name="b_date_return<?php echo (int) $i; ?>" id="b_date_return<?php echo (int) $i; ?>" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" class="mn-status-borrow-datereturn" />
        </td>
        <td><textarea name="b_tracking<?php echo (int) $i; ?>" id="b_tracking<?php echo (int) $i; ?>" rows="3" class="mn-status-borrow-tracking"><?php echo maintenance_status_logistic_h($track); ?></textarea></td>
        <td align="center" valign="middle">
          <a href="javascript:mnStatusBorrowSave(<?php echo (int) $b_id; ?>, <?php echo (int) $i; ?>);" class="mn-status-borrow-save"><i class="fa fa-save" aria-hidden="true"></i><br />Save</a>
        </td>
      </tr>
            <?php
        }
        ?>
      <tr>
        <td colspan="10" class="mn-status-borrow-summary">รวม <?php echo (int) $row_count; ?> รายการ</td>
      </tr>
      </tbody>
    </table>
    </div>
  </div>
</body>
</html>
