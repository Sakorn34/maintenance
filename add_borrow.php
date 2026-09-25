<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าเพิ่มรายการยืมอุปกรณ์ — refactor จาก `old_system/add_borrow.php` ให้ใช้ auth, bootstrap mysqli, ธีม mn-*, modal แทน `alert`
 * บันทึกผ่าน `insert_borrow` (GET) — AJAX ค้นหาใช้ `search_cusname`, `search_cusname_security`, `search_address` ที่รากโปรเจกต์
 *
 * Flow:
 * require_auth → header → bootstrap → `bootstrap_logistic_recorder` (ชื่อผู้รับเรื่องจาก session) → ปิด `$connect` → render — logic ใน `js/add_borrow-page.js`
 *
 * Example:
 * กรอกฟอร์มแล้วกด Save → redirect ไป `insert_borrow?...` → redirect กลับ `status_borrow`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/add_borrow_endpoints.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

maintenance_db_odbc_close($connect);

$today = date('Y-m-d');
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
<link rel="stylesheet" href="css/mn-page-add-borrow.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>เพิ่มรายการยืมอุปกรณ์</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript">
window.MN_ADD_BORROW = <?php echo json_encode(maintenance_add_borrow_client_config(), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script type="text/javascript" src="js/add_borrow-page.js"></script>
</head>
<body class="mn-addjob-page mn-add-borrow-page">
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
    <div class="mn-form-centered mn-add-borrow-inner">
    <table class="mn-add-borrow-toplink" width="100%">
      <tr>
        <td><a href="status_borrow" class="mn-add-borrow-backlink"><i class="fa fa-arrow-left" aria-hidden="true"></i> กลับรายการยืม</a></td>
      </tr>
    </table>
    <hr />
    <table id="table1" class="mn-add-borrow-table" width="100%">
      <tr>
        <td width="25%"></td>
        <td width="25%"></td>
        <td width="25%"></td>
        <td width="25%"></td>
      </tr>
      <tr class="mn-add-borrow-headrow">
        <td colspan="4" align="center"><p><b>เพิ่มรายการยืมอุปกรณ์</b></p></td>
      </tr>
      <tr><td colspan="4"></td></tr>
      <tr class="spaceUnder">
        <td colspan="2" width="50%"><b class="ex2">วันที่รับเรื่อง : </b> &nbsp;
          <input type="date" name="b_date" id="b_date" value="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" style="width:auto;" />
        </td>
        <td colspan="2" width="50%"><b class="ex2">ผู้รับเรื่อง : </b> &nbsp;
          <input type="hidden" name="b_user_receive" id="b_user_receive" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
          <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
        </td>
      </tr>
      <tr>
        <td colspan="4"><hr /></td>
      </tr>
      <tr class="spaceUnder05">
        <td colspan="4"><p class="ex"><b>*กรุณาเลือกประเภทลูกค้า</b> &nbsp;
          <input name="b_customer_group" id="b_customer_group1" type="radio" value="" onclick="add_hideCustomer('enduser');" />
          <b>End user</b> &nbsp; &nbsp; &nbsp;
          <input name="b_customer_group" id="b_customer_group2" type="radio" value="" onclick="add_hideCustomer('security');" />
          <b>บริษัท รปภ.</b></p></td>
      </tr>
      <tr class="spaceUnder05">
        <td colspan="2"><b class="ex" id="b_enduser1" style="display:none;">บริษัท End user</b><b class="ex" id="b_enduser2" style="display:none;">ผู้ว่าจ้าง</b></td>
        <td colspan="2"><b class="ex" id="b_security" style="display:none;">บริษัท รปภ.</b></td>
      </tr>
      <tr class="spaceUnder">
        <td colspan="2"><div class="search-box-user">
            <input type="text" autocomplete="off" placeholder="Search user company" name="b_enduser_com" id="b_enduser_com" style="width:400px; max-width:100%; display:none;" />
            <div class="result"></div>
          </div></td>
        <td colspan="2"><div class="search-box-sec">
            <input type="text" autocomplete="off" placeholder="Search security company" name="b_security_com" id="b_security_com" style="width:400px; max-width:100%; display:none;" />
            <div class="result_sec"></div>
          </div></td>
      </tr>
      <tr>
        <td colspan="4"><p class="ex"><b>ข้อมูลผู้ยืม : </b></p></td>
      </tr>
      <tr class="spaceUnder">
        <td colspan="4"><p style="display:inline"><b>Name </b>
            <input type="text" name="b_borrow_name" id="b_borrow_name" style="width:auto;" placeholder="*โปรดระบุ" onchange="autochangeAdd_name(this.value);" />
            &nbsp; &nbsp; <b> Tel </b>
            <input type="text" name="b_borrow_tel" id="b_borrow_tel" style="width:150px;" onchange="autochangeAdd_tel(this.value);" />
            &nbsp; &nbsp; <b>Line </b>
            <input type="text" name="b_borrow_line" id="b_borrow_line" style="width:150px;" />
            &nbsp; &nbsp; <b>Email </b>
            <input type="text" name="b_borrow_email" id="b_borrow_email" style="width:160px;" />
          </p>
        </td>
      </tr>
      <tr>
        <td colspan="4"><p class="ex"><b>ที่อยู่จัดส่งอุปกรณ์ที่ยืม</b>
          <input name="b_send_group" id="b_send_group1" type="radio" value="" checked="checked" onclick="add_hideSend('parcel');" />
          <b>ส่งพัสดุ</b> &nbsp; &nbsp; &nbsp;
          <input name="b_send_group" id="b_send_group2" type="radio" value="" onclick="add_hideSend('walkin');" />
          <b>Walk in/ฝาก</b></p>
        </td>
      </tr>
      <tr class="spaceUnder" id="row_receive">
        <td colspan="4"><b>ผู้รับ &nbsp;</b>
          <input type="text" name="b_address_name" id="b_address_name" style="width:auto;" />
          &nbsp; &nbsp; <b> Tel </b>
          <input type="text" name="b_address_tel" id="b_address_tel" style="width:auto;" />
        </td>
      </tr>
      <tr id="row_address">
        <td><b>เลขที่ </b>
          <input type="text" name="b_address1" id="b_address1" style="width:170px;" /></td>
        <td colspan="3" align="left"><div class="search-box-tambon">
            <input type="text" name="b_address2" id="b_address2" placeholder="พิมพ์ตำบล เพื่อค้นหา" />
            <div class="result_tambon"></div>
          </div></td>
      </tr>
      <tr>
        <td colspan="4"><hr /></td>
      </tr>
      <tr>
        <td colspan="4"><p class="ex2"><b>ข้อมูลอุปกรณ์ที่ยืม &nbsp; &nbsp; </b></p></td>
      </tr>
      <tr valign="top">
        <td colspan="4"><textarea name="b_borrow_hardware" id="b_borrow_hardware" rows="3" style="width:100%" placeholder="ระบุรายละเอียดอุปกรณ์ที่ยืม..."></textarea>
        </td>
      </tr>
      <tr>
        <td colspan="4"><hr /></td>
      </tr>
      <tr>
        <td colspan="4" style="font-size:24px; text-align:center; color:#009900"><p><a href="#" class="mn-add-borrow-save" onclick="saveBorrow(); return false;"><i class="fa fa-save" aria-hidden="true"></i> Save</a></p></td>
      </tr>
    </table>
    <br />
    <br />
    </div>
  </div>
</body>
</html>
