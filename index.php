<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าแรกรายการใบซ่อม (ค้นหา + ตารางผลลัพธ์) — refactor จาก `old_system/index.php` ใช้ theme mn-*, GET แทน POST, SQL รวมเงื่อนไขแบบ AND
 *
 * Flow:
 * require_auth → bootstrap → ดึงรายการ Product (`data_add_job`) + สถานะ (`request_statusjob`) → parse GET → ประกอบ SQL (รวม S/N สำรองผ่าน `transac_spare`) → แสดงลิงก์ลัด + ฟอร์มค้นหา + ตาราง
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_index_page.php';
require_once __DIR__ . '/includes/data_add_job.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/index_helpers.php';

$params = maintenance_index_parse_search_params($_GET);

$array_products = maintenance_new_fetch_product_names_sorted($connect);
$arr_length_products = count($array_products);

$arrayjob = request_statusjob();
$arr_lengthjob = count($arrayjob);

$query_parts = maintenance_index_build_list_sql($connect, $params);
$sql_list = $query_parts['sql'];

$exe_list = maintenance_db_odbc_exec($connect, $sql_list);
if (!$exe_list) {
    maintenance_db_odbc_close($connect);
    exit('Error Query [' . htmlspecialchars($sql_list, ENT_QUOTES, 'UTF-8') . ']: ' . htmlspecialchars(maintenance_db_odbc_errormsg($connect), ENT_QUOTES, 'UTF-8'));
}

$rows = array();
while ($r = maintenance_db_odbc_fetch_array($exe_list)) {
    if (is_array($r)) {
        $rows[] = $r;
    }
}
maintenance_db_odbc_close($connect);

