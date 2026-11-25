<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ค้นหาประวัติการซ่อมตาม S/N — refactor จาก `old_system/check_history_repair.php`
 * ใช้ auth, bootstrap, ธีม mn-*, POST + prepared ผ่าน helper
 *
 * Flow:
 * POST `sp_sn` → `maintenance_check_history_repair_sn_html()` → แสดงผล (HTML จาก helper ไม่ห่อ `htmlspecialchars` ซ้ำทั้งก้อน)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/check_history_repair_helpers.php';

$posted_sn = isset($_POST['sp_sn']) ? trim((string) $_POST['sp_sn']) : '';
$history_html = '';
$show_result = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $show_result = true;
    if ($posted_sn === '') {
        $history_html = '<p class="mn-check-history-repair-msg">กรุณาระบุ S/N</p>';
    } elseif (strlen($posted_sn) > 200) {
        $history_html = '<p class="mn-check-history-repair-msg">S/N ยาวเกินกำหนด</p>';
    } else {
        $history_html = maintenance_check_history_repair_sn_html($connect, $posted_sn, '');
    }
    maintenance_db_odbc_close($connect);
} else {
    maintenance_db_odbc_close($connect);
}

require_once __DIR__ . '/config/app_environment.php';
$index_url = maintenance_new_maintenance_index_url();
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-check-history-repair.css" />
<title>ประวัติการซ่อม</title>
</head>
<body class="mn-addjob-page mn-check-history-repair-page">
  <div class="header">
    <?php
    $page = '';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-check-history-repair-inner">
      <p><a class="mn-check-history-repair-home" href="<?php echo htmlspecialchars($index_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-home fa-lg" aria-hidden="true"></i> BACK TO HOME...</a></p>
      <hr />
      <form action="check_history_repair" method="post" id="mn-check-history-repair-form">
        <div class="mn-check-history-repair-searchrow">
          <span class="mn-check-history-repair-searchlabel"><strong>ค้นหาประวัติการซ่อม [ S/N ] : </strong></span>
          <input type="text" name="sp_sn" id="sp_sn" class="mn-check-history-repair-searchinput" maxlength="200" value="<?php echo htmlspecialchars($posted_sn, ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="submit" name="mn_search" class="mn-check-history-repair-searchsubmit" value="ค้นหา" />
        </div>
      </form>
      <?php if ($show_result && $posted_sn !== '') { ?>
      <p class="mn-check-history-repair-snline"><b>หมายเลข S/N ที่ค้นหา : </b><span class="mn-check-history-repair-snval"><?php echo htmlspecialchars($posted_sn, ENT_QUOTES, 'UTF-8'); ?></span></p>
      <?php } ?>
      <div class="mn-check-history-repair-result">
        <?php echo $history_html; ?>
      </div>
      <hr />
    </div>
  </div>
</body>
</html>
