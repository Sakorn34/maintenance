<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าเปิดใบซ่อม (คัดลอกจาก add_job.php รากโปรเจกต์) ใช้ mysqli ผ่าน includes/bootstrap_add_job_page.php
 * และ includes/title-h.php (แถบเมนู) แทน connect.php / title-h.php ราก
 *
 * สไตล์หน้าเว็บแยกไปที่โฟลเดอร์ `css/` (mn-theme, mn-layout, mn-nav, mn-page-addjob)
 *
 * Flow:
 * require `includes/require_auth.php` ก่อน (production จะตรวจ `$_SESSION['profile']`; development ข้ามการเช็คสิทธิ์)
 * โหลด employee + รายการ Product จาก MySQL (`pn_name`; ตาราง product_name หรือ product_name) แล้ว render ฟอร์ม; ผู้รับเรื่องเป็นชื่อเดียวตามผู้ login (hidden); logic ฝั่งเบราว์เซอร์อยู่ที่ `js/add_job-form.js`
 * การบันทึก: ส่ง POST ไป `insert_job` (ไม่ใช้ query string — รองรับ `&` ในชื่อ/ที่อยู่) ที่รากโปรเจกต์
 * ไล่ปัญหา insert ได้จาก debug ใน `includes/add_job_insert_debug.php` + log ที่ `storage/logs/` เมื่อเปิดใน env.local
 * อ่าน URL ปลายทางรวมอยู่ที่ includes/add_job_endpoints.php (ไม่มีนามสกุล .php) → ส่งเป็น window.MN_ADDJOB
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/add_job_endpoints.php';
require_once __DIR__ . '/includes/bootstrap_add_job_page.php';
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
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>เปิดใบซ่อม</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript">
window.MN_ADDJOB = <?php echo json_encode(maintenance_new_add_job_client_config(), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script type="text/javascript" src="js/add_job-form.js"></script>
</head>
<body class="mn-addjob-page">
  <div class="header">
    <?php
    $page = 'add_job';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <form id="form1" name="form1" method="get" action="">
    <div class="mn-form-centered">
      <table id="table1" >
		<tr>
          <td colspan="4" align="center" style="font-size:16px; padding:1px; color:white;"></td>
        </tr>
		<tr>
          <td colspan="4"><br /></td>
        </tr>
        <tr class="mn-addjob-mode-row">
          <td colspan="4">
            <b class="ex2">ประเภทการเปิดงาน :</b> &nbsp;
            <label><input type="radio" name="add_job_mode_radio" id="add_job_mode_new" value="new" checked="checked" onclick="mnAddJobOnModeChange('new');" /> เปิดงานซ่อมใหม่</label>
            &nbsp; &nbsp;
            <label><input type="radio" name="add_job_mode_radio" id="add_job_mode_existing" value="existing" onclick="mnAddJobOnModeChange('existing');" /> เพิ่มอุปกรณ์งานซ่อมเดิม</label>
            <input type="hidden" name="add_job_mode" id="add_job_mode" value="new" />
            <input type="hidden" name="existing_ts_id" id="existing_ts_id" value="" />
          </td>
        </tr>
        <tr id="mn-addjob-existing-summary" class="mn-addjob-existing-summary" style="display:none;">
          <td colspan="4">
            <div class="mn-addjob-existing-summary-box">
              <b class="ex2">งานที่เลือก :</b>
              <span id="mn-addjob-existing-summary-text"></span>
              &nbsp;
              <a href="javascript:void(0);" id="mn-addjob-change-existing">[ เปลี่ยนงาน ]</a>
            </div>
          </td>
        </tr>
        <tbody id="mn-addjob-transac-block">
        <tr class="spaceUnder">
          <td colspan="2" width="50%"><b class="ex2">วันที่รับเรื่อง : </b> &nbsp;
            <input type="date" name="ts_date" id="ts_date" value="<?php echo date("Y-m-d"); ?>" style="width:auto;" />
          </td>
          <td colspan="2" width="50%"><b class="ex2">ผู้รับเรื่อง : </b> &nbsp;
            <?php
            // เทียบเท่า legacy: $array = employee("", $profile->login_name); — ค่ามาจาก bootstrap (กรองตาม login_name)
            $array = $employeeNames;
            $arrLength = count($array);
            $ts_user_recive_value = ($arrLength > 0) ? (string) $array[0] : '';
            ?>
            <input type="hidden" name="ts_user_recive" id="ts_user_recive" value="<?php echo htmlspecialchars($ts_user_recive_value, ENT_QUOTES, 'UTF-8'); ?>" />
            <span class="mn-user-receive-display ex2"><?php echo $ts_user_recive_value !== '' ? htmlspecialchars($ts_user_recive_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
            <!-- &nbsp; &nbsp; &nbsp; เลขที่งานซ่อม <input type="text" name="ts_mtn_number" id="ts_mtn_number" style="width:auto;" placeholder="YYxxxx" /> -->
          </td>
        </tr>
        <tr>
          <td colspan="4"><hr /></td>
        </tr>
        <tr class="spaceUnder05">
          <td colspan="4"><b class="ex">*กรุณาเลือกประเภทลูกค้า</b> &nbsp;
            <input name="ts_customer_group" id="ts_customer_group1" type="radio" value="" onclick="javascript: add_hideCustomer('enduser');" />
            <b>End user</b> &nbsp; &nbsp; &nbsp;
            <input name="ts_customer_group" id="ts_customer_group2" type="radio" value="" onclick="javascript: add_hideCustomer('security');" />
            <b>บริษัท รปภ.</b></td>
        </tr>
        <tr class="spaceUnder05">
          <td colspan="2"><b class="ex"  id="lb_enduser1" style="display:none;">บริษัท End user</b><b class="ex"  id="lb_enduser2"  style="display:none;">ผู้ว่าจ้าง</b></td>
          <td colspan="2"><b class="ex"  id="lb_security" style="display:none;">บริษัท รปภ.</b></td>
        </tr>
        <tr class="spaceUnder">
          <td colspan="2"><div class="search-box-user">
              <input type="text" autocomplete="off" placeholder="Search user company" name="ts_enduser_com" id="ts_enduser_com" style="width:400px; display:none;" />
              <div class="result"></div>
            </div></td>
          <td colspan="2"><div class="search-box-sec">
              <input type="text" autocomplete="off" placeholder="Search security company" name="ts_security_com" id="ts_security_com" style="width:400px; display:none;" />
              <div class="result_sec"></div>
            </div></td>
        </tr>
        <tr>
          <td colspan="4"><p class="ex"><b>ข้อมูลผู้แจ้ง : </b></p></td>
        </tr>
        <tr class="spaceUnder">
          <td colspan="4"><p style="display:inline"><b>Name </b>
              <input type="text" name="ts_report_name" id="ts_report_name" style="width:auto;" placeholder="*โปรดระบุ"  onchange="javascript: autochangeAdd_name(this.value);" />
              &nbsp; &nbsp; <b> Tel </b>
              <input type="text" name="ts_report_tel" id="ts_report_tel" style="width:150px;" onchange="javascript: autochangeAdd_tel(this.value);" />
              &nbsp; &nbsp; <b>Line </b>
              <input type="text" name="ts_report_line" id="ts_report_line" style="width:150px;" />
              &nbsp; &nbsp; <b>Email </b>
              <input type="text" name="ts_report_email" id="ts_report_email" style="width:170px;" />
            </p>
          </td>
        </tr>
        <tr style="display: none;">
          <td colspan="4"><p class="ex"><b>ข้อมูลผู้อนุมัติ : </b></p></td>
        </tr>
        <tr class="spaceUnder" style="display: none;">
          <td colspan="4"><p style="display:inline"><b>Name </b>
              <input type="text" name="ts_approve_name" id="ts_approve_name" style="width:auto;" />
              &nbsp; &nbsp; <b> Tel </b>
              <input type="text" name="ts_approve_tel" id="ts_approve_tel" style="width:auto;" />
              &nbsp; &nbsp; <b>Line </b>
              <input type="text" name="ts_approve_line" id="ts_approve_line" style="width:auto;" />
              &nbsp; &nbsp; <b>Email </b>
              <input type="text" name="ts_approve_email" id="ts_approve_email" style="width:auto;" />
            </p>
          </td>
        </tr>
        <tr>
          <td colspan="4"><p class="ex"><b>ที่อยู่จัดส่งเครื่องสำรอง</b></p></td>
        </tr>
        <tr class="spaceUnder">
          <td colspan="3"><b>ผู้รับ &nbsp;</b>
            <input type="text" name="ts_address_name" id="ts_address_name" style="width:auto;" />
            &nbsp; &nbsp; <b> Tel </b>
            <input type="text" name="ts_address_tel" id="ts_address_tel" style="width:auto;" />
			<b>&nbsp; &nbsp; ส่งสำรองไปที่ : </b>
			<b id="at_site" style="display:none;"><input name="ts_radio_address" id="ts_radio_address1" type="radio" value="" onclick="javascript: radio_address('enduser');" /> หน้างาน</b>
             <b id="at_security" style="display:none;">&nbsp; &nbsp; &nbsp; <input name="ts_radio_address" id="ts_radio_address2" type="radio" value="" onclick="javascript: radio_address('security');" /> บริษัท รปภ.</b>
			<input type="hidden" id="ts_address_name" value="" />
          </td>
        </tr>
        <tr>
          <td><b>เลขที่ </b>
            <input type="text" name="ts_address1" id="ts_address1" style="width:auto;" />
          </td>
          <td colspan="3"><div class="search-box-tambon">
              <input type="text" name="ts_address2" id="ts_address2" placeholder="พิมพ์ตำบล เพื่อค้นหา" />
              <div class="result_tambon"></div>
            </div>
		  </td>
        </tr>
        <tr class="spaceUnder">
          <td colspan="4"><hr />
          </td>
        </tr>
        </tbody>
        <tr>
          <td><p class="ex"><b>ข้อมูลเครื่องซ่อม &nbsp; &nbsp; <a href='javascript:addListMaintenance("del");'><i class="fa fa-minus-circle" aria-hidden="true" style="color:#FF0000"> Del </i></a> &nbsp; &nbsp; <a href='javascript:addListMaintenance("add");'><i class="fa fa-plus-circle" aria-hidden="true" style="color:#339900"> Add </i></a></b></p></td>
          <td align="center"><p class="ex"><b>S/N เครื่องซ่อม</b></p></td>
          <td colspan="2"><p class="ex2"><b>ข้อมูลเครื่องสำรอง &nbsp; &nbsp;</b> &nbsp; <a target = '_blank' href="add_spare" >[ <i class="fa fa-plus-circle" aria-hidden="true" style="color:#333333"> เพิ่มเครื่องสำรอง </i>]</a></p></td>
        </tr>
        <?php
				$array1 = $productNames;
				$arrLength1 = count($array1);
		?>
        <tr valign="top">
          <td><b>รายการที่ 1 </b><br>
            <select class="custom-select" id="pd_mtn1" name="pd_mtn1">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn1" id="sn_mtn1" style="width:70%" placeholder="ระบุภายหลังได้" onkeypress="checkInputFirst_Vowel(event);"  />
          </td>
          <td><b>S/N </b><br>
            <select class="custom-select" name="sn_sp1" id="sn_sp1">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT1" style="display: none;">S/N<br>
            <select class="custom-select" name="sn_spAT1" id="sn_spAT1">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" class="spaceUnder">
          <td><textarea name="cause_mtn1" id="cause_mtn1" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr valign="top" id="r_mt2" style="display:none">
          <td><b>รายการที่ 2 </b><br>
            <select class="custom-select" id="pd_mtn2" name="pd_mtn2">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn2" id="sn_mtn2" style="width:70%" onkeypress="checkInputFirst_Vowel(event);" /></td>
          <td><b>S/N </b>
            <select class="custom-select" name="sn_sp2" id="sn_sp2" style=" width:200px">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT2" style="display: none;">S/N
            <select class="custom-select" name="sn_spAT2" id="sn_spAT2">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" id="r_mt22" style="display:none" class="spaceUnder">
          <td><textarea name="cause_mtn2" id="cause_mtn2" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr valign="top" id="r_mt3" style="display:none">
          <td><b>รายการที่ 3 </b><br>
            <select class="custom-select" id="pd_mtn3" name="pd_mtn3">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn3" id="sn_mtn3" style="width:70%" onkeypress="checkInputFirst_Vowel(event);" /></td>
          <td><b>S/N </b>
            <select class="custom-select" name="sn_sp3" id="sn_sp3" style=" width:200px">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT3" style="display: none;">S/N<br>
            <select class="custom-select" name="sn_spAT3" id="sn_spAT3">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" id="r_mt23" style="display:none" class="spaceUnder">
          <td><textarea name="cause_mtn3" id="cause_mtn3" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr valign="top" id="r_mt4" style="display:none">
          <td><b>รายการที่ 4 </b><br>
            <select class="custom-select" id="pd_mtn4" name="pd_mtn4">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn4" id="sn_mtn4" style="width:70%" onkeypress="checkInputFirst_Vowel(event);" /></td>
          <td><b>S/N </b>
            <select class="custom-select" name="sn_sp4" id="sn_sp4" style=" width:200px">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT4" style="display: none;">S/N<br>
            <select class="custom-select" name="sn_spAT4" id="sn_spAT4">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" id="r_mt24" style="display:none" class="spaceUnder">
          <td><textarea name="cause_mtn4" id="cause_mtn4" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr valign="top" id="r_mt5" style="display:none">
          <td><b>รายการที่ 5 </b><br>
            <select class="custom-select" id="pd_mtn5" name="pd_mtn5">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn5" id="sn_mtn5" style="width:70%" onkeypress="checkInputFirst_Vowel(event);" /></td>
          <td><b>S/N </b>
            <select class="custom-select" name="sn_sp5" id="sn_sp5" style=" width:200px">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT5" style="display: none;">S/N<br>
            <select class="custom-select" name="sn_spAT5" id="sn_spAT5">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" id="r_mt25" style="display:none">
          <td><textarea name="cause_mtn5" id="cause_mtn5" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr valign="top" id="r_mt6" style="display:none">
          <td><b>รายการที่ 6 </b><br>
            <select class="custom-select" id="pd_mtn6" name="pd_mtn6">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn6" id="sn_mtn6" style="width:70%" onkeypress="checkInputFirst_Vowel(event);" /></td>
          <td><b>S/N </b>
            <select class="custom-select" name="sn_sp6" id="sn_sp6" style=" width:200px">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT6" style="display: none;">S/N<br>
            <select class="custom-select" name="sn_spAT6" id="sn_spAT6">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" id="r_mt26" style="display:none">
          <td><textarea name="cause_mtn6" id="cause_mtn6" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr valign="top" id="r_mt7" style="display:none">
          <td><b>รายการที่ 7 </b><br>
            <select class="custom-select" id="pd_mtn7" name="pd_mtn7">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn7" id="sn_mtn7" style="width:70%" onkeypress="checkInputFirst_Vowel(event);" /></td>
          <td><b>S/N </b>
            <select class="custom-select" name="sn_sp7" id="sn_sp7" style=" width:200px">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT7" style="display: none;">S/N<br>
            <select class="custom-select" name="sn_spAT7" id="sn_spAT7">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" id="r_mt27" style="display:none">
          <td><textarea name="cause_mtn7" id="cause_mtn7" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr valign="top" id="r_mt8" style="display:none">
          <td><b>รายการที่ 8 </b><br>
            <select class="custom-select" id="pd_mtn8" name="pd_mtn8">
              <option value="">Product</option>
              <?php for ($a = 0; $a < $arrLength1; $a++) { ?>
              <option value="<?= $array1[$a]; ?>">
              <?= $array1[$a]; ?>
              </option>
              <?php } ?>
            </select>
          </td>
          <td><b>S/N </b><br>
            <input type="text" name="sn_mtn8" id="sn_mtn8" style="width:70%" onkeypress="checkInputFirst_Vowel(event);" /></td>
          <td><b>S/N </b>
            <select class="custom-select" name="sn_sp8" id="sn_sp8" style=" width:200px">
              <option value="">S/N</option>
            </select>
          </td>
          <td><b id="p_spAT8" style="display: none;">S/N<br>
            <select class="custom-select" name="sn_spAT8" id="sn_spAT8">
              <option value="">S/N</option>
            </select>
            </b>
          </td>
        </tr>
        <tr valign="top" id="r_mt28" style="display:none">
          <td><textarea name="cause_mtn8" id="cause_mtn8" rows="2" style="width:100%" placeholder="*โปรดระบุ อาการที่แจ้ง"></textarea></td>
          <td></td>
        </tr>
        <tr>
          <td colspan="4"><hr />
          </td>
        </tr>
        <tbody id="mn-addjob-remarks-block">
        <tr>
          <td colspan="4"><b>***หมายเหตุ</b></td>
        </tr>
        <tr>
          <td colspan="4"><p>
              <textarea name="ts_remarks" id="ts_remarks" cols="100%" rows="3" placeholder="สำหรับกรณีมีรายละเอียดเพิ่มเติม.. เช่น ใส่ path VDO"></textarea>
            </p></td>
        </tr>
        </tbody>
        <tr>
          <td colspan="4"><hr />
          </td>
        </tr>
        <tr>
          <td colspan="4" class="mn-save-row"><p><a href='javascript: save_job();'><i class="fa fa-save" aria-hidden="true"> Save</i></a></p></td>
        </tr>
      </table>
    </div>
      <br />
      <br />
    </form>
  </div>
  <div id="mn-addjob-pick-transac" class="mn-addjob-pick-overlay" style="display:none;" aria-hidden="true">
    <div class="mn-addjob-pick-dialog" role="dialog" aria-labelledby="mn-addjob-pick-title">
      <div class="mn-addjob-pick-head">
        <strong id="mn-addjob-pick-title">เลือกงานซ่อมเดิม</strong>
        <button type="button" class="mn-addjob-pick-close-btn" id="mn-addjob-pick-close" title="ปิด">&times;</button>
      </div>
      <div class="mn-addjob-pick-search">
        <input type="text" id="mn-addjob-pick-term" placeholder="ค้นหาเลขที่ซ่อม / บริษัท End user / บริษัท รปภ." />
        <button type="button" id="mn-addjob-pick-search-btn" class="mn-addjob-pick-search-btn">ค้นหา</button>
      </div>
      <div class="mn-addjob-pick-body">
        <table class="mn-addjob-pick-table" id="table1">
          <thead>
            <tr>
              <th>เลขที่ซ่อม</th>
              <th>บริษัท End user</th>
              <th>บริษัท รปภ.</th>
              <th>วันที่รับเรื่อง</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="mn-addjob-pick-tbody"></tbody>
        </table>
        <p id="mn-addjob-pick-empty" class="mn-addjob-pick-empty" style="display:none;">ไม่พบรายการงานที่เพิ่มเครื่องได้</p>
      </div>
    </div>
  </div>
</body>
</html>
