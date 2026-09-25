<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * อ่านข้อมูล `$_SESSION['profile']` แบบรองรับทั้ง object (เช่น stdClass จาก json_decode) และ array
 * ใช้ร่วมกับ auth_guard หลัง session_start แล้ว
 *
 * องค์ประกอบหลัก:
 * - `maintenance_new_session_profile()` คืน profile ดิบหรือ null
 * - `maintenance_new_profile_login_name()` คืน login_name สำหรับกรอง employee
 * - `maintenance_new_profile_display_name()` ชื่อแสดงเมื่อไม่พบแถวใน `employee`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_session_profile')) {
    /**
     * Purpose:
     * คืนค่า profile จาก session ถ้ามีและเป็นชนิดที่อ่านได้
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - object|array|null
     */
    function maintenance_new_session_profile()
    {
        if (!isset($_SESSION['profile'])) {
            return null;
        }
        return $_SESSION['profile'];
    }
}

if (!function_exists('maintenance_new_profile_login_name')) {
    /**
     * Purpose:
     * ดึง login_name จาก profile เพื่อใช้กรองรายชื่อพนักงานเหมือน legacy employee("", $profile->login_name)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string|null ถ้าไม่มีคีย์หรือ session ไม่พร้อม
     */
    function maintenance_new_profile_login_name()
    {
        $p = maintenance_new_session_profile();
        if ($p === null) {
            return null;
        }
        if (is_object($p) && isset($p->login_name)) {
            return (string) $p->login_name;
        }
        if (is_array($p) && isset($p['login_name'])) {
            return (string) $p['login_name'];
        }
        return null;
    }
}

if (!function_exists('maintenance_new_profile_display_name')) {
    /**
     * Purpose:
     * คืนชื่อที่ใช้แสดงแทน `emp_name` เมื่อจับคู่ตาราง `employee` ไม่ได้ — อ่านจาก profile ตามคีย์ที่พบบ่อยใน SSO
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ข้อความแสดง (อาจว่าง)
     */
    function maintenance_new_profile_display_name()
    {
        $p = maintenance_new_session_profile();
        if ($p === null) {
            return '';
        }
        $keys = array('display_name', 'name', 'full_name', 'emp_name', 'nickname', 'login_name');
        foreach ($keys as $k) {
            if (is_object($p) && isset($p->{$k})) {
                $v = trim((string) $p->{$k});
                if ($v !== '') {
                    return $v;
                }
            }
            if (is_array($p) && isset($p[$k])) {
                $v = trim((string) $p[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }
}
