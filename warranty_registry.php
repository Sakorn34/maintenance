<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ทะเบียนประกัน (หลังบ้าน — ต้องใส่รหัสหน้าตั้งค่า)
 * - ค้นหา S/N → ประวัติประกัน (เครื่องใหม่ / งานซ่อม / ซื้อเพิ่ม) ณ วันนี้
 * - บันทึกประกันซื้อเพิ่มเอง (ขายพร้อมเครื่องใหม่ / ขายแยก) — เริ่มต่อท้ายประกันเครื่องใหม่/ซื้อเพิ่มที่ยังเหลือ
 * - ยกเลิกประกันซื้อเพิ่ม (ต้องใส่เหตุผล ไม่ลบจริง)
 * - รายการประกันซื้อเพิ่มล่าสุด (รวมที่ระบบสร้างจากใบงานที่มีรายการ "ซื้อประกันเพิ่ม N เดือน")
 *
 * Flow: require_auth → bootstrap → ด่านรหัส → POST add/void → redirect | GET → sync ใบงาน → render
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/settings_lock_helpers.php';
mn_settings_gate($connect, 'page'); // หน้าตั้งค่าหลังบ้าน — ต้องใส่รหัส
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/warranty_registry_helpers.php';

function mn_wrp_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['act']) ? (string) $_POST['act'] : '';
    $sn_back = isset($_POST['sn']) ? trim((string) $_POST['sn']) : '';
    if ($act === 'add') {
        list($ok, $err, $row) = mn_wr_add($connect, array(
            'sn' => $sn_back, 'product' => isset($_POST['product']) ? $_POST['product'] : '',
            'months' => isset($_POST['months']) ? (int) $_POST['months'] : 0, 'purchase' => isset($_POST['purchase']) ? $_POST['purchase'] : '',
            'ref' => isset($_POST['ref']) ? $_POST['ref'] : '', 'note' => isset($_POST['note']) ? $_POST['note'] : '',
            'source' => 'manual', 'by' => (string) $mn_logistic_recorder_value));
        $msg = $ok ? 'บันทึกประกันซื้อเพิ่มแล้ว ' . mn_wr_dmy($row['start']) . ' – ' . mn_wr_dmy($row['end']) : $err;
    } elseif ($act === 'void') {
        list($ok, $err) = mn_wr_void($connect, isset($_POST['id']) ? (int) $_POST['id'] : 0, isset($_POST['reason']) ? $_POST['reason'] : '', (string) $mn_logistic_recorder_value);
        $msg = $ok ? 'ยกเลิกประกันแล้ว' : $err;
    } else {
        $ok = false;
        $msg = 'ไม่รู้จักคำสั่ง';
    }
    maintenance_db_odbc_close($connect);
    header('Location: warranty_registry?' . http_build_query(array('sn' => $sn_back, ($ok ? 'ok' : 'err') => $msg)));
    exit;
}

$synced = mn_wr_sync_jobs($connect);
$sn = isset($_GET['sn']) ? trim((string) $_GET['sn']) : '';
$timeline = array();
$job = array('product' => '', 'buy_date' => '');
if ($sn !== '') {
    $job = mn_wr_last_job($connect, $sn);
    $timeline = mn_wr_timeline($connect, $sn, $job['product'], $job['buy_date'], 0, date('Y-m-d'));
}
$packages = mn_sys_setting($connect, 'warranty_packages');
mn_wr_ensure_table($connect);
$recent = array();
$res = mysqli_query($connect, 'SELECT * FROM cl_warranty ORDER BY wr_id DESC LIMIT 100');
while ($res && ($r = mysqli_fetch_assoc($res))) { $recent[] = $r; }
maintenance_db_odbc_close($connect);

