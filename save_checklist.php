<?php
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/checklist_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$body = file_get_contents('php://input');
$data = ($body !== false && $body !== '') ? json_decode($body, true) : null;
if (!is_array($data)) {
    maintenance_db_odbc_close($connect);
    echo json_encode(array('ok' => false, 'error' => 'bad request'), JSON_UNESCAPED_UNICODE);
    exit;
}

$trp_id     = isset($data['trp_id'])     ? (int) $data['trp_id']     : 0;
$symptom_id = isset($data['symptom_id']) ? (int) $data['symptom_id'] : 0;
$results    = isset($data['results'])    ? (array) $data['results']   : array();

if ($trp_id < 1 || $symptom_id < 0) {
    maintenance_db_odbc_close($connect);
    echo json_encode(array('ok' => false, 'error' => 'missing trp_id or symptom_id'), JSON_UNESCAPED_UNICODE);
    exit;
}

// normalise results: each entry must have 'result' key
$clean = array();
foreach ($results as $ci_id => $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $r = isset($entry['result']) ? trim((string) $entry['result']) : '';
    $n = isset($entry['note'])   ? trim((string) $entry['note'])   : '';
    if ($r !== '') {
        $clean[(int) $ci_id] = array('result' => $r, 'note' => $n);
    }
}

maintenance_checklist_ensure_tables($connect);
if ($symptom_id === 0 && isset($data['keep_symptoms']) && is_array($data['keep_symptoms'])) {
    maintenance_checklist_prune_symptoms($connect, $trp_id, $data['keep_symptoms']);
}
$out = maintenance_checklist_save($connect, $trp_id, $symptom_id, $clean, $mn_logistic_recorder_value);

maintenance_db_odbc_close($connect);
echo json_encode(array(
    'ok'              => $out['ok'],
    'suggested_rates' => $out['suggested_rates'],
), JSON_UNESCAPED_UNICODE);
