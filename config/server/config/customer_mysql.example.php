<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * กำหนดการเชื่อมต่อ MySQL แยกสำหรับค้นหาลูกค้า (`tbl_customer` ใน `biton_leasing`) ตาม production / development
 * override ได้จาก getenv หรือคีย์ `customer_mysql` ใน `config/env.local.php`
 *
 * องค์ประกอบหลัก:
 * - `maintenance_new_customer_mysql_config_row()` คืนค่า host/user/password/name/port/charset
 * - `maintenance_new_customer_mysqli_connect()` เปิด mysqli หรือ false เมื่อล้มเหลว
 *
 * Flow:
 * `includes/mysql_customer_search.php` require ไฟล์นี้แล้วเรียก connect ก่อนรัน SELECT
 */

require_once __DIR__ . '/app_environment.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_customer_mysql_config_row')) {
    /**
     * Purpose:
     * รวมค่าเชื่อมต่อฐานลูกค้า (development: root + biton_leasing; production: biton_leasing user)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array คีย์ host, user, password, name, port, charset
     */
    function maintenance_new_customer_mysql_config_row()
    {
        $local = maintenance_new_env_local_config();
        $block = (isset($local['customer_mysql']) && is_array($local['customer_mysql'])) ? $local['customer_mysql'] : array();
        if (maintenance_new_app_env_is_production()) {
            return array(
                'host' => isset($block['host']) ? (string) $block['host'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_HOST') ?: 'localhost'),
                'user' => isset($block['user']) ? (string) $block['user'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_USER') ?: 'biton_leasing'),
                'password' => isset($block['password']) ? (string) $block['password'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_PASSWORD') ?: ''),
                'name' => isset($block['name']) ? (string) $block['name'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_NAME') ?: 'biton_leasing'),
                'port' => isset($block['port']) ? (int) $block['port'] : (int) (getenv('MAINTENANCE_NEW_CUSTOMER_DB_PORT') ?: 3306),
                'charset' => isset($block['charset']) ? (string) $block['charset'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_CHARSET') ?: 'utf8mb4'),
            );
        }

        return array(
            'host' => isset($block['host']) ? (string) $block['host'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_HOST') ?: 'localhost'),
            'user' => isset($block['user']) ? (string) $block['user'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_USER') ?: 'root'),
            'password' => isset($block['password']) ? (string) $block['password'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_PASSWORD') ?: ''),
            'name' => isset($block['name']) ? (string) $block['name'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_NAME') ?: 'biton_leasing'),
            'port' => isset($block['port']) ? (int) $block['port'] : (int) (getenv('MAINTENANCE_NEW_CUSTOMER_DB_PORT') ?: 3306),
            'charset' => isset($block['charset']) ? (string) $block['charset'] : (getenv('MAINTENANCE_NEW_CUSTOMER_DB_CHARSET') ?: 'utf8mb4'),
        );
    }
}

if (!function_exists('maintenance_new_customer_mysqli_connect')) {
    /**
     * Purpose:
     * เปิด mysqli ไปยังฐานข้อมูลลูกค้า (biton_leasing)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - mysqli|false
     */
    function maintenance_new_customer_mysqli_connect()
    {
        if (!function_exists('mysqli_connect')) {
            return false;
        }
        $c = maintenance_new_customer_mysql_config_row();
        $mysqli = @mysqli_connect($c['host'], $c['user'], $c['password'], $c['name'], $c['port']);
        if (!$mysqli) {
            return false;
        }
        if (!mysqli_set_charset($mysqli, $c['charset'])) {
            mysqli_close($mysqli);
            return false;
        }

        return $mysqli;
    }
}
