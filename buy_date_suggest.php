<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * AJAX JSON — เสนอวันที่ซื้อของเครื่องจากหลักฐานจริง (ประวัติซ่อม / ส่งเครื่องจากคลัง / ติดตั้ง / ส่งมอบ / ใบสั่งติดตั้ง)
 * GET ?sn=&trp_id=   (ต้องล็อกอิน, อ่านอย่างเดียว) — ใช้ใน update_wait_hardware ผ่าน js/buy-date-suggest.js
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/buy_date_suggest_helpers.php';

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$out = mn_bds_suggest($connect,
    isset($_GET['sn']) ? mb_substr((string) $_GET['sn'], 0, 60, 'UTF-8') : '',
    isset($_GET['trp_id']) ? (int) $_GET['trp_id'] : 0);
maintenance_db_odbc_close($connect);
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(array('ok' => true) + $out, JSON_UNESCAPED_UNICODE);
