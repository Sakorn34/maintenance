<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * helper สร้างลิงก์ติดตามงานซ่อมภายนอก (maintenancetracking) จากหน้า maintenance ภายใน
 *
 * Example:
 * require_once __DIR__ . '/maintenance_tracking_link.php';
 * $url = maintenance_tracking_status_url_for_repair(123);
 */

require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/maintenance_tracking_job_token.php';

if (!function_exists('maintenance_tracking_row_trp_id')) {
    /**
     * Purpose:
     * อ่าน trp_id จากแถวผลลัพธ์ index (รองรับชื่อคอลัมน์ต่างรูปแบบจาก driver)
     *
     * Parameters สำคัญ:
     * - array|null $row: แถวจาก mysqli fetch
     *
     * Return value:
     * - int รหัส transac_repair.trp_id หรือ 0
     */
    function maintenance_tracking_row_trp_id($row)
    {
        if (!is_array($row)) {
            return 0;
        }
        if (isset($row['trp_id'])) {
            return (int) $row['trp_id'];
        }
        foreach ($row as $key => $val) {
            if (is_string($key) && strcasecmp($key, 'trp_id') === 0) {
                return (int) $val;
            }
        }

        return 0;
    }
}

if (!function_exists('maintenance_tracking_status_url_for_repair')) {
    /**
     * Purpose:
     * สร้าง URL สาธารณะ `maintenancetracking/status?job=...` สำหรับส่งให้ลูกค้า
     *
     * Parameters สำคัญ:
     * - int $trp_id: รหัส transac_repair.trp_id
     *
     * Return value:
     * - string URL หรือค่าว่างถ้า encode ไม่ได้
     */
    function maintenance_tracking_status_url_for_repair($trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) {
            return '';
        }

        return maintenance_tracking_status_url($id);
    }
}
