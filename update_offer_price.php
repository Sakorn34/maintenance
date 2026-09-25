<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ GET จากหน้า `edit_offer_price` หรือ `detail_job` (แก้ใบเสนอราคา) แล้วอัปเดต `transac_repair` (QV + ราคา + สถานะ `รออนุมัติซ่อม`) — refactor จาก `old_system/update_offer_price.php` ใช้ mysqli prepared + modal แล้ว redirect
 *
 * Flow:
 * require_auth → mysqli → `maintenance_offer_price_qv_apply_from_get` → ปิด connection → modal → redirect ตาม `page` (ค่า default `status_offer_price`; จาก `detail_job` อาจส่ง `status_wait_firmQV`)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_odbc_compat.php';
require_once __DIR__ . '/includes/offer_price_qv_helpers.php';

$result = maintenance_offer_price_qv_apply_from_get($connect, $_GET);
$mn_modal_msg = $result['message'];
$mn_modal_variant = $result['ok'] ? 'success' : 'danger';

$return_page = isset($_GET['page']) ? trim((string) $_GET['page']) : '';
$allowed_return = array('status_offer_price', 'status_wait_firmQV');
if ($return_page === '' || !in_array($return_page, $allowed_return, true)) {
    $return_page = 'status_offer_price';
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
<title>บันทึกเสนอราคา</title>
</head>
<body>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode($mn_modal_msg, JSON_UNESCAPED_UNICODE); ?>, {
    variant: <?php echo json_encode($mn_modal_variant); ?>,
    onClose: function () { window.location.href = <?php echo json_encode($return_page, JSON_UNESCAPED_UNICODE); ?>; }
});
</script>
</body>
</html>
