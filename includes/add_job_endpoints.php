<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รวม URL ของสคริปต์ที่หน้า add_job เรียก (บันทึกงาน, AJAX ค้นหา, รายการ S/N)
 * ให้เปลี่ยนจุดเดียวได้เมื่อย้าย path หรือเปลี่ยนชื่อไฟล์ — ทุก path อยู่ภายใต้รากโปรเจกต์ maintenance
 *
 * องค์ประกอบหลัก:
 * - ฟังก์ชัน `maintenance_new_add_job_client_config()` คืนค่า array สำหรับส่งให้ JavaScript ผ่าน json_encode
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_add_job_client_config')) {
    /**
     * Purpose:
     * คืนค่าชุด URL ปลายทางสำหรับฝั่งเบราว์เซอร์ (relative จากหน้า add_job — ไม่มีนามสกุล .php; อาศัย .htaccess rewrite)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array แอสโซซิเอทีฟ string => string (ชื่อคีย์ใช้ใน add_job-form.js)
     */
    function maintenance_new_add_job_client_config()
    {
        return array(
            'insertJob' => 'insert_job',
            'searchCusname' => 'search_cusname',
            'searchCusnameSecurity' => 'search_cusname_security',
            'searchAddress' => 'search_address',
            'listSnSpare' => 'list_sn_spare',
            'listExistingTransac' => 'add_job_list_existing_transac',
        );
    }
}
