<?php
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/checklist_helpers.php';

$product    = isset($_GET['product'])    ? trim((string) $_GET['product'])    : '';
$symptom_id = isset($_GET['symptom_id']) ? (int) $_GET['symptom_id']           : 0;
$trp_id     = isset($_GET['trp_id'])     ? (int) $_GET['trp_id']               : 0;

if ($product === '' || $symptom_id < 0) {
    maintenance_db_odbc_close($connect);
    echo json_encode(array('items' => array()), JSON_UNESCAPED_UNICODE);
    exit;
}

maintenance_checklist_ensure_tables($connect);
$items   = maintenance_checklist_fetch_items($connect, maintenance_checklist_effective_product($connect, $product), $symptom_id);
$results = $trp_id > 0 ? maintenance_checklist_fetch_job_results($connect, $trp_id) : array();

$out = array();
foreach ($items as $item) {
    $id    = (int) $item['ci_id'];
    $saved = isset($results[$id]) ? $results[$id] : array('result' => '', 'note' => '');

    // parse ci_opts → array of {label, has_note}
    $opts_raw = isset($item['ci_opts']) ? (string) $item['ci_opts'] : '';
    $opts_arr = null;
    if ($opts_raw !== '') {
        $parts    = explode('|', $opts_raw);
        $rates    = json_decode((string) (isset($item['ci_opt_rates']) ? $item['ci_opt_rates'] : ''), true);
        $colors   = json_decode((string) (isset($item['ci_opt_colors']) ? $item['ci_opt_colors'] : ''), true);
        $notes    = json_decode((string) (isset($item['ci_opt_notes']) ? $item['ci_opt_notes'] : ''), true);
        $groups   = json_decode((string) (isset($item['ci_opt_groups']) ? $item['ci_opt_groups'] : ''), true);
        $opts_arr = array();
        foreach ($parts as $pi => $part) {
            $part     = trim($part);
            $has_note = (substr($part, -5) === ':note');
            $label    = $has_note ? substr($part, 0, -5) : $part;
            if ($label !== '') {
                $rate = is_array($rates)
                    ? (isset($rates[$pi]) ? (string) $rates[$pi] : '')
                    : ($pi > 0 ? (string) $item['ci_rate_on_fail'] : '');
                $color = is_array($colors) && isset($colors[$pi]) ? (string) $colors[$pi] : '';
                $note_default = $has_note && is_array($notes) && isset($notes[$pi]) ? (string) $notes[$pi] : '';
                $group = is_array($groups) && isset($groups[$pi]) ? (int) $groups[$pi] : 0;
                $opts_arr[] = array('label' => $label, 'has_note' => $has_note, 'note_default' => $note_default, 'rate' => $rate, 'color' => $color, 'group' => $group);
            }
        }
        if (count($opts_arr) === 0) { $opts_arr = null; }
    }

    // auto-calc สำหรับ items ที่มี ci_auto_source
    $auto_source = isset($item['ci_auto_source']) ? (string) $item['ci_auto_source'] : '';
    $auto_result = null;
    $auto_label  = null;
    $auto_detail = null;
    if ($auto_source !== '' && $trp_id > 0) {
        $auto_data   = maintenance_checklist_auto_result($connect, $trp_id, $auto_source);
        $auto_result = $auto_data['result'];
        $auto_label  = $auto_data['label'];
        $auto_detail = $auto_data['detail'];
    }

    $out[] = array(
        'ci_id'          => $id,
        'ci_step'        => (int) $item['ci_step'],
        'ci_question'    => $item['ci_question'],
        'ci_rate_on_fail'=> $item['ci_rate_on_fail'],
        'ci_hint'        => $item['ci_hint'],
        'ci_unit'        => isset($item['ci_unit']) ? $item['ci_unit'] : '',
        'ci_val_min'     => (isset($item['ci_val_min']) && $item['ci_val_min'] !== null) ? (float) $item['ci_val_min'] : null,
        'ci_val_max'     => (isset($item['ci_val_max']) && $item['ci_val_max'] !== null) ? (float) $item['ci_val_max'] : null,
        'ci_opts'        => $opts_arr,
        'ci_multi'       => $opts_arr !== null && (int) $item['ci_multi'] === 1 && (string) $item['ci_unit'] === '',
        'group_id'       => (int) $item['ci_group_id'],
        'group_name'     => (string) $item['group_name'],
        'ci_auto_source' => $auto_source !== '' ? $auto_source : null,
        'auto_result'    => $auto_result,
        'auto_label'     => $auto_label,
        'auto_detail'    => $auto_detail,
        'saved_result'   => $saved['result'],
        'saved_note'     => $saved['note'],
    );
}

maintenance_db_odbc_close($connect);
echo json_encode(array('items' => $out), JSON_UNESCAPED_UNICODE);
