<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Bootstrap มาตรฐานสำหรับหน้า maintenance ที่ใช้ mysqli + compat `odbc_*` และฟังก์ชัน legacy
 * (ฟอร์มย่อย / popup / save handler ที่ไม่ต้องการ state พิเศษของหน้าใดหน้าหนึ่ง)
 *
 * Flow:
 * หลัง `require_auth.php` แล้ว `require_once` ไฟล์นี้ — ได้ `$connect`, `function_legacy.php` และ `maintenance_activity_log_write()` จาก `activity_log_helpers.php`
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/mysql_odbc_compat.php';
require_once __DIR__ . '/function_legacy.php';
require_once __DIR__ . '/activity_log_helpers.php';
