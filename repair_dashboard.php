<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า Dashboard วิเคราะห์งานซ่อม — แนวโน้มอะไหล่สำรอง, ระยะเวลารอเครื่อง/รออนุมัติ/ช่วงซ่อม, รายได้จากราคา QV, สถิติเสริม (สถานะ, รายเดือน, รุ่นสินค้า, WIP)
 * ข้อมูลอ้างอิงช่วง `transac.ts_date` (วันเปิดใบงาน) ผ่าน `includes/repair_dashboard_helpers.php` + กราฟ Chart.js
 *
 * Flow:
 * require_auth → bootstrap → parse ช่วงวันที่ → `maintenance_repair_dashboard_fetch_all` → ปิด connection → render + JSON (กราฟเดือนเติม 0 ทุกเดือนในช่วง) → `js/repair_dashboard-page.js`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/repair_dashboard_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$range = maintenance_repair_dashboard_parse_range($_GET);
$dash = maintenance_repair_dashboard_fetch_all($connect, $range);
maintenance_db_odbc_close($connect);

$from_h = maintenance_status_logistic_h($dash['range']['from']);
$to_h = maintenance_status_logistic_h($dash['range']['to']);
$json_payload = json_encode(
    $dash,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($json_payload === false) {
    $json_payload = '{}';
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-repair-dashboard.css" />
<title>Dashboard การซ่อม</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body class="mn-addjob-page mn-repair-dashboard-page">
  <div class="header">
    <?php
    $page = 'repair_dashboard';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered mn-repair-dashboard-wrap">
      <header class="mn-repair-dashboard-head">
        <h1 class="mn-repair-dashboard-title"><i class="fa fa-bar-chart" aria-hidden="true"></i> Dashboard การซ่อม</h1>
        <p class="mn-repair-dashboard-lead">สรุปจากใบงานที่ <strong>วันที่เปิดใบ</strong> (<code>ts_date</code>) อยู่ในช่วงที่เลือก — ใช้ประกอบการวิเคราะห์ภาพรวม ไม่ใช่รายงานบัญชีอย่างเป็นทางการ<br />
        <span class="mn-repair-dashboard-lead__note">ถ้าไม่ระบุวันที่ ระบบจะใช้ <strong>ปีปฏิทินปัจจุบัน</strong> (1 ม.ค.–31 ธ.ค.) — กราฟ «ปริมาณงานต่อเดือน» จะแสดงทุกเดือนในช่วงที่เลือก (เดือนที่ไม่มีงานเป็น 0) เพื่อให้เห็นรูปแบบชัดเมื่อเลือกทั้งปีหรือหนึ่งเดือน</span></p>
        <form class="mn-repair-dashboard-filter" method="get" action="repair_dashboard">
          <span class="mn-repair-dashboard-filter__control">
            <label for="dash_from">ตั้งแต่</label>
            <input type="date" id="dash_from" name="from" value="<?php echo $from_h; ?>" />
          </span>
          <span class="mn-repair-dashboard-filter__control">
            <label for="dash_to">ถึง</label>
            <input type="date" id="dash_to" name="to" value="<?php echo $to_h; ?>" />
          </span>
          <button type="submit" class="mn-repair-dashboard-filter__btn">นำไปใช้</button>
          <span class="mn-repair-dashboard-filter__presets" role="group" aria-label="ช่วงด่วน">
            <button type="button" class="mn-repair-dashboard-filter__preset" id="mnDashThisYear" title="ตั้งช่วงเป็นปีปฏิทินปัจจุบัน">ปีนี้</button>
            <button type="button" class="mn-repair-dashboard-filter__preset" id="mnDashThisMonth" title="ตั้งช่วงเป็นเดือนปัจจุบัน">เดือนนี้</button>
          </span>
        </form>
      </header>
        <?php
        $sparse = (int) $dash['job_count'] > 0
            && (int) $dash['wait_hardware']['n'] + (int) $dash['wait_approval']['n'] + (int) $dash['repair_cycle']['n'] + (int) $dash['rate_after_receive']['n'] === 0;
        ?>
        <?php if ($sparse) { ?>
        <p class="mn-repair-dashboard-sparse-note" role="status">งานในช่วงนี้มีแล้ว แต่ KPI ระยะเวลาหลายตัวเป็น «—» เพราะยังไม่มีวันที่ครบชุดในฐานข้อมูลสำหรับสูตรนั้น (เช่น งานสถานะ <strong>รอส่งสำรอง</strong> มักยังไม่มี <code>trp_receive_date</code> / <code>trp_qv_date</code>) — เป็นเรื่องปกติเมื่อดูช่วงสั้นหรืองานยังไม่ไหลต่อเนื่อง</p>
        <?php } ?>
      <section class="mn-repair-dashboard-kpis" aria-label="ตัวเลขหลัก">
        <article class="mn-repair-dashboard-kpi">
          <h2 class="mn-repair-dashboard-kpi__label">งานในช่วง</h2>
          <p class="mn-repair-dashboard-kpi__value"><?php echo (int) $dash['job_count']; ?></p>
          <p class="mn-repair-dashboard-kpi__hint">จำนวนแถวซ่อมที่ join กับใบงานในช่วงวันที่</p>
        </article>
        <article class="mn-repair-dashboard-kpi">
          <h2 class="mn-repair-dashboard-kpi__label">รอรับเครื่อง (เฉลี่ยวัน)</h2>
          <p class="mn-repair-dashboard-kpi__value"><?php echo $dash['wait_hardware']['avg_days'] !== null ? maintenance_status_logistic_h((string) $dash['wait_hardware']['avg_days']) : '—'; ?></p>
          <p class="mn-repair-dashboard-kpi__hint">จาก <code>ts_date</code> ถึง <code>trp_receive_date</code> (n=<?php echo (int) $dash['wait_hardware']['n']; ?>)</p>
        </article>
        <article class="mn-repair-dashboard-kpi">
          <h2 class="mn-repair-dashboard-kpi__label">รออนุมัติ QV (เฉลี่ยวัน)</h2>
          <p class="mn-repair-dashboard-kpi__value"><?php echo $dash['wait_approval']['avg_days'] !== null ? maintenance_status_logistic_h((string) $dash['wait_approval']['avg_days']) : '—'; ?></p>
          <p class="mn-repair-dashboard-kpi__hint"><code>trp_qv_date</code> → <code>trp_firm_qv_date</code> (n=<?php echo (int) $dash['wait_approval']['n']; ?>)</p>
        </article>
        <article class="mn-repair-dashboard-kpi">
          <h2 class="mn-repair-dashboard-kpi__label">ช่วงซ่อมหลังอนุมัติ (เฉลี่ยวัน)</h2>
          <p class="mn-repair-dashboard-kpi__value"><?php echo $dash['repair_cycle']['avg_days'] !== null ? maintenance_status_logistic_h((string) $dash['repair_cycle']['avg_days']) : '—'; ?></p>
          <p class="mn-repair-dashboard-kpi__hint"><code>trp_firm_qv_date</code> → <code>trp_success_date</code> (n=<?php echo (int) $dash['repair_cycle']['n']; ?>)</p>
        </article>
        <article class="mn-repair-dashboard-kpi mn-repair-dashboard-kpi--accent">
          <h2 class="mn-repair-dashboard-kpi__label">รวมราคา QV ในช่วง</h2>
          <p class="mn-repair-dashboard-kpi__value"><?php echo maintenance_status_logistic_h(number_format($dash['revenue_total'], 2)); ?></p>
          <p class="mn-repair-dashboard-kpi__hint">ผลรวม <code>trp_qv_price</code> ที่แปลงเป็นตัวเลขได้ (<?php echo (int) $dash['revenue_rows']; ?> แถว)</p>
        </article>
        <article class="mn-repair-dashboard-kpi">
          <h2 class="mn-repair-dashboard-kpi__label">ไปประเมินหลังรับเครื่อง (เฉลี่ยวัน)</h2>
          <p class="mn-repair-dashboard-kpi__value"><?php echo $dash['rate_after_receive']['avg_days'] !== null ? maintenance_status_logistic_h((string) $dash['rate_after_receive']['avg_days']) : '—'; ?></p>
          <p class="mn-repair-dashboard-kpi__hint"><code>trp_receive_date</code> → <code>trp_rate_date</code> (n=<?php echo (int) $dash['rate_after_receive']['n']; ?>)</p>
        </article>
      </section>

      <div class="mn-repair-dashboard-grid">
        <section class="mn-repair-dashboard-card" aria-labelledby="mn-dash-spare-title">
          <h2 id="mn-dash-spare-title" class="mn-repair-dashboard-card__title">แนวโน้มอะไหล่ / สินค้าสำรอง (transac_spare)</h2>
          <p class="mn-repair-dashboard-card__desc">นับจำนวนครั้งที่บันทึกสำรองตาม <code>tsp_product</code> ในใบงานในช่วงวันที่ — ช่วยเห็นรุ่นที่ถูกยืม/ส่งสำรองบ่อย</p>
          <div class="mn-repair-dashboard-chart"><canvas id="mnChartSpare" width="400" height="220" aria-label="กราฟแนวโน้มสำรอง"></canvas></div>
        </section>
        <section class="mn-repair-dashboard-card" aria-labelledby="mn-dash-month-title">
          <h2 id="mn-dash-month-title" class="mn-repair-dashboard-card__title">ปริมาณงานต่อเดือน</h2>
          <p class="mn-repair-dashboard-card__desc">จำนวนแถวซ่อมตามเดือนของ <code>ts_date</code> — แสดงทุกเดือนในช่วงที่เลือก (เดือนไม่มีงานเป็น 0)</p>
          <div class="mn-repair-dashboard-chart"><canvas id="mnChartMonthly" width="400" height="220" aria-label="กราฟรายเดือน"></canvas></div>
        </section>
        <section class="mn-repair-dashboard-card" aria-labelledby="mn-dash-status-title">
          <h2 id="mn-dash-status-title" class="mn-repair-dashboard-card__title">สัดส่วนสถานะงานซ่อม (ในช่วง)</h2>
          <p class="mn-repair-dashboard-card__desc">กระจาย <code>trp_status_job</code> ของงานที่เปิดใบในช่วง</p>
          <div class="mn-repair-dashboard-chart mn-repair-dashboard-chart--donut"><canvas id="mnChartStatus" width="280" height="280" aria-label="กราฟสถานะ"></canvas></div>
        </section>
        <section class="mn-repair-dashboard-card" aria-labelledby="mn-dash-product-title">
          <h2 id="mn-dash-product-title" class="mn-repair-dashboard-card__title">รุ่นสินค้าที่เข้าซ่อมบ่อย</h2>
          <p class="mn-repair-dashboard-card__desc">อันดับจาก <code>trp_product</code></p>
          <div class="mn-repair-dashboard-chart"><canvas id="mnChartProducts" width="400" height="260" aria-label="กราฟรุ่นสินค้า"></canvas></div>
        </section>
        <section class="mn-repair-dashboard-card mn-repair-dashboard-card--wide" aria-labelledby="mn-dash-wip-title">
          <h2 id="mn-dash-wip-title" class="mn-repair-dashboard-card__title">งานค้างในระบบ (ทุกช่วงวันที่)</h2>
          <p class="mn-repair-dashboard-card__desc">นับจาก <code>transac_repair</code> ปัจจุบันทั้งหมด — สะท้อน pipeline คงค้าง (WIP)</p>
          <div class="mn-repair-dashboard-tablewrap">
            <table class="mn-repair-dashboard-table">
              <thead><tr><th>สถานะ</th><th>จำนวน</th></tr></thead>
              <tbody>
                <?php foreach ($dash['wip_by_status'] as $w) { ?>
                <tr>
                  <td><?php echo maintenance_status_logistic_h($w['label']); ?></td>
                  <td align="right"><?php echo (int) $w['count']; ?></td>
                </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </div>
  <script type="application/json" id="mn-repair-dashboard-json"><?php echo $json_payload; ?></script>
  <script type="text/javascript" src="js/repair_dashboard-page.js"></script>
</body>
</html>
