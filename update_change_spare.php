<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เปลี่ยน S/N เครื่องสำรองใน transac_spare และสลับ sp_status ใน Spare (เทียบ old_system/update_change_spare.php)
 *
 * Flow:
 * GET tsp_id, tsp_snNew, tsp_snOld → UPDATE transac_spare → UPDATE spare (use / blank) → modal → redirect status_logistic
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$tsp_id = isset($_GET['tsp_id']) ? (int) $_GET['tsp_id'] : 0;
$trp_id = isset($_GET['trp_id']) ? (int) $_GET['trp_id'] : 0;
$tsp_ts_id = isset($_GET['tsp_ts_id']) ? (int) $_GET['tsp_ts_id'] : 0;
$tsp_product = isset($_GET['tsp_product']) ? (string) $_GET['tsp_product'] : '';
$tsp_sn_new = isset($_GET['tsp_snNew']) ? (string) $_GET['tsp_snNew'] : '';
$tsp_sn_old = isset($_GET['tsp_snOld']) ? (string) $_GET['tsp_snOld'] : '';

$tsp_id = maintenance_status_logistic_resolve_tsp_id($connect, $tsp_id, $trp_id, $tsp_sn_old, $tsp_ts_id, $tsp_product);

$mn_modal_msg = 'บันทึกไม่สำเร็จ';
$mn_modal_variant = 'danger';

if ($tsp_id <= 0) {
    $mn_modal_msg = 'ข้อมูลไม่ครบ (tsp_id) — ตรวจแถว transac_spare บนฐานข้อมูล production';
} else {
    $e_new = mysqli_real_escape_string($connect, $tsp_sn_new);
    $e_old = mysqli_real_escape_string($connect, $tsp_sn_old);
    $sql = 'UPDATE transac_spare SET tsp_sn = \'' . $e_new . '\' WHERE tsp_id = ' . $tsp_id;
    if (!maintenance_db_odbc_exec($connect, $sql)) {
        $mn_modal_msg .= ' [' . $sql . '] ' . maintenance_db_odbc_errormsg($connect);
    } else {
        $sql2 = "UPDATE spare SET sp_status = 'use' WHERE sp_sn = '" . $e_new . "'";
        if (!maintenance_db_odbc_exec($connect, $sql2)) {
            $mn_modal_msg .= ' (Spare use) [' . $sql2 . '] ' . maintenance_db_odbc_errormsg($connect);
        } elseif ($tsp_sn_old === '') {
            $mn_modal_msg = 'บันทึกสำเร็จ';
            $mn_modal_variant = 'success';
        } else {
            $sql3 = "UPDATE spare SET sp_status = 'blank' WHERE sp_sn = '" . $e_old . "'";
            if (!maintenance_db_odbc_exec($connect, $sql3)) {
                $mn_modal_msg .= ' (Spare blank) [' . $sql3 . '] ' . maintenance_db_odbc_errormsg($connect);
            } else {
                $mn_modal_msg = 'บันทึกสำเร็จ';
                $mn_modal_variant = 'success';
            }
        }
    }
}

maintenance_db_odbc_close($connect);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/1999/xhtml">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>เปลี่ยน S/N สำรอง</title>
</head>
<body>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($mn_modal_msg, JSON_UNESCAPED_UNICODE); ?>, {
    variant: <?php echo json_encode($mn_modal_variant); ?>,
    onClose: function () { window.location.href = 'status_logistic'; }
});
</script>
</body>
</html>
