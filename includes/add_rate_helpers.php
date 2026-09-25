<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ดึงข้อมูล `Rate` สำหรับหน้า `add_rate` — ชื่อ product แบบไม่ซ้ำ และรายการ `r_rate` ต่อ product ด้วย prepared statement
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_add_rate_tis620_to_utf8')) {
    /**
     * Purpose:
     * คืนข้อความสำหรับแสดงคอลัมน์ `r_rate` — ถ้าเป็น UTF-8 อยู่แล้วไม่แปลงซ้ำ; ถ้าไม่ใช่ลองจาก TIS-620 (legacy)
     *
     * Parameters สำคัญ:
     * - string $value: ข้อความจากฐานข้อมูล
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_add_rate_tis620_to_utf8($value)
    {
        $s = (string) $value;
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_check_encoding')) {
            if (mb_check_encoding($s, 'UTF-8')) {
                return $s;
            }
        } else {
            return $s;
        }
        $out = @iconv('TIS-620', 'UTF-8//IGNORE', $s);

        return ($out !== false && $out !== '') ? $out : $s;
    }
}

if (!function_exists('maintenance_add_rate_fetch_distinct_products')) {
    /**
     * Purpose:
     * รายการ `r_product` ไม่ซ้ำเรียงตามชื่อ (ใช้ใน dropdown)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของ string
     */
    function maintenance_add_rate_fetch_distinct_products($connect)
    {
        $sql = 'SELECT DISTINCT r_product FROM rate ORDER BY r_product ASC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $out = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (isset($row['r_product']) && $row['r_product'] !== '') {
                $out[] = (string) $row['r_product'];
            }
        }

        return $out;
    }
}

if (!function_exists('maintenance_add_rate_fetch_products_by_count')) {
    /**
     * Purpose:
     * รายการ `r_product` จัดกลุ่มเรียงตามจำนวนแถวน้อยไปมาก (ตารางสรุปด้านล่าง)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของ string
     */
    function maintenance_add_rate_fetch_products_by_count($connect)
    {
        $sql = 'SELECT r_product FROM rate GROUP BY r_product ORDER BY COUNT(*) ASC, r_product ASC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $out = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (isset($row['r_product']) && $row['r_product'] !== '') {
                $out[] = (string) $row['r_product'];
            }
        }

        return $out;
    }
}

if (!function_exists('maintenance_add_rate_fetch_rates_for_product')) {
    /**
     * Purpose:
     * ดึงค่า `r_rate` ทั้งหมดของ product หนึ่งรายการ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $product: ชื่อ product
     *
     * Return value:
     * - array ของ string (พร้อมแสดง UTF-8 — ผ่าน `maintenance_add_rate_tis620_to_utf8` เมื่อจำเป็น)
     */
    function maintenance_add_rate_fetch_rates_for_product($connect, $product)
    {
        $sql = 'SELECT r_rate FROM rate WHERE r_product = ? ORDER BY r_rate ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 's', $product);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $lines = array();
        if ($res !== false) {
            while ($row = mysqli_fetch_assoc($res)) {
                $lines[] = maintenance_add_rate_tis620_to_utf8(isset($row['r_rate']) ? $row['r_rate'] : '');
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $lines;
    }
}