$index_filter_detail_ts_id = 0;
if (count($rows) === 1) {
    $index_filter_detail_ts_id = (int) maintenance_status_logistic_row($rows[0], 'ts_id', 0);
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-index.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>ระบบงานซ่อม — รายการ</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/index-page.js"></script>
</head>
<body class="mn-addjob-page mn-index-page">
  <div class="header">
    <?php
    $page = 'index';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-index-centered">
      <nav class="mn-index-legacy-quicklinks" aria-label="ลิงก์ลัดที่เกี่ยวกับใบซ่อม">
        <a href="wait_sendtracking" target="_top" rel="noopener">[ <i class="fa fa-link" aria-hidden="true" style="color:#333333"> รอส่งเลขพัสดุลูกค้า</i> ]</a> &nbsp;
        <a href="check_history_repair">[ <i class="fa fa-search" aria-hidden="true" style="color:#333333"> เช็คประวัติการซ่อม</i> ]</a> &nbsp;
        <a href="add_spare">[ <i class="fa fa-plus-circle" aria-hidden="true" style="color:#333333"> รายการเครื่องสำรอง</i> ]</a> &nbsp;
        <a href="add_rate">[ <i class="fa fa-plus-circle" aria-hidden="true" style="color:#333333"> เพิ่มรายการซ่อม</i> ]</a> &nbsp;
        <a href="manage_logistics">[ <i class="fa fa-cog" aria-hidden="true" style="color:#333333"> จัดการขนส่ง</i> ]</a> &nbsp;
        <a href="status_onsite" target="_blank" rel="noopener">[ <i class="fa fa-eye" aria-hidden="true" style="color:#333333"> ดูพื้นที่เข้ารับเครื่อง Onsite</i> ]</a> &nbsp;
      </nav>
      <form id="mn-index-search-form" class="mn-index-search-form" method="get" action="index" autocomplete="off">
        <fieldset class="mn-index-filters">
          <legend class="mn-index-filters-legend">ค้นหาใบซ่อม</legend>
          <div class="mn-index-filters-grid">
            <label class="mn-index-field mn-index-field--number">
              <span class="mn-index-label">เลขที่ซ่อม</span>
              <input type="text" id="s_number" name="s_number" value="<?php echo maintenance_status_logistic_h($params['s_number']); ?>" placeholder="ค้นหา" maxlength="120" />
            </label>
            <label class="mn-index-field mn-index-field--company">
              <span class="mn-index-label">บริษัท</span>
              <input type="text" id="s_company" name="s_company" value="<?php echo maintenance_status_logistic_h($params['s_company']); ?>" placeholder="End user / รปภ." maxlength="200" />
              <?php if ($index_filter_detail_ts_id > 0) { ?>
              <?php echo maintenance_index_detail_job_link_html($index_filter_detail_ts_id, 'mn-index-detail-link--filter'); ?>
              <?php } ?>
            </label>
            <label class="mn-index-field mn-index-field--product">
              <span class="mn-index-label">รุ่น (เครื่องซ่อม)</span>
              <select id="s_product" name="s_product" class="mn-index-select mn-index-select--product">
                <option value="">All</option>
                <?php
                for ($a = 0; $a < $arr_length_products; $a++) {
                    $pn = $array_products[$a];
                    $sel = ($params['s_product'] === $pn) ? ' selected="selected"' : '';
                    ?>
                <option value="<?php echo maintenance_status_logistic_h($pn); ?>"<?php echo $sel; ?>><?php echo maintenance_status_logistic_h($pn); ?></option>
                    <?php
                }
                ?>
              </select>
            </label>
            <label class="mn-index-field mn-index-field--product-sn">
              <span class="mn-index-label">S/N เครื่องซ่อม</span>
              <input type="text" id="s_product_sn" name="s_product_sn" value="<?php echo maintenance_status_logistic_h($params['s_product_sn']); ?>" placeholder="S/N" maxlength="120" />
            </label>
            <label class="mn-index-field mn-index-field--product-sn">
              <span class="mn-index-label">S/N เครื่องสำรอง</span>
              <input type="text" id="s_spare_sn" name="s_spare_sn" value="<?php echo maintenance_status_logistic_h($params['s_spare_sn']); ?>" placeholder="S/N" maxlength="120" />
            </label>
            <label class="mn-index-field mn-index-field--status">
              <span class="mn-index-label">สถานะ</span>
              <select id="s_status" name="s_status" class="mn-index-select">
                <option value=""<?php echo $params['s_status'] === '' ? ' selected="selected"' : ''; ?>>All</option>
                <?php
                for ($a = 0; $a < $arr_lengthjob; $a++) {
                    $st = (string) $arrayjob[$a];
                    if ($st === '') {
                        continue;
                    }
                    $sel = ($params['s_status'] === $st) ? ' selected="selected"' : '';
                    ?>
                <option value="<?php echo maintenance_status_logistic_h($st); ?>"<?php echo $sel; ?>><?php echo maintenance_status_logistic_h($st); ?></option>
                    <?php
                }
                ?>
              </select>
            </label>
            <label class="mn-index-field mn-index-field--report">
              <span class="mn-index-label">ผู้แจ้งเรื่อง</span>
              <input type="text" id="s_report" name="s_report" value="<?php echo maintenance_status_logistic_h($params['s_report']); ?>" placeholder="ชื่อผู้แจ้ง" maxlength="200" />
            </label>
            <div class="mn-index-field mn-index-field--actions">
              <span class="mn-index-label">ดำเนินการ</span>
              <div class="mn-index-toolbar-btns">
                <button type="submit" class="mn-index-btn mn-index-btn--primary"><i class="fa fa-search" aria-hidden="true"></i> ค้นหา</button>
                <a class="mn-index-btn mn-index-btn--ghost" href="index"><i class="fa fa-eraser" aria-hidden="true"></i> ล้างตัวกรอง</a>
              </div>
            </div>
          </div>
        </fieldset>
      </form>

      <table id="table1" class="mn-index-table" width="100%">
        <thead>
          <tr class="mn-index-thead">
            <th width="5%"><div align="center"><b>ลำดับ</b></div></th>
            <th width="10%"><div align="center"><b>วันที่รับเรื่อง</b></div></th>
            <th width="10%"><div align="center"><b>เลขที่ซ่อม</b></div></th>
            <th width="20%"><div align="center"><b>บริษัท</b></div></th>
            <th width="15%"><div align="center"><b>เครื่องซ่อม</b></div></th>
            <th width="10%"><div align="center"><b>สถานะ</b></div></th>
            <th width="8%"><div align="center"><b>ระยะเวลา</b></div></th>
            <th width="10%"><div align="center"><b>ผู้แจ้งเรื่อง</b></div></th>
            <th width="6%"><div align="center"><b>ความคืบหน้า</b></div></th>
            <th width="5%"><div align="center"><b>Detail</b></div></th>
          </tr>
        </thead>
        <tbody>
          <tr class="mn-index-clear-hint">
            <td colspan="10" align="left"><a href="index"><i class="fa fa-times-circle" aria-hidden="true"></i> ล้างตัวกรองและโหลดล่าสุด</a></td>
          </tr>
          <?php
          $i = 1;
          foreach ($rows as $result) {
              $ts_id_row = (int) maintenance_status_logistic_row($result, 'ts_id', 0);
              $trp_id_row = (int) maintenance_status_logistic_row($result, 'trp_id', 0);
              $sw = maintenance_index_row_status_html($result);
              $title = 'ผู้รับเรื่อง :: ' . maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'ts_user_recive'));
              ?>
          <tr class="mn-index-data-row" valign="top" title="<?php echo $title; ?>">
            <td align="center" valign="middle"><?php echo (int) $i++; ?></td>
            <td align="center" valign="middle"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'ts_date')); ?><br /><?php echo cal_wait_date(maintenance_status_logistic_row($result, 'ts_date')); ?></td>
            <td align="center" valign="middle"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'ts_mtn_number')); ?></td>
            <td><?php echo maintenance_index_company_cell_html($result, $ts_id_row); ?></td>
            <td valign="middle"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'trp_product')); ?><br /><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'trp_sn')); ?></td>
            <td align="center" valign="middle"><?php echo $sw['status']; ?></td>
            <td align="center" valign="middle"><?php echo $sw['wait']; ?></td>
            <td valign="middle"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'ts_report_name')); ?><br /><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'ts_report_tel')); ?><br /><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($result, 'ts_report_line')); ?></td>
            <?php $jobProcessHref = $trp_id_row > 0 ? 'job_process?trp_id=' . (int) $trp_id_row : ''; ?>
            <td align="center" valign="middle"><?php if ($jobProcessHref !== '') { ?><a class="mn-index-process-link" target="_blank" rel="noopener noreferrer" href="<?php echo maintenance_status_logistic_h($jobProcessHref); ?>" title="กดเพื่อดูความคืบหน้างานซ่อม"><i class="fa fa-list-ol" aria-hidden="true"> view</i></a><?php } else { ?><span class="mn-index-empty-cell" title="ไม่มีรหัสงานซ่อม">—</span><?php } ?></td>
            <td align="center" valign="middle"><?php echo maintenance_index_detail_job_link_html($ts_id_row); ?></td>
          </tr>
              <?php
          }
          ?>
        </tbody>
      </table>

      <div class="mn-index-summary" role="status">
        <p><strong>สรุปตัวกรอง:</strong> <?php echo maintenance_status_logistic_h(implode(' | ', $query_parts['summary_lines'])); ?></p>
      </div>
    </div>
  </div>
</body>
</html>
