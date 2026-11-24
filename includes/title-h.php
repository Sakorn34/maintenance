<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * แถบนำทางหลักของระบบ maintenance สำหรับหน้าในรากโปรเจกต์นี้
 * ดึงจำนวนงานจากฐานข้อมูลด้วย mysqli แล้ว render แถบเมนู HTML (ลิงก์ราก `/maintenance/...` ไม่มีนามสกุล .php — อาศัย .htaccess ที่รากโปรเจกต์; ลิงก์ภายนอกเช่น «ต่ออายุเครื่องสำรอง» ใช้ `target="_blank"`)
 * สไตล์แถบเมนูอยู่ที่ `css/mn-nav.css` — หน้าเรียกต้องโหลด `css/mn-theme.css` แล้วตามด้วย
 * `css/mn-layout.css` (ถ้ามีพื้นที่ทำงานแบบกึ่งกลาง) แล้วตามด้วย `css/mn-nav.css` ใน head ของเอกสาร
 *
 * Flow:
 * กำหนดตัวแปร `$page` (และถ้าต้องการ `$maintenanceWebBase`, `$maintenanceAddJobUrl`) จากหน้าเรียก
 * แล้ว `require_once` ไฟล์นี้ภายใน layout ที่ต้องการแสดงแถบเมนู
 *
 * Parameters จากหน้าเรียกที่เกี่ยวข้อง:
 * - string $page: รหัสหน้า เช่น add_job, index (ใช้ highlight เมนู)
 * - string|null $maintenanceWebBase: path รากเว็บของแอป (default จาก `maintenance_new_maintenance_web_base()` เช่น `/maintenance`)
 * - string|null $maintenanceAddJobUrl: URL หน้าเปิดใบซ่อม (default `{base}/add_job` ไม่มี .php)
 * - string|null $maintenanceNewAddJobUrl: (legacy) ถ้าตั้งไว้ก่อน include จะใช้แทน default ของ `$maintenanceAddJobUrl`
 */

// ─────────────────────────────────────────────────────────────────
// constants (override ได้จากหน้าเรียกก่อน include)
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/session_profile.php';
if (!isset($maintenanceWebBase) || !is_string($maintenanceWebBase) || $maintenanceWebBase === '') {
    $maintenanceWebBase = maintenance_new_maintenance_web_base();
}
if (!isset($maintenanceAddJobUrl) || !is_string($maintenanceAddJobUrl) || $maintenanceAddJobUrl === '') {
    if (isset($maintenanceNewAddJobUrl) && is_string($maintenanceNewAddJobUrl) && $maintenanceNewAddJobUrl !== '') {
        $maintenanceAddJobUrl = $maintenanceNewAddJobUrl;
    } else {
        $maintenanceAddJobUrl = rtrim($maintenanceWebBase, '/') . '/add_job';
    }
}
if (!isset($page)) {
    $page = '';
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_title_nav_count_rows')) {
    /**
     * Purpose:
     * นับจำนวนแถวจากผลลัพธ์ SELECT แบบง่าย (ใช้กับ query ที่ไม่ต้อง filter แถวใน PHP)
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection
     * - string $sql: คำสั่ง SELECT
     *
     * Return value:
     * - int จำนวนแถว
     */
    function maintenance_new_title_nav_count_rows($mysqli, $sql)
    {
        $result = mysqli_query($mysqli, $sql);
        if ($result === false) {
            maintenance_new_db_fail('title-h nav: Error Query [' . $sql . ']: ' . mysqli_error($mysqli));
        }
        $n = 0;
        while (mysqli_fetch_assoc($result)) {
            $n++;
        }
        mysqli_free_result($result);

        return $n;
    }
}

