<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ล็อกหน้าตั้งค่าหลังบ้านด้วยรหัส (PIN) — ใช้กับ settings, checklist_settings(+api ยกเว้น ping), warranty_settings, manage_logistics และตัวบันทึก
 *
 * ที่เก็บรหัส (เก็บเฉพาะ hash จาก password_hash — ไม่เก็บรหัสจริงในโค้ด เพราะ repo เป็น public):
 *  1) cl_setting.cs_key = 'settings_pin_hash'  (เปลี่ยนรหัสจากหน้า settings)
 *  2) config/settings_pin.local.php → return array('hash' => '...')  (ค่าตั้งต้น — gitignored, สคริปต์สร้างชุดทดลองคัดลอกไปให้)
 * ไม่มีทั้งสองที่ = ไม่ล็อก
 *
 * ปลดล็อกแล้วจำใน session 30 นาทีนับจากใช้งานล่าสุด | ใส่ผิด 5 ครั้ง รอ 5 นาที
 */

if (!defined('MN_SETTINGS_UNLOCK_MINUTES')) { define('MN_SETTINGS_UNLOCK_MINUTES', 30); }

if (!function_exists('mn_settings_session')) {
    function mn_settings_session()
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }
        return session_status() === PHP_SESSION_ACTIVE;
    }
}

/** hash ของรหัสที่ใช้อยู่ หรือ '' (ไม่ล็อก) */
if (!function_exists('mn_settings_pin_hash')) {
    function mn_settings_pin_hash($connect)
    {
        if ($connect instanceof mysqli) {
            if (!function_exists('mn_sys_settings_saved')) { require_once __DIR__ . '/system_settings_helpers.php'; }
            $saved = mn_sys_settings_saved($connect);
            if (!empty($saved['settings_pin_hash'][0])) { return (string) $saved['settings_pin_hash'][0]; }
        }
        $file = dirname(__DIR__) . '/config/settings_pin.local.php';
        if (is_file($file)) {
            $cfg = require $file;
            if (is_array($cfg) && !empty($cfg['hash'])) { return (string) $cfg['hash']; }
        }
        return '';
    }
}

if (!function_exists('mn_settings_unlocked')) {
    function mn_settings_unlocked($connect)
    {
        if (mn_settings_pin_hash($connect) === '') { return true; }
        if (!mn_settings_session()) { return false; }
        $at = isset($_SESSION['mn_settings_unlock']) ? (int) $_SESSION['mn_settings_unlock'] : 0;
        if ($at > 0 && time() - $at < MN_SETTINGS_UNLOCK_MINUTES * 60) {
            $_SESSION['mn_settings_unlock'] = time(); // ต่ออายุทุกครั้งที่ใช้งาน
            return true;
        }
        unset($_SESSION['mn_settings_unlock']);
        return false;
    }
}

/** ตรวจรหัส → array(ok, error) */
if (!function_exists('mn_settings_try_pin')) {
    function mn_settings_try_pin($connect, $pin)
    {
        mn_settings_session();
        $fail = isset($_SESSION['mn_settings_fail']) && is_array($_SESSION['mn_settings_fail']) ? $_SESSION['mn_settings_fail'] : array('n' => 0, 'at' => 0);
        if ($fail['n'] >= 5 && time() - $fail['at'] < 300) {
            return array(false, 'ใส่รหัสผิดหลายครั้ง รอ ' . (int) ceil((300 - (time() - $fail['at'])) / 60) . ' นาทีแล้วลองใหม่');
        }
        $hash = mn_settings_pin_hash($connect);
        if ($hash !== '' && password_verify((string) $pin, $hash)) {
            $_SESSION['mn_settings_unlock'] = time();
            unset($_SESSION['mn_settings_fail']);
            return array(true, '');
        }
        $n = ($fail['n'] >= 5) ? 1 : $fail['n'] + 1;
        $_SESSION['mn_settings_fail'] = array('n' => $n, 'at' => time());
        return array(false, 'รหัสไม่ถูกต้อง' . ($n >= 3 && $n < 5 ? ' (เหลือ ' . (5 - $n) . ' ครั้ง)' : ''));
    }
}

if (!function_exists('mn_settings_lock')) {
    function mn_settings_lock()
    {
        if (mn_settings_session()) { unset($_SESSION['mn_settings_unlock']); }
    }
}

