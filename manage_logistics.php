<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าจัดการรายการขนส่ง (`logistics_carrier`) — เพิ่ม/แก้ไขชื่อ, ลิงก์ตรวจ tracking, เปิด-ปิดใช้งาน (ไม่ลบแถว)
 *
 * Flow:
 * require_auth → bootstrap → ดึงรายการทั้งหมด → แสดงตาราง + ฟอร์ม (โหมดเพิ่มหรือ `?edit=lc_id`) → POST ไป `save_manage_logistics`
 *
 * Example:
 * `/maintenance/manage_logistics` | `/maintenance/manage_logistics?edit=3`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/manage_logistics_helpers.php';

$tableOk = maintenance_logistics_table_available($connect);
$allRows = $tableOk ? maintenance_manage_logistics_fetch_all($connect) : array();

$edit_id = maintenance_flow_get_int($_GET, 'edit', 0);
$editRow = null;
$formMode = 'insert';
if ($edit_id > 0 && $tableOk) {
    $editRow = maintenance_manage_logistics_fetch_by_id($connect, $edit_id);
    if ($editRow !== null) {
        $formMode = 'update';
    }
}

$form_name = $editRow !== null ? $editRow['lc_name'] : '';
$form_url = $editRow !== null ? $editRow['lc_tracking_url'] : '';
$form_active = $editRow !== null ? (int) $editRow['lc_active'] : 1;
$form_lc_id = $editRow !== null ? (int) $editRow['lc_id'] : 0;

$flash = isset($_GET['mn_flash']) ? trim((string) $_GET['mn_flash']) : '';
$flashMsg = isset($_GET['mn_msg']) ? trim((string) $_GET['mn_msg']) : '';

maintenance_db_odbc_close($connect);

