<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Entry เดียวสำหรับหน้า/ API ใน maintenance ที่ต้องล็อกอิน (production) — require ก่อน output ใดๆ
 * โหมด development จะไม่เรียกตรวจ profile
 */

require_once __DIR__ . '/auth_guard.php';
maintenance_new_require_logged_in();