/** เปลี่ยนรหัส (ต้องรู้รหัสเดิม) → array(ok, error) */
if (!function_exists('mn_settings_change_pin')) {
    function mn_settings_change_pin($connect, $old, $new, $by)
    {
        $hash = mn_settings_pin_hash($connect);
        if ($hash !== '' && !password_verify((string) $old, $hash)) { return array(false, 'รหัสเดิมไม่ถูกต้อง'); }
        if (!preg_match('/^\d{4,8}$/', (string) $new)) { return array(false, 'รหัสใหม่ต้องเป็นตัวเลข 4–8 หลัก'); }
        if (!function_exists('mn_sys_settings_ensure')) { require_once __DIR__ . '/system_settings_helpers.php'; }
        mn_sys_settings_ensure($connect);
        $stmt = mysqli_prepare($connect,
            'INSERT INTO cl_setting (cs_key, cs_value, cs_by, cs_at) VALUES (\'settings_pin_hash\', ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE cs_value = VALUES(cs_value), cs_by = VALUES(cs_by), cs_at = VALUES(cs_at)');
        if (!$stmt) { return array(false, 'บันทึกรหัสไม่สำเร็จ'); }
        $h = password_hash((string) $new, PASSWORD_DEFAULT);
        $who = mb_substr(trim((string) $by), 0, 100, 'UTF-8');
        $now = date('Y-m-d H:i:s');
        mysqli_stmt_bind_param($stmt, 'sss', $h, $who, $now);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return array((bool) $ok, $ok ? '' : 'บันทึกรหัสไม่สำเร็จ');
    }
}

/**
 * ด่านใส่รหัสของหน้าตั้งค่า — เรียกหลัง require_auth + bootstrap ก่อนส่ง HTML
 * ยังไม่ปลดล็อก: แสดงหน้าใส่รหัส (POST settings_pin มาที่ URL เดิม) แล้ว exit
 * $mode = 'page' | 'json' (API → 403) | 'post' (ตัวบันทึก → กลับไปหน้าเดิมให้ใส่รหัส)
 */
if (!function_exists('mn_settings_gate')) {
    function mn_settings_gate($connect, $mode = 'page')
    {
        $err = '';
        if ($mode === 'page' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_pin'])) {
            list($ok, $err) = mn_settings_try_pin($connect, (string) $_POST['settings_pin']);
            if ($ok) {
                maintenance_db_odbc_close($connect);
                $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : 'settings';
                header('Location: ' . $uri);
                exit;
            }
        }
        if (mn_settings_unlocked($connect)) { return; }
        maintenance_db_odbc_close($connect);
        if ($mode === 'json') {
            while (ob_get_level() > 0) { ob_end_clean(); }
            http_response_code(403);
            header('Content-type: application/json; charset=utf-8');
            echo json_encode(array('ok' => false, 'locked' => true, 'error' => 'หน้าตั้งค่าถูกล็อก ใส่รหัสที่หน้าตั้งค่าระบบก่อน'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($mode === 'post') {
            header('Location: settings');
            exit;
        }
        $page = 'settings';
        header('Content-type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(dirname(__DIR__) . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-settings.css?v=<?php echo filemtime(dirname(__DIR__) . '/css/mn-page-settings.css'); ?>" />
<title>ตั้งค่าระบบ (ล็อก)</title>
</head>
<body class="mn-addjob-page mn-settings-page">
  <div class="header">
    <?php require dirname(__DIR__) . '/includes/title-h.php'; ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace">
    <form class="mn-set-lock" method="post" action="" autocomplete="off">
      <i class="fa fa-lock mn-set-lock__icon" aria-hidden="true"></i>
      <h1>ตั้งค่าระบบหลังบ้าน</h1>
      <p>ใส่รหัสเพื่อเข้าหน้าตั้งค่า</p>
      <?php if ($err !== '') { ?><p class="mn-set-lock__err" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
      <input type="password" name="settings_pin" inputmode="numeric" pattern="[0-9]*" maxlength="8" autofocus="autofocus" aria-label="รหัสเข้าหน้าตั้งค่า" class="mn-set-lock__pin" />
      <button type="submit" class="mn-set__btn is-primary"><i class="fa fa-unlock" aria-hidden="true"></i> เข้าหน้าตั้งค่า</button>
      <p class="mn-set-lock__note">ปลดล็อกแล้วใช้ได้ <?php echo (int) MN_SETTINGS_UNLOCK_MINUTES; ?> นาทีนับจากใช้งานล่าสุด</p>
    </form>
  </div>
</body>
</html>
<?php
        exit;
    }
}
