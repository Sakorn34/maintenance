<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * แผงข้างของหน้าประเมินเครื่อง (assess_repair / checklist_try) — ซ้าย: ข้อมูลเครื่อง | ขวา: ประวัติการซ่อม
 * แสดงเฉพาะจอกว้าง (CSS) จอแคบยังดูในขั้น "ข้อมูลเครื่อง" ตามเดิม
 *
 * ต้องการ: function_legacy.php (maintenance_legacy_tis620_to_utf8) เมื่อเรียก mn_ar_fetch_history
 */

if (!function_exists('mn_ar_side_h')) {
    function mn_ar_side_h($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mn_ar_side_txt')) {
    function mn_ar_side_txt($v)
    {
        return trim(maintenance_legacy_tis620_to_utf8($v));
    }
}

/**
 * ประวัติการซ่อมของ SN — ทั้งจากตารางเก่า repair_history และใบงานในระบบ (ไม่รวมใบงานปัจจุบัน) เรียงใหม่ → เก่า
 * คืนค่า array ของ array('no','date','number','company','inform','assess','parts','remarks','rater','by','status')
 * ใบงานในระบบมี 'firm','firm_date','qv_no','qv_price','firm_note' (ผลอนุมัติ) ส่วนประวัติระบบเก่ามี 'old' => true
 */
if (!function_exists('mn_ar_fetch_history')) {
    function mn_ar_fetch_history($connect, $sn, $exclude_ts_id)
    {
        $sn = trim((string) $sn);
        if ($sn === '' || $sn === '-') { return array(); }
        $out = array();

        $stmt = mysqli_prepare($connect,
            'SELECT rh_date, rh_mtn_number, rh_com_security, rh_com_enduser, rh_inform_repair, rh_repair'
            . ' FROM repair_history WHERE rh_sn = ? ORDER BY rh_id'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $sn);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $out[] = array(
                    'date'    => (string) $r['rh_date'],
                    'number'  => (string) $r['rh_mtn_number'],
                    'company' => trim(mn_ar_side_txt($r['rh_com_security']) . ' — ' . mn_ar_side_txt($r['rh_com_enduser']), ' —'),
                    'inform'  => mn_ar_side_txt($r['rh_inform_repair']),
                    'assess'  => '',
                    'parts'   => array(),
                    'remarks' => '',
                    'rater'   => '',
                    'by'      => mn_ar_side_txt($r['rh_repair']),
                    'status'  => '',
                    'old'     => true,
                );
            }
            mysqli_stmt_close($stmt);
        }

        $cols = array();
        for ($i = 1; $i <= 10; $i++) { $cols[] = 'r.trp_repair_no' . $i; }
        $stmt = mysqli_prepare($connect,
            'SELECT r.trp_id, t.ts_mtn_number, t.ts_security_com, t.ts_enduser_com, r.trp_receive_date, r.trp_repair_inform,'
            . ' r.trp_user_rate, r.trp_repair_technician, ' . implode(', ', $cols) . ','
            . ' r.trp_clean, r.trp_repeat_solder, r.trp_spray, r.trp_fw, r.trp_repair_remarks, r.trp_user_ma, r.trp_status_job,'
            . ' r.trp_status_firmma, r.trp_firm_qv_date, r.trp_qv_number, r.trp_qv_price, r.trp_backup'
            . ' FROM transac t INNER JOIN transac_repair r ON t.ts_id = r.trp_ts_id'
            . ' WHERE r.trp_sn = ? AND t.ts_id <> ? ORDER BY t.ts_id'
        );
        if ($stmt) {
            $ex = (int) $exclude_ts_id;
            mysqli_stmt_bind_param($stmt, 'si', $sn, $ex);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $parts = array();
                for ($i = 1; $i <= 10; $i++) {
                    $p = mn_ar_side_txt($r['trp_repair_no' . $i]);
                    if ($p !== '') { $parts[] = $p; }
                }
                foreach (array('trp_clean', 'trp_repeat_solder', 'trp_spray', 'trp_fw') as $k) {
                    $p = mn_ar_side_txt($r[$k]);
                    if ($p !== '') { $parts[] = $p; }
                }
                $out[] = array(
                    'trp_id'  => (int) $r['trp_id'],
                    'date'    => (string) $r['trp_receive_date'],
                    'number'  => (string) $r['ts_mtn_number'],
                    'company' => trim(mn_ar_side_txt($r['ts_security_com']) . ' — ' . mn_ar_side_txt($r['ts_enduser_com']), ' —'),
                    'inform'  => mn_ar_side_txt($r['trp_repair_inform']),
                    'assess'  => mn_ar_side_txt($r['trp_repair_technician']),
                    'parts'   => $parts,
                    'remarks' => mn_ar_side_txt($r['trp_repair_remarks']),
                    'rater'   => mn_ar_side_txt($r['trp_user_rate']),
                    'by'      => mn_ar_side_txt($r['trp_user_ma']),
                    'status'  => mn_ar_side_txt($r['trp_status_job']),
                    // ผลอนุมัติ (ช่องเดียวกับหน้าใบงาน: trp_backup = หมายเหตุอนุมัติ)
                    'firm'      => mn_ar_side_txt($r['trp_status_firmma']),
                    'firm_date' => (string) $r['trp_firm_qv_date'],
                    'qv_no'     => mn_ar_side_txt($r['trp_qv_number']),
                    'qv_price'  => mn_ar_side_txt($r['trp_qv_price']),
                    'firm_note' => mn_ar_side_txt($r['trp_backup']),
                );
            }
            mysqli_stmt_close($stmt);
        }

        foreach ($out as $i => &$e) { $e['no'] = $i + 1; }
        unset($e);
        return array_reverse($out);
    }
}

