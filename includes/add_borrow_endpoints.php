<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รวม URL ปลายทางที่หน้า `add_borrow` เรียก (บันทึกผ่าน GET, AJAX ค้นหา)
 * path ไม่มีนามสกุล `.php` — อาศัย `.htaccess` ที่รากโปรเจกต์
 *
 * Flow:
 * `add_borrow.php` โหลดไฟล์นี้แล้วส่งค่าเข้า `json_encode` เป็น `window.MN_ADD_BORROW`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_add_borrow_client_config')) {
    /**
     * Purpose:
     * คืนค่าชุด URL สำหรับฝั่งเบราว์เซอร์ (relative จากหน้า add_borrow)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array แอสโซซิเอทีฟ string => string
     */
    function maintenance_add_borrow_client_config()
    {
        return array(
            'insertBorrow' => 'insert_borrow',
            'searchCusname' => 'search_cusname',
            'searchCusnameSecurity' => 'search_cusname_security',
            'searchAddress' => 'search_address',
        );
    }
}
