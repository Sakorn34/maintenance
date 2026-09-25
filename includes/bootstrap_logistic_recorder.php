<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ตั้งค่า `$mn_logistic_recorder_value` สำหรับช่อง «ผู้บันทึก / ผู้แจ้งบันทึก» ให้สอดคล้อง `add_job.php`
 * (ดึงชื่อจาก `employee` โดยจับคู่ `login_name` กับ emp_name หรือ emp_password; ถ้าไม่พบใช้ชื่อจาก profile หรือ login)
 *
 * Flow:
 * `require_once` ไฟล์นี้หลัง `require_auth.php` — ได้ string ชื่อเดียวสำหรับ hidden + แสดงเป็น span
 * API บันทึก (เช่น `save_job_process_activate_spare.php`) เรียก `maintenance_logistic_recorder_resolve_staff_name($connect)`
 *
 * Example:
 * `require_once .../bootstrap_logistic_recorder.php';` แล้วใช้ `$mn_logistic_recorder_value` ใน markup
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/data_add_job.php';
require_once __DIR__ . '/session_profile.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_logistic_recorder_resolve_staff_name')) {
    /**
     * Purpose:
     * คืนชื่อพนักงานผู้ทำรายการ — lookup `employee` จาก login แล้ว fallback profile / login_name
     *
     * Parameters สำคัญ:
     * - mysqli|null $mysqli: connection ที่มีอยู่แล้ว (ถ้าไม่ส่งจะเปิด connection ชั่วคราว)
     *
     * Return value:
     * - string ชื่อพนักงาน (development = `Max`, production อาจว่างถ้าไม่มี session)
     */
    function maintenance_logistic_recorder_resolve_staff_name($mysqli = null)
    {
        if (!maintenance_new_app_env_is_production()) {
            return 'Max';
        }

        if (function_exists('maintenance_new_auth_bootstrap')) {
            maintenance_new_auth_bootstrap();
        }

        $loginName = maintenance_new_profile_login_name();
        $ownConnection = false;

        if ($loginName !== null && $loginName !== '') {
            if (!($mysqli instanceof mysqli)) {
                $mysqli = maintenance_new_db_connect();
                $ownConnection = ($mysqli instanceof mysqli);
            }
            if ($mysqli instanceof mysqli) {
                $names = maintenance_new_fetch_employee_names($mysqli, '', $loginName);
                if (count($names) > 0) {
                    $name = trim((string) $names[0]);
                    if ($ownConnection) {
                        mysqli_close($mysqli);
                    }
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
            if ($ownConnection && $mysqli instanceof mysqli) {
                mysqli_close($mysqli);
                $ownConnection = false;
            }
        }

        $display = maintenance_new_profile_display_name();
        if ($display !== '') {
            return $display;
        }
        if ($loginName !== null && $loginName !== '') {
            return (string) $loginName;
        }

        return '';
    }
}

// ─────────────────────────────────────────────────────────────────
// main
// ─────────────────────────────────────────────────────────────────
if (!isset($mn_logistic_recorder_value)) {
    global $connect;
    $mysqliForRecorder = (isset($connect) && $connect instanceof mysqli) ? $connect : null;
    $mn_logistic_recorder_value = maintenance_logistic_recorder_resolve_staff_name($mysqliForRecorder);
}