/**
 * $info = array('product','sn','receive','company','buy','age','insurance','ins_in','inform','remarks')
 */
if (!function_exists('mn_ar_info_panel')) {
    function mn_ar_info_panel(array $info, $demo = false)
    {
        $h = 'mn_ar_side_h';
        $row = function ($label, $value) use ($h) {
            return '<div><dt>' . $h($label) . '</dt><dd>' . ($value !== '' ? $value : '<span class="mn-ar-muted">—</span>') . '</dd></div>';
        };
        $ins = (string) $info['insurance'];
        $html = '<aside class="mn-ar-side mn-ar-side--info" aria-label="ข้อมูลเครื่องที่กำลังประเมิน">'
            . '<div class="mn-ar-side__head"><i class="fa fa-desktop" aria-hidden="true"></i><span>เครื่องที่กำลังประเมิน</span>'
            . ($demo ? '<span class="mn-ar-demo-badge">ข้อมูลตัวอย่าง</span>' : '') . '</div>'
            . '<div class="mn-ar-side__body">'
            . (isset($info['top_html']) ? (string) $info['top_html'] : '')
            . '<div class="mn-ar-side__product">' . $h($info['product']) . '</div>'
            . '<div class="mn-ar-side__sn">SN ' . $h($info['sn'] !== '' ? $info['sn'] : '—') . '</div>'
            . '<dl class="mn-ar-side__dl">'
            . $row('วันที่รับซ่อม', $h($info['receive']))
            . $row('บริษัท', nl2br($h($info['company'])))
            . $row('วันที่ซื้อ', $h($info['buy']) . ($info['age'] !== '' ? ' <span class="mn-ar-muted">' . $h($info['age']) . '</span>' : ''))
            . $row('ประกัน', '<span class="mn-ar-tag ' . (!empty($info['ins_in']) ? 'mn-ar-tag--ok' : 'mn-ar-tag--off') . '">' . $h($ins !== '' ? $ins : '—') . '</span>')
            . '</dl>'
            . '<div class="mn-ar-side__note"><span class="mn-ar-note__lbl">อาการที่แจ้ง</span><p>' . nl2br($h($info['inform'] !== '' ? $info['inform'] : '—')) . '</p></div>'
            . (trim((string) $info['remarks']) !== ''
                ? '<div class="mn-ar-side__note mn-ar-side__note--warn"><span class="mn-ar-note__lbl">หมายเหตุใบงาน</span><p>' . nl2br($h($info['remarks'])) . '</p></div>'
                : '')
            . ($demo ? '<p class="mn-ar-side__foot">งานจริงแสดงข้อมูลจากใบงานที่กำลังประเมิน</p>' : '')
            . (isset($info['foot']) && $info['foot'] !== '' ? '<p class="mn-ar-side__foot">' . $h($info['foot']) . '</p>' : '')
            . '</div></aside>';
        return $html;
    }
}

