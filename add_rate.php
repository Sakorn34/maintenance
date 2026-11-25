<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * แบบฟอร์ม + ดูสรุปรายการซ่อม (`Rate`) ต่ออุปกรณ์ — เลือกอุปกรณ์ในแบบฟอร์มด้านบนเพื่อดูรายการด้านล่าง (preview ผ่าน JSON) หรือเปิดด้วย GET `catalog_product` สำหรับลิงก์เดิม
 * ไม่โหลดรายการซ่อมทุกอุปกรณ์ตอนเปิดหน้า (ลด query) — ถ้ามี `catalog_product` ค่อยดึง `r_rate` ครั้งแรกตอน render
 *
 * Flow:
 * bootstrap → ดึงรายชื่ออุปกรณ์ (distinct) → ถ้ามี `catalog_product` ค่อยดึง `r_rate` → ปิด `$connect` → render — `js/add_rate-page.js` + `add_rate_rates_json` เมื่อเปลี่ยน `#r_product`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/add_rate_endpoints.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/add_rate_helpers.php';

$dropdown_products = maintenance_add_rate_fetch_distinct_products($connect);
$catalog_product = isset($_GET['catalog_product']) ? trim((string) $_GET['catalog_product']) : '';

$summary_blocks = array();
if ($catalog_product !== '') {
    $rates = maintenance_add_rate_fetch_rates_for_product($connect, $catalog_product);
    $summary_blocks[] = array(
        'product' => $catalog_product,
        'rates' => $rates,
    );
}

maintenance_db_odbc_close($connect);

