<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * จัดการการเชื่อมต่อ MySQL แบบ mysqli สำหรับโปรเจกต์ maintenance โดยไม่พึ่ง connect.php
 * ของโค้ดเก่า เพื่อให้ migration ทำทีละหน้าได้ชัดเจน
 *
 * องค์ประกอบหลัก:
 * - โหมด production|development: เลือกจาก `MAINTENANCE_APP_ENV` / `env.local.php` คีย์ `app_env` หรือเดาจาก HTTP host (`config/app_environment.php`)
 * - โหมด production: ถ้าไม่มี `env.local.php` และไม่มี getenv — ใช้ `localhost` / `biton_maintenance` / `CHANGE_ME` / `biton_maintenance` (override ได้จาก env.local / getenv)
 * - โหมด development: default user `root` + ฐาน `maintenance` — ถ้า MySQL ไม่รับ root ให้ตั้งคีย์ `database` ใน env.local หรือให้ host ถูกเดาเป็น production
 * - ฟังก์ชัน `maintenance_new_db_connect()` สำหรับเปิด connection
 * - ฟังก์ชัน `maintenance_new_db_fail()` สำหรับแจ้ง error แล้วจบ request
 *
 * Flow การใช้งานแบบสั้น:
 * 1) ตั้ง `MAINTENANCE_APP_ENV` หรือ `MAINTENANCE_NEW_APP_ENV` หรือสร้าง `config/env.local.php` จาก env.local.example.php
 * 2) require ไฟล์นี้จากหน้า PHP ในโปรเจกต์ maintenance
 * 3) เรียก `$mysqli = maintenance_new_db_connect();` แล้วใช้ mysqli API โดยตรง
 *
 * Example:
 * require __DIR__ . '/config/database.php';
 * $mysqli = maintenance_new_db_connect();
 * $res = mysqli_query($mysqli, "SELECT 1");
 */

require_once __DIR__ . '/app_environment.php';

// ─────────────────────────────────────────────────────────────────
// constants (ค่าเริ่มต้นตามสภาพแวดล้อม — override ได้จาก env.local / getenv)
// ─────────────────────────────────────────────────────────────────
$local = maintenance_new_env_local_config();
$dbLocal = isset($local['database']) && is_array($local['database']) ? $local['database'] : array();

if (maintenance_new_app_env_is_production()) {
    $GLOBALS['MAINTENANCE_NEW_DB_HOST'] = isset($dbLocal['host']) ? (string) $dbLocal['host'] : (getenv('MAINTENANCE_DB_HOST') ?: getenv('MAINTENANCE_NEW_DB_HOST') ?: 'localhost');
    $GLOBALS['MAINTENANCE_NEW_DB_USER'] = isset($dbLocal['user']) ? (string) $dbLocal['user'] : (getenv('MAINTENANCE_DB_USER') ?: getenv('MAINTENANCE_NEW_DB_USER') ?: 'biton_maintenance');
    $GLOBALS['MAINTENANCE_NEW_DB_PASSWORD'] = isset($dbLocal['password']) ? (string) $dbLocal['password'] : (getenv('MAINTENANCE_DB_PASSWORD') ?: getenv('MAINTENANCE_NEW_DB_PASSWORD') ?: '');
    $GLOBALS['MAINTENANCE_NEW_DB_NAME'] = isset($dbLocal['name']) ? (string) $dbLocal['name'] : (getenv('MAINTENANCE_DB_NAME') ?: getenv('MAINTENANCE_NEW_DB_NAME') ?: 'biton_maintenance');
    $GLOBALS['MAINTENANCE_NEW_DB_PORT'] = isset($dbLocal['port']) ? (int) $dbLocal['port'] : (int) (getenv('MAINTENANCE_DB_PORT') ?: getenv('MAINTENANCE_NEW_DB_PORT') ?: 3306);
    $GLOBALS['MAINTENANCE_NEW_DB_CHARSET'] = isset($dbLocal['charset']) ? (string) $dbLocal['charset'] : (getenv('MAINTENANCE_DB_CHARSET') ?: getenv('MAINTENANCE_NEW_DB_CHARSET') ?: 'utf8mb4');
} else {
    $GLOBALS['MAINTENANCE_NEW_DB_HOST'] = isset($dbLocal['host']) ? (string) $dbLocal['host'] : (getenv('MAINTENANCE_DB_HOST') ?: getenv('MAINTENANCE_NEW_DB_HOST') ?: 'localhost');
    $GLOBALS['MAINTENANCE_NEW_DB_USER'] = isset($dbLocal['user']) ? (string) $dbLocal['user'] : (getenv('MAINTENANCE_DB_USER') ?: getenv('MAINTENANCE_NEW_DB_USER') ?: 'root');
    $GLOBALS['MAINTENANCE_NEW_DB_PASSWORD'] = isset($dbLocal['password']) ? (string) $dbLocal['password'] : (getenv('MAINTENANCE_DB_PASSWORD') ?: getenv('MAINTENANCE_NEW_DB_PASSWORD') ?: '');
    $GLOBALS['MAINTENANCE_NEW_DB_NAME'] = isset($dbLocal['name']) ? (string) $dbLocal['name'] : (getenv('MAINTENANCE_DB_NAME') ?: getenv('MAINTENANCE_NEW_DB_NAME') ?: 'maintenance');
    $GLOBALS['MAINTENANCE_NEW_DB_PORT'] = isset($dbLocal['port']) ? (int) $dbLocal['port'] : (int) (getenv('MAINTENANCE_DB_PORT') ?: getenv('MAINTENANCE_NEW_DB_PORT') ?: 3306);
    $GLOBALS['MAINTENANCE_NEW_DB_CHARSET'] = isset($dbLocal['charset']) ? (string) $dbLocal['charset'] : (getenv('MAINTENANCE_DB_CHARSET') ?: getenv('MAINTENANCE_NEW_DB_CHARSET') ?: 'utf8mb4');
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_db_fail')) {
    /**
     * Purpose:
     * แสดงข้อความ error ของฐานข้อมูลแล้ว exit เพื่อไม่ให้หน้า render ต่อด้วย state ไม่สมบูรณ์
     *
     * Parameters สำคัญ:
     * - string $message: ข้อความที่ผู้ดูแลระบบใช้ไล่ปัญหา
     *
     * Return value:
     * - ไม่ return (exit)
     */
    function maintenance_new_db_fail($message)
    {
        exit($message);
    }
}

