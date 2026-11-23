<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * JSON API ของหน้า checklist_settings — GET: products / load / history · POST (JSON body): บันทึก ลบ เรียงลำดับ
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/update_rate_helpers.php';
require_once __DIR__ . '/includes/checklist_helpers.php';
require_once __DIR__ . '/includes/checklist_settings_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

function mn_cls_respond($connect, array $out)
{
    maintenance_db_odbc_close($connect);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

maintenance_checklist_ensure_tables($connect);

$is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$body = array();
if ($is_post) {
    $raw = file_get_contents('php://input');
    $body = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($body)) { mn_cls_respond($connect, array('ok' => false, 'error' => 'ข้อมูลไม่ถูกต้อง')); }
}
$src = $is_post ? $body : $_GET;
$action = isset($src['action']) ? (string) $src['action'] : '';
$product = isset($src['product']) ? trim((string) $src['product']) : '';

if ($action === 'ping') {
    mn_cls_respond($connect, array('ok' => true));
}

if ($action === 'products') {
    mn_cls_respond($connect, array('ok' => true, 'products' => mn_cls_products($connect)));
}

if ($product === '' || !mn_cls_product_exists($connect, $product)) {
    mn_cls_respond($connect, array('ok' => false, 'error' => 'ไม่พบรุ่นสินค้า'));
}

if (!$is_post) {
    if ($action === 'load') {
        mn_cls_respond($connect, array('ok' => true, 'data' => mn_cls_load($connect, $product)));
    }
    if ($action === 'history') {
        mn_cls_respond($connect, array('ok' => true, 'history' => mn_cls_history($connect, $product)));
    }
    mn_cls_respond($connect, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'));
}

$id = isset($body['id']) ? (int) $body['id'] : 0;
switch ($action) {
    case 'save_item':
        $out = mn_cls_save_item($connect, $product, isset($body['item']) && is_array($body['item']) ? $body['item'] : array());
        break;
    case 'delete_item':
        $out = mn_cls_delete_item($connect, $product, $id, !empty($body['force']));
        break;
    case 'reorder_items':
    case 'reorder_symptoms':
    case 'reorder_groups':
        $kind = array('reorder_items' => 'item', 'reorder_symptoms' => 'symptom', 'reorder_groups' => 'group');
        $out = mn_cls_reorder($connect, $product, $kind[$action], isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : array());
        break;
    case 'save_symptom':
    case 'save_group':
        $out = mn_cls_save_named($connect, $product, $action === 'save_symptom' ? 'symptom' : 'group', $body);
        break;
    case 'delete_symptom':
    case 'delete_group':
        $out = mn_cls_delete_named($connect, $product, $action === 'delete_symptom' ? 'symptom' : 'group', $id);
        break;
    case 'set_share':
        $out = mn_cls_set_share($connect, $product, isset($body['share_from']) ? $body['share_from'] : '', $mn_logistic_recorder_value);
        break;
    default:
        $out = array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง');
}
mn_cls_respond($connect, $out);
