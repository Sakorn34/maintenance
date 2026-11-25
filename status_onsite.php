<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รายการงานรอเครื่องซ่อม/รอสำรองสำหรับเช็คพื้นที่ onsite — refactor จาก `old_system/status_onsite.php`
 * ใช้ JOIN หลักแบบ prepared, batch spare/tracking, ธีม mn-*, ลิงก์ไม่มี `.php`
 *
 * Flow:
 * เลย์เอาต์: `mn-form-centered` + `table#table1.mn-status-onsite-table` — `js/status_onsite-page.js` สำหรับ filter URL
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/status_onsite_helpers.php';

$get_addr = isset($_GET['addr']) ? trim((string) $_GET['addr']) : '';
$get_product = isset($_GET['product']) ? trim((string) $_GET['product']) : '';

$product_options = maintenance_status_onsite_fetch_product_options($connect);
$main_rows = maintenance_status_onsite_fetch_main_rows($connect, $get_addr, $get_product);
$trp_ids = array();
foreach ($main_rows as $mr) {
    if (isset($mr['trp_id'])) {
        $trp_ids[] = (int) $mr['trp_id'];
    }
}
$spare_map = maintenance_status_onsite_fetch_spare_map($connect, $trp_ids);
$tracking_map = maintenance_status_onsite_fetch_tracking_map($connect, $trp_ids);

maintenance_db_odbc_close($connect);

