<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ GET จากฟอร์มประเมิน (`update_rate`) หรือ POST จากหน้า `assess_repair` (ข้อความสรุปยาว) แล้วอัปเดต `transac_repair` — refactor จาก `old_system/save_rate.php` ใช้ auth + mysqli prepared + modal แล้วกลับ `status_rate`
 *
 * Flow:
 * require_auth → mysqli → `maintenance_save_rate_apply_from_get` → ปิด connection → แสดง modal → redirect `status_rate`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/save_rate_helpers.php';

$rate_input = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$result = maintenance_save_rate_apply_from_get($connect, $rate_input);
$mn_modal_msg = $result['message'];
$mn_modal_variant = $result['ok'] ? 'success' : 'danger';
$mn_redirect = !empty($result['redirect']) ? (string) $result['redirect'] : 'status_rate';

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
<title>บันทึกประเมิน</title>
</head>
<body>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($mn_modal_msg, JSON_UNESCAPED_UNICODE); ?>, {
    variant: <?php echo json_encode($mn_modal_variant); ?>,
    onClose: function () { window.location.href = <?php echo json_encode($mn_redirect); ?>; }
});
</script>
</body>
</html>
