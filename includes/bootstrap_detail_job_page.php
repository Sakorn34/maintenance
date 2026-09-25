<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เตรียม `$connect` (mysqli + compat `odbc_*`) สำหรับหน้า `detail_job.php` รายละเอียดใบงานตาม `ts_id`
 *
 * Flow:
 * หลัง `require_auth.php` แล้ว `require_once` ไฟล์นี้ — ได้ `$connect` และฟังก์ชัน legacy ที่ helper ใช้ (เช่น `strReplace()` ใน `function_legacy.php`)
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/mysql_odbc_compat.php';
require_once __DIR__ . '/function_legacy.php';
