<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ POST แล้วอัปเดต `transac.ts_address` ตาม `ts_id`
 * ตรวจสิทธิ์ตาม `mn_address_scope`: โหมด `logistic` จำกัดเฉพาะสถานะบนหน้ารอส่งพัสดุ; โหมด `detail` อนุญาตเมื่อมี transac + transac_repair ผูก `ts_id` (หน้า detail_job)
 *
 * Flow:
 * validate POST → require_auth → ตรวจตาม scope → UPDATE → modal → redirect ตาม `mn_return`
 *
 * Parameters สำคัญจาก POST:
 * - ts_id, ts_address
 * - mn_address_scope: `logistic` | `detail` (default logistic)
 * - mn_return: `status_logistic` | `detail_job` (default status_logistic)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';

$ts_id = isset($_POST['ts_id']) ? (int) $_POST['ts_id'] : 0;
$ts_address = isset($_POST['ts_address']) ? (string) $_POST['ts_address'] : '';
$scope = isset($_POST['mn_address_scope']) ? trim((string) $_POST['mn_address_scope']) : 'logistic';
$mn_return = isset($_POST['mn_return']) ? trim((string) $_POST['mn_return']) : 'status_logistic';

$mn_modal_msg = 'บันทึกไม่สำเร็จ';
$mn_modal_variant = 'danger';
$redirect = 'status_logistic';
if ($mn_return === 'detail_job' && $ts_id > 0) {
    $redirect = 'detail_job?ts_id=' . $ts_id;
}

if ($ts_id <= 0) {
    $mn_modal_msg = 'ข้อมูลไม่ครบ (ts_id)';
} else {
    $ok_check = false;
    if ($scope === 'detail') {
        $sqlCheck = 'SELECT t.ts_id FROM transac t INNER JOIN transac_repair r ON r.trp_ts_id = t.ts_id WHERE t.ts_id = ' . $ts_id . ' LIMIT 1';
        $exeCheck = maintenance_db_odbc_exec($connect, $sqlCheck);
        $rowCheck = $exeCheck ? maintenance_db_odbc_fetch_array($exeCheck) : false;
        $ok_check = is_array($rowCheck) && isset($rowCheck['ts_id']);
        if (!$ok_check) {
            $mn_modal_msg = 'ไม่พบใบงานที่อนุญาตให้แก้ไขที่อยู่ (ต้องมี transac_repair ผูก ts_id)';
        }
    } else {
        $status1 = mysqli_real_escape_string($connect, 'รอส่งคืนเครื่องซ่อม');
        $status2 = mysqli_real_escape_string($connect, 'รอส่งสำรอง');
        $sqlCheck = 'SELECT trp_id FROM transac_repair WHERE trp_ts_id = ' . $ts_id
            . " AND trp_status_job IN ('" . $status1 . "','" . $status2 . "') LIMIT 1";
        $exeCheck = maintenance_db_odbc_exec($connect, $sqlCheck);
        $rowCheck = $exeCheck ? maintenance_db_odbc_fetch_array($exeCheck) : false;
        $ok_check = is_array($rowCheck) && isset($rowCheck['trp_id']);
        if (!$ok_check) {
            $mn_modal_msg = 'ไม่พบใบงานที่อนุญาตให้แก้ไขที่อยู่สำหรับรายการนี้ (สถานะไม่อยู่ในรอส่งพัสดุ)';
        }
    }

    if ($ok_check) {
        $e_addr = mysqli_real_escape_string($connect, $ts_address);
        $sql_update = "UPDATE transac SET ts_address = '" . $e_addr . "' WHERE ts_id = " . $ts_id;
        if (maintenance_db_odbc_exec($connect, $sql_update)) {
            $mn_modal_msg = 'บันทึกที่อยู่สำเร็จ';
            $mn_modal_variant = 'success';
        } else {
            $mn_modal_msg .= ' [' . $sql_update . '] ' . maintenance_db_odbc_errormsg($connect);
        }
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
<title>อัปเดตที่อยู่จัดส่ง</title>
</head>
<body>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($mn_modal_msg, JSON_UNESCAPED_UNICODE); ?>, {
    variant: <?php echo json_encode($mn_modal_variant); ?>,
    onClose: function () { window.location.href = <?php echo json_encode($redirect, JSON_UNESCAPED_UNICODE); ?>; }
});
</script>
</body>
</html>
