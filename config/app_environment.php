<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * กำหนดสภาพแวดล้อม `development` | `production` และค่า redirect หลัง session หมดอายุ
 * ลำดับ: getenv → `env.local.php` คีย์ `app_env` (ยกเว้นค่า `auto` หรือว่าง) → เดาแบบอัตโนมัติจาก host ของ request (localhost / LAN = development, โดเมนจริง = production)
 *
 * องค์ประกอบหลัก:
 * - `maintenance_new_env_local_config()` โหลด config จาก env.local ครั้งเดียว (prefix ฟังก์ชันคงไว้เพื่อ backward compatibility)
 * - `maintenance_new_guess_app_env_from_runtime()` เดาโหมดจาก HTTP host เมื่อไม่ได้ตั้งค่าแน่ชัด
 * - ค่าคงที่ `MAINTENANCE_APP_ENV` (และ `MAINTENANCE_NEW_APP_ENV` ค่าเดียวกัน) สำหรับอ่านสภาพแวดล้อม
 * - `maintenance_new_maintenance_web_base()` / `maintenance_new_maintenance_index_url()` ลิงก์รากแอปแบบไม่มี .php
 * - `maintenance_new_auth_login_url()` URL สำหรับ header Location เมื่อไม่มี profile (default หน้า BIT login บน bit-online.net)
 *
 * Flow:
 * database.php และ auth_guard.php require ไฟล์นี้ก่อน — ห้าม require database จากไฟล์นี้ (กันวงจร)
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_guess_app_env_from_runtime')) {
    /**
     * Purpose:
     * เดา `development` | `production` จาก host ของ HTTP request (เมื่อไม่ได้ตั้ง MAINTENANCE_APP_ENV / app_env ชัดเจน)
     *
     * Parameters สำคัญ:
     * - ไม่มี (อ่านจาก `$_SERVER` และ `PHP_SAPI`)
     *
     * Return value:
     * - string `'development'` หรือ `'production'`
     */
    function maintenance_new_guess_app_env_from_runtime()
    {
        if (PHP_SAPI === 'cli') {
            return 'development';
        }
        $host = '';
        if (!empty($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $host = strtolower(trim($_SERVER['HTTP_HOST']));
            $host = preg_replace('/:\d+$/', '', $host);
        } elseif (!empty($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) {
            $host = strtolower(trim($_SERVER['SERVER_NAME']));
        }
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return 'development';
        }
        $len = strlen($host);
        if ($len >= 6 && substr($host, -6) === '.local') {
            return 'development';
        }
        if ($len >= 5 && substr($host, -5) === '.test') {
            return 'development';
        }
        if (preg_match('/^192\.168\.\d+\.\d+$/', $host)
            || preg_match('/^10\.\d+\.\d+\.\d+$/', $host)
            || preg_match('/^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/', $host)
        ) {
            return 'development';
        }
        return 'production';
    }
}
if (!function_exists('maintenance_new_env_local_config')) {
    /**
     * Purpose:
     * โหลด `config/env.local.php` ถ้ามี (คืน array ว่างถ้าไม่มีหรือไฟล์ไม่คืน array)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array คีย์เช่น app_env, auth_login_url, maintenance_web_base, customer_mysql, address_mysql, database => [...] — redirect login อ่านจาก auth_login_url หรือ getenv `MAINTENANCE_NEW_AUTH_LOGIN_URL` / `MAINTENANCE_AUTH_LOGIN_URL` ก่อนค่า default ในโค้ด
     */
    function maintenance_new_env_local_config()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = array();
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'env.local.php';
        if (!is_readable($path)) {
            return $cached;
        }
        $loaded = include $path;
        if (is_array($loaded)) {
            $cached = $loaded;
        }
        return $cached;
    }
}

if (!function_exists('maintenance_new_resolve_app_env')) {
    /**
 * Purpose:
 * คืนค่า 'production' หรือ 'development' — ลำดับ: getenv → env.local `app_env` (รองรับ `auto`) → เดาจาก host
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string 'production' | 'development'
     */
    function maintenance_new_resolve_app_env()
    {
        $fromEnv = getenv('MAINTENANCE_APP_ENV');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return strtolower($fromEnv) === 'production' ? 'production' : 'development';
        }
        $fromEnv = getenv('MAINTENANCE_NEW_APP_ENV');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return strtolower($fromEnv) === 'production' ? 'production' : 'development';
        }
        $local = maintenance_new_env_local_config();
        if (!empty($local['app_env']) && is_string($local['app_env'])) {
            $raw = strtolower(trim($local['app_env']));
            if ($raw === 'auto') {
                return maintenance_new_guess_app_env_from_runtime();
            }
            if ($raw === 'production') {
                return 'production';
            }
            if ($raw === 'development') {
                return 'development';
            }
        }
        return maintenance_new_guess_app_env_from_runtime();
    }
}

