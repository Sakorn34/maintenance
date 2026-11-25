<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * แบบฟอร์ม QC หลังซ่อมตาม `trp_id` — refactor จาก `old_system/qc_checklist.php`
 * เลย์เอาต์การ์ด + กริด (`mn-page-qc-checklist.css`) ไม่ยึดตาราง legacy
 * ใช้ auth, bootstrap, JOIN เดียว, helper สร้าง HTML, ธีม mn-*, modal แทน `alert`/`confirm`, บันทึกผ่าน `update_wait_qc` (ไม่มี `.php`)
 *
 * Flow:
 * GET `trp_id` → ดึงแถว join → `maintenance_qc_checklist_history_html()` (ครั้งที่ 1, 2, …) → `bootstrap_logistic_recorder` (ชื่อเจ้าหน้าที่ QC จาก session) → ปิด `$connect` → render + `js/qc_checklist-page.js`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/qc_checklist_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$row = maintenance_qc_checklist_fetch_row($connect, $trp_id);
if ($row === null) {
    maintenance_db_odbc_close($connect);
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="th">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>ไม่พบงานซ่อม</title>
</head>
<body>
<p>ไม่พบรายการซ่อมสำหรับรหัสที่ระบุ</p>
<p><a href="status_wait_qc">กลับหน้ารอ QC</a></p>
</body>
</html>
    <?php
    exit;
}

$result_trp = $row;
$result_ts = $row;
$detail = maintenance_qc_checklist_company_detail_html($row);
$list_repair = maintenance_qc_checklist_list_repair_html($row);
$history_html = maintenance_qc_checklist_history_html($connect, $row);

maintenance_db_odbc_close($connect);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8" />
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-qc-checklist.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>QC หลังซ่อม</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/qc_checklist-page.js"></script>
</head>
<body class="mn-addjob-page mn-qc-checklist-page">
  <div class="header">
    <?php
    $page = 'status_wait_qc';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-qc-checklist-inner">
      <?php require __DIR__ . '/includes/qc_checklist_form_rows.inc.php'; ?>
    </div>
  </div>
</body>
</html>