/**
 * ผลอนุมัติของรายการประวัติ 1 ใบงาน → array('tone' ok|no|wait|na, 'icon', 'label', 'date', 'qv' (HTML), 'note')
 */
if (!function_exists('mn_ar_hist_approval')) {
    function mn_ar_hist_approval(array $e)
    {
        $h = 'mn_ar_side_h';
        $out = array('tone' => 'na', 'icon' => 'fa-minus', 'label' => 'ไม่มีข้อมูลอนุมัติ (ระบบเก่า)', 'date' => '', 'qv' => '', 'note' => '');
        if (!empty($e['old']) || !array_key_exists('firm', $e)) { return $out; }
        $firm = trim((string) $e['firm']);
        if (strpos($firm, 'ไม่อนุมัติ') !== false) {
            $out['tone'] = 'no'; $out['icon'] = 'fa-times'; $out['label'] = 'ไม่อนุมัติซ่อม';
        } elseif (strpos($firm, 'อนุมัติ') !== false) {
            $out['tone'] = 'ok'; $out['icon'] = 'fa-check'; $out['label'] = 'อนุมัติซ่อม';
        } else {
            $out['tone'] = 'wait'; $out['icon'] = 'fa-clock-o'; $out['label'] = 'ยังไม่มีผลอนุมัติ';
        }
        $d = isset($e['firm_date']) ? substr(trim((string) $e['firm_date']), 0, 10) : '';
        if ($out['tone'] !== 'wait' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strpos($d, '0000') !== 0) { $out['date'] = $d; }
        $no = isset($e['qv_no']) ? trim((string) $e['qv_no']) : '';
        $price = isset($e['qv_price']) ? trim((string) $e['qv_price']) : '';
        if ($no === '-') {
            $out['qv'] = 'ไม่เสนอราคา (ในประกัน)';
        } elseif ($no !== '' && $no !== '0') {
            $out['qv'] = '<b>' . $h($no) . '</b>' . ($price !== '' && $price !== '0' ? ' ' . $h($price) . ' บาท' : '');
        }
        // หมายเหตุอนุมัติ: ตัด * ที่พิมพ์เน้นไว้หัว/ท้าย
        $out['note'] = isset($e['firm_note']) ? trim((string) $e['firm_note'], " \t\r\n*") : '';
        return $out;
    }
}

/**
 * รายการประวัติ (ใช้ทั้งแผงขวาและขั้น "ข้อมูลเครื่อง" บนจอแคบ)
 */
