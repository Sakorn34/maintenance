<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ตัวช่วยของหน้า `login.php` / `logout.php` สำหรับรันระบบบน localhost ด้วยหลักการเดียวกับ production
 *
 * production ล็อกอินผ่าน bitlogin (LINE Login) → `bitlogin/examine.php` จับคู่ LINE `sub` กับ `tbl_employee`
 * แล้วสร้าง `$_SESSION['profile']` เป็น object คีย์ access_token / refresh_token / name / picture / email /
 * sub / aud / iat / nonce / amr / login_name — จากนั้นทุกหน้าใน maintenance ตรวจ session ผ่าน `auth_guard.php`
 * และอ่านชื่อผู้ทำรายการจาก `login_name` (`includes/session_profile.php`)
 *
 * บน localhost ใช้ flow นั้นไม่ได้เพราะ `redirect_uri` ของ LINE ผูกกับ `https://bit-online.net/bitlogin/callback.php`
 * ไฟล์นี้จึงประกอบ profile คีย์ชุดเดียวกันจากตาราง `employee` ในฐานข้อมูลของแอปแทน — ส่วนที่เหลือของระบบ
 * (auth_guard, session_profile, การจับคู่ employee) ทำงานเหมือน production ทุกประการ
 *
 * องค์ประกอบหลัก:
 * - `maintenance_local_login_is_localhost_request()` เปิดใช้ล็อกอินแบบไม่ใช้รหัสเฉพาะเครื่องตัวเอง
 * - `maintenance_local_login_bitlogin_url()` URL bitlogin จริง (ใช้เมื่อถูกเรียกจากโฮสต์ที่ไม่ใช่ localhost)
 * - `maintenance_local_login_config()` อ่านคีย์ `dev_*` จาก `config/env.local.php`
 * - `maintenance_local_login_fetch_employees()` / `maintenance_local_login_find_employee()` อ่านตาราง `employee`
 * - `maintenance_local_login_build_profile()` ประกอบ object รูปแบบเดียวกับ bitlogin
 *
 * Flow:
 * `login.php` require ไฟล์นี้ → ตรวจ localhost → หา employee → `maintenance_local_login_build_profile()` → เก็บลง `$_SESSION['profile']`
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/../config/database.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_local_login_is_localhost_request')) {
    /**
     * Purpose:
     * ตรวจว่าคำขอมาจากเครื่องเดียวกันจริง ๆ — ล็อกอินแบบไม่ใช้รหัสผ่านต้องใช้ได้เฉพาะกรณีนี้เท่านั้น
     * (เข้มกว่าโหมด development ของ `app_environment.php` ที่นับ LAN 192.168.x / 10.x เป็น development ด้วย
     * เพราะเซิร์ฟเวอร์ maintenance ตัวจริงขององค์กรรันบน LAN IP)
     *
     * Parameters สำคัญ:
     * - ไม่มี (อ่านจาก `$_SERVER`)
     *
     * Return value:
     * - bool true เมื่อทั้ง REMOTE_ADDR และ HTTP_HOST เป็น localhost
     */
    function maintenance_local_login_is_localhost_request()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $localIp = in_array($ip, array('127.0.0.1', '::1'), true);
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $localHost = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host);
        $cached = ($localIp && $localHost);

        return $cached;
    }
}

if (!function_exists('maintenance_local_login_config')) {
    /**
     * Purpose:
     * อ่านคีย์ตั้งค่าของหน้า login จาก `config/env.local.php`
     *
     * Parameters สำคัญ:
     * - string $key: ชื่อคีย์ เช่น `dev_login_name`, `dev_auto_login`, `bitlogin_url`
     * - mixed $default: ค่าที่คืนเมื่อไม่พบคีย์
     *
     * Return value:
     * - mixed ค่าจาก env.local หรือ $default
     */
    function maintenance_local_login_config($key, $default = null)
    {
        $local = maintenance_new_env_local_config();
        if (array_key_exists($key, $local)) {
            return $local[$key];
        }

        return $default;
    }
}

if (!function_exists('maintenance_local_login_bitlogin_url')) {
    /**
     * Purpose:
     * URL หน้า login จริงของระบบ (bitlogin / LINE Login) — ใช้เมื่อหน้านี้ถูกเรียกจากโฮสต์ที่ไม่ใช่ localhost
     * เพื่อไม่ให้ล็อกอินแบบไม่ใช้รหัสผ่านหลุดไปใช้บนเซิร์ฟเวอร์จริง
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string absolute URL
     */
    function maintenance_local_login_bitlogin_url()
    {
        $url = maintenance_local_login_config('bitlogin_url', '');
        if (is_string($url) && $url !== '') {
            return $url;
        }

        return 'https://bit-online.net/bitlogin/index.php';
    }
}

