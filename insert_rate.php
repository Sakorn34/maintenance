<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * INSERT แถว `Rate` จากพารามิเตอร์ GET แล้วแสดง modal ผลลัพธ์ก่อนกลับ `add_rate` — แทน `old_system/insert_rate.php` (alert + meta refresh)
 *
 * Flow:
 * require_auth → bootstrap → validate → prepared INSERT → `maintenance_activity_log_write` (เมื่อสำเร็จ) → modal → redirect `add_rate`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';

$r_rate = isset($_GET['r_rate']) ? trim((string) $_GET['r_rate']) : '';
$r_product = isset($_GET['r_product']) ? trim((string) $_GET['r_product']) : '';
$r_price = isset($_GET['r_price']) ? trim((string) $_GET['r_price']) : '';

$mn_modal_msg = 'ข้อมูลไม่ครบ';
$mn_modal_variant = 'warning';

if ($r_rate !== '' && $r_product !== '') {
    $sql = 'INSERT INTO rate (r_rate, r_product, r_price) VALUES (?, ?, ?)';
    $stmt = mysqli_prepare($connect, $sql);
    if ($stmt === false) {
        $mn_modal_msg = 'เตรียมคำสั่งไม่สำเร็จ';
        $mn_modal_variant = 'danger';
    } else {
        mysqli_stmt_bind_param($stmt, 'sss', $r_rate, $r_product, $r_price);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if ($ok) {
            $mn_modal_msg = 'บันทึกสำเร็จ';
            $mn_modal_variant = 'success';
            maintenance_activity_log_write($connect, 'insert_rate', array(
                'entity_type' => 'Rate',
                'payload' => array(
                    'r_product' => $r_product,
                    'r_rate' => $r_rate,
                ),
            ));
        } else {
            $mn_modal_msg = 'บันทึกไม่สำเร็จ';
            $mn_modal_variant = 'danger';
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
<title>บันทึกรายการซ่อม</title>
</head>
<body>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($mn_modal_msg, JSON_UNESCAPED_UNICODE); ?>, {
    variant: <?php echo json_encode($mn_modal_variant); ?>,
    onClose: function () { window.location.href = 'add_rate'; }
});
</script>
</body>
</html>
