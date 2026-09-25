<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้าเข้าสู่ระบบสำหรับรัน maintenance บน localhost โดยยังใช้ "หลักการ login แบบ production"
 * — ทุกหน้าถูก `includes/auth_guard.php` บังคับให้มี `$_SESSION['profile']` (env.local ตั้ง `app_env = production`)
 *   และ redirect มาที่นี่เมื่อไม่มี session ส่วนคำขอ AJAX ได้ HTTP 401 JSON เหมือนเดิม
 *
 * ต่างจาก production เฉพาะขั้นตอนพิสูจน์ตัวตน: production ใช้ bitlogin (LINE Login) ซึ่ง `redirect_uri`
 * ผูกกับ `https://bit-online.net/bitlogin/callback.php` จึงเรียกจาก localhost ไม่ได้ — หน้านี้จึงเลือก
 * พนักงานจากตาราง `employee` แล้วสร้าง profile คีย์ชุดเดียวกับ bitlogin (ดู `includes/local_login_helpers.php`)
 *
 * ใช้ได้เฉพาะเมื่อคำขอมาจาก localhost จริง — โฮสต์อื่น (รวม LAN IP ของเซิร์ฟเวอร์จริง) จะถูกส่งไป bitlogin
 *
 * Flow:
 * bootstrap session → กันโฮสต์ที่ไม่ใช่ localhost → มี profile แล้วเด้งเข้า index →
 * POST เลือกคน หรือ auto-login ตาม `dev_login_name` / `dev_auto_login` ใน env.local → เก็บ session → index
 *
 * Query string ที่รองรับ:
 * - `?u=<emp_name>` ล็อกอินเป็นคนนั้นทันที (ใช้สลับผู้ใช้ตอนทดสอบ)
 * - `?logout=1` มาจาก `logout.php` — ข้าม auto-login เพื่อให้เลือกผู้ใช้ใหม่ได้
 */

date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/local_login_helpers.php';

maintenance_new_auth_bootstrap();

// ─────────────────────────────────────────────────────────────────
// main
// ─────────────────────────────────────────────────────────────────
$webBase = rtrim(maintenance_new_maintenance_web_base(), '/');
$indexUrl = $webBase . '/index';
$loginUrl = $webBase . '/login';

// โฮสต์ที่ไม่ใช่ localhost ต้องใช้ระบบ login จริงเท่านั้น
if (!maintenance_local_login_is_localhost_request()) {
    header('Location: ' . maintenance_local_login_bitlogin_url());
    exit;
}

// มี session อยู่แล้ว — ไม่ต้องล็อกอินซ้ำ (พฤติกรรมเดียวกับ bitlogin/index.php)
if (isset($_SESSION['profile']) && $_SESSION['profile'] !== null && $_SESSION['profile'] !== '') {
    header('Location: ' . $indexUrl);
    exit;
}

$mysqli = maintenance_new_db_connect();
$employees = maintenance_local_login_fetch_employees($mysqli);

$cameFromLogout = isset($_GET['logout']) && $_GET['logout'] !== '';
$isPost = (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST');

$configuredName = maintenance_local_login_config('dev_login_name', '');
$configuredName = is_string($configuredName) ? trim($configuredName) : '';
$autoLoginEnabled = (bool) maintenance_local_login_config('dev_auto_login', true);

// ลำดับการเลือกผู้ใช้: ฟอร์ม POST → `?u=` → `dev_login_name` → คนแรกในตาราง
$requestedName = '';
if ($isPost && isset($_POST['emp_name'])) {
    $requestedName = trim((string) $_POST['emp_name']);
} elseif (isset($_GET['u'])) {
    $requestedName = trim((string) $_GET['u']);
} elseif ($configuredName !== '') {
    $requestedName = $configuredName;
} elseif (count($employees) > 0) {
    $requestedName = (string) $employees[0]['emp_name'];
}

$explicitChoice = ($isPost && isset($_POST['emp_name'])) || (isset($_GET['u']) && trim((string) $_GET['u']) !== '');
$shouldLogin = $explicitChoice || ($autoLoginEnabled && !$cameFromLogout);

$errorMessage = '';
if (count($employees) < 1) {
    $errorMessage = 'ไม่พบข้อมูลในตาราง employee ของฐานข้อมูล '
        . $GLOBALS['MAINTENANCE_NEW_DB_NAME']
        . ' — ตรวจสอบคีย์ database ใน config/env.local.php';
} elseif ($shouldLogin) {
    $employeeRow = maintenance_local_login_find_employee($mysqli, $requestedName);
    if ($employeeRow !== null) {
        mysqli_close($mysqli);
        maintenance_local_login_start_session_for($employeeRow);
        header('Location: ' . $indexUrl);
        exit;
    }
    $errorMessage = 'ไม่พบพนักงานชื่อ "' . $requestedName . '" ในตาราง employee — เลือกจากรายการด้านล่าง';
}

mysqli_close($mysqli);

$h = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$selectedName = $requestedName;
header('Content-type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-login.css" />
<title>ระบบงานซ่อม — เข้าสู่ระบบ</title>
</head>
<body class="mn-addjob-page mn-login-page">
  <div class="content mn-workspace">
    <div class="mn-login-card">
      <div class="mn-login-card__head">
        <div class="mn-login-card__brand">Maintenance</div>
        <div class="mn-login-card__tagline">ระบบจัดการงานซ่อม · ภายในองค์กร</div>
      </div>

      <div class="mn-login-card__body">
        <p class="mn-login-note">
          <i class="fa fa-info-circle" aria-hidden="true"></i>
          เครื่องนี้รันแบบ localhost แต่บังคับตรวจสิทธิ์เหมือน production —
          บนเซิร์ฟเวอร์จริงขั้นตอนนี้คือ LINE Login ผ่าน bitlogin
        </p>

        <?php if ($errorMessage !== '') { ?>
          <p class="mn-login-error"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo $h($errorMessage); ?></p>
        <?php } ?>

        <?php if (count($employees) > 0) { ?>
        <form method="post" action="<?php echo $h($loginUrl); ?>" class="mn-login-form">
          <label class="mn-login-label" for="emp_name">เข้าใช้งานในนามพนักงาน</label>
          <select class="mn-login-select" id="emp_name" name="emp_name">
            <?php foreach ($employees as $employee) {
                $name = (string) $employee['emp_name'];
                $position = trim((string) $employee['emp_position']);
                $label = $position !== '' ? ($name . ' — ' . $position) : $name;
                $isSelected = ($name === $selectedName) ? ' selected="selected"' : '';
            ?>
            <option value="<?php echo $h($name); ?>"<?php echo $isSelected; ?>><?php echo $h($label); ?></option>
            <?php } ?>
          </select>
          <button type="submit" class="mn-login-submit">
            <i class="fa fa-sign-in" aria-hidden="true"></i> เข้าสู่ระบบ
          </button>
        </form>
        <?php } ?>
      </div>

      <div class="mn-login-card__foot">
        ตั้งผู้ใช้ตั้งต้นและปิด/เปิดล็อกอินอัตโนมัติได้ที่คีย์ <code>dev_login_name</code> / <code>dev_auto_login</code>
        ใน <code>config/env.local.php</code>
      </div>
    </div>
  </div>
</body>
</html>
