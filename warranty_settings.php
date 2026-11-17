<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าตั้งค่าระยะเวลาประกันรายรุ่น — แสดงรุ่นสินค้าทั้งหมดพร้อมจำนวนเดือนประกัน และ edit inline
 *
 * Flow:
 * require_auth → bootstrap → fetch product+warranty → ปิด connect → render ตาราง + inline form
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/warranty_settings_helpers.php';

$edit_product = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
$saved        = isset($_GET['saved']) && $_GET['saved'] === '1';
$err          = isset($_GET['err']) ? trim((string) $_GET['err']) : '';

$rows = maintenance_warranty_fetch_all($connect);
maintenance_db_odbc_close($connect);

$page = 'warranty_settings';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-warranty-settings.css" />
<title>ตั้งค่าประกันรายรุ่น</title>
</head>
<body class="mn-addjob-page mn-warranty-settings-page">
  <div class="header">
    <?php require_once __DIR__ . '/includes/title-h.php'; ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-ws-inner">
      <header class="mn-wsy-header">
        <h1 class="mn-wsy-title"><i class="fa fa-shield" aria-hidden="true"></i> ตั้งค่าระยะเวลาประกันรายรุ่น</h1>
        <p class="mn-wsy-desc">กำหนดระยะเวลาประกันผลิตภัณฑ์ใหม่ต่อรุ่น ระบบจะใช้ค่านี้คำนวณสถานะประกันอัตโนมัติตอนรับเครื่องซ่อม</p>
      </header>

      <?php if ($saved): ?>
      <div class="mn-wsy-alert mn-wsy-alert--success"><i class="fa fa-check-circle" aria-hidden="true"></i> บันทึกเรียบร้อยแล้ว</div>
      <?php elseif ($err === 'invalid'): ?>
      <div class="mn-wsy-alert mn-wsy-alert--danger"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> ค่าไม่ถูกต้อง กรุณาตรวจสอบ</div>
      <?php elseif ($err === 'product'): ?>
      <div class="mn-wsy-alert mn-wsy-alert--danger"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> ไม่พบรุ่นสินค้านี้</div>
      <?php endif; ?>

      <table class="mn-wsy-table" id="table1">
        <thead>
          <tr>
            <th>รุ่นสินค้า</th>
            <th>ระยะเวลาประกัน</th>
            <th>แก้ไขล่าสุด</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $pn = (string) $r['pn_name'];
            $pwMonths = ($r['pw_months'] !== null) ? (int) $r['pw_months'] : null;
            $isEditing = ($edit_product !== '' && $edit_product === $pn);
            $editYears = ($pwMonths !== null) ? (int) floor($pwMonths / 12) : 1;
            $editMons  = ($pwMonths !== null) ? ($pwMonths % 12) : 0;
        ?>
          <tr class="mn-wsy-row<?php echo $isEditing ? ' mn-wsy-row--editing' : ''; ?>">
            <td class="mn-wsy-cell mn-wsy-cell--product"><?php echo htmlspecialchars($pn, ENT_QUOTES, 'UTF-8'); ?></td>
            <?php if ($isEditing): ?>
            <td class="mn-wsy-cell mn-wsy-cell--edit" colspan="3">
              <form class="mn-wsy-form" method="get" action="save_warranty_settings">
                <input type="hidden" name="pw_product" value="<?php echo htmlspecialchars($pn, ENT_QUOTES, 'UTF-8'); ?>" />
                <label class="mn-wsy-form-label">ปี</label>
                <input class="mn-wsy-input mn-wsy-input--year" type="number" name="pw_years" min="0" max="50" value="<?php echo (int) $editYears; ?>" />
                <label class="mn-wsy-form-label">เดือน</label>
                <input class="mn-wsy-input mn-wsy-input--month" type="number" name="pw_add_months" min="0" max="11" value="<?php echo (int) $editMons; ?>" />
                <button class="mn-wsy-btn mn-wsy-btn--save" type="submit"><i class="fa fa-save" aria-hidden="true"></i> บันทึก</button>
                <a class="mn-wsy-btn mn-wsy-btn--cancel" href="warranty_settings">ยกเลิก</a>
              </form>
            </td>
            <?php else: ?>
            <td class="mn-wsy-cell mn-wsy-cell--months">
              <?php if ($pwMonths !== null): ?>
                <span class="mn-wsy-badge"><?php echo htmlspecialchars(maintenance_warranty_months_label($pwMonths), ENT_QUOTES, 'UTF-8'); ?></span>
              <?php else: ?>
                <span class="mn-wsy-badge mn-wsy-badge--none">ยังไม่ได้ตั้งค่า</span>
              <?php endif; ?>
            </td>
            <td class="mn-wsy-cell mn-wsy-cell--date"><?php echo htmlspecialchars((string) ($r['pw_updated_at'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="mn-wsy-cell mn-wsy-cell--action">
              <a class="mn-wsy-btn mn-wsy-btn--edit" href="warranty_settings?edit=<?php echo rawurlencode($pn); ?>"><i class="fa fa-pencil" aria-hidden="true"></i> แก้ไข</a>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
