<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ GET จากหน้า status_logistic (แถวเครื่องซ่อมลูกค้า) แล้วอัปเดต transac_repair — วันส่ง ขนส่ง tracking ผู้ส่ง และ trp_status_job จากนั้นคิว `sendtracking_history`
 * Logic เทียบ `old_system/update_logistic_repair.php` (มี/ไม่มีเครื่องสำรองค้างจาก tsp_datereturn)
 *
 * Parameters สำคัญจาก GET:
 * - id: trp_id
 * - trp_send_date, trp_logistics, trp_tracking, trp_sendby
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/sendtracking_history_helpers.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$trp_send_date = isset($_GET['trp_send_date']) ? (string) $_GET['trp_send_date'] : '';
$trp_logistics = isset($_GET['trp_logistics']) ? (string) $_GET['trp_logistics'] : '';
$trp_tracking = isset($_GET['trp_tracking']) ? (string) $_GET['trp_tracking'] : '';
$trp_sendby = isset($_GET['trp_sendby']) ? (string) $_GET['trp_sendby'] : '';

$mn_modal_msg = 'บันทึกไม่สำเร็จ';
$mn_modal_variant = 'danger';

if ($id <= 0) {
    $mn_modal_msg = 'ข้อมูลไม่ครบ (id)';
} else {
    $sql = 'SELECT tsp_trp_id, tsp_datereturn FROM transac_spare WHERE tsp_trp_id = ' . $id;
    $exe = maintenance_db_odbc_exec($connect, $sql);
    $result = $exe ? maintenance_db_odbc_fetch_array($exe) : false;
    if (!is_array($result)) {
        $tsp_trp_id = '';
        $datereturn = '';
    } else {
        $tsp_trp_id = isset($result['tsp_trp_id']) ? (string) $result['tsp_trp_id'] : '';
        $datereturn = isset($result['tsp_datereturn']) ? trim((string) $result['tsp_datereturn']) : '';
    }

    $e_date = mysqli_real_escape_string($connect, $trp_send_date);
    $e_log = mysqli_real_escape_string($connect, $trp_logistics);
    $e_trk = mysqli_real_escape_string($connect, $trp_tracking);
    $e_by = mysqli_real_escape_string($connect, $trp_sendby);

    $sql_update = "UPDATE transac_repair SET trp_send_date = '" . $e_date . "', trp_logistics = '" . $e_log
        . "', trp_tracking = '" . $e_trk . "', trp_sendby = '" . $e_by . "'";
    if ($tsp_trp_id === '' || $tsp_trp_id === '0' || $datereturn !== '') {
        $sql_update .= ", trp_status_job = 'เสร็จสิ้น' ";
    } else {
        $sql_update .= ", trp_status_job = 'รอเครื่องสำรอง' ";
    }
    $sql_update .= 'WHERE trp_id = ' . $id;

    if (maintenance_db_odbc_exec($connect, $sql_update)) {
        $ts_for_sth = 0;
        $sql_ts = 'SELECT trp_ts_id FROM transac_repair WHERE trp_id = ' . (int) $id . ' LIMIT 1';
        $exe_ts = maintenance_db_odbc_exec($connect, $sql_ts);
        $row_ts = $exe_ts ? maintenance_db_odbc_fetch_array($exe_ts) : false;
        if (is_array($row_ts) && isset($row_ts['trp_ts_id'])) {
            $ts_for_sth = (int) $row_ts['trp_ts_id'];
        }
        maintenance_sendtracking_history_upsert_row(
            $connect,
            'repair',
            $id,
            $ts_for_sth,
            $id,
            $trp_tracking,
            $trp_logistics,
            $trp_send_date,
            $trp_sendby
        );
        $mn_modal_msg = maintenance_sendtracking_history_status_logistic_save_success_message();
        $mn_modal_variant = 'success';
    } else {
        $mn_modal_msg .= ' [' . $sql_update . '] ' . maintenance_db_odbc_errormsg($connect);
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
<title>อัปเดตขนส่ง (ซ่อม)</title>
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
