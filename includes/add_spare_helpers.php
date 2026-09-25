<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ดึงข้อมูลสำหรับหน้า `add_spare` — dropdown อุปกรณ์จาก `product_name.pn_name`, จัดกลุ่ม S/N จาก `spare` เรียงตามจำนวนน้อยไปมาก
 * ใช้ prepared statement เฉพาะคำสั่งที่มีพารามิเตอร์จากชื่อ product
 *
 * Flow:
 * `add_spare.php` เรียก `maintenance_add_spare_fetch_product_names()` แล้ว `maintenance_add_spare_fetch_rows_by_product()` ต่อแถว product
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_add_spare_fetch_distinct_product_names')) {
    /**
     * Purpose:
     * คืนรายการชื่ออุปกรณ์จาก `product_name.pn_name` เรียงตามชื่อ (ใช้ใน `<select>` เพิ่มเครื่องสำรอง)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของ string (UTF-8)
     */
    function maintenance_add_spare_fetch_distinct_product_names($connect)
    {
        if (!($connect instanceof mysqli)) {
            return array();
        }
        $sql = 'SELECT pn_name FROM product_name ORDER BY pn_name ASC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $out = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (!is_array($row) || !isset($row['pn_name'])) {
                continue;
            }
            $name = trim(maintenance_flow_db_text_to_utf8($row['pn_name']));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}

if (!function_exists('maintenance_add_spare_fetch_product_names')) {
    /**
     * Purpose:
     * คืนรายการ `sp_product_name` แบบไม่ซ้ำ เรียงตามจำนวนแถวใน `Spare` จากน้อยไปมาก (เทียบเท่า legacy)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของ string
     */
    function maintenance_add_spare_fetch_product_names($connect)
    {
        $sql = 'SELECT sp_product_name, COUNT(*) AS c FROM spare GROUP BY sp_product_name ORDER BY c ASC, sp_product_name ASC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $out = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (isset($row['sp_product_name']) && $row['sp_product_name'] !== '') {
                $out[] = (string) $row['sp_product_name'];
            }
        }

        return $out;
    }
}

if (!function_exists('maintenance_add_spare_fetch_rows_by_product')) {
    /**
     * Purpose:
     * ดึง `sp_sn`, `sp_status` ของ product หนึ่งรายการ เรียง `sp_status` DESC
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $product_name: ชื่อ product
     *
     * Return value:
     * - array ของแถว associative `sp_sn`, `sp_status`
     */
    function maintenance_add_spare_fetch_rows_by_product($connect, $product_name)
    {
        $sql = 'SELECT sp_sn, sp_status FROM spare WHERE sp_product_name = ? ORDER BY sp_status DESC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 's', $product_name);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = array();
        if ($res !== false) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = $r;
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('maintenance_add_spare_status_label_html')) {
    /**
     * Purpose:
     * แปลง `sp_status` เป็น HTML แสดงผล (มี tag สี) สำหรับลิงก์ประวัติสำรอง
     *
     * Parameters สำคัญ:
     * - string $sp_sn: serial
     * - string $status: ค่า `sp_status` ดิบ
     *
     * Return value:
     * - string HTML (ไม่ escape ซ้ำเมื่อนำไปประกอบลิงก์ที่ escape `sn` แล้ว)
     */
    function maintenance_add_spare_status_label_html($sp_sn, $status)
    {
        $sn_esc = htmlspecialchars((string) $sp_sn, ENT_QUOTES, 'UTF-8');
        $st = (string) $status;
        if ($st === 'use') {
            return "<span style=\"color:#FF0000;\">" . $sn_esc . '[use]</span>';
        }
        if ($st === 'lost') {
            return '<span style="color:#000000;">' . $sn_esc . '[สูญหาย]</span>';
        }
        if ($st === 'wear out') {
            return '<span style="color:#000000;">' . $sn_esc . '[เสื่อมสภาพ]</span>';
        }

        return '<span style="color:#0000FF;">' . $sn_esc . ' [' . htmlspecialchars($st, ENT_QUOTES, 'UTF-8') . ']</span>';
    }
}