$flash_ok = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
$flash_err = isset($_GET['err']) ? (string) $_GET['err'] : '';
$icon = array('new' => 'fa-certificate', 'extended' => 'fa-plus-circle', 'repair' => 'fa-wrench');
$page = 'settings';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-settings.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-settings.css'); ?>" />
<title>ทะเบียนประกัน</title>
</head>
<body class="mn-addjob-page mn-settings-page mn-warranty-registry-page">
  <div class="header">
    <?php require_once __DIR__ . '/includes/title-h.php'; ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-set">
      <header class="mn-set__head">
        <h1><i class="fa fa-certificate" aria-hidden="true"></i> ทะเบียนประกัน</h1>
        <p><a href="settings"><i class="fa fa-angle-left" aria-hidden="true"></i> ตั้งค่าระบบ</a> | ประกันเครื่องใหม่ / งานซ่อม / ซื้อเพิ่ม ต่อ S/N</p>
      </header>

      <?php if ($flash_ok !== '') { ?><div class="mn-set__alert is-ok" role="status"><i class="fa fa-check-circle" aria-hidden="true"></i> <?php echo mn_wrp_h($flash_ok); ?></div><?php } ?>
      <?php if ($flash_err !== '') { ?><div class="mn-set__alert is-bad" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <?php echo mn_wrp_h($flash_err); ?></div><?php } ?>
      <?php if ($synced > 0) { ?><div class="mn-set__alert is-ok" role="status"><i class="fa fa-refresh" aria-hidden="true"></i> สร้างประกันซื้อเพิ่มจากใบงานที่ซ่อมเสร็จแล้ว <?php echo (int) $synced; ?> รายการ</div><?php } ?>

      <section class="mn-set__card">
        <h2><i class="fa fa-search" aria-hidden="true"></i> ดูประวัติประกันของเครื่อง</h2>
        <form method="get" action="warranty_registry" class="mn-set__pinform">
          <label>S/N <input type="text" name="sn" value="<?php echo mn_wrp_h($sn); ?>" class="mn-wrp__sn" placeholder="BP22111240" /></label>
          <button type="submit" class="mn-set__btn"><i class="fa fa-search" aria-hidden="true"></i> ค้นหา</button>
        </form>
        <?php if ($sn !== '') { ?>
        <p class="mn-set__help">รุ่น <?php echo mn_wrp_h($job['product'] !== '' ? $job['product'] : 'ไม่พบในใบงาน'); ?> | วันที่ซื้อ <?php echo mn_wrp_h($job['buy_date'] !== '' ? mn_wr_dmy($job['buy_date']) : 'ไม่พบในใบงาน'); ?> | สถานะ ณ วันนี้</p>
          <?php if (!$timeline) { ?>
        <p class="mn-set__muted">ยังไม่มีข้อมูลประกันของเครื่องนี้</p>
          <?php } else { ?>
        <ul class="mn-wrp__list">
            <?php foreach ($timeline as $it) { ?>
          <li class="<?php echo $it['active'] ? 'is-on' : ''; ?>"><i class="fa <?php echo mn_wrp_h($icon[$it['type']]); ?>" aria-hidden="true"></i>
            <span><b><?php echo mn_wrp_h($it['title']); ?></b><small><?php echo mn_wrp_h(mn_wr_dmy($it['start']) . ' – ' . mn_wr_dmy($it['end']) . ($it['ref'] !== '' ? ' | ' . $it['ref'] : '')); ?></small></span>
            <em><?php echo $it['active'] ? 'เหลือ ' . (int) $it['left'] . ' วัน' : ($it['future'] ? 'ยังไม่เริ่ม' : 'หมดแล้ว'); ?></em></li>
            <?php } ?>
        </ul>
          <?php } ?>
        <?php } ?>
      </section>

      <section class="mn-set__card">
        <h2><i class="fa fa-plus-circle" aria-hidden="true"></i> บันทึกประกันซื้อเพิ่ม</h2>
        <form method="post" action="warranty_registry" class="mn-wrp__form">
          <input type="hidden" name="act" value="add" />
          <label>S/N <input type="text" name="sn" value="<?php echo mn_wrp_h($sn); ?>" required="required" /></label>
          <label>รุ่น <input type="text" name="product" value="<?php echo mn_wrp_h($job['product']); ?>" placeholder="ว่าง = ดึงจากใบงานล่าสุด" /></label>
          <label>แพ็กเกจ
            <select name="months" required="required">
              <?php foreach ((array) $packages as $m) { ?><option value="<?php echo (int) $m; ?>"<?php echo (int) $m === 12 ? ' selected="selected"' : ''; ?>>ซื้อประกันเพิ่ม <?php echo (int) $m; ?> เดือน</option><?php } ?>
            </select>
          </label>
          <label>วันที่ซื้อประกัน <input type="date" name="purchase" value="<?php echo date('Y-m-d'); ?>" required="required" /></label>
          <label>เลขอ้างอิง <input type="text" name="ref" placeholder="PO / Invoice / QV" /></label>
          <label class="is-wide">หมายเหตุ <input type="text" name="note" /></label>
          <button type="submit" class="mn-set__btn is-primary"><i class="fa fa-check" aria-hidden="true"></i> บันทึก</button>
        </form>
        <p class="mn-set__help">วันเริ่มคำนวณให้เอง: ต่อท้ายประกันเครื่องใหม่/ซื้อเพิ่มที่ยังเหลือ ณ วันที่ซื้อ (ไม่นับประกันงานซ่อม) ถ้าหมดแล้วเริ่มวันที่ซื้อ | ขายผ่านใบเสนอราคาซ่อม ใช้รายการ "ซื้อประกันเพิ่ม N เดือน" ระบบสร้างให้เมื่ออนุมัติและซ่อมเสร็จ</p>
      </section>

      <section class="mn-set__card">
        <h2><i class="fa fa-list" aria-hidden="true"></i> ประกันซื้อเพิ่มล่าสุด</h2>
        <?php if (!$recent) { ?>
        <p class="mn-set__muted">ยังไม่มีรายการ</p>
        <?php } else { ?>
        <div class="mn-wrp__tablewrap">
        <table class="mn-wrp__table">
          <thead><tr><th>S/N</th><th>แพ็กเกจ</th><th>ช่วงประกัน</th><th>ที่มา</th><th>บันทึก</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r) { ?>
            <tr class="<?php echo (int) $r['wr_void'] === 1 ? 'is-void' : ''; ?>">
              <td><a href="warranty_registry?sn=<?php echo rawurlencode($r['wr_sn']); ?>"><?php echo mn_wrp_h($r['wr_sn']); ?></a><small><?php echo mn_wrp_h($r['wr_product']); ?></small></td>
              <td><?php echo (int) $r['wr_months']; ?> เดือน</td>
              <td><?php echo mn_wrp_h(mn_wr_dmy($r['wr_start']) . ' – ' . mn_wr_dmy($r['wr_end'])); ?><small>ซื้อ <?php echo mn_wrp_h(mn_wr_dmy($r['wr_buy_date'])); ?></small></td>
              <td><?php echo $r['wr_source'] === 'job' ? 'ใบงานซ่อม' : 'บันทึกเอง'; ?><small><?php echo mn_wrp_h($r['wr_ref']); ?></small></td>
              <td><?php echo mn_wrp_h($r['wr_by']); ?><small><?php echo mn_wrp_h($r['wr_at'] ? date('d/m/Y H:i', strtotime($r['wr_at'])) : ''); ?></small></td>
              <td>
                <?php if ((int) $r['wr_void'] === 1) { ?>
                <span class="mn-wrp__void" title="<?php echo mn_wrp_h($r['wr_void_reason']); ?>">ยกเลิกแล้ว</span><small><?php echo mn_wrp_h($r['wr_void_reason']); ?></small>
                <?php } else { ?>
                <form method="post" action="warranty_registry" class="mn-wrp__voidform" onsubmit="var r = prompt('เหตุผลที่ยกเลิกประกันนี้'); if (!r) { return false; } this.reason.value = r; return true;">
                  <input type="hidden" name="act" value="void" /><input type="hidden" name="id" value="<?php echo (int) $r['wr_id']; ?>" />
                  <input type="hidden" name="sn" value="<?php echo mn_wrp_h($sn); ?>" /><input type="hidden" name="reason" value="" />
                  <button type="submit" class="mn-set__btn"><i class="fa fa-ban" aria-hidden="true"></i> ยกเลิก</button>
                </form>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div>
        <?php } ?>
      </section>
    </div>
  </div>
</body>
</html>
