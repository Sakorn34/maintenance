<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * AJAX JSON บันทึก/ลบ ฉบับแก้ไขของใบประเมินสภาพเครื่อง (PDF) ต่อใบงาน — เรียกจากหน้าตัวอย่างใน assess_repair
 * POST {action: 'save', trp_id, html, sig} | {action: 'clear', trp_id}
 * ไม่แตะข้อมูลประเมินในใบงาน
 */
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';
require_once __DIR__ . '/includes/assess_pdf_helpers.php';

function mn_assess_pdf_out($connect, array $out)
{
    maintenance_db_odbc_close($connect);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

$body = file_get_contents('php://input');
if ($body !== false && strlen($body) > MN_ASSESS_PDF_MAX + 4096) {
    mn_assess_pdf_out($connect, array('ok' => false, 'error' => 'เอกสารใหญ่เกินไป'));
}
$data = ($body !== false && $body !== '') ? json_decode($body, true) : null;
if (!is_array($data) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    mn_assess_pdf_out($connect, array('ok' => false, 'error' => 'bad request'));
}

$action = isset($data['action']) ? (string) $data['action'] : '';
$trp_id = isset($data['trp_id']) ? (int) $data['trp_id'] : 0;
if ($trp_id < 1) {
    mn_assess_pdf_out($connect, array('ok' => false, 'error' => 'ไม่พบใบงาน'));
}

// ใบงานต้องมีอยู่จริง
$stmt = mysqli_prepare($connect, 'SELECT trp_id FROM transac_repair WHERE trp_id = ? LIMIT 1');
$found = false;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $trp_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $found = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
}
if (!$found) {
    mn_assess_pdf_out($connect, array('ok' => false, 'error' => 'ไม่พบใบงาน'));
}

if ($action === 'save') {
    $html = isset($data['html']) ? (string) $data['html'] : '';
    if (trim($html) === '' || strlen($html) > MN_ASSESS_PDF_MAX) {
        mn_assess_pdf_out($connect, array('ok' => false, 'error' => trim($html) === '' ? 'ไม่มีเนื้อหา' : 'เอกสารใหญ่เกินไป'));
    }
    $saved = mn_assess_pdf_save($connect, $trp_id, $html, isset($data['sig']) ? $data['sig'] : '', (string) $mn_logistic_recorder_value);
    if (!$saved) {
        mn_assess_pdf_out($connect, array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ'));
    }
    mn_assess_pdf_out($connect, array('ok' => true, 'edit' => $saved));
}

if ($action === 'clear') {
    $ok = mn_assess_pdf_delete($connect, $trp_id);
    mn_assess_pdf_out($connect, array('ok' => $ok, 'error' => $ok ? '' : 'ลบไม่สำเร็จ'));
}

mn_assess_pdf_out($connect, array('ok' => false, 'error' => 'bad action'));
