<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ค้นหา `tbl_customer` ผ่าน MySQL (ฐาน biton_leasing) สำหรับ autocomplete บน add_job
 * แทนการอ่าน SaleSystem.mdb — รูปแบบผลลัพธ์ `cus_id#cus_name` ตามที่ระบบต้องการ
 *
 * องค์ประกอบหลัก:
 * - `maintenance_new_mysql_search_customer_enduser_lines()` End user / ผู้ว่าจ้าง (ไม่รวมชื่อรปภ.)
 * - `maintenance_new_mysql_search_customer_security_lines()` บริษัทรปภ.
 *
 * Flow:
 * search_cusname.php / search_cusname_security.php require ไฟล์นี้
 */

require_once __DIR__ . '/../config/customer_mysql.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_mysql_like_pattern')) {
    /**
     * Purpose:
     * สร้าง pattern สำหรับ LIKE โดย escape % _ \ ใน mysqli
     *
     * Parameters สำคัญ:
     * - string $term: คำค้นจากผู้ใช้
     *
     * Return value:
     * - string pattern ที่มี % ครอบ
     */
    function maintenance_new_mysql_like_pattern($term)
    {
        $t = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $term);

        return '%' . $t . '%';
    }
}

if (!function_exists('maintenance_new_mysql_search_customer_enduser_lines')) {
    /**
     * Purpose:
     * ค้นหาลูกค้าแบบไม่รวมบริษัทรปภ. สูงสุด 4 แถว คืนค่าแต่ละแถวเป็น `cus_id#cus_name`
     *
     * Parameters สำคัญ:
     * - string $term: คำค้น (เทียบ $_GET['term'] / $_REQUEST['term'])
     *
     * Return value:
     * - array ของ string
     */
    function maintenance_new_mysql_search_customer_enduser_lines($term)
    {
        $lines = array();
        $mysqli = maintenance_new_customer_mysqli_connect();
        if ($mysqli === false) {
            return $lines;
        }
        $pattern = maintenance_new_mysql_like_pattern($term);
        $sql = 'SELECT cus_id, cus_name FROM tbl_customer WHERE cus_name LIKE ? AND cus_name NOT LIKE ? LIMIT 4';
        $exclude = '%รักษาความปลอดภัย%';
        $stmt = mysqli_prepare($mysqli, $sql);
        if ($stmt === false) {
            mysqli_close($mysqli);
            return $lines;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $pattern, $exclude);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $cusId, $cusName);
        while (mysqli_stmt_fetch($stmt)) {
            $lines[] = (string) $cusName;
        }
        mysqli_stmt_close($stmt);
        mysqli_close($mysqli);

        return $lines;
    }
}

if (!function_exists('maintenance_new_mysql_search_customer_security_lines')) {
    /**
     * Purpose:
     * ค้นหาบริษัทรปภ. (ชื่อมีคำว่า รักษาความปลอดภัย) สูงสุด 21 แถว คืนค่า `cus_id#cus_name`
     *
     * Parameters สำคัญ:
     * - string $term: คำค้น
     *
     * Return value:
     * - array ของ string
     */
    function maintenance_new_mysql_search_customer_security_lines($term)
    {
        $lines = array();
        $mysqli = maintenance_new_customer_mysqli_connect();
        if ($mysqli === false) {
            return $lines;
        }
        $pattern = maintenance_new_mysql_like_pattern($term);
        $include = '%รักษาความปลอดภัย%';
        $sql = 'SELECT cus_id, cus_name FROM tbl_customer WHERE cus_name LIKE ? AND cus_name LIKE ? LIMIT 21';
        $stmt = mysqli_prepare($mysqli, $sql);
        if ($stmt === false) {
            mysqli_close($mysqli);
            return $lines;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $pattern, $include);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $cusId, $cusName);
        while (mysqli_stmt_fetch($stmt)) {
            $lines[] = (string) $cusName;
        }
        mysqli_stmt_close($stmt);
        mysqli_close($mysqli);

        return $lines;
    }
}
