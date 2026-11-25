<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ประวัติการส่งสำรองตาม S/N (เปิดจาก `add_spare` แบบ popup) — refactor จาก `old_system/check_history_spare.php`
 * ใช้ auth, bootstrap, JOIN + prepared, ธีม mn-*, ลิงก์ภายในโปรเจกต์ไม่มี `.php`
 *
 * Flow:
 * GET `sn` → helper ดึงแถว → ปิด `$connect` → render ตารางใน `table#table1`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/check_history_spare_helpers.php';

$sn_raw = isset($_GET['sn']) ? (string) $_GET['sn'] : '';
$sn = trim($sn_raw);
if (strlen($sn) > 200) {
    $sn = substr($sn, 0, 200);
}

$rows = array();
if ($sn !== '') {
    $rows = maintenance_check_history_spare_fetch_rows($connect, $sn);
}

maintenance_db_odbc_close($connect);

$row_count = count($rows);
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
<link rel="stylesheet" href="css/mn-page-check-history-spare.css" />
<title>ประวัติส่งสำรอง</title>
</head>
<body class="mn-addjob-page mn-check-history-spare-page">
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-check-history-spare-inner">
      <section class="mn-chs-card" aria-labelledby="mn-chs-title">
        <header class="mn-chs-card__header">
          <h1 id="mn-chs-title" class="mn-chs-card__title">ประวัติการส่งเครื่องสำรอง</h1>
          <?php if ($sn !== '') { ?>
          <p class="mn-chs-card__meta">S/N: <strong><?php echo htmlspecialchars($sn, ENT_QUOTES, 'UTF-8'); ?></strong> — พบ <?php echo (int) $row_count; ?> แถว</p>
          <?php } ?>
        </header>
        <div class="mn-chs-card__body">
          <?php if ($sn === '') { ?>
          <p class="mn-chs-hint">ไม่ได้ระบุ S/N ใน URL — เปิดจากลิงก์ประวัติในหน้าเพิ่มสำรอง (พารามิเตอร์ <code>sn</code>)</p>
          <?php } elseif ($row_count === 0) { ?>
          <p class="mn-chs-empty">ไม่พบประวัติการส่งสำรองสำหรับ S/N นี้</p>
          <?php } else { ?>
          <table id="table1" class="mn-check-history-spare-table">
            <thead>
              <tr>
                <th>สินค้า</th>
                <th class="mn-chs-col--sn">SN</th>
                <th>วันที่ส่ง</th>
                <th>ขนส่ง</th>
                <th class="mn-chs-col--track">Tracking</th>
                <th>ผู้ส่ง</th>
                <th>วันที่คืน</th>
                <th>ผู้รับ</th>
                <th>Security</th>
                <th>Enduser</th>
              </tr>
            </thead>
            <tbody>
              <?php
              foreach ($rows as $result) {
                  $sec = maintenance_check_history_spare_iconv(isset($result['ts_security_com']) ? $result['ts_security_com'] : '');
                  $end = maintenance_check_history_spare_iconv(isset($result['ts_enduser_com']) ? $result['ts_enduser_com'] : '');
                  ?>
              <tr>
                <td><?php echo htmlspecialchars((string) (isset($result['tsp_product']) ? $result['tsp_product'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="mn-chs-col--sn"><?php echo htmlspecialchars((string) (isset($result['tsp_sn']) ? $result['tsp_sn'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) (isset($result['tsp_datesend']) ? $result['tsp_datesend'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) (isset($result['tsp_logistics']) ? $result['tsp_logistics'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="mn-chs-col--track"><?php echo htmlspecialchars((string) (isset($result['tsp_tracking']) ? $result['tsp_tracking'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) (isset($result['tsp_sendby']) ? $result['tsp_sendby'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) (isset($result['tsp_datereturn']) ? $result['tsp_datereturn'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) (isset($result['tsp_receiveby']) ? $result['tsp_receiveby'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($sec, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($end, ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
                  <?php
              }
              ?>
            </tbody>
          </table>
          <?php } ?>
        </div>
      </section>
    </div>
  </div>
</body>
</html>
