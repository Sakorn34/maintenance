<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ GET จากหน้า status_logistic แล้วอัปเดต transac_spare (ขนส่ง / tracking / ผู้ส่ง / วันที่ส่ง)
 * จากนั้นอัปเดตสถานะงานซ่อม (เฉพาะเมื่อยัง «รอส่งสำรอง» → «รอเครื่องซ่อม») แล้วแสดง modal ก่อนกลับหน้า status_logistic
 *
 * Flow:
 * validate GET → UPDATE transac_spare → ถ้า `trp_status_job = รอส่งสำรอง` จึงตั้งเป็น `รอเครื่องซ่อม` → คิว sendtracking_history → modal → redirect
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/sendtracking_history_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$logis_logistics = isset($_GET['logis_logistics']) ? (string) $_GET['logis_logistics'] : '';
$logis_tracking = isset($_GET['logis_tracking']) ? (string) $_GET['logis_tracking'] : '';
$logis_sendby = isset($_GET['logis_sendby']) ? (string) $_GET['logis_sendby'] : '';
$logis_date = isset($_GET['logis_date']) ? (string) $_GET['logis_date'] : '';

$mn_modal_msg = 'บันทึกไม่สำเร็จ';
$mn_modal_variant = 'danger';

if ($id <= 0) {
    $mn_modal_msg = 'ข้อมูลไม่ครบ (id)';
    maintenance_db_odbc_close($connect);
} else {
    $e_log = mysqli_real_escape_string($connect, $logis_logistics);
    $e_trk = mysqli_real_escape_string($connect, $logis_tracking);
    $e_by = mysqli_real_escape_string($connect, $logis_sendby);
    $e_date = mysqli_real_escape_string($connect, $logis_date);

    $old_datesend = '';
    $stmtOld = mysqli_prepare($connect, 'SELECT tsp_datesend FROM transac_spare WHERE tsp_id = ? LIMIT 1');
    if ($stmtOld) {
        mysqli_stmt_bind_param($stmtOld, 'i', $id);
        if (mysqli_stmt_execute($stmtOld)) {
            $resOld = mysqli_stmt_get_result($stmtOld);
            $rowOld = $resOld ? mysqli_fetch_assoc($resOld) : null;
            if ($resOld) {
                mysqli_free_result($resOld);
            }
            if (is_array($rowOld) && isset($rowOld['tsp_datesend'])) {
                $old_datesend = trim((string) $rowOld['tsp_datesend']);
            }
        }
        mysqli_stmt_close($stmtOld);
    }

    $sql_update = "UPDATE transac_spare SET tsp_logistics = '" . $e_log . "', tsp_tracking = '" . $e_trk
        . "', tsp_sendby = '" . $e_by . "', tsp_datesend = '" . $e_date . "' WHERE tsp_id = " . $id;

    if (maintenance_db_odbc_exec($connect, $sql_update)) {
        $sql2 = 'SELECT * FROM transac_spare WHERE tsp_id = ' . $id;
        $exe2 = maintenance_db_odbc_exec($connect, $sql2);
        $result1 = $exe2 ? maintenance_db_odbc_fetch_array($exe2) : false;
        if (is_array($result1) && isset($result1['tsp_trp_id'])) {
            maintenance_status_logistic_apply_repair_status_after_spare_send($connect, (int) $result1['tsp_trp_id']);
            $sth_ts = isset($result1['tsp_ts_id']) ? (int) $result1['tsp_ts_id'] : 0;
            $sth_trp = (int) $result1['tsp_trp_id'];
            maintenance_sendtracking_history_upsert_row(
                $connect,
                'spare',
                $id,
                $sth_ts,
                $sth_trp,
                $logis_tracking,
                $logis_logistics,
                $logis_date,
                $logis_sendby
            );
        } else {
            maintenance_db_odbc_close($connect);
        }
        $mn_modal_msg = maintenance_sendtracking_history_status_logistic_save_success_message();
        $mn_modal_variant = 'success';
    } else {
        $mn_modal_msg .= ' [' . $sql_update . '] ' . maintenance_db_odbc_errormsg($connect);
        maintenance_db_odbc_close($connect);
    }
}

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
<title>อัปเดตขนส่ง (สำรอง)</title>
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
