<?php
require_once __DIR__ . '/includes/require_auth.php';
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=tis-620" />
</head>
<body>
	<?php
require_once __DIR__ . '/includes/mysql_odbc_compat.php';

	$search = "";
	if (isset($_GET['pd_sp1'])) {
		$search = $_GET['pd_sp1'];
	} else if (isset($_GET['pd_sp2'])) {
		$search = $_GET['pd_sp2'];
	} else if (isset($_GET['pd_sp3'])) {
		$search = $_GET['pd_sp3'];
	} else if (isset($_GET['pd_sp4'])) {
		$search = $_GET['pd_sp4'];
	} else if (isset($_GET['pd_sp5'])) {
		$search = $_GET['pd_sp5'];
	} else if (isset($_GET['pd_sp6'])) {
		$search = $_GET['pd_sp6'];
	}else if (isset($_GET['pd_sp7'])) {
		$search = $_GET['pd_sp7'];
	}else if (isset($_GET['pd_sp8'])) {
		$search = $_GET['pd_sp8'];
	}else if (isset($_GET['pd_mtn'])) {
		$search = $_GET['pd_mtn'];
	}
	//echo $search;
	?>

	<?php if (isset($search)) { ?>
		<option value="">Select SN</option> 
		<?php
		$sql =  "SELECT * FROM spare Where sp_product_name = '" . $search . "' and sp_status = 'blank' ";
		if($search == "bitSupply 125" || $search == "bitSupply 1224"){
			$adapter = "%Adapter%";
			$sql =  "SELECT * FROM spare Where sp_product_name LIKE '". $adapter ."' and sp_status = 'blank' ";
		}else if($search == "bitScan Pro" || $search == "bitScan Kit" || $search == "bitVehicle" || $search == "bitScan Lite" || $search == "bitScan Online"){
			$bitScan = "%bitScan%";
			$sql =  "SELECT * FROM spare Where sp_product_name LIKE '". $bitScan ."' and sp_status = 'blank' ";
		}

		//$sql =  "SELECT * FROM spare Where sp_product_name = '" . $search . "' and sp_status = 'blank' ";
		$exec = maintenance_db_odbc_exec($connect, $sql) or die("Error Query [" . $sql . "]");

		while ($result = maintenance_db_odbc_fetch_array($exec)) {
		?>
			<option value="<?= $result['sp_sn'] ?>"><?= $result['sp_sn'] ?></option>
		<?php } ?>
	<?php } else { ?>
			<option value="">Not Found</option>
	<?php } ?>
			<option value="Waiting for spare">Waiting for spare</option>
</body>

</html>