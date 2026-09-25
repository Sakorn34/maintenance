<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เพิ่มเครื่องสำรอง / แก้สถานะ / แสดงรายการ S/N จาก `Spare` — refactor ให้ใช้ auth, bootstrap, ธีม mn-*, helper + prepared สำหรับดึงรายการตาม product
 *
 * Flow:
 * bootstrap → ดึงชื่อ product + แถว spare → ปิด `$connect` → render — JS ใน `js/add_spare-page.js`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/add_spare_helpers.php';

$dropdown_products = maintenance_add_spare_fetch_distinct_product_names($connect);
$group_product_names = maintenance_add_spare_fetch_product_names($connect);

$spare_grid = array();
foreach ($group_product_names as $pname) {
    $spare_grid[] = array(
        'name' => $pname,
        'rows' => maintenance_add_spare_fetch_rows_by_product($connect, $pname),
    );
}

maintenance_db_odbc_close($connect);

require_once __DIR__ . '/config/app_environment.php';
$index_url = maintenance_new_maintenance_index_url();
$dropdown_count = count($dropdown_products);
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
<link rel="stylesheet" href="css/mn-page-add-spare.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>ADD Spare</title>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/add_spare-page.js"></script>
</head>
<body class="mn-addjob-page mn-add-spare-page">
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
    <div class="mn-form-centered mn-add-spare-inner">
      <p><a class="mn-add-spare-home" href="<?php echo htmlspecialchars($index_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-home fa-lg" aria-hidden="true"></i> BACK TO HOME...</a></p>
      <table class="mn-add-spare-table" align="center">
        <tr class="mn-add-spare-head">
          <td colspan="9" align="center"><strong>แบบฟอร์ม เพิ่มเครื่องสำรอง</strong></td>
        </tr>
        <tr class="mn-add-spare-row mn-add-spare-row-add">
          <td colspan="9">
            <div class="mn-add-spare-toolbar mn-add-spare-toolbar--add">
              <span class="mn-add-spare-toolbar-label"><strong>เพิ่มเครื่องสำรอง : </strong></span>
              <select class="custom-select mn-add-spare-select-product" id="sp_product_name" name="sp_product_name">
              <option value="">กรุณาเลือกอุปกรณ์</option>
              <?php
              for ($a = 0; $a < $dropdown_count; $a++) {
                  $opt = (string) $dropdown_products[$a];
                  ?>
              <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php
              }
              ?>
            </select>
              <span class="mn-add-spare-toolbar-label"><strong>S/N : </strong></span>
              <input type="text" class="mn-add-spare-input-sn" name="sp_sn" id="sp_sn" />
              <a href="#" class="mn-add-spare-save mn-add-spare-save-icon" onclick="save_spare(); return false;" title="Save"><i class="fa fa-save fa-2x" aria-hidden="true"></i></a>
            </div>
          </td>
        </tr>
        <tr><td colspan="9"><hr /></td></tr>
        <tr class="mn-add-spare-row mn-add-spare-row-status">
          <td colspan="9">
            <div class="mn-add-spare-toolbar mn-add-spare-toolbar--status">
              <span class="mn-add-spare-toolbar-label"><strong>แก้สถานะเครื่องสำรอง : </strong></span>
              <span class="mn-add-spare-toolbar-label"><strong>S/N : </strong></span>
              <input type="text" class="mn-add-spare-input-sn-edit" name="sp_sn_edit" id="sp_sn_edit" />
              <select class="custom-select mn-add-spare-select-status" id="sp_status" name="sp_status">
              <option value="">กรุณาเลือกสถานะ</option>
              <option value="lost">สูญหาย</option>
              <option value="wear out">เสื่อมสภาพ</option>
              <option value="use">ใช้งานอยู่</option>
              <option value="blank">ว่าง</option>
            </select>
              <a href="#" class="mn-add-spare-save mn-add-spare-save-icon" onclick="status_spare(); return false;" title="อัปเดต"><i class="fa fa-arrow-circle-right fa-2x" aria-hidden="true"></i></a>
            </div>
          </td>
        </tr>
        <tr class="mn-add-spare-section-title"><td colspan="9" align="center"><b>รายการ S/N สำรอง</b></td></tr>
        <?php
        $cols = 5;
        $chunks = array_chunk($spare_grid, $cols);
        foreach ($chunks as $chunk) {
            ?>
        <tr valign="top">
            <?php
            foreach ($chunk as $col) {
                $pname = $col['name'];
                ?>
          <td align="center" style="width:20%;">
            <p class="mn-add-spare-producthead">&nbsp; <?php echo htmlspecialchars($pname, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php
                foreach ($col['rows'] as $r) {
                    $sn = isset($r['sp_sn']) ? (string) $r['sp_sn'] : '';
                    $st = isset($r['sp_status']) ? (string) $r['sp_status'] : '';
                    $label = maintenance_add_spare_status_label_html($sn, $st);
                    $href = 'check_history_spare?sn=' . rawurlencode($sn);
                    ?>
            <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo $label; ?></a><br />
                <?php
                }
                ?>
          </td>
                <?php
            }
            $pad = $cols - count($chunk);
            for ($p = 0; $p < $pad; $p++) {
                echo '<td></td>';
            }
            ?>
        </tr>
            <?php
        }
        ?>
      </table>
      <br />
    </div>
  </div>
</body>
</html>
