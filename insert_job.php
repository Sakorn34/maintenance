<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับพารามิเตอร์จากฟอร์ม add_job (POST เป็นหลัก — รองรับ `&` ในข้อความ; GET ยังรองรับเพื่อ backward compat) แล้วบันทึกลง transac, transac_repair และความสัมพันธ์ spare
 * แสดง modal กลาง (`mnModalAlert`) แทน alert/jQuery dialog แล้วพากลับหน้า add_job
 *
 * Flow ย่อ:
 * - เชื่อมต่อ DB → insert transac → อัปเดตสถานะ spare → insert repair (อ่าน `trp_id` ผ่าน `mysqli_insert_id`) → insert spare ตามจำนวนเครื่องที่กรอก
 * - สำเร็จ: ข้อความพร้อมเลขที่งาน (`ts_mtn_number`) → ปิด modal แล้ว redirect
 * - ล้มเหลว: แสดงข้อความ error พร้อม SQL → redirect เช่นเดียวกัน
 *
 * Debug (เปิดใน `env.local.php`: `add_job_insert_debug` => true): เขียน JSON ลง `storage/logs/add_job_insert_YYYY-MM-DD.log`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/insert_job_helpers.php';
require_once __DIR__ . '/includes/function_legacy.php';
require_once __DIR__ . '/includes/add_job_insert_debug.php';
require_once __DIR__ . '/includes/add_job_existing_transac_helpers.php';
maintenance_new_add_job_insert_debug_register_shutdown();
?>
<head>
<meta http-equiv="Content-Language" content="th">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>บันทึกงานซ่อม</title>
</head>
<body>
	<?php
	maintenance_new_add_job_insert_debug_log('request_start', array(
		'get_summary' => maintenance_new_add_job_insert_debug_safe_get_summary(),
		'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
	));

	$job_in = maintenance_insert_job_request_input();

	$ts_spare = "No";
	//เธฃเธฒเธขเธเธฒเธฃเน€เธเธฃเธทเนเธญเธเธเนเธญเธก
	$ts_mtn1 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn1'));
	$ts_mtn2 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn2'));
	$ts_mtn3 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn3'));
	$ts_mtn4 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn4'));
	$ts_mtn5 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn5'));
	$ts_mtn6 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn6'));
	$ts_mtn7 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn7'));
	$ts_mtn8 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_mtn8'));
	//$ts_mtn10 = เธชเธดเธเธเนเธฒ เน€เธเธฃเธทเนเธญเธเธเนเธญเธก ENG
	//$ts_mtn11 = sn เน€เธเธฃเธทเนเธญเธเธเนเธญเธก	ENG
	//$ts_mtn12 = เธญเธฒเธเธฒเธฃเน€เธชเธตเธขเน€เธเธฃเธทเนเธญเธเธเนเธญเธก

	// S/N เน€เธเธฃเธทเนเธญเธเธชเธณเธฃเธญเธ
	$ts_sp1 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp1'));
	$ts_sp2 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp2'));
	$ts_sp3 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp3'));
	$ts_sp4 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp4'));
	$ts_sp5 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp5'));
	$ts_sp6 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp6'));
	$ts_sp7 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp7'));
	$ts_sp8 = explode("@", maintenance_insert_job_input_string($job_in, 'ts_sp8'));

	// map จากค่าที่คั่นด้วย @ → ts_mtn10, ts_sp10 ฯลฯ (เดิมอาศัย register_globals ซึ่งไม่มีใน PHP ปัจจุบัน)
	$mtnRows = array($ts_mtn1, $ts_mtn2, $ts_mtn3, $ts_mtn4, $ts_mtn5, $ts_mtn6, $ts_mtn7, $ts_mtn8);
	foreach ($mtnRows as $ix => $row) {
		$n = $ix + 1;
		$base = $n * 10;
		${'ts_mtn' . $base} = isset($row[0]) ? $row[0] : '';
		${'ts_mtn' . ($base + 1)} = isset($row[1]) ? $row[1] : '';
		${'ts_mtn' . ($base + 2)} = isset($row[2]) ? $row[2] : '';
	}
	$spRows = array($ts_sp1, $ts_sp2, $ts_sp3, $ts_sp4, $ts_sp5, $ts_sp6, $ts_sp7, $ts_sp8);
	foreach ($spRows as $ix => $row) {
		$n = $ix + 1;
		$base = $n * 10;
		${'ts_sp' . $base} = isset($row[0]) ? $row[0] : '';
		${'ts_sp' . ($base + 1)} = isset($row[1]) ? $row[1] : '';
	}

	for ($mn_i = 1; $mn_i <= 8; $mn_i++) {
		$mn_base = $mn_i * 10;
		for ($mn_j = 0; $mn_j <= 2; $mn_j++) {
			$mn_var = 'ts_mtn' . ($mn_base + $mn_j);
			if (isset($$mn_var)) {
				$$mn_var = maintenance_insert_job_esc($connect, $$mn_var);
			}
		}
		for ($mn_j = 0; $mn_j <= 1; $mn_j++) {
			$mn_var = 'ts_sp' . ($mn_base + $mn_j);
			if (isset($$mn_var)) {
				$$mn_var = maintenance_insert_job_esc($connect, $$mn_var);
			}
		}
	}

	maintenance_new_add_job_insert_debug_log('parsed_fields', array(
		'ts_mtn10' => isset($ts_mtn10) ? $ts_mtn10 : null,
		'ts_mtn20' => isset($ts_mtn20) ? $ts_mtn20 : null,
		'ts_sp10' => isset($ts_sp10) ? $ts_sp10 : null,
		'ts_sp20' => isset($ts_sp20) ? $ts_sp20 : null,
	));

	$add_job_mode = maintenance_insert_job_input_string($job_in, 'add_job_mode', 'new');
	$existing_ts_id_raw = isset($job_in['existing_ts_id']) ? filter_var($job_in['existing_ts_id'], FILTER_VALIDATE_INT) : false;
	$existing_ts_id = ($existing_ts_id_raw !== false && $existing_ts_id_raw > 0) ? (int) $existing_ts_id_raw : 0;
	$is_existing_add = ($add_job_mode === 'existing' && $existing_ts_id > 0);

	/**
	 * Purpose:
	 * แสดง modal ข้อผิดพลาดแล้วกลับหน้า add_job
	 *
	 * Parameters สำคัญ:
	 * - string $message: ข้อความ
	 * - string $variant: ชนิด modal
	 *
	 * Return value:
	 * - void (exit)
	 */
	$mn_insert_job_fail_modal = function ($message, $variant) use ($connect) {
		?>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($message, JSON_UNESCAPED_UNICODE); ?>, {
	variant: <?php echo json_encode($variant, JSON_UNESCAPED_UNICODE); ?>,
	onClose: function () { window.location.href = 'add_job'; }
});
</script>
		<?php
		maintenance_db_odbc_close($connect);
		exit;
	};

	if ($add_job_mode === 'existing' && $existing_ts_id <= 0) {
		$mn_insert_job_fail_modal('กรุณาเลือกงานซ่อมเดิม', 'warning');
	}

	//เธชเธ–เธฒเธเธฐ
	// เธฃเธญเธชเนเธเธชเธณเธฃเธญเธ		เธฃเธญเน€เธเธฃเธทเนเธญเธเธเนเธญเธก	เธฃเธญเธเธฃเธฐเน€เธกเธดเธ	เธฃเธญเน€เธชเธเธญเธฃเธฒเธเธฒ	 เธญเธเธธเธกเธฑเธ•เธดเธเนเธญเธก/เนเธกเนเธญเธเธธเธกเธฑเธ•เธดเธเนเธญเธก	เธฃเธญเธเนเธญเธก		เธฃเธญ QC 		เธฃเธญเน€เธเธฃเธทเนเธญเธเธชเธณเธฃเธญเธ
	//เธชเธ–เธฒเธเธฐเธเธญเธ job
	//get_status_addjob(SNเธชเธณเธฃเธญเธ); 
	$status_sp1 = get_status_addjob($ts_sp10); 
	$status_sp2 = get_status_addjob($ts_sp20);  
	$status_sp3 = get_status_addjob($ts_sp30); 
	$status_sp4 = get_status_addjob($ts_sp40);
	$status_sp5 = get_status_addjob($ts_sp50); 
	$status_sp6 = get_status_addjob($ts_sp60); 
	$status_sp7 = get_status_addjob($ts_sp70); 
	$status_sp8 = get_status_addjob($ts_sp80);
	
	$ok_transac = false;
	$ts_id = null;
	$mtn_number = '';

	if ($is_existing_add) {
		if (!maintenance_add_job_existing_transac_is_eligible($connect, $existing_ts_id)) {
			$mn_insert_job_fail_modal('งานที่เลือกมีเครื่องซ่อมในสถานะที่ไม่สามารถเพิ่มอุปกรณ์ได้ กรุณาเลือกงานอื่น', 'warning');
		}
		$existing_row = maintenance_add_job_existing_transac_fetch_one($connect, $existing_ts_id);
		if (!$existing_row) {
			$mn_insert_job_fail_modal('ไม่พบข้อมูลงานซ่อมที่เลือก', 'danger');
		}
		$ts_id = $existing_ts_id;
		$mtn_number = isset($existing_row['ts_mtn_number']) ? (string) $existing_row['ts_mtn_number'] : '';
		$ok_transac = true;
		maintenance_new_add_job_insert_debug_log('existing_transac_mode', array(
			'ts_id' => $ts_id,
			'mtn_number' => $mtn_number,
		));
	} else {
		//เน€เธฃเธตเธขเธเธ”เธนเน€เธฅเธเน€เธญเธเธชเธฒเธฃเธฅเนเธฒเธชเธธเธ”
		$sql_se = "SELECT * FROM transac ORDER BY ts_mtn_number DESC";
		$exe_se = maintenance_db_odbc_exec($connect, $sql_se) or die("Error Query [" . $sql_se . "]");
		$result_se = maintenance_db_odbc_fetch_array($exe_se);
		$ts_mtn_nb = (is_array($result_se) && isset($result_se['ts_mtn_number'])) ? $result_se['ts_mtn_number'] : '';
		if ($ts_mtn_nb === '') {
			maintenance_new_add_job_insert_debug_log('transac_table_empty_or_no_mtn_number', array(
				'has_row' => (bool) $result_se,
			));
		}
		$yy = $ts_mtn_nb !== '' ? substr($ts_mtn_nb, 0, 2) : '';
		$running = $ts_mtn_nb !== '' && strlen($ts_mtn_nb) > 3 ? substr($ts_mtn_nb, 3) : '';

		$yynow = intval(date("Y")) + 543; // YY
		$yynow = substr($yynow, 2, 3);

		$temp_number = 10000;

		if ($running == "") {
			$running = "0001";
		}

		if ($yy != $yynow) {
			$mtn_number = $yynow . "-0001";
		} else {
			$running_now = 10001 + intval($running);
			$mtn_number = $yy . "-" . substr(strval($running_now), 1);
		}

		maintenance_new_add_job_insert_debug_log('mtn_number_ready', array(
			'mtn_number' => $mtn_number,
			'ts_mtn_nb_last' => $ts_mtn_nb,
			'yy' => $yy,
			'running' => $running,
		));

		$sql = "insert into transac ";
		$sql .= "(ts_date, ts_user_recive, ts_enduser_com, ts_security_com, 
ts_report_name, ts_report_tel, ts_report_line, ts_report_email, ts_address, 
ts_approve_name, ts_approve_tel, ts_approve_line, ts_approve_email, 
ts_remarks, ts_spare, ts_sendspare, ts_mtn_number)";
		$sql .= "values ";
		$sql .= "('" . maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_date')) . "',  '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_user_recive')) . "', '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_enduser_com')) . "' , '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_security_com')) . "' , 
