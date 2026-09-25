<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Bootstrap สำหรับหน้า `status_wait_ma.php` — delegate ไป `bootstrap_maintenance_legacy.php`
 *
 * Flow:
 * หลัง `require_auth.php` แล้ว `require_once` ไฟล์นี้ — ได้ `$connect` และ `function_legacy.php`
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/bootstrap_maintenance_legacy.php';
