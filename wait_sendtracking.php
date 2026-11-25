<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า «คิวส่งลิงก์ลูกค้า» — แสดงรายการ `sendtracking_history` หลังบันทึก tracking จาก `status_logistic` ให้ staff คัดลอกข้อความแชร์ลูกค้าแบบเดียวกับหน้า `job_process.php` (รวมลิงก์ LINE / mailto)
 *
 * Flow:
 * bootstrap → ดึงแถวล่าสุด → สร้าง payload แชร์ลูกค้าต่อ `trp_id` (cache) → ปิด DB → ตาราง + ปุ่มคัดลอกข้อความ (หลังคัดลอกสำเร็จ POST `remove_sendtracking_queue` ลบแถวคิว)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_wait_sendtracking_page.php';

$table_error = '';
$page_load_error = '';
try {
    $rows = maintenance_sendtracking_history_fetch_recent_rows($connect, 200);
    if ($rows === false) {
        $table_error = 'ไม่สามารถอ่านตาราง sendtracking_history ได้ — รัน SQL migrate (`database/sendtracking_history.sql`) หรือตรวจสิทธิ์ผู้ใช้ DB';
        $rows = array();
    }
    $share_cache = array();
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $tid = isset($r['sth_trp_id']) ? (int) $r['sth_trp_id'] : 0;
        if ($tid > 0 && !isset($share_cache[$tid])) {
            $share_cache[$tid] = maintenance_job_process_customer_share_payload_for_trp($connect, $tid);
        }
    }
} catch (Throwable $e) {
    $page_load_error = 'โหลดคิวส่งลิงก์ไม่สำเร็จ — ' . $e->getMessage();
    $rows = array();
    $share_cache = array();
}
maintenance_db_odbc_close($connect);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-wait-sendtracking.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>คิวส่งลิงก์ลูกค้า</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/wait_sendtracking-page.js"></script>
</head>
<body class="mn-addjob-page mn-wait-sendtracking-page">
  <div class="header">
    <?php
    $page = 'wait_sendtracking';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
      <p class="mn-wait-sendtracking-intro">
        รายการนี้เกิดจากการบันทึกเลข tracking ในหน้า «รอส่งพัสดุ» — ปุ่ม <strong>คัดลอกข้อความ</strong> ให้ข้อความเดียวกับปุ่มคัดลอกในหน้า «ความคืบหน้างานซ่อม» นำไปวาง LINE / เมลส่งลูกค้าได้ทันที
      </p>
      <?php if ($page_load_error !== '') { ?>
      <p class="mn-wait-sendtracking-error"><?php echo maintenance_status_logistic_h($page_load_error); ?></p>
      <?php } ?>
      <?php if ($table_error !== '') { ?>
      <p class="mn-wait-sendtracking-error"><?php echo maintenance_status_logistic_h($table_error); ?></p>
      <?php } ?>
      <table id="table1" class="mn-wait-sendtracking-table" width="100%">
        <thead>
          <tr class="spaceUnder mn-wait-sendtracking-thead">
            <th width="4%"><div align="center"><b>No</b></div></th>
            <th width="12%"><div align="center"><b>อัปเดตล่าสุด</b></div></th>
            <th width="14%"><div align="center"><b>หน่วยงาน</b><br /><b>สินค้า</b></div></th>
            <th width="11%"><div align="center"><b>Tracking</b></div></th>
            <th width="9%"><div align="center"><b>ขนส่ง</b></div></th>
            <th width="8%"><div align="center"><b>วันที่ส่ง</b></div></th>
            <th width="10%"><div align="center"><b>LINE ผู้แจ้ง</b></div></th>
            <th width="10%"><div align="center"><b>อีเมลผู้แจ้ง</b></div></th>
            <th width="27%"><div align="center"><b>คัดลอกข้อความ</b></div></th>
          </tr>
        </thead>
        <tbody>
        <?php
        $n = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n += 1;
            $trp_id = isset($row['sth_trp_id']) ? (int) $row['sth_trp_id'] : 0;
            $payload_empty = array(
                'plain' => '',
                'line_url' => '',
                'mailto' => '',
                'report_line' => '',
                'report_email' => '',
                'org_enduser' => '',
                'product_detail' => '',
            );
            $payload = ($trp_id > 0 && isset($share_cache[$trp_id]) && is_array($share_cache[$trp_id]))
                ? $share_cache[$trp_id]
                : $payload_empty;
            $share_plain = isset($payload['plain']) ? (string) $payload['plain'] : '';
            $line_url = isset($payload['line_url']) ? (string) $payload['line_url'] : '';
            $mailto = isset($payload['mailto']) ? (string) $payload['mailto'] : '';
            $report_line = isset($payload['report_line']) ? (string) $payload['report_line'] : '';
            $report_email = isset($payload['report_email']) ? (string) $payload['report_email'] : '';
            $org_enduser = isset($payload['org_enduser']) ? (string) $payload['org_enduser'] : '';
            $product_detail = isset($payload['product_detail']) ? (string) $payload['product_detail'] : '';
            $has_plain = $share_plain !== '';
            $updated = isset($row['sth_updated_at']) ? (string) $row['sth_updated_at'] : '';
            $tracking = isset($row['sth_tracking']) ? (string) $row['sth_tracking'] : '';
            $logistics = isset($row['sth_logistics']) ? (string) $row['sth_logistics'] : '';
            $send_date = isset($row['sth_send_date']) ? (string) $row['sth_send_date'] : '';
            $sth_id = isset($row['sth_id']) ? (int) $row['sth_id'] : 0;
            ?>
          <tr class="spaceUnder mn-wait-sendtracking-row mn-wst-data-row" data-sth-id="<?php echo (int) $sth_id; ?>">
            <td align="center"><?php echo (int) $n; ?></td>
            <td align="center"><?php echo maintenance_status_logistic_h($updated); ?></td>
            <td align="left" class="mn-wst-col-org-product">
              <div class="mn-wst-org-line"><?php echo maintenance_status_logistic_h($org_enduser !== '' ? $org_enduser : '—'); ?></div>
              <div class="mn-wst-product-line"><?php echo maintenance_status_logistic_h($product_detail !== '' ? $product_detail : '—'); ?></div>
            </td>
            <td><?php echo maintenance_status_logistic_h($tracking); ?></td>
            <td><?php echo maintenance_status_logistic_h($logistics); ?></td>
            <td align="center"><?php echo maintenance_status_logistic_h($send_date); ?></td>
            <td align="center">
              <?php if ($line_url !== '') { ?>
                <?php
                $line_link_text = trim($report_line) !== '' ? $report_line : 'ส่งข้อความ LINE';
                ?>
              <a class="mn-wst-channel-link mn-wst-channel-link--line" href="<?php echo htmlspecialchars($line_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" title="เปิด LINE พร้อมข้อความอัปเดต"><?php echo maintenance_status_logistic_h($line_link_text); ?></a>
              <?php } elseif (trim($report_line) !== '') { ?>
              <span class="mn-wst-channel-muted" title="ยังไม่มีข้อความแชร์ให้เปิด LINE"><?php echo maintenance_status_logistic_h($report_line); ?></span>
              <?php } else { ?>
              <span>—</span>
              <?php } ?>
            </td>
            <td align="center">
              <?php if ($mailto !== '') { ?>
                <?php
                $mail_link_text = trim($report_email) !== '' ? $report_email : 'ไม่พบอีเมล';
                ?>
              <a class="mn-wst-channel-link mn-wst-channel-link--mail" href="<?php echo htmlspecialchars($mailto, ENT_QUOTES, 'UTF-8'); ?>" title="เปิดโปรแกรมเมลพร้อมข้อความอัปเดต"><?php echo maintenance_status_logistic_h($mail_link_text); ?></a>
              <?php } elseif (trim($report_email) !== '') { ?>
              <span class="mn-wst-channel-muted" title="ยังไม่มีข้อความแชร์ให้เปิดเมล"><?php echo maintenance_status_logistic_h($report_email); ?></span>
              <?php } else { ?>
              <span>—</span>
              <?php } ?>
            </td>
            <td>
              <div class="mn-wst-copy-stack">
                <textarea class="mn-wst-share-plain" readonly="readonly" rows="6" cols="32"><?php echo htmlspecialchars($share_plain, ENT_QUOTES, 'UTF-8'); ?></textarea>
                <button type="button" class="mn-wst-copy-btn"<?php echo !$has_plain ? ' disabled="disabled"' : ''; ?>>คัดลอกข้อความ</button>
              </div>
            </td>
          </tr>
        <?php } ?>
        <?php if ($n === 0) { ?>
          <tr class="mn-wait-sendtracking-row mn-wst-empty-row">
            <td colspan="9" align="center">ไม่มีรายการในคิว</td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
