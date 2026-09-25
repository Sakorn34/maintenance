<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับค่า GET สำหรับเพิ่มแถว Spare แล้วแสดง modal ผลลัพธ์แทน alert ก่อนกลับหน้า add_spare
 */
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/function_legacy.php';

$sn = isset($_GET['sp_sn']) ? $_GET['sp_sn'] : '';
$sqlInsert = '';
$duplicate = false;

$sql = "SELECT * FROM spare WHERE sp_sn = '" . mysqli_real_escape_string($connect, $sn) . "' ";
$exec = maintenance_db_odbc_exec($connect, $sql);
$result = maintenance_db_odbc_fetch_array($exec);
if ($result !== false && isset($result['sp_sn']) && $result['sp_sn'] !== '') {
    $duplicate = true;
} else {
    $date = date('Y');
    $sqlInsert = "insert into spare (sp_product_model, sp_product_name, sp_sn, sp_pickup_year, sp_status) values ";
    $sqlInsert .= "('" . mysqli_real_escape_string($connect, isset($_GET['sp_product_model']) ? $_GET['sp_product_model'] : '') . "', '" .
        mysqli_real_escape_string($connect, isset($_GET['sp_product_name']) ? $_GET['sp_product_name'] : '') . "','" .
        mysqli_real_escape_string($connect, $sn) . "', '" . $date . "', 'blank' ) ";
    maintenance_db_odbc_exec($connect, $sqlInsert);
}

if ($duplicate) {
    $mn_modal_msg = 'คุณเพิ่มเครื่องสำรองซ้ำ';
    $mn_modal_variant = 'warning';
} elseif (!maintenance_db_odbc_error()) {
    maintenance_db_odbc_commit($connect);
    $mn_modal_msg = 'บันทึกสำเร็จ';
    $mn_modal_variant = 'success';
} else {
    maintenance_db_odbc_rollback($connect);
    $mn_modal_msg = 'บันทึกไม่สำเร็จ';
    if ($sqlInsert !== '') {
        $mn_modal_msg .= ' [' . $sqlInsert . ']';
    }
    $mn_modal_variant = 'danger';
}
maintenance_db_odbc_close($connect);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <link rel="stylesheet" href="css/mn-theme.css" />
    <?php
    $mnModalAssetPrefix = '';
    require_once __DIR__ . '/includes/mn_modal_assets.php';
    ?>
    <title>บันทึกเครื่องสำรอง</title>
</head>
<body>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($mn_modal_msg, JSON_UNESCAPED_UNICODE); ?>, {
    variant: <?php echo json_encode($mn_modal_variant); ?>,
    onClose: function () { window.location.href = 'add_spare'; }
});
</script>
</body>
</html>
