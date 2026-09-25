<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Endpoint AJAX สำหรับคำนวณ «อายุเครื่อง» จากวันที่ซื้อ (term) ถึงวันนี้ แบบปฏิทิน — คืนข้อความ plain text
 *
 * Flow:
 * jQuery `$.get('cal_lifetime', { term: 'Y-m-d' })` → ข้อความเช่น `2 ปี 3 เดือน 5 วัน`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/cal_lifetime_helpers.php';

echo maintenance_cal_lifetime_plain_from_buy_date(isset($_REQUEST['term']) ? (string) $_REQUEST['term'] : '');
maintenance_db_odbc_close($connect);
