<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * แก้ไขสถานะ Spare / transac_spare แล้วแสดง modal ผลลัพธ์แทน alert ก่อนกลับหน้า add_spare
 */
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/function_legacy.php';

$esc = function ($s) use ($connect) {
    return mysqli_real_escape_string($connect, (string) $s);
};
$spSnEdit = isset($_GET['sp_sn_edit']) ? $_GET['sp_sn_edit'] : '';

$sql = "SELECT * FROM spare WHERE sp_sn = '" . $esc($spSnEdit) . "' ";
$exec = maintenance_db_odbc_exec($connect, $sql);
$result = maintenance_db_odbc_fetch_array($exec);

$mn_modal_msg = '';
$mn_modal_variant = 'danger';

if ($result === false || !isset($result['sp_sn']) || $result['sp_sn'] === '') {
    $mn_modal_msg = 'ไม่พบเครื่องสำรองที่ต้องการแก้ไข';
    $mn_modal_variant = 'warning';
} else {
    $sqlcheckSpare = "SELECT * FROM transac_spare WHERE tsp_sn = '" . $esc($spSnEdit) . "' ORDER BY tsp_id DESC";
    $exec = maintenance_db_odbc_exec($connect, $sqlcheckSpare);
    $resultSp = maintenance_db_odbc_fetch_array($exec);
    if ($resultSp !== false && isset($resultSp['tsp_id']) && $resultSp['tsp_id'] !== '' && $resultSp['tsp_id'] !== null
        && (empty($resultSp['tsp_datereturn']) || $resultSp['tsp_datereturn'] === '')
    ) {
        $sqlReturnSpare = "UPDATE transac_spare SET ";
        $sqlReturnSpare .= "tsp_datereturn = '" . date('Y-m-d') . "' ";
        $sqlReturnSpare .= ",tsp_receiveby = 'admin' ";
        $sqlReturnSpare .= 'WHERE tsp_id = ' . (int) $resultSp['tsp_id'] . '  ';
        maintenance_db_odbc_exec($connect, $sqlReturnSpare);
        $blank = 'blank';
        $sql2 = "UPDATE spare SET sp_status = '" . $esc($blank) . "' WHERE sp_sn = '" . $esc($spSnEdit) . "'  ";
        maintenance_db_odbc_exec($connect, $sql2);
    } else {
        $sqlUpSpare = 'UPDATE spare SET ';
        $sqlUpSpare .= "sp_sn = '" . $esc($spSnEdit) . "' ";
        $sqlUpSpare .= ",sp_status = '" . $esc(isset($_GET['sp_status']) ? $_GET['sp_status'] : '') . "' ";
        $sqlUpSpare .= "WHERE sp_sn = '" . $esc($spSnEdit) . "'  ";
        maintenance_db_odbc_exec($connect, $sqlUpSpare);
    }

    if (!maintenance_db_odbc_error()) {
        maintenance_db_odbc_commit($connect);
        $mn_modal_msg = 'แก้ไขสำเร็จ';
        $mn_modal_variant = 'success';
    } else {
        maintenance_db_odbc_rollback($connect);
        $mn_modal_msg = 'เกิดข้อผิดพลาด';
        $mn_modal_variant = 'danger';
    }
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
    <title>อัปเดตสถานะสำรอง</title>
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
