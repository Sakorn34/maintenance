<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ฟังก์ชัน legacy (product, employee, cal_wait_date, strReplace ฯลฯ) จากระบบเดิม — ถูก include จาก bootstrap หลายหน้า
 * ไม่ส่ง HTTP header และไม่ตั้ง timezone ที่นี่ (ให้หน้า entry / require_auth จัดการ) เพื่อกันซ้ำและกันค่า Content-Type ผิดรูปแบบบนโฮสต์เข้ม
 */

function get_status_addjob($sn_spare)
{
	$txt_return = "";

	if ( trim($sn_spare) != '' ) {	
		$txt_return = "รอส่งสำรอง";
	}else{	
		$txt_return =  "รอเครื่องซ่อม";	
	}
	return $txt_return;
}

/**
 * Purpose:
 * คำนวณระยะเวลาระหว่างวันที่รับเรื่อง (หรือวันที่ส่งเข้ามา) ถึงวันนี้ แบบปฏิทิน (ปี/เดือน/วัน) และครอบ span สีแดงเมื่อรอนาน
 *
 * Parameters สำคัญ:
 * - string $date: วันที่ต้นทาง (รูปแบบที่ strtotime อ่านได้ เช่น Y-m-d)
 *
 * Return value:
 * - string ข้อความระยะเวลา อาจมี tag `<span>` สีแดง หรือค่าว่างถ้าไม่มีวันที่
 */
function cal_wait_date($date)
{
	$trimmed = str_replace(' ', '', (string) $date);
	if ($trimmed === '' || $trimmed === '-') {
		return '';
	}

	$ts = strtotime($date);
	if ($ts === false) {
		return '';
	}

	$tz = new DateTimeZone('Asia/Bangkok');
	$start = DateTime::createFromFormat('Y-m-d', date('Y-m-d', $ts), $tz);
	if ($start === false) {
		return '';
	}
	$start->setTime(0, 0, 0);

	$end = new DateTime('now', $tz);
	$end->setTime(0, 0, 0);

	if ($start > $end) {
		$u_y = 0;
		$u_m = 0;
		$u_d = 0;
	} else {
		$diff = $start->diff($end);
		$u_y = (int) $diff->y;
		$u_m = (int) $diff->m;
		$u_d = (int) $diff->d;
	}

	$send = '';

	if ($u_y <= 0 && $u_m <= 0 && $u_d <= 0) {
		$send = $u_d . ' วัน ';
	} else {
		if ($u_y > 0) {
			if ($u_m > 0 && $u_d <= 0) {
				$send = $u_y . ' ปี ' . $u_m . ' เดือน ';
			} elseif ($u_m <= 0 && $u_d > 0) {
				$send = $u_y . ' ปี ' . $u_d . ' วัน ';
			} else {
				$send = $u_y . ' ปี ' . $u_m . ' เดือน ' . $u_d . ' วัน ';
			}
		} elseif ($u_m > 0) {
			if ($u_d <= 0) {
				$send = $u_m . ' เดือน ';
			} else {
				$send = $u_m . ' เดือน ' . $u_d . ' วัน ';
			}
		} elseif ($u_d > 0) {
			$send = $u_d . ' วัน ';
		} else {
			$send = $u_y . ' ปี ' . $u_m . ' เดือน ' . $u_d . ' วัน ';
		}
	}

	if ($u_m > 0 || $u_y > 0 || $u_d > 7) {
		$send = "<span style='color:#FF0000'>" . $send . '</span>';
	}

	return $send;
}

function employee($position)
{

	require __DIR__ . '/mysql_odbc_compat.php';
	$array = array();

	if ($position == "") {

		$sql =  "SELECT * FROM employee";
		$exe = maintenance_db_odbc_exec($connect, $sql) or die("Error Query [" . $sql . "]");
		while ($result = maintenance_db_odbc_fetch_array($exe)) {
			array_push($array, $result['emp_name']);
		}

	} else if ($position == "TC") {

		$sql_em =  "SELECT *  FROM employee WHERE emp_position = 'TC' ";
		$exe_em = maintenance_db_odbc_exec($connect, $sql_em) or die("Error Query [" . $sql_em . "]");
		while ($result_em = maintenance_db_odbc_fetch_array($exe_em)) {
			array_push($array, $result_em['emp_name']);
		}
		
	} else if ($position == "SP-QC") {

		$sql_em =  "SELECT *  FROM employee WHERE emp_position = 'SP-QC' ";
		$exe_em = maintenance_db_odbc_exec($connect, $sql_em) or die("Error Query [" . $sql_em . "]");
		while ($result_em = maintenance_db_odbc_fetch_array($exe_em)) {
			array_push($array, $result_em['emp_name']);
		}
		
	}

	//$arrLength = count($array);
	sort($array);

	return $array;
}