if (!function_exists('maintenance_new_title_nav_render_link')) {
    /**
     * Purpose:
     * Render ลิงก์เมนูหนึ่งรายการพร้อมสถานะ active และ badge ตัวเลข (ถ้ามี)
     *
     * Parameters สำคัญ:
     * - bool $isActive: true เมื่อเป็นหน้าปัจจุบัน
     * - string $href: URL ปลายทาง
     * - string $iconClass: class ของไอคอน Font Awesome 4 (เช่น fa fa-home)
     * - string $labelHtml: ข้อความ label อนุญาต HTML เบา ๆ (เช่น <br>)
     * - int|null $badge: ตัวเลขแสดงบน badge หรือ null ถ้าไม่แสดง
     * - string|null $linkTarget: ถ้าเป็น `_blank` จะใส่ `target` + `rel` สำหรับลิงก์ภายนอก
     *
     * Return value:
     * - void (echo HTML)
     */
    function maintenance_new_title_nav_render_link($isActive, $href, $iconClass, $labelHtml, $badge = null, $linkTarget = null)
    {
        $activeClass = $isActive ? ' mn-navlink--active' : '';
        $aria = $isActive ? ' aria-current="page"' : '';
        $extra = '';
        if ($linkTarget === '_blank') {
            $extra = ' target="_blank" rel="noopener noreferrer"';
        }
        echo '<li class="mn-navlist__item">';
        echo '<a class="mn-navlink' . $activeClass . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $aria . $extra . '>';
        echo '<span class="mn-navlink__icon"><i class="' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i></span>';
        echo '<span class="mn-navlink__text">' . $labelHtml . '</span>';
        if ($badge !== null) {
            echo '<span class="mn-navlink__badge">' . (int) $badge . '</span>';
        }
        echo '</a></li>';
    }
}

// ─────────────────────────────────────────────────────────────────
// main: โหลดข้อมูลนับจำนวน
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/database.php';

$mysqli = maintenance_new_db_connect();

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รอส่งสำรอง'";
$num_logis = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รอส่งคืนเครื่องซ่อม'";
$num_logis += maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รอเครื่องซ่อม'";
$num_hw = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รอประเมิน'";
$num_rate = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รอเสนอราคา' AND trp_insurance = 'หมดประกัน'";
$num_wQV = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รออนุมัติซ่อม'";
$num_fQV = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รอซ่อม'";
$num_wMA = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_status_job = 'รอ QC'";
$num_qc = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM ((transac_repair
    INNER JOIN transac_spare ON transac_spare.tsp_trp_id = transac_repair.trp_id )
    INNER JOIN transac ON transac.ts_id = transac_repair.trp_ts_id )
    WHERE transac_repair.trp_status_job = 'รอเครื่องสำรอง' ORDER BY transac_repair.trp_id ASC";
$num_spare = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = "SELECT * FROM transac_repair WHERE trp_contact_pickup IS NOT NULL AND trp_date_pickup IS NULL";
$num_pickup = maintenance_new_title_nav_count_rows($mysqli, $sql);

$sql = 'SELECT * FROM borrow';
$result = mysqli_query($mysqli, $sql);
if ($result === false) {
    maintenance_new_db_fail('title-h nav: Error Query [' . $sql . ']: ' . mysqli_error($mysqli));
}
$num_borrow = 0;
while ($row = mysqli_fetch_assoc($result)) {
    if (isset($row['b_status']) && $row['b_status'] !== '3-ส่งคืนแล้ว') {
        $num_borrow++;
    }
}
mysqli_free_result($result);

mysqli_close($mysqli);

$b = rtrim($maintenanceWebBase, '/');

// ป้าย "ระบบทดลอง" — แสดงเมื่อมี config/site_banner.local.php (ชุดทดลองบน server เท่านั้น ระบบหลักไม่มีไฟล์นี้)
$siteBanner = '';
$siteBannerFile = dirname(__DIR__) . '/config/site_banner.local.php';
if (is_file($siteBannerFile)) {
    $siteBannerValue = require $siteBannerFile;
    $siteBanner = is_string($siteBannerValue) ? trim($siteBannerValue) : '';
}

// ผู้ใช้ที่ล็อกอินอยู่ (production: มาจาก bitlogin; localhost: จาก login.php) — ว่างเมื่อโหมด development ที่ไม่ตรวจ session
$navProfileName = maintenance_new_profile_display_name();
if ($navProfileName === '') {
    $loginNameForNav = maintenance_new_profile_login_name();
    $navProfileName = ($loginNameForNav === null) ? '' : trim((string) $loginNameForNav);
}

?>

