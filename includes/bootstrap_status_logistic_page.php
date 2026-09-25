<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เตรียมการเชื่อมต่อฐานข้อมูล (mysqli ผ่าน `mysql_odbc_compat`) และโหลดฟังก์ชัน legacy
 * สำหรับหน้า `status_logistic.php` — รายการรอส่งพัสดุ (เครื่องสำรอง + เครื่องซ่อมลูกค้า)
 *
 * Flow:
 * หลัง `require_auth.php` แล้ว `require_once` ไฟล์นี้ — ได้ `$connect`, รายชื่อขนส่ง, `$mn_logistic_recorder_value` (ผู้บันทึกแบบเดียวกับ add_job)
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/mysql_odbc_compat.php';
require_once __DIR__ . '/function_legacy.php';
require_once __DIR__ . '/bootstrap_logistic_recorder.php';

// ─────────────────────────────────────────────────────────────────
// main
// ─────────────────────────────────────────────────────────────────
$mn_status_logistics_list = logistisc();