function product()
{
	require __DIR__ . '/mysql_odbc_compat.php';
	$array = array();
	$sqlSP =  "SELECT * FROM product_name";
	$exeSP = maintenance_db_odbc_exec($connect, $sqlSP) or die("Error Query [" . $sqlSP . "]");
	while ($resultSP = maintenance_db_odbc_fetch_array($exeSP)) {
		array_push($array, $resultSP['pn_name']);
	}
	//$arrLength = count($array);
	sort($array);
	
	return $array;
}

function strReplace($str)
{
	$data = "error";
	$data = str_replace("มบ.", '', str_replace("บจก.", '', str_replace("บริษัท", '', str_replace("รักษาความปลอดภัย", '', str_replace("จำกัด", '', $str)))));
	return $data;
}

function update_status_spare($status, $sn)
{

	if(trim($sn) != ""){

		require __DIR__ . '/mysql_odbc_compat.php';
		$sql_update = "UPDATE spare SET sp_status = '".$status."' WHERE sp_sn = '".$sn."'  ";
		$exec = maintenance_db_odbc_exec($connect, $sql_update); 
		if($exec){ 
			//return "ok";
		}else{
			//return "fail";
		}
		maintenance_db_odbc_close($connect);

	}
	
}

function update_status_job($status, $id)
{

	require __DIR__ . '/mysql_odbc_compat.php';
	$sql = "UPDATE transac_repair SET trp_status_job = '".$status."' WHERE trp_id = ".$id."  ";
	$exec = maintenance_db_odbc_exec($connect, $sql); 
	if($exec){ 
		//return "ok";
	}else{
		//return "fail";
	}
	maintenance_db_odbc_close($connect);
	
}

function logistisc()
{
	require_once __DIR__ . '/logistics_helpers.php';
	global $connect;
	if (isset($connect) && $connect instanceof mysqli) {
		return maintenance_logistics_fetch_active_names($connect);
	}

	return maintenance_logistics_legacy_fallback_names();
}

function formatPhoneNumber($phoneNumber)
{
	$phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
	if (strstr($phoneNumber, "02")) {
		if (strpos($phoneNumber, "02") == 0) {
			$areaCode = substr($phoneNumber, 0, 2);
			$nextThree = substr($phoneNumber, 3, 3);
			$lastFour = substr($phoneNumber, 5, strlen($phoneNumber));
			$phoneNumber = $areaCode . "-" . $nextThree . '-' . $lastFour;
		}
	} else {

		if (strlen($phoneNumber) > 10) {
			$areaCode = substr($phoneNumber, 0, 3);
			$nextThree = substr($phoneNumber, 3, 3);
			$lastFour = substr($phoneNumber, 6, strlen($phoneNumber));

			$phoneNumber = $areaCode . '-' . $nextThree . '-' . $lastFour;
		} else if (strlen($phoneNumber) == 10) {
			$areaCode = substr($phoneNumber, 0, 3);
			$nextThree = substr($phoneNumber, 3, 3);
			$lastFour = substr($phoneNumber, 6, 4);

			$phoneNumber = $areaCode . '-' . $nextThree . '-' . $lastFour;
		} else if (strlen($phoneNumber) == 9) {
			$oneThree = substr($phoneNumber, 0, 3);
			$nextThree = substr($phoneNumber, 3, 3);
			$lastThree = substr($phoneNumber, 6, 3);

			$phoneNumber = $oneThree . "-" . $nextThree . '-' . $lastThree;
		}
	}
	return $phoneNumber;
}