require_once __DIR__ . '/config/app_environment.php';
$index_url = maintenance_new_maintenance_index_url();
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
<link rel="stylesheet" href="css/mn-page-manage-logistics.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>จัดการขนส่ง</title>
<script type="text/javascript">
window.MN_MANAGE_LOGISTICS = <?php echo json_encode(
    array(
        'flash' => $flash,
        'flashMessage' => $flashMsg,
        'formMode' => $formMode,
    ),
    JSON_UNESCAPED_UNICODE
); ?>;
</script>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/manage_logistics-page.js"></script>
</head>
<body class="mn-addjob-page mn-manage-logistics-page">
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
    <div class="mn-form-centered mn-mlg-inner">
      <nav class="mn-mlg-toolbar" aria-label="นำทาง">
        <a class="mn-mlg-home" href="<?php echo maintenance_status_logistic_h($index_url); ?>">
          <i class="fa fa-home fa-lg" aria-hidden="true"></i> กลับหน้าแรก
        </a>
      </nav>

      <h1 class="mn-mlg-title">จัดการขนส่ง</h1>
      <p class="mn-mlg-lead">เพิ่มหรือแก้ไขชื่อขนส่งและลิงก์ตรวจ tracking — ปิดใช้งานได้แต่<strong>ไม่สามารถลบ</strong>รายการออกจากระบบ งานเก่าที่บันทึกชื่อขนส่งไว้แล้วยังอ้างชื่อเดิมในฐานข้อมูล</p>

      <?php if (!$tableOk) { ?>
      <section class="mn-mlg-card mn-mlg-card--alert" aria-live="polite">
        <p>ยังไม่พบตาราง <code>logistics_carrier</code> — รันไฟล์ <code>database/logistics_carrier.sql</code> บนฐานข้อมูล maintenance ก่อน</p>
      </section>
      <?php } else { ?>

      <section class="mn-mlg-card" aria-labelledby="mn-mlg-form-title">
        <header class="mn-mlg-card__head">
          <h2 id="mn-mlg-form-title" class="mn-mlg-card__title">
            <?php echo $formMode === 'update' ? 'แก้ไขขนส่ง' : 'เพิ่มขนส่งใหม่'; ?>
          </h2>
          <?php if ($formMode === 'update') { ?>
          <a class="mn-mlg-link-cancel" href="manage_logistics">ยกเลิกแก้ไข</a>
          <?php } ?>
        </header>
        <form id="mn-mlg-form" class="mn-mlg-form" method="post" action="save_manage_logistics" autocomplete="off">
          <input type="hidden" name="mn_action" value="<?php echo maintenance_status_logistic_h($formMode); ?>" />
          <?php if ($formMode === 'update') { ?>
          <input type="hidden" name="lc_id" value="<?php echo (int) $form_lc_id; ?>" />
          <?php } ?>
          <div class="mn-mlg-form-grid">
            <label class="mn-mlg-field">
              <span class="mn-mlg-label">ชื่อขนส่ง <span class="mn-mlg-req">*</span></span>
              <input type="text" name="lc_name" id="lc_name" maxlength="100" required="required"
                value="<?php echo maintenance_status_logistic_h($form_name); ?>" placeholder="เช่น Kerry" />
            </label>
            <label class="mn-mlg-field mn-mlg-field--wide">
              <span class="mn-mlg-label">ลิงก์ตรวจ tracking</span>
              <input type="text" name="lc_tracking_url" id="lc_tracking_url" maxlength="500"
                value="<?php echo maintenance_status_logistic_h($form_url); ?>" placeholder="https://..." />
              <span class="mn-mlg-hint">ว่างได้ (เช่น Customer / Emp) — ต้องขึ้นต้นด้วย http:// หรือ https://</span>
            </label>
            <label class="mn-mlg-field mn-mlg-field--check">
              <input type="checkbox" name="lc_active" id="lc_active" value="1"<?php echo $form_active === 1 ? ' checked="checked"' : ''; ?> />
              <span>เปิดใช้งานใน dropdown</span>
            </label>
          </div>
          <div class="mn-mlg-form-actions">
            <button type="submit" class="mn-mlg-btn mn-mlg-btn--primary">
              <i class="fa fa-save" aria-hidden="true"></i>
              <?php echo $formMode === 'update' ? 'บันทึกการแก้ไข' : 'เพิ่มขนส่ง'; ?>
            </button>
          </div>
        </form>
      </section>

      <section class="mn-mlg-card" aria-labelledby="mn-mlg-list-title">
        <header class="mn-mlg-card__head">
          <h2 id="mn-mlg-list-title" class="mn-mlg-card__title">รายการขนส่งทั้งหมด (<?php echo (int) count($allRows); ?>)</h2>
        </header>
        <div class="mn-mlg-table-wrap">
          <table id="table1" class="mn-mlg-table">
            <thead>
              <tr>
                <th scope="col">ชื่อขนส่ง</th>
                <th scope="col">ลิงก์ตรวจ tracking</th>
                <th scope="col">สถานะ</th>
                <th scope="col">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php
              if (count($allRows) === 0) {
                  ?>
              <tr>
                <td colspan="4" class="mn-mlg-empty">ยังไม่มีรายการ — ใช้แบบฟอร์มด้านบนเพื่อเพิ่ม</td>
              </tr>
                  <?php
              }
              foreach ($allRows as $row) {
                  $rid = (int) $row['lc_id'];
                  $isActive = (int) $row['lc_active'] === 1;
                  $rowClass = $isActive ? '' : ' class="mn-mlg-row--inactive"';
                  $url = (string) $row['lc_tracking_url'];
                  ?>
              <tr<?php echo $rowClass; ?>>
                <td><?php echo maintenance_status_logistic_h($row['lc_name']); ?></td>
                <td class="mn-mlg-col-url">
                  <?php
                  if ($url !== '') {
                      echo '<a href="' . maintenance_status_logistic_h($url) . '" target="_blank" rel="noopener noreferrer">'
                          . maintenance_status_logistic_h($url) . '</a>';
                  } else {
                      echo '<span class="mn-mlg-muted">—</span>';
                  }
                  ?>
                </td>
                <td>
                  <?php if ($isActive) { ?>
                  <span class="mn-mlg-badge mn-mlg-badge--on">เปิดใช้งาน</span>
                  <?php } else { ?>
                  <span class="mn-mlg-badge mn-mlg-badge--off">ปิดใช้งาน</span>
                  <?php } ?>
                </td>
                <td>
                  <a class="mn-mlg-edit-link" href="manage_logistics?edit=<?php echo $rid; ?>">
                    <i class="fa fa-pencil" aria-hidden="true"></i> แก้ไข
                  </a>
                </td>
              </tr>
                  <?php
              }
              ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php } ?>
    </div>
  </div>
</body>
</html>
