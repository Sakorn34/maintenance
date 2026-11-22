<?php
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';

$product = isset($_GET['product']) ? trim((string) $_GET['product']) : '';
$trp_id  = isset($_GET['trp_id'])  ? (int) $_GET['trp_id']           : 0;

if ($product === '') {
    maintenance_db_odbc_close($connect);
    echo json_encode(array('top' => array()), JSON_UNESCAPED_UNICODE);
    exit;
}

$freq = array();
$stmt = $connect->prepare(
    'SELECT trp_repair_no1, trp_repair_no2, trp_repair_no3, trp_repair_no4, trp_repair_no5,
            trp_repair_no6, trp_repair_no7, trp_repair_no8, trp_repair_no9, trp_repair_no10
     FROM transac_repair
     WHERE trp_product = ? AND trp_id != ?
     ORDER BY trp_id DESC
     LIMIT 100'
);
if ($stmt) {
    $stmt->bind_param('si', $product, $trp_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        for ($n = 1; $n <= 10; $n++) {
            $v = trim((string) ($row['trp_repair_no' . $n] ?? ''));
            if ($v !== '') {
                $freq[$v] = isset($freq[$v]) ? $freq[$v] + 1 : 1;
            }
        }
    }
    arsort($freq);
    $stmt->close();
}

$top = array();
foreach (array_slice($freq, 0, 8, true) as $rate => $count) {
    $top[] = array('rate' => (string) $rate, 'count' => (int) $count);
}

maintenance_db_odbc_close($connect);
echo json_encode(array('top' => $top), JSON_UNESCAPED_UNICODE);