function cal_tracking_due($date)
{
	if($date == ""){
		return "<span style='color:#0033FF'>รอติดตาม</span>";
	}
	$day_send = date("Y-m-d", strtotime($date));
	list($syear, $smonth, $sday) = explode("-", $day_send);       
	list($tyear, $tmonth, $tday) = explode("-", date("Y-m-d"));  
	$mday_send = mktime(0, 0, 0, $smonth, $sday, $syear);
	$mnow = mktime(0, 0, 0, $tmonth, $tday, $tyear);
	if($mnow > $mday_send){
		$mage = ($mnow - $mday_send);
	}else{
		$mage = ($mday_send - $mnow);
	}
	
	$u_y = date("Y", $mage) - 1970;
	$u_m = date("m", $mage) - 1;
	$u_d = date("d", $mage) - 1;
	$send = "";

	if($u_y <= 0 && $u_m <= 0 && $u_d <= 0){
		$send =  $u_d . " วัน ";
	}else{

		if ($u_y > 0) {
			if ($u_m > 0 && $u_d <= 0) {
				$send =  $u_y . " ปี " . $u_m . " เดือน ";
			} else if ($u_m <= 0 && $u_d > 0) {
				$send =  $u_y . " ปี " . $u_d . " วัน ";
			} else {
				$send =  $u_y . " ปี " . $u_m . " เดือน " . $u_d . " วัน ";
			}
		} else if ($u_m > 0) {
			if ($u_d <= 0) {
				$send =  $u_m . " เดือน ";
			} else {
				$send =  $u_m . " เดือน " . $u_d . " วัน ";
			}
		} else if ($u_d > 0) {
			$send =  $u_d . " วัน ";
		} else {
			$send =  $u_y . " ปี " . $u_m . " เดือน " . $u_d . " วัน ";
		}

	}

	if($mnow > $mday_send){
		$send = "<span style='color:#FF0000'>Past due ".$send." </span>";
	}else if($u_d < 3){
		$send = "<span style='color:#FF0000'>Next due ".$send." </span>";
	}else{
		$send = "Next due ".$send;
	}

	return $send;
}

function check_duplicateSpare($sn)
{

	require __DIR__ . '/mysql_odbc_compat.php';
	$sql = "SELECT * FROM spare WHERE sp_sn = '".$sn."' ";
	$exec = maintenance_db_odbc_exec($connect, $sql); 
	$result = maintenance_db_odbc_fetch_array($exec);
	if($result != ""){ 
		return "duplicate";
	}else{
		return "ok";
	}
	maintenance_db_odbc_close($connect);
	
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

/**
 * Purpose:
 * แปลงข้อความจากคอลัมน์ DB ให้เป็น UTF-8 สำหรับแสดงใน HTML ของ `check_history_repair()` — ถ้าเป็น UTF-8 อยู่แล้วคืนตามเดิม; ถ้าไม่ใช่ให้ iconv จาก TIS-620 พร้อม `//IGNORE` เพื่อไม่ให้เกิด notice จาก byte ไม่ถูกต้อง
 *
 * Parameters สำคัญ:
 * - mixed $value: ค่าจาก mysqli (มักเป็น string)
 *
 * Return value:
 * - string ข้อความ UTF-8
 */
function maintenance_legacy_tis620_to_utf8($value)
{
	if ($value === null || $value === false) {
		return '';
	}
	$s = (string) $value;
	if ($s === '') {
		return '';
	}
	if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')) {
		return $s;
	}
	$c = @iconv('TIS-620', 'UTF-8//IGNORE', $s);

	return $c !== false ? $c : $s;
}