'"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_report_name')) . "' , '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_report_tel')) . "' , '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_report_line')) . "' , '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_report_email')) . "' , '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_address')) . "' , 
'"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_approve_name')) . "' , '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_approve_tel')) . "', '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_approve_line')) . "', '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_approve_email')) . "' , '"
			. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_remarks')) . "', 
'" . $ts_spare . "', 'No', '" . maintenance_insert_job_esc($connect, $mtn_number) . "' ) ";

		$ok_transac = maintenance_db_odbc_exec($connect, $sql);
		maintenance_new_add_job_insert_debug_log('insert_transac', array(
			'ok' => (bool) $ok_transac,
			'odbc_errno' => maintenance_db_odbc_error($connect),
			'odbc_errmsg' => maintenance_db_odbc_errormsg($connect),
			'sql' => $sql,
		));
	}

	if ($ok_transac) {
		update_status_spare("use", $ts_sp10);
		update_status_spare("use", $ts_sp20);
		update_status_spare("use", $ts_sp30);
		update_status_spare("use", $ts_sp40);
		update_status_spare("use", $ts_sp50);
		update_status_spare("use", $ts_sp60);
		update_status_spare("use", $ts_sp70);
		update_status_spare("use", $ts_sp80);

		if (!$is_existing_add) {
			$sql2 = "select * from transac where ts_enduser_com = '"
				. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_enduser_com'))
				. "' and ts_security_com = '"
				. maintenance_insert_job_esc($connect, maintenance_insert_job_input_string($job_in, 'ts_security_com'))
				. "' order by ts_id DESC";
			$exe2 = maintenance_db_odbc_exec($connect, $sql2) or die("Error Query [" . $sql2 . "]");
			$result1 = maintenance_db_odbc_fetch_array($exe2);
			$ts_id = (is_array($result1) && isset($result1['ts_id'])) ? $result1['ts_id'] : null;
			maintenance_new_add_job_insert_debug_log('fetch_new_ts_id', array(
				'ts_id' => $ts_id,
				'sql2' => $sql2,
			));
		}

		// เก็บ trp_id หลัง INSERT repair สำหรับ tsp_trp_id — ใช้ `maintenance_insert_job_last_trp_id()` (mysqli_insert_id)
		$rp_ID1 = "";		$rp_ID2 = "";		$rp_ID3 = "";		$rp_ID4 = "";		$rp_ID5 = "";		$rp_ID6 = "";		$rp_ID7 = "";		$rp_ID8 = "";
		$sqlmtn = "insert into transac_repair ";
		$sqlmtn .= "(trp_ts_id, trp_product, trp_sn, trp_repair_inform, trp_status_job) VALUES ";

		if ($ts_mtn10 != '' and $ts_mtn20 != '' and $ts_mtn30 != '' and $ts_mtn40 != '' and $ts_mtn50 != '' and $ts_mtn60 != '' and $ts_mtn70 != '' and $ts_mtn80 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
			$sqlmtn2 = "('" . $ts_id . "', '" . $ts_mtn20 . "', '" . $ts_mtn21 . "', '" . $ts_mtn22 . "','".$status_sp2."')";
			$r_mtn2 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn2);
			$rp_ID2 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn20, $ts_mtn21, $status_sp2);
			$sqlmtn3 = "('" . $ts_id . "', '" . $ts_mtn30 . "', '" . $ts_mtn31 . "', '" . $ts_mtn32 . "','".$status_sp3."')";
			$r_mtn3 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn3);
			$rp_ID3 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn30, $ts_mtn31, $status_sp3);
			$sqlmtn4 = "('" . $ts_id . "', '" . $ts_mtn40 . "', '" . $ts_mtn41 . "', '" . $ts_mtn42 . "','".$status_sp4."')";
			$r_mtn4 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn4);
			$rp_ID4 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn40, $ts_mtn41, $status_sp4);
			$sqlmtn5 = "('" . $ts_id . "', '" . $ts_mtn50 . "', '" . $ts_mtn51 . "', '" . $ts_mtn52 ."','".$status_sp5."')";
			$r_mtn5 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn5);
			$rp_ID5 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn50, $ts_mtn51, $status_sp5);
			$sqlmtn6 = "('" . $ts_id . "', '" . $ts_mtn60 . "', '" . $ts_mtn61 . "', '" . $ts_mtn62 . "','".$status_sp6."')";
			$r_mtn6 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn6);
			$rp_ID6 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn60, $ts_mtn61, $status_sp6);
			$sqlmtn7 = "('" . $ts_id . "', '" . $ts_mtn70 . "', '" . $ts_mtn71 . "', '" . $ts_mtn72 ."','".$status_sp7."')";
			$r_mtn7 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn7);
			$rp_ID7 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn70, $ts_mtn71, $status_sp7);
			$sqlmtn8 = "('" . $ts_id . "', '" . $ts_mtn80 . "', '" . $ts_mtn81 . "', '" . $ts_mtn82 . "','".$status_sp8."')";
			$r_mtn8 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn8);
			$rp_ID8 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn80, $ts_mtn81, $status_sp8);

		} else if ($ts_mtn10 != '' and $ts_mtn20 != '' and $ts_mtn30 != '' and $ts_mtn40 != '' and $ts_mtn50 != '' and $ts_mtn60 != '' and $ts_mtn70 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
			$sqlmtn2 = "('" . $ts_id . "', '" . $ts_mtn20 . "', '" . $ts_mtn21 . "', '" . $ts_mtn22 . "','".$status_sp2."')";
			$r_mtn2 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn2);
			$rp_ID2 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn20, $ts_mtn21, $status_sp2);
			$sqlmtn3 = "('" . $ts_id . "', '" . $ts_mtn30 . "', '" . $ts_mtn31 . "', '" . $ts_mtn32 . "','".$status_sp3."')";
			$r_mtn3 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn3);
			$rp_ID3 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn30, $ts_mtn31, $status_sp3);
			$sqlmtn4 = "('" . $ts_id . "', '" . $ts_mtn40 . "', '" . $ts_mtn41 . "', '" . $ts_mtn42 . "','".$status_sp4."')";
			$r_mtn4 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn4);
			$rp_ID4 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn40, $ts_mtn41, $status_sp4);
			$sqlmtn5 = "('" . $ts_id . "', '" . $ts_mtn50 . "', '" . $ts_mtn51 . "', '" . $ts_mtn52 ."','".$status_sp5."')";
			$r_mtn5 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn5);
			$rp_ID5 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn50, $ts_mtn51, $status_sp5);
			$sqlmtn6 = "('" . $ts_id . "', '" . $ts_mtn60 . "', '" . $ts_mtn61 . "', '" . $ts_mtn62 . "','".$status_sp6."')";
			$r_mtn6 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn6);
			$rp_ID6 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn60, $ts_mtn61, $status_sp6);
			$sqlmtn7 = "('" . $ts_id . "', '" . $ts_mtn70 . "', '" . $ts_mtn71 . "', '" . $ts_mtn72 ."','".$status_sp7."')";
			$r_mtn7 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn7);
			$rp_ID7 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn70, $ts_mtn71, $status_sp7);

		} else if ($ts_mtn10 != '' and $ts_mtn20 != '' and $ts_mtn30 != '' and $ts_mtn40 != '' and $ts_mtn50 != '' and $ts_mtn60 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
			$sqlmtn2 = "('" . $ts_id . "', '" . $ts_mtn20 . "', '" . $ts_mtn21 . "', '" . $ts_mtn22 . "','".$status_sp2."')";
			$r_mtn2 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn2);
			$rp_ID2 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn20, $ts_mtn21, $status_sp2);
			$sqlmtn3 = "('" . $ts_id . "', '" . $ts_mtn30 . "', '" . $ts_mtn31 . "', '" . $ts_mtn32 . "','".$status_sp3."')";
			$r_mtn3 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn3);
			$rp_ID3 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn30, $ts_mtn31, $status_sp3);
			$sqlmtn4 = "('" . $ts_id . "', '" . $ts_mtn40 . "', '" . $ts_mtn41 . "', '" . $ts_mtn42 . "','".$status_sp4."')";
			$r_mtn4 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn4);
			$rp_ID4 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn40, $ts_mtn41, $status_sp4);
			$sqlmtn5 = "('" . $ts_id . "', '" . $ts_mtn50 . "', '" . $ts_mtn51 . "', '" . $ts_mtn52 ."','".$status_sp5."')";
			$r_mtn5 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn5);
			$rp_ID5 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn50, $ts_mtn51, $status_sp5);
			$sqlmtn6 = "('" . $ts_id . "', '" . $ts_mtn60 . "', '" . $ts_mtn61 . "', '" . $ts_mtn62 . "','".$status_sp6."')";
			$r_mtn6 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn6);
			$rp_ID6 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn60, $ts_mtn61, $status_sp6);

		} else if ($ts_mtn10 != '' and $ts_mtn20 != '' and $ts_mtn30 != '' and $ts_mtn40 != '' and $ts_mtn50 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
			$sqlmtn2 = "('" . $ts_id . "', '" . $ts_mtn20 . "', '" . $ts_mtn21 . "', '" . $ts_mtn22 . "','".$status_sp2."')";
			$r_mtn2 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn2);
			$rp_ID2 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn20, $ts_mtn21, $status_sp2);
			$sqlmtn3 = "('" . $ts_id . "', '" . $ts_mtn30 . "', '" . $ts_mtn31 . "', '" . $ts_mtn32 . "','".$status_sp3."')";
			$r_mtn3 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn3);
			$rp_ID3 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn30, $ts_mtn31, $status_sp3);
			$sqlmtn4 = "('" . $ts_id . "', '" . $ts_mtn40 . "', '" . $ts_mtn41 . "', '" . $ts_mtn42 . "','".$status_sp4."')";
			$r_mtn4 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn4);
			$rp_ID4 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn40, $ts_mtn41, $status_sp4);
			$sqlmtn5 = "('" . $ts_id . "', '" . $ts_mtn50 . "', '" . $ts_mtn51 . "', '" . $ts_mtn52 ."','".$status_sp5."')";
			$r_mtn5 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn5);
			$rp_ID5 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn50, $ts_mtn51, $status_sp5);

		} else if ($ts_mtn10 != '' and $ts_mtn20 != '' and $ts_mtn30 != '' and $ts_mtn40 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
			$sqlmtn2 = "('" . $ts_id . "', '" . $ts_mtn20 . "', '" . $ts_mtn21 . "', '" . $ts_mtn22 . "','".$status_sp2."')";
			$r_mtn2 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn2);
			$rp_ID2 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn20, $ts_mtn21, $status_sp2);
			$sqlmtn3 = "('" . $ts_id . "', '" . $ts_mtn30 . "', '" . $ts_mtn31 . "', '" . $ts_mtn32 . "','".$status_sp3."')";
			$r_mtn3 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn3);
			$rp_ID3 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn30, $ts_mtn31, $status_sp3);
			$sqlmtn4 = "('" . $ts_id . "', '" . $ts_mtn40 . "', '" . $ts_mtn41 . "', '" . $ts_mtn42 . "','".$status_sp4."')";
			$r_mtn4 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn4);
			$rp_ID4 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn40, $ts_mtn41, $status_sp4);

		} else if ($ts_mtn10 != '' and $ts_mtn20 != '' and $ts_mtn30 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
			$sqlmtn2 = "('" . $ts_id . "', '" . $ts_mtn20 . "', '" . $ts_mtn21 . "', '" . $ts_mtn22 . "','".$status_sp2."')";
			$r_mtn2 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn2);
			$rp_ID2 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn20, $ts_mtn21, $status_sp2);
			$sqlmtn3 = "('" . $ts_id . "', '" . $ts_mtn30 . "', '" . $ts_mtn31 . "', '" . $ts_mtn32 . "','".$status_sp3."')";
			$r_mtn3 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn3);
			$rp_ID3 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn30, $ts_mtn31, $status_sp3);

		} else if ($ts_mtn10 != '' and $ts_mtn20 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
			$sqlmtn2 = "('" . $ts_id . "', '" . $ts_mtn20 . "', '" . $ts_mtn21 . "', '" . $ts_mtn22 . "','".$status_sp2."')";
			$r_mtn2 = maintenance_db_odbc_exec($connect, $sqlmtn .  $sqlmtn2);
			$rp_ID2 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn20, $ts_mtn21, $status_sp2);

		} else if ($ts_mtn10 != '') {

			$sqlmtn1 = "('" . $ts_id . "', '" . $ts_mtn10 . "', '" . $ts_mtn11 . "', '" . $ts_mtn12 . "','".$status_sp1."' )";
			$r_mtn1 = maintenance_db_odbc_exec($connect, $sqlmtn . $sqlmtn1);
			$rp_ID1 = maintenance_insert_job_last_trp_id($connect, (int) $ts_id, $ts_mtn10, $ts_mtn11, $status_sp1);
		}

		// Insert [เน€เธเธฃเธทเนเธญเธเธชเธณเธฃเธญเธ]
		$sqlsp = "insert into transac_spare ";
		$sqlsp .= "(tsp_product, tsp_sn, tsp_ts_id, tsp_trp_id)";
		$sqlsp .= "values ";
		if ($ts_sp10 != '' and $ts_sp20 != '' and $ts_sp30 != '' and $ts_sp40 != '' and $ts_sp50 != '' and $ts_sp60 != '' and $ts_sp70 != '' and $ts_sp80 != '') {
			//sp1
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

			//sp2
			if ($ts_sp20 != '' and $ts_sp21 != '') {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
				$sqlsp2 = "";
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp21 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			} else {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			}

			//sp3
			if ($ts_sp30 != '' and $ts_sp31 != '') {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
				$sqlsp3 = "";
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp31 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			} else {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			}

			//sp4
			if ($ts_sp40 != '' and $ts_sp41 != '') {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
				$sqlsp4 = "";
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp41 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			} else {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			}

			//sp5
			if ($ts_sp50 != '' and $ts_sp51 != '') {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
				$sqlsp5 = "";
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp51 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			} else {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			}

			//sp6
			if ($ts_sp60 != '' and $ts_sp61 != '') {
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp60 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
				$sqlsp6 = "";
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp61 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
			} else {
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp60 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
			}

			//sp7
			if ($ts_sp70 != '' and $ts_sp71 != '') {
				$sqlsp7 = "('" . $ts_mtn70 . "',  '" . $ts_sp70 . "', '" . $ts_id . "', '".$rp_ID7."')";
				$r_sp7 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp7);
				$sqlsp7 = "";
				$sqlsp7 = "('" . $ts_mtn70 . "',  '" . $ts_sp71 . "', '" . $ts_id . "', '".$rp_ID7."')";
				$r_sp7 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp7);
			} else {
				$sqlsp7 = "('" . $ts_mtn70 . "',  '" . $ts_sp70 . "', '" . $ts_id . "', '".$rp_ID7."')";
				$r_sp7 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp7);
			}

			//sp8
			if ($ts_sp80 != '' and $ts_sp81 != '') {
				$sqlsp8 = "('" . $ts_mtn80 . "',  '" . $ts_sp80 . "', '" . $ts_id . "', '".$rp_ID8."')";
				$r_sp8 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp8);
				$sqlsp8 = "";
				$sqlsp8 = "('" . $ts_mtn80 . "',  '" . $ts_sp81 . "', '" . $ts_id . "', '".$rp_ID8."')";
				$r_sp8 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp8);
			} else {
				$sqlsp8 = "('" . $ts_mtn80 . "',  '" . $ts_sp80 . "', '" . $ts_id . "', '".$rp_ID8."')";
				$r_sp8 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp8);
			}

		} else if ($ts_sp10 != '' and $ts_sp20 != '' and $ts_sp30 != '' and $ts_sp40 != '' and $ts_sp50 != '' and $ts_sp60 != '' and $ts_sp70 != '') {
			//sp1
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

			//sp2
			if ($ts_sp20 != '' and $ts_sp21 != '') {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
				$sqlsp2 = "";
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp21 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			} else {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			}

			//sp3
			if ($ts_sp30 != '' and $ts_sp31 != '') {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
				$sqlsp3 = "";
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp31 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			} else {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			}

			//sp4
			if ($ts_sp40 != '' and $ts_sp41 != '') {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
				$sqlsp4 = "";
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp41 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			} else {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			}

			//sp5
			if ($ts_sp50 != '' and $ts_sp51 != '') {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
				$sqlsp5 = "";
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp51 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			} else {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			}

			//sp6
			if ($ts_sp60 != '' and $ts_sp61 != '') {
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp60 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
				$sqlsp6 = "";
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp61 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
			} else {
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp60 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
			}

			//sp7
			if ($ts_sp70 != '' and $ts_sp71 != '') {
				$sqlsp7 = "('" . $ts_mtn70 . "',  '" . $ts_sp70 . "', '" . $ts_id . "', '".$rp_ID7."')";
				$r_sp7 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp7);
				$sqlsp7 = "";
				$sqlsp7 = "('" . $ts_mtn70 . "',  '" . $ts_sp71 . "', '" . $ts_id . "', '".$rp_ID7."')";
				$r_sp7 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp7);
			} else {
				$sqlsp7 = "('" . $ts_mtn70 . "',  '" . $ts_sp70 . "', '" . $ts_id . "', '".$rp_ID7."')";
				$r_sp7 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp7);
			}

		} else if ($ts_sp10 != '' and $ts_sp20 != '' and $ts_sp30 != '' and $ts_sp40 != '' and $ts_sp50 != '' and $ts_sp60 != '') {
			//sp1
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

			//sp2
			if ($ts_sp20 != '' and $ts_sp21 != '') {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
				$sqlsp2 = "";
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp21 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			} else {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			}

			//sp3
			if ($ts_sp30 != '' and $ts_sp31 != '') {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
				$sqlsp3 = "";
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp31 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			} else {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			}

			//sp4
			if ($ts_sp40 != '' and $ts_sp41 != '') {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
				$sqlsp4 = "";
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp41 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			} else {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			}

			//sp5
			if ($ts_sp50 != '' and $ts_sp51 != '') {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
				$sqlsp5 = "";
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp51 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			} else {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			}

			//sp6
			if ($ts_sp60 != '' and $ts_sp61 != '') {
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp60 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
				$sqlsp6 = "";
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp61 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
			} else {
				$sqlsp6 = "('" . $ts_mtn60 . "',  '" . $ts_sp60 . "', '" . $ts_id . "', '".$rp_ID6."')";
				$r_sp6 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp6);
			}

		} else if ($ts_sp10 != '' and $ts_sp20 != '' and $ts_sp30 != '' and $ts_sp40 != '' and $ts_sp50 != '') {
			//sp1
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

			//sp2
			if ($ts_sp20 != '' and $ts_sp21 != '') {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
				$sqlsp2 = "";
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp21 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			} else {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			}

			//sp3
			if ($ts_sp30 != '' and $ts_sp31 != '') {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
				$sqlsp3 = "";
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp31 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			} else {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			}

			//sp4
			if ($ts_sp40 != '' and $ts_sp41 != '') {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
				$sqlsp4 = "";
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp41 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			} else {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			}

			//sp5
			if ($ts_sp50 != '' and $ts_sp51 != '') {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
				$sqlsp5 = "";
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp51 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			} else {
				$sqlsp5 = "('" . $ts_mtn50 . "',  '" . $ts_sp50 . "', '" . $ts_id . "', '".$rp_ID5."')";
				$r_sp5 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp5);
			}

		} else if ($ts_sp10 != '' and $ts_sp20 != '' and $ts_sp30 != '' and $ts_sp40 != '') {
			//sp1
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

			//sp2
			if ($ts_sp20 != '' and $ts_sp21 != '') {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
				$sqlsp2 = "";
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp21 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			} else {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			}

			//sp3
			if ($ts_sp30 != '' and $ts_sp31 != '') {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
				$sqlsp3 = "";
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp31 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			} else {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			}

			//sp4
			if ($ts_sp40 != '' and $ts_sp41 != '') {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
				$sqlsp4 = "";
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp41 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			} else {
				$sqlsp4 = "('" . $ts_mtn40 . "',  '" . $ts_sp40 . "', '" . $ts_id . "', '".$rp_ID4."')";
				$r_sp4 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp4);
			}

		} else if ($ts_sp10 != '' and $ts_sp20 != '' and $ts_sp30 != '') {
			//sp1
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

			//sp2
			if ($ts_sp20 != '' and $ts_sp21 != '') {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
				$sqlsp2 = "";
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp21 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			} else {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			}

			//sp3
			if ($ts_sp30 != '' and $ts_sp31 != '') {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
				$sqlsp3 = "";
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp31 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			} else {
				$sqlsp3 = "('" . $ts_mtn30 . "',  '" . $ts_sp30 . "', '" . $ts_id . "', '".$rp_ID3."')";
				$r_sp3 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp3);
			}

		} else if ($ts_sp10 != '' and $ts_sp20 != '') {
			//sp1
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

			//sp2
			if ($ts_sp20 != '' and $ts_sp21 != '') {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
				$sqlsp2 = "";
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp21 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			} else {
				$sqlsp2 = "('" . $ts_mtn20 . "',  '" . $ts_sp20 . "', '" . $ts_id . "', '".$rp_ID2."')";
				$r_sp2 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp2);
			}

		} else if ($ts_sp10 != '') {
			if ($ts_sp10 != '' and $ts_sp11 != '') {
				//เธเธฃเธ“เธต adapter 2 เธเธดเนเธ
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
				$sqlsp1 = "";
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp11 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			} else {
				$sqlsp1 = "('" . $ts_mtn10 . "',  '" . $ts_sp10 . "', '" . $ts_id . "', '".$rp_ID1."')";
				$r_sp1 = maintenance_db_odbc_exec($connect, $sqlsp . $sqlsp1);
			}

		}

		maintenance_new_add_job_insert_debug_log('insert_job_complete', array(
			'mtn_number' => $mtn_number,
			'ts_id' => isset($ts_id) ? $ts_id : null,
		));
		$success_msg = $is_existing_add
			? 'เพิ่มอุปกรณ์สำเร็จ เลขที่งาน ' . $mtn_number
			: 'บันทึกสำเร็จ เลขที่งาน ' . $mtn_number;
		?>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($success_msg, JSON_UNESCAPED_UNICODE); ?>, {
	variant: 'success',
	onClose: function () { window.location.href = 'add_job'; }
});
</script>
		<?php

	} else {
		$mn_insert_job_err = $is_existing_add
			? 'บันทึกไม่สำเร็จ (เพิ่มอุปกรณ์งานเดิม)'
			: 'บันทึกไม่สำเร็จ [' . (isset($sql) ? $sql : '') . ']';
		maintenance_new_add_job_insert_debug_log('insert_transac_failed', array(
			'odbc_errno' => maintenance_db_odbc_error($connect),
			'odbc_errmsg' => maintenance_db_odbc_errormsg($connect),
			'sql' => isset($sql) ? $sql : '',
		));
		?>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($mn_insert_job_err, JSON_UNESCAPED_UNICODE); ?>, {
	variant: 'danger',
	onClose: function () { window.location.href = 'add_job'; }
});
</script>
		<?php
	}
	maintenance_db_odbc_close($connect);
	?>
</body>

</html>
