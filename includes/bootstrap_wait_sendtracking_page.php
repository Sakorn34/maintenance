<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เตรียมการเชื่อมต่อฐานข้อมูล (mysqli ผ่าน `mysql_odbc_compat`) และ helper สำหรับหน้า `wait_sendtracking.php` — คิว `sendtracking_history` หลังบันทึก tracking จาก `status_logistic`
 *
 * Flow:
 * หลัง `require_auth.php` แล้ว `require_once` ไฟล์นี้ — ได้ `$connect` และฟังก์ชันใน `sendtracking_history_helpers` + `status_logistic_helpers` + `function_legacy` (ให้ timeline ใช้ `cal_wait_date`) + `job_process_customer_share_helpers`
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/mysql_odbc_compat.php';
require_once __DIR__ . '/sendtracking_history_helpers.php';
require_once __DIR__ . '/status_logistic_helpers.php';
require_once __DIR__ . '/function_legacy.php';
require_once __DIR__ . '/job_process_customer_share_helpers.php';
