<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * โหลดรายชื่อพนักงานตามผู้ login (เทียบ employee("", login_name)) สำหรับช่องผู้รับเรื่อง และรายการ Product
 * แยกออกจาก add_job.php เพื่อให้ไฟล์หลักเน้น markup
 *
 * Flow:
 * production: ดึง `$employeeNames` จับคู่ `employee` จาก `login_name` (emp_name หรือ emp_password) ถ้าไม่พบใช้ชื่อจาก profile หรือ login
 * development: ไม่บังคับ session; $employeeNames ใช้ชื่อ `Max` สำหรับทดสอบ
 * ทั้งสองโหมดได้ $productNames จากฐานข้อมูล
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/data_add_job.php';
require_once __DIR__ . '/session_profile.php';

// ─────────────────────────────────────────────────────────────────
// main
// ─────────────────────────────────────────────────────────────────
$mysqli = maintenance_new_db_connect();
if (!maintenance_new_app_env_is_production()) {
    $employeeNames = array('Max');
} else {
    $loginName = maintenance_new_profile_login_name();
    if ($loginName === null || $loginName === '') {
        $employeeNames = array();
    } else {
        $employeeNames = maintenance_new_fetch_employee_names($mysqli, '', $loginName);
        if (count($employeeNames) < 1) {
            $fallback = maintenance_new_profile_display_name();
            $employeeNames = $fallback !== '' ? array($fallback) : array($loginName);
        }
    }
}
$productNames = maintenance_new_fetch_product_names_sorted($mysqli);
mysqli_close($mysqli);
