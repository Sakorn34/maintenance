<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `status_borrow.php` — ดึงรายการ `Borrow` เรียงตามสถานะ (จำกัด 100 แถว) สำหรับตารางอัปเดตสถานะ/tracking
 *
 * Flow:
 * `maintenance_status_borrow_fetch_rows($connect)` → render ใน PHP
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

if (!defined('MAINTENANCE_STATUS_BORROW_LIST_LIMIT')) {
    /** จำนวนแถวสูงสุดในหน้ารายการยืม (เทียบ `SELECT Top 100` เดิมบน SQL Server) */
    define('MAINTENANCE_STATUS_BORROW_LIST_LIMIT', 100);
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_status_borrow_status_options')) {
    /**
     * Purpose:
     * คืนรายการค่า `b_status` ที่ใช้ใน dropdown หน้า `status_borrow` (ต้องตรงกับข้อมูลเดิมในระบบ)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array ของ string สี่ค่า (รวม `2-รอส่งคืน` สำหรับข้อมูลเก่าจากฟอร์ม `add_borrow`)
     */
    function maintenance_status_borrow_status_options()
    {
        return array('1-รอส่งพัสดุ', '2-รอรับคืน', '2-รอส่งคืน', '3-ส่งคืนแล้ว');
    }
}

if (!function_exists('maintenance_status_borrow_fetch_rows')) {
    /**
     * Purpose:
     * ดึงแถว `Borrow` เรียง `b_status ASC` จำกัดจำนวนตาม `MAINTENANCE_STATUS_BORROW_LIST_LIMIT`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_status_borrow_fetch_rows($connect)
    {
        $lim = (int) MAINTENANCE_STATUS_BORROW_LIST_LIMIT;
        if ($lim < 1) {
            $lim = 100;
        }
        $sql = 'SELECT b_id, b_date, b_user_receive, b_enduser_com, b_security_com, b_borrow_name, b_borrow_tel, '
            . 'b_borrow_line, b_borrow_email, b_address, b_borrow_hardware, b_status, b_tracking, b_date_return '
            . 'FROM Borrow ORDER BY b_status ASC LIMIT ' . $lim;
        $rows = array();
        $exe = mysqli_query($connect, $sql);
        if ($exe === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_borrow: ' . mysqli_error($connect));
            }
            exit('Error Query [' . htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') . ']');
        }
        while ($r = mysqli_fetch_assoc($exe)) {
            $rows[] = $r;
        }
        mysqli_free_result($exe);

        return $rows;
    }
}

if (!function_exists('maintenance_status_borrow_company_html')) {
    /**
     * Purpose:
     * HTML คอลัมน์บริษัท (enduser / security) แบบเดียวกับหน้าเก่า
     *
     * Parameters สำคัญ:
     * - array $row: แถว `Borrow`
     *
     * Return value:
     * - string HTML
     */
    function maintenance_status_borrow_company_html(array $row)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $eu = maintenance_status_logistic_normalize_company_label(
            isset($row['b_enduser_com']) ? $row['b_enduser_com'] : ''
        );
        $sec = maintenance_status_logistic_normalize_company_label(
            isset($row['b_security_com']) ? $row['b_security_com'] : ''
        );

        return maintenance_status_logistic_company_names_html($eu, $sec);
    }
}
