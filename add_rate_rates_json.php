<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * API อ่านอย่างเดียวสำหรับหน้า `add_rate` — คืนรายการ `r_rate` ของอุปกรณ์หนึ่งเป็น JSON (UTF-8) ให้ JS แสดง preview โดยไม่รีเฟรชหน้า
 *
 * Flow:
 * GET `product` (trim) → prepared `maintenance_add_rate_fetch_rates_for_product` → `{"ok":true,"product":"...","rates":[...]}` หรือ error
 */

// ─────────────────────────────────────────────────────────────────
// bootstrap
// ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/add_rate_helpers.php';

// ─────────────────────────────────────────────────────────────────
// response
// ─────────────────────────────────────────────────────────────────
$raw = isset($_GET['product']) ? (string) $_GET['product'] : '';
$product = trim($raw);
if (function_exists('mb_strlen')) {
    if (mb_strlen($product, 'UTF-8') > 500) {
        $product = mb_substr($product, 0, 500, 'UTF-8');
    }
} elseif (strlen($product) > 500) {
    $product = substr($product, 0, 500);
}

if ($product === '') {
    maintenance_db_odbc_close($connect);
    echo json_encode(array('ok' => true, 'product' => '', 'rates' => array()), JSON_UNESCAPED_UNICODE);
    exit;
}

$rates = maintenance_add_rate_fetch_rates_for_product($connect, $product);
maintenance_db_odbc_close($connect);

echo json_encode(
    array(
        'ok' => true,
        'product' => $product,
        'rates' => $rates,
    ),
    JSON_UNESCAPED_UNICODE
);