if (!function_exists('maintenance_new_db_connect')) {
    /**
     * Purpose:
     * เปิดการเชื่อมต่อ MySQL ด้วย mysqli และตั้ง charset ก่อนรัน query
     *
     * Parameters สำคัญ:
     * - ไม่มีพารามิเตอร์ (อ่านค่าจาก global constants ด้านบน)
     *
     * Return value:
     * - mysqli instance เมื่อเชื่อมต่อสำเร็จ
     */
    function maintenance_new_db_connect()
    {
        if (maintenance_new_app_env_is_production()) {
            if ($GLOBALS['MAINTENANCE_NEW_DB_USER'] === '' || $GLOBALS['MAINTENANCE_NEW_DB_NAME'] === '') {
                maintenance_new_db_fail('production: กำหนด user/name ของฐานข้อมูลใน config/env.local.php หรือ getenv MAINTENANCE_DB_* / MAINTENANCE_NEW_DB_*');
            }
        }

        if (!function_exists('mysqli_connect')) {
            maintenance_new_db_fail('ไม่พบ mysqli extension กรุณาเปิดใช้งาน php_mysqli ใน php.ini');
        }

        $host = $GLOBALS['MAINTENANCE_NEW_DB_HOST'];
        $user = $GLOBALS['MAINTENANCE_NEW_DB_USER'];
        $password = $GLOBALS['MAINTENANCE_NEW_DB_PASSWORD'];
        $database = $GLOBALS['MAINTENANCE_NEW_DB_NAME'];
        $port = (int) $GLOBALS['MAINTENANCE_NEW_DB_PORT'];
        $charset = $GLOBALS['MAINTENANCE_NEW_DB_CHARSET'];

        $mysqli = mysqli_connect($host, $user, $password, $database, $port);
        if (!$mysqli) {
            $envLabel = maintenance_new_app_env_is_production() ? 'production' : 'development';
            $diag = ' [app_env=' . $envLabel . ' host=' . $host . ' user=' . $user . ' database=' . $database . ']';
            $guide = ' ตรวจสอบ config/env.local.php (คีย์ database.user/password/name) '
                . 'หรือ MAINTENANCE_DB_* / MAINTENANCE_APP_ENV — '
                . 'โหมดแอปเลือกจาก getenv / app_env / เดา host (localhost=development); '
                . 'ถ้า user เป็น root แต่ MySQL ปฏิเสธ ให้ใส่ database ใน env.local ให้ตรงบัญชีจริง หรือเปิดเข้าผ่านโดเมนจริงเพื่อให้เป็น production';
            maintenance_new_db_fail('เชื่อมต่อ MySQL ไม่สำเร็จ: ' . mysqli_connect_error() . $diag . ' —' . $guide);
        }

        if (!mysqli_set_charset($mysqli, $charset)) {
            maintenance_new_db_fail('ตั้งค่า charset ไม่สำเร็จ: ' . mysqli_error($mysqli));
        }

        return $mysqli;
    }
}