if (!defined('MAINTENANCE_NEW_APP_ENV')) {
    define('MAINTENANCE_NEW_APP_ENV', maintenance_new_resolve_app_env());
}

if (!defined('MAINTENANCE_APP_ENV')) {
    define('MAINTENANCE_APP_ENV', MAINTENANCE_NEW_APP_ENV);
}

if (!function_exists('maintenance_new_app_env_is_production')) {
    /**
     * Purpose:
     * ตรวจว่าเป็น production หรือไม่ (ใช้เลือก credential DB)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - bool
     */
    function maintenance_new_app_env_is_production()
    {
        return MAINTENANCE_NEW_APP_ENV === 'production';
    }
}

if (!function_exists('maintenance_new_auth_login_url')) {
    /**
     * Purpose:
     * URL หน้า login เมื่อ session ไม่มี profile / หมดอายุ — ใช้กับ `header('Location: ...')` ใน auth_guard
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string absolute URL หรือ path สำหรับ header Location
     */
    function maintenance_new_auth_login_url()
    {
        $fromEnv = getenv('MAINTENANCE_NEW_AUTH_LOGIN_URL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }
        $fromEnv = getenv('MAINTENANCE_AUTH_LOGIN_URL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }
        $local = maintenance_new_env_local_config();
        if (!empty($local['auth_login_url']) && is_string($local['auth_login_url'])) {
            return $local['auth_login_url'];
        }
        return 'https://bit-online.net/bitlogin/index.php';
    }
}

if (!function_exists('maintenance_new_maintenance_web_base')) {
    /**
     * Purpose:
     * path รากของแอป maintenance เดิม (ใช้ประกอบลิงก์เมนู / กลับหน้าแรก) แบบไม่มีนามสกุลไฟล์ — อาศัย .htaccess ที่รากโปรเจกต์ maintenance
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string เช่น `/maintenance` (ไม่มี slash ท้าย)
     */
    function maintenance_new_maintenance_web_base()
    {
        $fromEnv = getenv('MAINTENANCE_NEW_MAINTENANCE_WEB_BASE');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }
        $local = maintenance_new_env_local_config();
        if (!empty($local['maintenance_web_base']) && is_string($local['maintenance_web_base'])) {
            return rtrim($local['maintenance_web_base'], '/');
        }
        return '/maintenance';
    }
}

if (!function_exists('maintenance_new_maintenance_index_url')) {
    /**
     * Purpose:
     * URL หน้าแรกระบบ maintenance เดิม (index ไม่มี .php)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string เช่น `/maintenance/index`
     */
    function maintenance_new_maintenance_index_url()
    {
        return maintenance_new_maintenance_web_base() . '/index';
    }
}

if (!function_exists('maintenance_new_tracking_public_base_url')) {
    /**
     * Purpose:
     * URL ฐานของแอป maintenancetracking (ไม่มี / ท้าย) สำหรับสร้างลิงก์จากหน้า maintenance ภายใน
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string เช่น `https://bit-online.net/maintenancetracking` หรือ `/maintenance/maintenancetracking`
     */
    function maintenance_new_tracking_public_base_url()
    {
        $fromEnv = getenv('MAINTENANCE_TRACKING_PUBLIC_BASE_URL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }
        $local = maintenance_new_env_local_config();
        if (!empty($local['tracking_public_base_url']) && is_string($local['tracking_public_base_url'])) {
            return rtrim($local['tracking_public_base_url'], '/');
        }
        if (maintenance_new_app_env_is_production()) {
            return 'https://bit-online.net/maintenancetracking';
        }
        $webBase = maintenance_new_maintenance_web_base();

        return rtrim($webBase, '/') . '/maintenancetracking';
    }
}

if (!function_exists('maintenance_new_inventory_web_base')) {
    /**
     * Purpose:
     * path รากของแอป inventory สำหรับลิงก์เบิกอะไหล่จาก maintenance
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string เช่น `/inventory` (ไม่มี slash ท้าย)
     */
    function maintenance_new_inventory_web_base()
    {
        $fromEnv = getenv('MAINTENANCE_INVENTORY_WEB_BASE');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }
        $local = maintenance_new_env_local_config();
        if (!empty($local['inventory_web_base']) && is_string($local['inventory_web_base'])) {
            return rtrim($local['inventory_web_base'], '/');
        }
        if (maintenance_new_app_env_is_production()) {
            return 'https://bit-online.net/inventory';
        }

        return '/inventory';
    }
}
