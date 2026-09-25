<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เปิด MySQL ด้วย mysqli แล้วตั้งตัวแปร `$connect` และ API แบบ ODBC-like สำหรับโค้ด legacy
 *
 * หมายเหตุสำคัญ:
 * ถ้า PHP โหลด `ext/odbc` อยู่ ฟังก์ชัน `odbc_exec` ฯลฯ จะเป็นของระบบ (คาด resource ODBC)
 * ไม่สามารถ override ด้วย userland ได้ — โค้ดในโปรเจกต์จึงต้องเรียก `maintenance_db_odbc_*` กับ `$connect` แบบ mysqli
 * บนเครื่องที่ไม่มี ext/odbc ไฟล์นี้จะประกาศ `odbc_*` เป็น alias ไปที่ `maintenance_db_odbc_*` เพื่อความเข้ากันได้แบบเดิม
 *
 * Flow:
 * require_once ไฟล์นี้ → ได้ `$connect` (mysqli) และฟังก์ชัน `maintenance_db_odbc_*` (และอาจมี `odbc_*` alias)
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/database.php';

if (!function_exists('maintenance_sql_to_utf8')) {
    /**
     * Purpose:
     * แปลง query string ให้เป็น UTF-8 ก่อนส่งเข้า MySQL (เทียบเท่า connect.php)
     *
     * Parameters สำคัญ:
     * - string $query: คำสั่ง SQL ต้นฉบับ
     *
     * Return value:
     * - string query ที่พร้อมส่ง mysqli
     */
    function maintenance_sql_to_utf8($query)
    {
        if (!is_string($query) || $query === '') {
            return $query;
        }

        if (preg_match('//u', $query)) {
            return $query;
        }

        $converted = @iconv('TIS-620', 'UTF-8//IGNORE', $query);
        return $converted !== false ? $converted : $query;
    }
}

// ─────────────────────────────────────────────────────────────────
// mysqli ODBC-like API (ใช้ชื่อไม่ชนกับ ext/odbc ของ PHP)
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_db_odbc_exec')) {
    /**
     * Purpose:
     * รัน SQL บน mysqli (ทดแทนการเรียก odbc_exec เมื่อใช้ `$connect` จากไฟล์นี้)
     *
     * Parameters สำคัญ:
     * - mysqli $connection: connection
     * - string $query: SQL
     *
     * Return value:
     * - mysqli_result|bool
     */
    function maintenance_db_odbc_exec($connection, $query)
    {
        $query = maintenance_sql_to_utf8($query);
        return mysqli_query($connection, $query);
    }

    /**
     * Purpose:
     * อ่านแถวจาก result set (mysqli_result)
     *
     * Parameters สำคัญ:
     * - mysqli_result|bool $result: ผลจาก maintenance_db_odbc_exec
     *
     * Return value:
     * - array|false
     */
    function maintenance_db_odbc_fetch_array($result)
    {
        return $result ? mysqli_fetch_array($result, MYSQLI_BOTH) : false;
    }

    /**
     * Purpose:
     * ปิด connection mysqli
     *
     * Parameters สำคัญ:
     * - mysqli $connection: connection
     *
     * Return value:
     * - bool
     */
    function maintenance_db_odbc_close($connection)
    {
        return mysqli_close($connection);
    }

    /**
     * Purpose:
     * คืนรหัส error ล่าสุดของ connection
     *
     * Parameters สำคัญ:
     * - mysqli|null $connection: connection (optional)
     *
     * Return value:
     * - string
     */
    function maintenance_db_odbc_error($connection = null)
    {
        if (!$connection) {
            return '';
        }

        $errno = mysqli_errno($connection);
        return $errno ? (string) $errno : '';
    }

    /**
     * Purpose:
     * คืนข้อความ error ล่าสุด
     *
     * Parameters สำคัญ:
     * - mysqli|null $connection: connection (optional)
     *
     * Return value:
     * - string
     */
    function maintenance_db_odbc_errormsg($connection = null)
    {
        if (!$connection) {
            return mysqli_connect_error();
        }

        return mysqli_error($connection);
    }

    /**
     * Purpose:
     * commit transaction
     *
     * Parameters สำคัญ:
     * - mysqli $connection: connection
     *
     * Return value:
     * - bool
     */
    function maintenance_db_odbc_commit($connection)
    {
        return mysqli_commit($connection);
    }

    /**
     * Purpose:
     * rollback transaction
     *
     * Parameters สำคัญ:
     * - mysqli $connection: connection
     *
     * Return value:
     * - bool
     */
    function maintenance_db_odbc_rollback($connection)
    {
        return mysqli_rollback($connection);
    }

    /**
     * Purpose:
     * เคลียร์ result set
     *
     * Parameters สำคัญ:
     * - mysqli_result|bool $result: result
     *
     * Return value:
     * - bool
     */
    function maintenance_db_odbc_free_result($result)
    {
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
        return true;
    }
}

// ─────────────────────────────────────────────────────────────────
// alias `odbc_*` เมื่อ PHP ไม่มี built-in (ไม่ชน ext/odbc)
// ─────────────────────────────────────────────────────────────────
if (!function_exists('odbc_exec')) {
    function odbc_exec($connection, $query)
    {
        return maintenance_db_odbc_exec($connection, $query);
    }
}

if (!function_exists('odbc_fetch_array')) {
    function odbc_fetch_array($result)
    {
        return maintenance_db_odbc_fetch_array($result);
    }
}

if (!function_exists('odbc_close')) {
    function odbc_close($connection)
    {
        return maintenance_db_odbc_close($connection);
    }
}

if (!function_exists('odbc_error')) {
    function odbc_error($connection = null)
    {
        return maintenance_db_odbc_error($connection);
    }
}

if (!function_exists('odbc_errormsg')) {
    function odbc_errormsg($connection = null)
    {
        return maintenance_db_odbc_errormsg($connection);
    }
}

if (!function_exists('odbc_commit')) {
    function odbc_commit($connection)
    {
        return maintenance_db_odbc_commit($connection);
    }
}

if (!function_exists('odbc_rollback')) {
    function odbc_rollback($connection)
    {
        return maintenance_db_odbc_rollback($connection);
    }
}

if (!function_exists('odbc_free_result')) {
    function odbc_free_result($result)
    {
        return maintenance_db_odbc_free_result($result);
    }
}

$connect = maintenance_new_db_connect();
