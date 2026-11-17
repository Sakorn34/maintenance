<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * API JSON สำหรับ popup รายการเบิกอะไหล่บน status_wait_ma
 * - โหมด machine: mtn_key + trp_sn → สรุปทุกเอกสาร (รอจ่าย + จ่ายแล้ว)
 * - โหมดเอกสารเดียว: pre_id → รายการจ่ายแล้วของใบนั้น
 *
 * Example:
 * `/maintenance/maintenance_inventory_pickup_lines?mtn_key=69-0562&trp_sn=BP21121070`
 */

require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/maintenance_inventory_pickup_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$mtnKey = isset($_GET['mtn_key']) ? trim((string) $_GET['mtn_key']) : '';
$trpSn = isset($_GET['trp_sn']) ? trim((string) $_GET['trp_sn']) : '';
$preId = isset($_GET['pre_id']) ? trim((string) $_GET['pre_id']) : '';

$inv = maintenance_new_inventory_mysqli_connect();
if (!$inv) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'message' => 'เชื่อมต่อฐานข้อมูล inventory ไม่ได้'), JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = null;
if ($mtnKey !== '' && $trpSn !== '') {
    if (function_exists('mb_substr')) {
        $mtnKey = mb_substr($mtnKey, 0, 7, 'UTF-8');
    } else {
        $mtnKey = substr($mtnKey, 0, 7);
    }
    $payload = maintenance_inventory_pickup_fetch_machine_summary_payload($inv, $mtnKey, $trpSn);
} elseif (maintenance_inventory_pickup_valid_pre_id($preId)) {
    $payload = maintenance_inventory_pickup_fetch_issued_lines_payload($inv, $preId);
}

mysqli_close($inv);

if ($payload === null) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'message' => 'ไม่พบรายการเบิกอะไหล่ของเครื่องนี้'), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
