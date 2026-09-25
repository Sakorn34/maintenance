<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกแจ้งรับพัสดุหน้างาน — สองโหมดตาม `old_system/save_pickup_wait_hardware.php`
 * - ไม่มีวันที่รับ (`trp_date_pickup` ว่าง): UPDATE `trp_contact_pickup`, `trp_user_inform` แล้วปิด popup
 * - มีวันที่รับ: UPDATE `trp_date_pickup`, `trp_logistics_pickup`, `trp_tracking_pickup`, `trp_user_inform_logistics` แล้วไปหน้า `status_logistic_pickup`
 *
 * Flow:
 * require_auth → bootstrap → แยกโหมดจาก GET → `mysqli_prepare` + execute → HTML/redirect
 *
 * Parameters สำคัญจาก GET:
 * - โหมด popup: trp_id, trp_contact_pickup, trp_user_inform (ไม่ส่งหรือส่ง `trp_date_pickup` เป็นค่าว่าง)
 * - โหมด logistics: trp_id, trp_date_pickup, trp_logistics_pickup, trp_tracking_pickup, trp_user_inform_logistics
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$trp_date_pickup = isset($_GET['trp_date_pickup']) ? trim((string) $_GET['trp_date_pickup']) : '';

if ($trp_id < 1) {
    maintenance_db_odbc_close($connect);
    header('Content-Type: text/html; charset=utf-8');
    echo '<script>window.close();</script>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

if ($trp_date_pickup === '') {
    $trp_contact_pickup = isset($_GET['trp_contact_pickup']) ? (string) $_GET['trp_contact_pickup'] : '';
    $trp_user_inform = isset($_GET['trp_user_inform']) ? (string) $_GET['trp_user_inform'] : '';
    $sql = 'UPDATE transac_repair SET trp_contact_pickup = ?, trp_user_inform = ? WHERE trp_id = ?';
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt === false) {
        maintenance_db_odbc_close($connect);
        exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($stmt, 'ssi', $trp_contact_pickup, $trp_user_inform, $trp_id);
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
function mnPickupSaveClose() {
  window.close();
}
</script>
</head>
<body onload="mnPickupSaveClose();">
<p>บันทึกแล้ว</p>
</body>
</html>
    <?php
    exit;
}

$trp_logistics_pickup = isset($_GET['trp_logistics_pickup']) ? trim((string) $_GET['trp_logistics_pickup']) : '';
$trp_tracking_pickup = isset($_GET['trp_tracking_pickup']) ? trim((string) $_GET['trp_tracking_pickup']) : '';
$trp_user_inform_logistics = isset($_GET['trp_user_inform_logistics']) ? trim((string) $_GET['trp_user_inform_logistics']) : '';

$sql = 'UPDATE transac_repair SET trp_date_pickup = ?, trp_logistics_pickup = ?, trp_tracking_pickup = ?, trp_user_inform_logistics = ? WHERE trp_id = ?';
$stmt = mysqli_prepare($connect, $sql);
if ($stmt === false) {
    maintenance_db_odbc_close($connect);
    exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param($stmt, 'ssssi', $trp_date_pickup, $trp_logistics_pickup, $trp_tracking_pickup, $trp_user_inform_logistics, $trp_id);
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
function mnPickupLogisticsRedirect() {
  window.location.href = 'status_logistic_pickup';
}
</script>
</head>
<body onload="mnPickupLogisticsRedirect();">
<p>บันทึกแล้ว</p>
</body>
</html>
