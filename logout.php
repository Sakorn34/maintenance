<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ออกจากระบบ — ล้าง session ทั้งหมดแล้วกลับไปหน้า login ตามลำดับเดียวกับ `bitlogin/logout.php` บน production
 * (`session_unset` → `session_destroy` → `session_start` ใหม่ → redirect)
 *
 * ส่ง `?logout=1` ไปหน้า login เพื่อให้ข้ามล็อกอินอัตโนมัติและเลือกผู้ใช้ใหม่ได้
 *
 * Flow:
 * ลิงก์ «ออกจากระบบ» บนแถบเมนู (`includes/title-h.php`) → ไฟล์นี้ → `/maintenance/login?logout=1`
 */

require_once __DIR__ . '/config/app_environment.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = array();
session_unset();
session_destroy();
session_start();

$webBase = rtrim(maintenance_new_maintenance_web_base(), '/');
header('Location: ' . $webBase . '/login?logout=1');
exit;
