<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ากรอก «เสนอราคา / QV» ตาม `trp_id` (GET) — แยกจาก `status_offer_price` เพื่อให้รายการไม่แน่นขยายฟอร์มได้ในอนาคต
 *
 * Flow:
 * require_auth → bootstrap → parse `trp_id` → ดึง bundle + ตรวจสถานะ `รอเสนอราคา` + ประกัน `หมดประกัน` → ปิด `$connect` → render หรือ modal redirect; บันทึกผ่าน `update_offer_price` จะซิงก์ QV/ราคาไปรายการซ่อมอื่นในเลขที่งานเดียวกันที่ยัง `รอเสนอราคา`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/offer_price_qv_helpers.php';
require_once __DIR__ . '/includes/status_offer_price_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$row = null;
if ($trp_id > 0) {
    $row = maintenance_offer_price_qv_fetch_bundle($connect, $trp_id);
}

$status_ok = maintenance_status_offer_price_status_literal();
$ins_ok = maintenance_status_offer_price_insurance_literal();
$state_valid = is_array($row)
    && isset($row['trp_status_job']) && isset($row['trp_insurance'])
    && (string) $row['trp_status_job'] === $status_ok
    && (string) $row['trp_insurance'] === $ins_ok;

$missing = ($trp_id < 1 || !is_array($row) || !$state_valid);

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
<link rel="stylesheet" href="css/mn-page-edit-offer-price.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title>กรอกเสนอราคา (QV)</title>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<script type="text/javascript" src="js/jquery-scroll.js"></script>
<script type="text/javascript" src="js/mn-maintenance-flow-modals.js"></script>
<script type="text/javascript" src="js/edit_offer_price-page.js"></script>
</head>
<body class="mn-addjob-page mn-edit-offer-price-page" data-mn-edit-qv-return-page="status_offer_price">
<?php if ($missing) { ?>
<script type="text/javascript">
mnModalAlert(<?php echo json_encode('ไม่พบข้อมูล สถานะไม่ใช่รอเสนอราคา หรือไม่ได้ระบุ trp_id', JSON_UNESCAPED_UNICODE); ?>, {
  variant: 'warning',
  onClose: function () { window.location.href = 'status_offer_price'; }
});
</script>
</body>
</html>
<?php
    exit;
}

$ts_id = (int) (isset($row['ts_id']) ? $row['ts_id'] : 0);
$detail_html = maintenance_status_offer_price_contact_block_html($row);
$list_repair_html = maintenance_status_offer_price_repair_list_html($row);
$mtn = isset($row['ts_mtn_number']) ? (string) $row['ts_mtn_number'] : '';
$rate_date = isset($row['trp_rate_date']) ? (string) $row['trp_rate_date'] : '';
$qv_date_val = isset($row['trp_qv_date']) ? trim((string) $row['trp_qv_date']) : '';
if ($qv_date_val === '') {
    $qv_date_val = date('Y-m-d');
}
$qv_send = isset($row['trp_qv_send']) ? trim((string) $row['trp_qv_send']) : '';
$qv_price = isset($row['trp_qv_price']) ? (string) $row['trp_qv_price'] : '';
$qv_number = isset($row['trp_qv_number']) ? (string) $row['trp_qv_number'] : '';
?>
  <div class="header">
    <?php
    $page = 'status_offer_price';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-edit-offer-price-wrap">
      <article class="mn-edit-offer-price-card" aria-labelledby="mn-edit-offer-price-title">
        <header class="mn-edit-offer-price-card__head">
          <h1 id="mn-edit-offer-price-title" class="mn-edit-offer-price-card__title">กรอกเสนอราคา (QV)</h1>
        </header>

        <section class="mn-edit-offer-price-summary" aria-label="ข้อมูลใบซ่อม">
          <dl>
            <dt>เลขที่ซ่อม / วันที่ประเมิน</dt>
            <dd><?php echo maintenance_status_logistic_h($mtn); ?> &nbsp;|&nbsp; <?php echo maintenance_status_logistic_h($rate_date); ?></dd>
            <dt>บริษัท / ผู้ติดต่อ</dt>
            <dd><?php echo $detail_html; ?></dd>
            <dt>รายการซ่อม</dt>
            <dd><?php echo $list_repair_html; ?></dd>
            <dt>ลิงก์ใบงาน</dt>
            <dd><a target="_blank" rel="noopener" href="detail_job?ts_id=<?php echo $ts_id; ?>">เปิด detail_job</a></dd>
          </dl>
        </section>

        <section class="mn-edit-offer-price-form" aria-label="ฟอร์ม QV">
          <div class="mn-edit-offer-price-field">
            <label class="mn-edit-offer-price-label" for="trp_qv_date">วันที่ส่งใบเสนอราคา</label>
            <input type="date" name="trp_qv_date" id="trp_qv_date" class="mn-edit-offer-price-input mn-edit-offer-price-input--date" value="<?php echo maintenance_status_logistic_h($qv_date_val); ?>" />
            <p class="mn-edit-offer-price-hint">*ด/ว/ป</p>
          </div>
          <div class="mn-edit-offer-price-field">
            <label class="mn-edit-offer-price-label" for="trp_qv_send">ส่งโดย</label>
            <select name="trp_qv_send" id="trp_qv_send" class="mn-edit-offer-price-select">
              <option value=""<?php echo $qv_send === '' ? ' selected="selected"' : ''; ?>>ส่งโดย</option>
              <option value="Mail"<?php echo $qv_send === 'Mail' ? ' selected="selected"' : ''; ?>>Mail</option>
              <option value="Line"<?php echo $qv_send === 'Line' ? ' selected="selected"' : ''; ?>>Line</option>
              <option value="Fax"<?php echo $qv_send === 'Fax' ? ' selected="selected"' : ''; ?>>Fax</option>
            </select>
          </div>
          <div class="mn-edit-offer-price-field">
            <label class="mn-edit-offer-price-label" for="trp_qv_price">ค่าซ่อมรวม VAT</label>
            <input name="trp_qv_price" id="trp_qv_price" type="text" class="mn-edit-offer-price-input" value="<?php echo maintenance_status_logistic_h($qv_price); ?>" onkeyup="mnEditOfferPriceAutoMask(this,event);" />
          </div>
          <div class="mn-edit-offer-price-field">
            <label class="mn-edit-offer-price-label" for="trp_qv_number">เลขหมาย QV</label>
            <input name="trp_qv_number" id="trp_qv_number" type="text" class="mn-edit-offer-price-input" value="<?php echo maintenance_status_logistic_h($qv_number); ?>" maxlength="12" onkeyup="mnEditOfferPriceAutoMaskQV(this,event);" oninput="this.value = this.value.toUpperCase()" />
          </div>

          <footer class="mn-edit-offer-price-toolbar">
            <p class="mn-edit-offer-price-hint">*บันทึกแล้วจะคัดลอก QV/ราคาไปรายการซ่อมอื่นในเลขที่งานเดียวกันที่ยังรอเสนอราคา</p>
            <a href="status_offer_price"><i class="fa fa-reply" aria-hidden="true"></i> กลับรายการ</a>
            <a href="javascript:mnEditOfferPriceSave(<?php echo (int) $trp_id; ?>);" class="mn-edit-offer-price-toolbar__save"><i class="fa fa-save" aria-hidden="true"></i> บันทึก</a>
          </footer>
        </section>
      </article>
    </div>
  </div>
</body>
</html>