if (!function_exists('mn_ar_history_list')) {
    function mn_ar_history_list(array $entries)
    {
        $h = 'mn_ar_side_h';
        if (!$entries) {
            return '<p class="mn-ar-hist__empty"><i class="fa fa-check-circle-o" aria-hidden="true"></i> ไม่พบประวัติการซ่อม</p>';
        }
        $html = '<ol class="mn-ar-hist">';
        foreach ($entries as $e) {
            $line = function ($label, $value, $cls = '') use ($h) {
                if (trim((string) $value) === '') { return ''; }
                return '<div class="mn-ar-hist__line' . $cls . '"><span>' . $h($label) . '</span>' . nl2br($h($value)) . '</div>';
            };
            $ap = mn_ar_hist_approval($e);
            $html .= '<li class="mn-ar-hist__item">'
                . '<div class="mn-ar-hist__band is-' . $ap['tone'] . '"><i class="fa ' . $ap['icon'] . '" aria-hidden="true"></i>' . $h($ap['label'])
                . ($ap['date'] !== '' ? '<small>' . $h($ap['date']) . '</small>' : '') . '</div>'
                . '<div class="mn-ar-hist__in">'
                . '<div class="mn-ar-hist__top"><span class="mn-ar-hist__no">ครั้งที่ ' . (int) $e['no'] . '</span>'
                . '<span class="mn-ar-hist__date">' . $h($e['date']) . '</span></div>'
                . ($e['number'] !== '' || $e['status'] !== ''
                    ? '<div class="mn-ar-hist__meta">' . ($e['number'] !== '' ? '<span>' . $h($e['number']) . '</span>' : '')
                        . ($e['status'] !== '' ? '<span class="mn-ar-hist__status">' . $h($e['status']) . '</span>' : '') . '</div>'
                    : '')
                . ($ap['qv'] !== '' ? '<div class="mn-ar-hist__qv">ใบเสนอราคา ' . $ap['qv'] . '</div>' : '')
                . ($ap['note'] !== ''
                    ? '<div class="mn-ar-hist__why is-' . $ap['tone'] . '"><span>' . ($ap['tone'] === 'no' ? 'เหตุผลที่ไม่ซ่อม' : 'หมายเหตุอนุมัติ') . '</span>' . nl2br($h($ap['note'])) . '</div>'
                    : '')
                . $line('อาการที่แจ้ง', $e['inform'])
                . $line('การประเมิน', $e['assess']);
            if ($e['parts']) {
                $html .= '<div class="mn-ar-hist__line"><span>' . ($ap['tone'] === 'no' ? 'รายการที่เสนอ (ไม่ได้ซ่อม)' : 'รายการซ่อม') . '</span>'
                    . '<div class="mn-ar-hist__parts' . ($ap['tone'] === 'no' ? ' is-off' : '') . '">';
                foreach ($e['parts'] as $p) { $html .= '<span class="mn-ar-hist__part">' . $h($p) . '</span>'; }
                $html .= '</div></div>';
            }
            if (!empty($e['photos']) && function_exists('mn_photo_strip_html')) {
                $html .= '<div class="mn-ar-hist__line"><span>รูปประกอบ (' . count($e['photos']) . ')</span>' . mn_photo_strip_html($e['photos'], 6, (int) $e['trp_id']) . '</div>';
            }
            $html .= $line('หมายเหตุ', $e['remarks'], ' mn-ar-hist__line--warn')
                . $line('การแก้ไข/ซ่อมโดย', $e['by'])
                . $line('ประเมินโดย', $e['rater'])
                . ($e['company'] !== '' ? '<div class="mn-ar-hist__co">' . $h($e['company']) . '</div>' : '')
                . '</div></li>';
        }
        return $html . '</ol>';
    }
}

if (!function_exists('mn_ar_history_panel')) {
    function mn_ar_history_panel(array $entries, $demo = false)
    {
        return '<aside class="mn-ar-side mn-ar-side--hist" aria-label="ประวัติการซ่อม">'
            . '<div class="mn-ar-side__head"><i class="fa fa-history" aria-hidden="true"></i><span>ประวัติการซ่อม</span>'
            . ($demo ? '<span class="mn-ar-demo-badge">ข้อมูลตัวอย่าง</span>' : '<span class="mn-ar-side__count">' . count($entries) . ' ครั้ง</span>')
            . '</div><div class="mn-ar-side__body">' . mn_ar_history_list($entries) . '</div></aside>';
    }
}
