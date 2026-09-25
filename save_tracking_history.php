<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * INSERT แถว tracking_history แล้วปิด popup — refactor จาก `old_system/save_tracking_history.php`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';

$th_ts_id = maintenance_flow_get_int($_GET, 'th_ts_id', 0);
$th_trp_id = maintenance_flow_get_int($_GET, 'th_trp_id', 0);
$th_tsp_id = maintenance_flow_get_int($_GET, 'th_tsp_id', 0);
$th_date_tracking = isset($_GET['th_date_tracking']) ? (string) $_GET['th_date_tracking'] : '';
$th_date_nextdue = isset($_GET['th_date_nextdue']) ? (string) $_GET['th_date_nextdue'] : '';
$th_status_job = isset($_GET['th_status_job']) ? (string) $_GET['th_status_job'] : '';
$th_detail = isset($_GET['th_detail']) ? (string) $_GET['th_detail'] : '';
$th_user = isset($_GET['th_user']) ? (string) $_GET['th_user'] : '';

if ($th_ts_id < 1 || $th_trp_id < 1) {
    maintenance_db_odbc_close($connect);
    echo '<script>window.close();</script>';
    exit;
}

$sql = 'INSERT INTO tracking_history (th_ts_id, th_trp_id, th_tsp_id, th_date_tracking, th_date_nextdue, th_status_job, th_detail, th_user) VALUES (?,?,?,?,?,?,?,?)';
$stmt = mysqli_prepare($connect, $sql);
if ($stmt === false) {
    maintenance_db_odbc_close($connect);
    exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param(
    $stmt,
    'iiisssss',
    $th_ts_id,
    $th_trp_id,
    $th_tsp_id,
    $th_date_tracking,
    $th_date_nextdue,
    $th_status_job,
    $th_detail,
    $th_user
);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
maintenance_db_odbc_close($connect);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8" />
<title>บันทึกแล้ว</title>
<script type="text/javascript">
function mnTrackingSaveClose() {
  window.close();
}
</script>
</head>
<body onload="mnTrackingSaveClose();">
<p>บันทึกแล้ว</p>
</body>
</html>