<nav class="mn-appbar" aria-label="เมนูหลักระบบซ่อมบำรุง">
  <div class="mn-appbar__inner">
    <div class="mn-appbar__brand">
      <a href="<?php echo htmlspecialchars($b . '/index', ENT_QUOTES, 'UTF-8'); ?>">Maintenance</a>
      <?php if ($siteBanner !== '') { ?>
      <span class="mn-appbar__tagline mn-appbar__tagline--test" title="ข้อมูลในระบบนี้เป็นสำเนา ไม่กระทบระบบจริง"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo htmlspecialchars($siteBanner, ENT_QUOTES, 'UTF-8'); ?></span>
      <?php } else { ?>
      <span class="mn-appbar__tagline">ระบบจัดการงานซ่อม | ภายในองค์กร</span>
      <?php } ?>
    </div>
    <div class="mn-appbar__navwrap">
      <ul class="mn-navlist">
        <?php
        maintenance_new_title_nav_render_link($page === 'index', $b . '/index', 'fa fa-home', 'Home', null);
        maintenance_new_title_nav_render_link($page === 'repair_dashboard', $b . '/repair_dashboard', 'fa fa-bar-chart', 'Dashboard', null);
        maintenance_new_title_nav_render_link($page === 'add_job', $maintenanceAddJobUrl, 'fa fa-plus', 'เปิดใบซ่อม', null);
        maintenance_new_title_nav_render_link($page === 'status_logistic', $b . '/status_logistic', 'fa fa-truck', 'รอส่งพัสดุ', $num_logis);
        maintenance_new_title_nav_render_link($page === 'status_wait_hardware', $b . '/status_wait_hardware', 'fa fa-hourglass-half', 'รอเครื่องซ่อม', $num_hw);
        maintenance_new_title_nav_render_link($page === 'status_rate', $b . '/status_rate', 'fa fa-hourglass-half', 'รอประเมิน', $num_rate);
        maintenance_new_title_nav_render_link($page === 'status_offer_price', $b . '/status_offer_price', 'fa fa-gavel', 'รอเสนอราคา', $num_wQV);
        maintenance_new_title_nav_render_link($page === 'status_wait_firmQV', $b . '/status_wait_firmQV', 'fa fa-hourglass-half', 'รออนุมัติ', $num_fQV);
        maintenance_new_title_nav_render_link($page === 'status_wait_ma', $b . '/status_wait_ma', 'fa fa-wrench', 'รอซ่อม', $num_wMA);
        maintenance_new_title_nav_render_link($page === 'status_wait_qc', $b . '/status_wait_qc', 'fa fa-heartbeat', 'รอ QC', $num_qc);
        maintenance_new_title_nav_render_link($page === 'status_wait_spare', $b . '/status_wait_spare', 'fa fa-exclamation-triangle', 'รอสำรอง', $num_spare);
        maintenance_new_title_nav_render_link($page === 'status_logistic_pickup', $b . '/status_logistic_pickup', 'fa fa-truck', 'เข้ารับหน้างาน', $num_pickup);
        maintenance_new_title_nav_render_link($page === 'status_borrow', $b . '/status_borrow', 'fa fa-upload', 'รายการยืม', $num_borrow);
        maintenance_new_title_nav_render_link(false, 'https://bit-online.net/qrcode_hire/expired.php', 'fa fa-qrcode', 'ต่ออายุ<br>เครื่องสำรอง', null, '_blank');
        ?>
      </ul>
    </div>
    <?php if ($navProfileName !== '') { ?>
    <div class="mn-appbar__user">
      <span class="mn-appbar__username" title="ผู้ใช้ที่เข้าสู่ระบบ">
        <i class="fa fa-user-circle-o" aria-hidden="true"></i>
        <?php echo htmlspecialchars($navProfileName, ENT_QUOTES, 'UTF-8'); ?>
      </span>
      <a class="mn-appbar__logout" href="<?php echo htmlspecialchars($b . '/logout', ENT_QUOTES, 'UTF-8'); ?>">
        <i class="fa fa-sign-out" aria-hidden="true"></i> ออกจากระบบ
      </a>
    </div>
    <?php } ?>
  </div>
  <div class="mn-appbar__accent" aria-hidden="true"></div>
</nav>
