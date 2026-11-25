<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * AJAX JSON — สถานะประกันของเครื่อง ณ วันรับเครื่อง จากทะเบียนประกัน (เครื่องใหม่ / งานซ่อม / ซื้อเพิ่ม)
 * GET ?sn=&product=&buy_date=&trp_id=&ref=   (ต้องล็อกอิน, อ่านอย่างเดียว ยกเว้นสร้างประกันซื้อเพิ่มจากใบงานที่ซ่อมเสร็จแล้วอัตโนมัติ)
 * ใช้ในหน้า update_wait_hardware ผ่าน js/warranty-timeline.js
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/warranty_registry_helpers.php';

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$g = function ($k, $max) { return isset($_GET[$k]) ? mb_substr(trim((string) $_GET[$k]), 0, $max, 'UTF-8') : ''; };
$s = maintenance_warranty_suggest_insurance($connect, $g('product', 100), $g('buy_date', 10), $g('sn', 60), (int) $g('trp_id', 12), $g('ref', 10));
$ref = mn_wr_date($g('ref', 10)) !== '' ? mn_wr_date($g('ref', 10)) : date('Y-m-d');
$items = array();
foreach ($s['timeline'] as $it) {
    $items[] = array('type' => $it['type'], 'title' => $it['title'], 'ref' => $it['ref'], 'start' => mn_wr_dmy($it['start']), 'end' => mn_wr_dmy($it['end']),
        'active' => $it['active'], 'future' => $it['future'], 'left' => $it['left'], 'ago' => $it['ago']);
}
maintenance_db_odbc_close($connect);
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(array('ok' => true, 'value' => $s['value'], 'hint' => $s['hint'], 'ref' => mn_wr_dmy($ref), 'items' => $items), JSON_UNESCAPED_UNICODE);