require_once __DIR__ . '/config/app_environment.php';
$web_base = maintenance_new_maintenance_web_base();
$row_count = count($main_rows);
$product_count = count($product_options);
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
<link rel="stylesheet" href="css/mn-page-status-onsite.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>เข้ารับเครื่อง Onsite</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery.maskedinput-1.2.1.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/status_onsite-page.js"></script>
</head>
<body class="mn-addjob-page mn-status-onsite-page">
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
    <div class="mn-form-centered mn-status-onsite-inner">
      <h2 class="mn-status-onsite-title"><strong>เช็คพื้นที่ให้ DDT เข้ารับเครื่อง ซ่อม/สำรอง หน้างาน</strong></h2>
      <div class="mn-status-onsite-notice">***หากแจ้งเข้ารับเครื่องหน้างานแล้ว ให้ระบุใน Tab ติดตามว่าให้เจ้าหน้าที่ เข้ารับพัสดุหน้างาน</div>
      <table id="table1" class="mn-status-onsite-table" width="100%">
        <tr class="mn-status-onsite-headrow">
          <td class="mn-status-onsite-th mn-status-onsite-col--company mn-status-onsite-td--center">
            <b>บริษัท</b><br />
            <input type="text" id="search_addr" name="search_addr" value="<?php echo htmlspecialchars($get_addr, ENT_QUOTES, 'UTF-8'); ?>" placeholder="ค้นหาที่อยู่" />
          </td>
          <td class="mn-status-onsite-th mn-status-onsite-col--product mn-status-onsite-td--center"><b>สินค้า</b><br />
            <select class="custom-select" id="head_product" name="head_product" onchange="sort_product(this.value);">
              <option value="">All Product</option>
              <?php
              for ($a = 0; $a < $product_count; $a++) {
                  $opt = (string) $product_options[$a];
                  $sel = ($get_product === $opt) ? ' selected="selected"' : '';
                  ?>
              <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $sel; ?>><?php echo htmlspecialchars(maintenance_status_onsite_iconv($opt), ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php
              }
              ?>
            </select>
          </td>
          <td class="mn-status-onsite-th mn-status-onsite-col--symptom mn-status-onsite-td--center"><b>อาการ</b></td>
          <td class="mn-status-onsite-th mn-status-onsite-col--spare mn-status-onsite-td--center"><b>สำรอง</b></td>
          <td class="mn-status-onsite-th mn-status-onsite-col--wait mn-status-onsite-td--center"><b>ระยะเวลารอ</b></td>
          <td class="mn-status-onsite-th mn-status-onsite-col--status mn-status-onsite-td--center"><b>สถานะงาน</b></td>
          <td class="mn-status-onsite-th mn-status-onsite-col--track mn-status-onsite-td--center"><b>ติดตาม</b></td>
        </tr>
        <?php
        $i = 0;
        foreach ($main_rows as $result_join) {
            $i++;
            $trp_id = isset($result_join['trp_id']) ? (int) $result_join['trp_id'] : 0;
            $ts_id = isset($result_join['ts_id']) ? (int) $result_join['ts_id'] : 0;
            $detail = maintenance_status_onsite_company_block_html($result_join);
            $spare_row = isset($spare_map[$trp_id]) ? $spare_map[$trp_id] : null;
            $has_spare = ($spare_row !== null && !empty($spare_row['tsp_sn']));
            $tsp_id = ($spare_row !== null && isset($spare_row['tsp_id'])) ? (int) $spare_row['tsp_id'] : 0;
            $th = isset($tracking_map[$trp_id]) ? $tracking_map[$trp_id] : null;
            $title_th = maintenance_status_onsite_tracking_title_attr($th);
            $next_due = ($th !== null && isset($th['th_date_nextdue'])) ? $th['th_date_nextdue'] : '';
            $prod_label = (isset($result_join['trp_product']) ? (string) $result_join['trp_product'] : '') . ' ' . (isset($result_join['trp_sn']) ? (string) $result_join['trp_sn'] : '');
            $track_q = http_build_query(
                array(
                    'ts_id' => isset($result_join['trp_ts_id']) ? (int) $result_join['trp_ts_id'] : 0,
                    'trp_id' => $trp_id,
                    'tsp_id' => $tsp_id,
                    'trp_product' => $prod_label,
                    'th_status_job' => 'ติดตามเครื่องซ่อม',
                ),
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $track_href = $web_base . '/tracking_history?' . $track_q;
            $detail_href = $web_base . '/detail_job?ts_id=' . $ts_id;
            ?>
        <tr id="row<?php echo (int) $i; ?>" class="mn-status-onsite-datarow">
          <td class="mn-status-onsite-col--company mn-status-onsite-td--left">
            <a target="_blank" rel="noopener noreferrer" href="<?php echo htmlspecialchars($detail_href, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars((string) $result_join['ts_mtn_number'], ENT_QUOTES, 'UTF-8'); ?><br /><?php echo $detail; ?>
            </a>
          </td>
          <td class="mn-status-onsite-col--product mn-status-onsite-td--center"><?php echo htmlspecialchars(maintenance_status_onsite_iconv(isset($result_join['trp_product']) ? $result_join['trp_product'] : ''), ENT_QUOTES, 'UTF-8'); ?><br /><?php echo htmlspecialchars((string) (isset($result_join['trp_sn']) ? $result_join['trp_sn'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="mn-status-onsite-col--symptom mn-status-onsite-td--left"><?php echo htmlspecialchars(maintenance_status_onsite_iconv(isset($result_join['trp_repair_inform']) ? $result_join['trp_repair_inform'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="mn-status-onsite-col--spare mn-status-onsite-td--center">
            <?php if ($has_spare) { ?><span class="mn-status-onsite-spare--yes">มีสำรอง</span><?php } else { ?>ไม่มีสำรอง<?php } ?>
          </td>
          <td class="mn-status-onsite-col--wait mn-status-onsite-td--center"><?php echo cal_wait_date(isset($result_join['ts_date']) ? $result_join['ts_date'] : ''); ?></td>
          <td class="mn-status-onsite-col--status mn-status-onsite-td--center"><b><?php echo htmlspecialchars(maintenance_status_onsite_iconv(isset($result_join['trp_status_job']) ? $result_join['trp_status_job'] : ''), ENT_QUOTES, 'UTF-8'); ?></b></td>
          <td class="mn-status-onsite-col--track mn-status-onsite-td--center" title="<?php echo $title_th; ?>">
            <a href="<?php echo htmlspecialchars($track_href, ENT_QUOTES, 'UTF-8'); ?>"
               onclick="window.open(this.href,'targetWindow','toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=800,height=550');return false;"><i class="fa fa-volume-control-phone mn-status-onsite-phoneico" aria-hidden="true"></i></a><br /><?php echo cal_tracking_due($next_due); ?>
          </td>
        </tr>
        <tr class="mn-status-onsite-seprow">
          <td colspan="7"><hr /></td>
        </tr>
            <?php
        }
        ?>
        <tr>
          <td colspan="7" class="mn-status-onsite-summary mn-status-onsite-td--center">รวมเครื่องซ่อมที่รอ <?php echo (int) $row_count; ?> รายการ</td>
        </tr>
      </table>
    </div>
  </div>
</body>
</html>