if (!function_exists('maintenance_local_login_fetch_employees')) {
    /**
     * Purpose:
     * รายชื่อพนักงานทั้งหมดสำหรับให้เลือกบนหน้า login (และใช้หาคนแรกเป็นค่าตั้งต้น)
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection ที่เปิดแล้ว
     *
     * Return value:
     * - array ของ array คีย์ emp_id, emp_name, emp_position
     */
    function maintenance_local_login_fetch_employees($mysqli)
    {
        $rows = array();
        $sql = "SELECT emp_id, emp_name, emp_position, emp_password"
            . " FROM employee"
            . " WHERE emp_name IS NOT NULL AND TRIM(emp_name) <> ''"
            . " ORDER BY emp_position ASC, emp_name ASC";
        $result = mysqli_query($mysqli, $sql);
        if ($result === false) {
            return $rows;
        }
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);

        return $rows;
    }
}

if (!function_exists('maintenance_local_login_find_employee')) {
    /**
     * Purpose:
     * หาแถว `employee` จาก token ที่ผู้ใช้เลือก — จับคู่ทั้ง `emp_name` และ `emp_password`
     * ให้ตรงกับวิธีที่ระบบใช้ตอน production (`maintenance_new_fetch_employee_names_by_login_token`)
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection ที่เปิดแล้ว
     * - string $token: ชื่อพนักงานหรือรหัสล็อกอิน
     *
     * Return value:
     * - array|null แถว employee (emp_id, emp_name, emp_position, emp_password) หรือ null เมื่อไม่พบ
     */
    function maintenance_local_login_find_employee($mysqli, $token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }
        $sql = "SELECT emp_id, emp_name, emp_position, emp_password"
            . " FROM employee"
            . " WHERE TRIM(emp_name) = TRIM(?)"
            . " OR (emp_password IS NOT NULL AND TRIM(emp_password) <> '' AND TRIM(emp_password) = TRIM(?))"
            . " ORDER BY emp_id ASC LIMIT 1";
        $stmt = mysqli_prepare($mysqli, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $token, $token);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = ($result !== false) ? mysqli_fetch_assoc($result) : null;
        if ($result !== false) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('maintenance_local_login_build_profile')) {
    /**
     * Purpose:
     * ประกอบ `$_SESSION['profile']` ให้มีคีย์ชุดเดียวกับที่ `bitlogin/examine.php` สร้างบน production
     * — `login_name` คือค่าที่ระบบใช้จับคู่ตาราง `employee` และบันทึกเป็นผู้ทำรายการ
     *
     * Parameters สำคัญ:
     * - array $employeeRow: แถวจาก `maintenance_local_login_find_employee()`
     *
     * Return value:
     * - stdClass profile
     */
    function maintenance_local_login_build_profile(array $employeeRow)
    {
        $empName = isset($employeeRow['emp_name']) ? trim((string) $employeeRow['emp_name']) : '';
        $empPassword = isset($employeeRow['emp_password']) ? trim((string) $employeeRow['emp_password']) : '';

        return (object) array(
            // บน production ค่าเหล่านี้มาจาก LINE — localhost ไม่มี token จริงจึงเว้นว่าง
            'access_token' => '',
            'refresh_token' => '',
            'name' => $empName,
            'picture' => '',
            'email' => '',
            // production เก็บ LINE `sub` ไว้ที่ `employee.emp_password` — ใช้ค่าเดียวกันเพื่อให้รูปทรงตรงกัน
            'sub' => $empPassword,
            'aud' => '',
            'iat' => time(),
            'nonce' => '',
            'amr' => '',
            'login_name' => $empName,
        );
    }
}

if (!function_exists('maintenance_local_login_start_session_for')) {
    /**
     * Purpose:
     * เขียน profile ลง session แบบเดียวกับ production พร้อมออก session id ใหม่กัน session fixation
     *
     * Parameters สำคัญ:
     * - array $employeeRow: แถว employee ที่ผ่านการค้นแล้ว
     *
     * Return value:
     * - void
     */
    function maintenance_local_login_start_session_for(array $employeeRow)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION['profile'] = maintenance_local_login_build_profile($employeeRow);
        $_SESSION['sessEmpName'] = isset($employeeRow['emp_name']) ? (string) $employeeRow['emp_name'] : '';
        $_SESSION['sessEmpMode'] = '';
    }
}