function check_history_repair($ts_id, $sn)
{
	if($sn == "" || $sn == "-"){
		return "ไม่พบประวัติการซ่อม";
	}

	require __DIR__ . '/mysql_odbc_compat.php';
	$historyMode = "";
	if($ts_id == "qc"){
		$historyMode = "qc";
		$ts_id = "";
	}
	$repair_history = "";
	$num = 1;
	$sql_rh =  "SELECT *  FROM repair_history WHERE rh_sn = '" . $sn . "' "; 
	$exe_rh = maintenance_db_odbc_exec($connect, $sql_rh) or die("Error Query [" . $sql_rh . "]");
	while ($result_rh = maintenance_db_odbc_fetch_array($exe_rh)) {
		//$result_rh['rh_id'], 
		$repair_history .= "<span style='color:#FF0000'><b>ครั้งที่ ".$num."</b></span><br>"; $num++;
		$repair_history .= "<b>วันที่แจ้งซ่อม</b> ".$result_rh['rh_date']."  <b>เลขงานซ่อม</b> ".$result_rh['rh_mtn_number']."<br>";
		$repair_history .="<b>บริษัท</b> ".maintenance_legacy_tis620_to_utf8( $result_rh['rh_com_security'])." -- ".maintenance_legacy_tis620_to_utf8( $result_rh['rh_com_enduser'])."<br>";
		$repair_history .="<b>สินค้า</b> ".$result_rh['rh_product']." [ ".$result_rh['rh_sn']." ] <br>";
		$repair_history .="<b>ข้อมูลการซ่อม</b> <br>อาการที่แจ้ง : ".maintenance_legacy_tis620_to_utf8( $result_rh['rh_inform_repair'])."<br> แก้ไขโดย : ".maintenance_legacy_tis620_to_utf8( $result_rh['rh_repair'])."<br><br>";
	}
	
	$sql_ctrp = "SELECT  * FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id WHERE transac_repair.trp_sn = '" . $sn . "' ";
	$exe_ctrp = maintenance_db_odbc_exec($connect, $sql_ctrp) or die("Error Query [" . $sql_ctrp. "]");
	while ($result_ctrp = maintenance_db_odbc_fetch_array($exe_ctrp)) {
		if($ts_id != $result_ctrp['ts_id']){
				$repair_history .= "<span style='color:#FF0000'><b>ครั้งที่ ".$num."</b></span><br>"; $num++;
				$repair_history .= "<b>วันที่รับซ่อม</b> ".$result_ctrp['trp_receive_date']."  <b>เลขงานซ่อม</b> ".$result_ctrp['ts_mtn_number']."<br>";
				$repair_history .="<b>บริษัท</b> ".maintenance_legacy_tis620_to_utf8( strReplace($result_ctrp['ts_security_com']))." -- ".
												maintenance_legacy_tis620_to_utf8( strReplace($result_ctrp['ts_enduser_com']))."<br>";
				$repair_history .="<b>สินค้า</b> ".$result_ctrp['trp_product']." [ ".$result_ctrp['trp_sn']." ] <br>";
				$repair_history .="<b>อาการที่แจ้ง : </b>".maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_inform'])."<br>";
				$repair_history .="<b>ประเมินโดน : </b>".maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_user_rate'])."<br>";
				$repair_history .="<b>การประเมิน : </b>".maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_technician'])."<br>";
				$list_repair = "";
				if($result_ctrp['trp_repair_no1'] != ""){ $list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no1']).", "; }
				if($result_ctrp['trp_repair_no2'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no2']).", ";}
				if($result_ctrp['trp_repair_no3'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no3']).", ";}
				if($result_ctrp['trp_repair_no4'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no4']).", ";}
				if($result_ctrp['trp_repair_no5'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no5']).", ";}
				if($result_ctrp['trp_repair_no6'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no6']).", ";}
				if($result_ctrp['trp_repair_no7'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no7']).", ";}
				if($result_ctrp['trp_repair_no8'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no8']).", ";}
				if($result_ctrp['trp_repair_no9'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no9']).", ";}
				if($result_ctrp['trp_repair_no10'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_no10']).", ";}
				if($result_ctrp['trp_clean'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_clean']).", ";}
				if($result_ctrp['trp_repeat_solder'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repeat_solder']).", ";}
				if($result_ctrp['trp_spray'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_spray']).", ";}
				if($result_ctrp['trp_fw'] != ""){$list_repair .= maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_fw']).", ";}
				$list_repair .= "<span style='color:#FF0000'>".maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_repair_remarks'])."</span>";
				$repair_history .="<b>รายการซ่อม : </b>".$list_repair."<br>";
				$repair_history .="<b>ซ่อมโดย : </b>".maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_user_ma'])."<br>";
				$repair_history .="<b>สถานซ่อม : </b><span style='color:#0000FF'>".maintenance_legacy_tis620_to_utf8( $result_ctrp['trp_status_job'])."</span><br><br>";
		}
	}
	
	if($repair_history === ""){
		$repair_history = "ไม่พบประวัติการซ่อม";
	}else if($num == 2 && $historyMode == "qc"){
		$repair_history = "ไม่พบประวัติการซ่อม";
	}
	
	
	return $repair_history;
	maintenance_db_odbc_close($connect);
	
}

function request_product($status)
{
	require __DIR__ . '/mysql_odbc_compat.php';
	$array = array();
	//array_push($array, "All");
	$sql = "SELECT trp_product  FROM transac_repair WHERE trp_status_job = '".$status."' GROUP BY trp_product HAVING COUNT(*) >= 1";
	$exe = maintenance_db_odbc_exec($connect, $sql) or die("Error Query [" . $sql . "]");
	while ($result = maintenance_db_odbc_fetch_array($exe)) {
		array_push($array, $result['trp_product']);
	}
	
	sort($array);
	
	return $array;
}

function request_company($status)
{
	require __DIR__ . '/mysql_odbc_compat.php';
	$array = array();
	//array_push($array, "All");

	$sqlsecurity =  "SELECT transac.ts_security_com FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id  WHERE transac_repair.trp_status_job = '".$status."' GROUP BY transac.ts_security_com HAVING COUNT(*) >= 1 ORDER BY transac.ts_security_com ASC";
	$execom_sec = maintenance_db_odbc_exec($connect, $sqlsecurity) or die("Error Query [" . $sqlsecurity . "]");
	while ($resultcom = maintenance_db_odbc_fetch_array($execom_sec)) {
		if($resultcom['ts_security_com'] != ""){
			array_push($array, $resultcom['ts_security_com']);
		}
	}
	$sqlenduser =  "SELECT transac.ts_enduser_com FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id  WHERE transac_repair.trp_status_job = '".$status."' GROUP BY transac.ts_enduser_com HAVING COUNT(*) >= 1 ORDER BY transac.ts_enduser_com ASC";
	$execom_end = maintenance_db_odbc_exec($connect, $sqlenduser) or die("Error Query [" . $sqlenduser . "]");
	while ($resultcom_end = maintenance_db_odbc_fetch_array($execom_end)) {
		if($resultcom_end['ts_enduser_com'] != ""){
			array_push($array, $resultcom_end['ts_enduser_com']);
		}
	}
	
	sort($array);
	
	return $array;
}

/**
 * Purpose:
 * คืนรายการค่า `trp_status_job` ที่ไม่ซ้ำจาก `transac_repair` สำหรับ dropdown กรองสถานะ (เทียบ legacy แต่แก้ HAVING จาก `> 1` เป็นเงื่อนไขที่ถูกต้อง)
 *
 * Parameters สำคัญ:
 * - ไม่มี — ใช้ `$connect` จาก bootstrap (`mysql_odbc_compat.php`) ถ้ามีแล้ว; ถ้าไม่มีค่อย require ภายในฟังก์ชัน (กันซ้ำ connection หลัง `require_once` ที่หน้า entry)
 *
 * Return value:
 * - array ของ string สถานะงานซ่อม
 */
function request_statusjob()
{
	global $connect;

	if (!isset($connect) || !($connect instanceof mysqli)) {
		require __DIR__ . '/mysql_odbc_compat.php';
	}

	$array = array();
	$sql = "SELECT DISTINCT trp_status_job FROM transac_repair WHERE trp_status_job IS NOT NULL AND trp_status_job <> '' ORDER BY trp_status_job ASC";
	$exe = maintenance_db_odbc_exec($connect, $sql);
	if (!$exe) {
		exit(
			'Error Query [' . htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') . ']: '
			. htmlspecialchars(maintenance_db_odbc_errormsg($connect), ENT_QUOTES, 'UTF-8')
		);
	}
	while ($result = maintenance_db_odbc_fetch_array($exe)) {
		array_push($array, $result['trp_status_job']);
	}

	return $array;
}


?>
