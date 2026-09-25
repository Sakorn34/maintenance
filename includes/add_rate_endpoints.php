<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * URL ปลายทางสำหรับหน้า `add_rate` (บันทึกผ่าน GET แบบ legacy — path ไม่มี `.php`)
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_add_rate_client_config')) {
    /**
     * Purpose:
     * คืนค่า array สำหรับ `window.MN_ADD_RATE` ใน `add_rate.php`
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array เช่น `insertRate` (path `insert_rate`), `ratesJson` (path `add_rate_rates_json` สำหรับ preview)
     */
    function maintenance_add_rate_client_config()
    {
        return array(
            'insertRate' => 'insert_rate',
            'ratesJson' => 'add_rate_rates_json',
        );
    }
}
