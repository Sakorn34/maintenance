<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ค้นหาตำบล/อำเภอ/จังหวัด/รหัสไปรษณีย์จากตาราง MySQL `address_thailand` สำหรับ autocomplete ช่องที่อยู่บน add_job
 * แทนการอ่าน maintenance.mdb ผ่าน ODBC
 *
 * องค์ประกอบหลัก:
 * - `maintenance_new_address_search_mysqli()` เปิด mysqli (override ได้ด้วยคีย์ `address_mysql` ใน env.local)
 * - `maintenance_new_mysql_search_address_thailand_lines()` คืน array ข้อความแสดงผลสูงสุด 4 แถว
 *
 * Flow:
 * search_address.php require ไฟล์นี้แล้วเรียกฟังก์ชันค้นหา (ชื่อ prefix `maintenance_new_*` คงไว้เพื่อ backward compatibility)
 */

require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/../config/database.php';

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────
if (!defined('MAINTENANCE_NEW_ADDRESS_THAILAND_TABLE')) {
    define('MAINTENANCE_NEW_ADDRESS_THAILAND_TABLE', 'address_thailand');
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_mysql_address_like_pattern')) {
    /**
     * Purpose:
     * สร้าง pattern สำหรับ LIKE โดย escape \ % _
     *
     * Parameters สำคัญ:
     * - string $term: คำค้น
     *
     * Return value:
     * - string
     */
    function maintenance_new_mysql_address_like_pattern($term)
    {
        $t = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $term);

        return '%' . $t . '%';
    }
}

if (!function_exists('maintenance_new_address_search_mysqli')) {
    /**
     * Purpose:
 * เปิด mysqli สำหรับอ่านตารางที่อยู่ — ถ้ามี `address_mysql` ใน env.local ใช้ค่านั้น ไม่เช่นนั้นใช้ `maintenance_new_db_connect()` ชุดเดียวกับแอป
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - mysqli|false
     */
    function maintenance_new_address_search_mysqli()
    {
        if (!function_exists('mysqli_connect')) {
            return false;
        }
        $local = maintenance_new_env_local_config();
        if (!empty($local['address_mysql']) && is_array($local['address_mysql'])) {
            $b = $local['address_mysql'];
            $user = isset($b['user']) ? (string) $b['user'] : '';
            $name = isset($b['name']) ? (string) $b['name'] : '';
            if ($user !== '' && $name !== '') {
                $host = isset($b['host']) ? (string) $b['host'] : 'localhost';
                $password = isset($b['password']) ? (string) $b['password'] : '';
                $port = isset($b['port']) ? (int) $b['port'] : 3306;
                $charset = isset($b['charset']) ? (string) $b['charset'] : 'utf8mb4';
                $mysqli = @mysqli_connect($host, $user, $password, $name, $port);
                if (!$mysqli) {
                    return false;
                }
                if (!mysqli_set_charset($mysqli, $charset)) {
                    mysqli_close($mysqli);
                    return false;
                }

                return $mysqli;
            }
        }

        return maintenance_new_db_connect();
    }
}

if (!function_exists('maintenance_new_mysql_search_address_thailand_lines')) {
    /**
     * Purpose:
     * ค้นหาแถวที่ `at_TambonTH` LIKE คำค้น จากตาราง `address_thailand` สูงสุด 4 แถว คืนข้อความ "ตำบล อำเภอ จังหวัด รหัสไปรษณีย์"
     *
     * Parameters สำคัญ:
     * - string $term: คำค้น (เช่น จาก $_REQUEST['term'])
     *
     * Return value:
     * - array ของ string UTF-8
     */
    function maintenance_new_mysql_search_address_thailand_lines($term)
    {
        $lines = array();
        $mysqli = maintenance_new_address_search_mysqli();
        if ($mysqli === false) {
            return $lines;
        }
        $pattern = maintenance_new_mysql_address_like_pattern($term);
        $tbl = MAINTENANCE_NEW_ADDRESS_THAILAND_TABLE;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tbl)) {
            $tbl = 'address_thailand';
        }
        $sql = 'SELECT at_TambonTH, at_DistrictTH, at_ProvinceTH, at_Post FROM `' . $tbl . '` WHERE at_TambonTH LIKE ? LIMIT 4';
        $stmt = mysqli_prepare($mysqli, $sql);
        if ($stmt === false) {
            mysqli_close($mysqli);
            return $lines;
        }
        mysqli_stmt_bind_param($stmt, 's', $pattern);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $tambon, $district, $province, $post);
        while (mysqli_stmt_fetch($stmt)) {
            $parts = array();
            if ($tambon !== null && $tambon !== '') {
                $parts[] = 'ตำบล ' . trim((string) $tambon);
            }
            if ($district !== null && $district !== '') {
                $parts[] = trim((string) $district);
            }
            if ($province !== null && $province !== '') {
                $parts[] = trim((string) $province);
            }
            if ($post !== null && $post !== '') {
                $parts[] = trim((string) $post);
            }
            $line = trim(implode(' ', $parts));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        mysqli_stmt_close($stmt);
        mysqli_close($mysqli);

        return $lines;
    }
}
