<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เตรียม `$connect` (mysqli + compat `odbc_*`) และฟังก์ชัน legacy สำหรับหน้า `index.php` (รายการใบซ่อม / ค้นหา)
 *
 * Flow:
 * หลัง `require_auth.php` แล้ว `require_once` ไฟล์นี้ — ได้ `$connect` และ `function_legacy.php` (เช่น `product()`, `request_statusjob()`, `cal_wait_date()`, `strReplace()`)
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/mysql_odbc_compat.php';
require_once __DIR__ . '/function_legacy.php';