require_once __DIR__ . '/config/app_environment.php';
$index_url = maintenance_new_maintenance_index_url();
$dropdown_count = count($dropdown_products);
$catalog_option_count = $dropdown_count;
$show_catalog_results = ($catalog_product !== '');
$mn_add_rate_hint = 'เลือกอุปกรณ์ในแบบฟอร์มด้านบนเพื่อดูรายการซ่อมด้านล่าง';
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
<link rel="stylesheet" href="css/mn-page-add-rate.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>เพิ่มรายการซ่อม (Rate)</title>
<script type="text/javascript">
window.MN_ADD_RATE = <?php echo json_encode(maintenance_add_rate_client_config(), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script type="text/javascript" src="js/add_rate-page.js"></script>
</head>
<body class="mn-addjob-page mn-add-rate-page">
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
    <div class="mn-form-centered mn-add-rate-inner">
      <div class="mn-add-rate-stack">
        <nav class="mn-add-rate-toolbar" aria-label="นำทางกลับหน้าหลัก">
          <a class="mn-add-rate-home" href="<?php echo htmlspecialchars($index_url, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa fa-home fa-lg" aria-hidden="true"></i> กลับหน้าแรก
          </a>
        </nav>

        <section class="mn-add-rate-card mn-add-rate-card--form" aria-labelledby="mn-add-rate-form-title">
          <header class="mn-add-rate-card__head">
            <h1 id="mn-add-rate-form-title" class="mn-add-rate-card__title">แบบฟอร์มรายการซ่อม</h1>
            <p class="mn-add-rate-card__lead">เลือกหรือเพิ่มชื่ออุปกรณ์ ระบุรายการซ่อม แล้วกดบันทึกเพื่อเพิ่มใน Rate</p>
          </header>
          <div class="mn-add-rate-card__body">
            <div class="mn-add-rate-form-inline">
              <span class="mn-add-rate-form__group mn-add-rate-form__group--product">
                <span class="mn-add-rate-form__label-row">
                  <label class="mn-add-rate-label" for="r_product"><strong>อุปกรณ์</strong></label>
                  <span class="mn-add-rate-form__meta-links">
                    <a href="#" class="mn-add-rate-link mn-add-rate-link--action" onclick="add_product(); return false;" id="a_add">เพิ่มชื่ออุปกรณ์</a>
                    <a href="add_rate" class="mn-add-rate-link mn-add-rate-link--muted" id="a_cancel" style="display:none;">ยกเลิก</a>
                  </span>
                </span>
                <span class="mn-add-rate-form__stack">
                  <span class="mn-add-rate-form__control-row">
                    <select class="custom-select mn-add-rate-select-product" id="r_product" name="r_product">
                      <option value="">กรุณาเลือกอุปกรณ์</option>
                      <?php
                      for ($a = 0; $a < $dropdown_count; $a++) {
                          $opt = (string) $dropdown_products[$a];
                          $sel_rp = ($catalog_product !== '' && $catalog_product === $opt) ? ' selected="selected"' : '';
                          ?>
                      <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $sel_rp; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                          <?php
                      }
                      ?>
                    </select>
                    <input type="text" name="r_productNew" id="r_productNew" class="mn-add-rate-input-productnew" style="display:none;" placeholder="ชื่ออุปกรณ์ใหม่" />
                  </span>
                </span>
              </span>
              <span class="mn-add-rate-form__group mn-add-rate-form__group--rate">
                <label class="mn-add-rate-label" for="r_rate"><strong>รายการซ่อม</strong></label>
                <span class="mn-add-rate-form__stack">
                  <input type="text" name="r_rate" id="r_rate" class="mn-add-rate-input-rate" placeholder="พิมพ์รายการซ่อม" />
                </span>
              </span>
              <span class="mn-add-rate-form__group mn-add-rate-form__group--save">
                <span class="mn-add-rate-form__save-slot" aria-hidden="true"></span>
                <span class="mn-add-rate-form__stack mn-add-rate-form__stack--save">
                  <a href="#" class="mn-add-rate-save mn-add-rate-save--btn" onclick="save_rate(); return false;" title="บันทึก">
                    <i class="fa fa-save" aria-hidden="true"></i><span class="mn-add-rate-save__text">บันทึก</span>
                  </a>
                </span>
              </span>
            </div>
          </div>
        </section>

        <section class="mn-add-rate-card mn-add-rate-card--catalog" aria-labelledby="mn-add-rate-catalog-title">
          <header class="mn-add-rate-card__head mn-add-rate-card__head--catalog">
            <h2 id="mn-add-rate-catalog-title" class="mn-add-rate-card__title mn-add-rate-card__title--secondary">ดูรายการซ่อมตามอุปกรณ์</h2>
            <p class="mn-add-rate-card__lead">เลือกอุปกรณ์ในแบบฟอร์มด้านบนเพื่อดูรายการ — มี <?php echo (int) $catalog_option_count; ?> อุปกรณ์ใน Rate</p>
            <?php if ($catalog_option_count > 0) { ?>
            <p class="mn-add-rate-card__catalog-reset"><a class="mn-add-rate-link mn-add-rate-link--muted" href="add_rate">ล้างการดู</a></p>
            <?php } ?>
          </header>
          <div class="mn-add-rate-card__body mn-add-rate-card__body--flush mn-add-rate-card__body--catalog-results">
            <div id="mn-add-rate-catalog-live" class="mn-add-rate-catalog-live" data-hint="<?php echo htmlspecialchars($mn_add_rate_hint, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($catalog_option_count === 0) { ?>
            <p class="mn-add-rate-empty">ยังไม่มีข้อมูลในตาราง Rate</p>
            <?php } elseif (!$show_catalog_results) { ?>
            <p class="mn-add-rate-hint"><?php echo htmlspecialchars($mn_add_rate_hint, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php } else {
                $block = $summary_blocks[0];
                $prod = (string) $block['product'];
                $rates = isset($block['rates']) && is_array($block['rates']) ? $block['rates'] : array();
                ?>
            <div class="mn-add-rate-catalog-grid mn-add-rate-catalog-grid--single">
              <article class="mn-add-rate-product-card">
                <header class="mn-add-rate-product-card__head"><?php echo htmlspecialchars($prod, ENT_QUOTES, 'UTF-8'); ?></header>
                <ul class="mn-add-rate-rate-list">
                  <?php
                  if (count($rates) === 0) {
                      ?>
                  <li class="mn-add-rate-rate-list__empty">ไม่มีรายการซ่อมสำหรับอุปกรณ์นี้</li>
                      <?php
                  } else {
                      foreach ($rates as $line) {
                          ?>
                  <li><?php echo htmlspecialchars((string) $line, ENT_QUOTES, 'UTF-8'); ?></li>
                          <?php
                      }
                  }
                  ?>
                </ul>
              </article>
            </div>
            <?php } ?>
            </div>
            <?php if ($show_catalog_results) {
                $blockInit = $summary_blocks[0];
                $prodInit = (string) $blockInit['product'];
                $ratesInit = isset($blockInit['rates']) && is_array($blockInit['rates']) ? $blockInit['rates'] : array();
                ?>
            <script type="application/json" id="mn-add-rate-initial-catalog"><?php echo json_encode(array('product' => $prodInit, 'rates' => $ratesInit), JSON_UNESCAPED_UNICODE); ?></script>
            <?php } ?>
          </div>
        </section>
      </div>
    </div>
  </div>
</body>
</html>
