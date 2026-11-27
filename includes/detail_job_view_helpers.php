<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ตัวช่วยแสดงผลหน้า detail_job แบบใหม่ (B2) — วันที่ ขั้นความคืบหน้า สรุปผลประเมินจากข้อความที่บันทึก สีผลตรวจจากผลที่บันทึก และประวัติซ่อมของ S/N
 * อ่านอย่างเดียว ไม่แก้ข้อมูล
 */

if (!function_exists('mn_dj_txt')) {
    function mn_dj_txt($v)
    {
        return trim(maintenance_flow_db_text_to_utf8((string) $v));
    }
}

/** วันที่ Y-m-d → d/m/Y ('' ถ้าว่างหรือไม่ใช่วันที่) */
if (!function_exists('mn_dj_date')) {
    function mn_dj_date($v)
    {
        $s = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && $m[1] !== '0000') {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return '';
    }
}

if (!function_exists('mn_dj_days_since')) {
    function mn_dj_days_since($v)
    {
        $s = trim((string) $v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) { return null; }
        $t = strtotime(substr($s, 0, 10));
        if ($t === false) { return null; }
        return (int) floor((strtotime(date('Y-m-d')) - $t) / 86400);
    }
}

if (!function_exists('mn_dj_wait_text')) {
    function mn_dj_wait_text($days)
    {
        if ($days === null || $days < 0) { return ''; }
        if ($days === 0) { return 'วันนี้'; }
        if ($days < 60) { return 'รอ ' . $days . ' วัน'; }
        if ($days < 730) { return 'รอ ' . (int) floor($days / 30) . ' เดือน'; }
        return 'รอ ' . (int) floor($days / 365) . ' ปี+';
    }
}

/** ป้ายสถานะงาน → class สี */
if (!function_exists('mn_dj_status_tone')) {
    function mn_dj_status_tone($status)
    {
        $s = trim((string) $status);
        if ($s === 'เสร็จสิ้น') { return 'ok'; }
        if ($s === '') { return 'gray'; }
        return 'wait';
    }
}

/**
 * ขั้นความคืบหน้า 7 ขั้นของรายการซ่อม
 * คืน array ของ array('key','label','date','state' => done|now|todo|no,'sub')
 */
if (!function_exists('mn_dj_steps')) {
    function mn_dj_steps(array $r)
    {
        $status = mn_dj_txt(isset($r['trp_status_job']) ? $r['trp_status_job'] : '');
        $firm = mn_dj_txt(isset($r['trp_status_firmma']) ? $r['trp_status_firmma'] : '');
        $defs = array(
            array('receive', 'รับเครื่อง', 'trp_receive_date'),
            array('rate', 'ประเมิน', 'trp_rate_date'),
            array('qv', 'เสนอราคา', 'trp_qv_date'),
            array('firm', $firm === 'ไม่อนุมัติซ่อม' ? 'ไม่อนุมัติ' : 'อนุมัติ', 'trp_firm_qv_date'),
            array('ma', 'ซ่อม', 'trp_success_date'),
            array('qc', 'QC', 'trp_qc_date'),
            array('send', 'ส่งคืน', 'trp_send_date'),
        );
        $now_by_status = array(
            'รอเครื่องซ่อม' => 0, 'รอประเมิน' => 1, 'รอเสนอราคา' => 2, 'รออนุมัติซ่อม' => 3,
            'รอซ่อม' => 4, 'รอ QC' => 5, 'รอส่งคืนเครื่องซ่อม' => 6, 'เสร็จสิ้น' => 7,
        );
        $now = isset($now_by_status[$status]) ? $now_by_status[$status] : -1;
        if ($now < 0) {
            // สถานะอื่น (รอเครื่องสำรอง ฯลฯ): ขั้นแรกที่ยังไม่มีวันที่
            $now = 7;
            foreach ($defs as $i => $d) {
                if (mn_dj_date(isset($r[$d[2]]) ? $r[$d[2]] : '') === '') { $now = $i; break; }
            }
        }
        $out = array();
        $prev_date = isset($r['ts_date']) ? (string) $r['ts_date'] : '';
        foreach ($defs as $i => $d) {
            $raw = isset($r[$d[2]]) ? (string) $r[$d[2]] : '';
            $date = mn_dj_date($raw);
            $state = $i < $now ? 'done' : ($i === $now ? 'now' : 'todo');
            if ($d[0] === 'firm' && $firm === 'ไม่อนุมัติซ่อม' && $state === 'done') { $state = 'no'; }
            $sub = $date;
            if ($state === 'now') { $sub = mn_dj_wait_text(mn_dj_days_since($prev_date)); }
            $out[] = array('key' => $d[0], 'label' => $d[1], 'date' => $date, 'state' => $state, 'sub' => $sub);
            if ($date !== '') { $prev_date = $raw; }
        }
        return $out;
    }
}

/**
 * แยกข้อความผลประเมินที่บันทึกจากหน้าประเมินแบบใหม่
 * รูปแบบ: "1) หัวข้อ" / "   - ข้อ: ผล" / "   เสนอ: ..." หรือ "   แนวทางการแก้ไข: ..." / สรุปรายการ / "\n// ความเห็นช่าง"
 * คืน array('ok' => bool, 'groups' => [...], 'parts' => [...], 'comment' => '', 'raw' => '')
 */
if (!function_exists('mn_dj_parse_report')) {
    function mn_dj_parse_report($text)
    {
        $text = str_replace("\r\n", "\n", (string) $text);
        $comment = '';
        $k = strpos($text, "\n// ");
        $body = $k === false ? $text : substr($text, 0, $k);
        if ($k !== false) { $comment = trim(substr($text, $k + 4)); }
        $out = array('ok' => false, 'groups' => array(), 'parts' => array(), 'comment' => $comment, 'raw' => trim($text));
        if (!preg_match('/^\d+\) /m', $body)) { return $out; }
        $g = null;
        $in_sum = false;
        foreach (explode("\n", $body) as $line) {
            if (trim($line) === '') { continue; }
            if (preg_match('/^\d+\) (.+)$/u', $line, $m)) {
                if ($g) { $out['groups'][] = $g; }
                $g = array('name' => trim($m[1]), 'lines' => array(), 'fix' => '');
                $in_sum = false;
                continue;
            }
            if (preg_match('/^สรุปรายการที่เสนอ/u', $line) || preg_match('/^สรุป:/u', $line)) {
                if ($g) { $out['groups'][] = $g; $g = null; }
                $in_sum = true;
                continue;
            }
            if ($in_sum) {
                if (preg_match('/^\s*-\s*(.+)$/u', $line, $m)) { $out['parts'][] = trim($m[1]); }
                continue;
            }
            if (!$g) { continue; }
            if (preg_match('/^\s+(?:เสนอ|แนวทางการแก้ไข):\s*(.+)$/u', $line, $m)) { $g['fix'] = trim($m[1]); continue; }
            if (preg_match('/^\s*-\s*(.+?):\s(.*)$/u', $line, $m)) {
                $g['lines'][] = array('label' => trim($m[1]), 'text' => trim($m[2]));
                continue;
            }
            if (preg_match('/^\s*-\s*(.+)$/u', $line, $m)) {
                $g['lines'][] = array('label' => '', 'text' => trim($m[1]));
            }
        }
        if ($g) { $out['groups'][] = $g; }
        // ข้อความแบบเดิมที่ขึ้นต้น "1) ..." แต่ไม่มีบรรทัด "   - ข้อ: ผล" ไม่ใช่สรุปจากหน้าประเมินใหม่
        $has_lines = false;
        foreach ($out['groups'] as $gg) {
            if ($gg['lines']) { $has_lines = true; break; }
        }
        $out['ok'] = $has_lines;
        if (!$has_lines) { $out['groups'] = array(); $out['parts'] = array(); }
        return $out;
    }
}

/**
 * สีผลตรวจจากผลที่บันทึกในใบงาน (cl_job_item) — คืน array('q' => [คำถาม => tone], 'r' => [ข้อความผล => tone])
 * tone: bad | warn | good | na
 */
if (!function_exists('mn_dj_tone_map')) {
    function mn_dj_tone_map($connect, $trp_id)
    {
        $map = array('q' => array(), 'r' => array());
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) { return $map; }
        $stmt = @mysqli_prepare($connect,
            'SELECT i.ci_question, i.ci_opts, i.ci_opt_oks, i.ci_multi, ji.cji_result'
            . ' FROM cl_job_item ji INNER JOIN cl_item i ON i.ci_id = ji.cji_item_id WHERE ji.cji_trp_id = ?');
        if (!$stmt) { return $map; }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $result = trim((string) $r['cji_result']);
            if ($result === '') { continue; }
            $tone = 'na';
            if ($result === 'pass') { $tone = 'good'; }
            elseif ($result === 'fail') { $tone = 'bad'; }
            elseif ($result === 'near') { $tone = 'warn'; }
            elseif ($result !== 'na') {
                $opts = array();
                foreach (explode('|', (string) $r['ci_opts']) as $o) {
                    $o = trim($o);
                    if (substr($o, -5) === ':note') { $o = substr($o, 0, -5); }
                    $opts[] = $o;
                }
                $oks = json_decode((string) $r['ci_opt_oks'], true);
                $picked = !empty($r['ci_multi']) ? explode('|', $result) : array($result);
                $worst = -1;
                foreach ($picked as $p) {
                    $i = array_search(trim($p), $opts, true);
                    if ($i === false) { continue; }
                    if ($i > 0 && is_array($oks) && !empty($oks[$i])) { $i = 0; }
                    $worst = max($worst, (int) $i);
                    $map['r'][trim($p)] = $i === 0 ? 'good' : ($i === 1 ? 'warn' : 'bad');
                }
                if ($worst >= 0) { $tone = $worst === 0 ? 'good' : ($worst === 1 ? 'warn' : 'bad'); }
            }
            $q = trim((string) $r['ci_question']);
            // คำถามซ้ำในหลายหัวข้อ: เก็บผลที่หนักกว่า
            $rank = array('na' => 0, 'good' => 1, 'warn' => 2, 'bad' => 3);
            if (!isset($map['q'][$q]) || $rank[$tone] > $rank[$map['q'][$q]]) { $map['q'][$q] = $tone; }
        }
        mysqli_stmt_close($stmt);
        return $map;
    }
}

/** สีของบรรทัดผลตรวจ (เทียบข้อความผลก่อน แล้วค่อยเทียบชื่อข้อ) */
if (!function_exists('mn_dj_line_tone')) {
    function mn_dj_line_tone(array $map, $label, $text)
    {
        $best = '';
        foreach ($map['r'] as $r => $tone) {
            if ($r !== '' && strpos($text, $r) === 0 && strlen($r) > strlen($best)) { $best = $r; }
        }
        if ($best !== '') { return $map['r'][$best]; }
        if (isset($map['q'][$label])) { return $map['q'][$label]; }
        if (preg_match('/^(ปกติ|ไม่ได้ตรวจ|ยังไม่เกิน)|อยู่ในค่ามาตรฐาน/u', $text)) { return 'good'; }
        return '';
    }
}

/**
 * รายละเอียดผล QC ของรายการซ่อม จากตาราง logqc_* (หมวด/ชื่อข้อตามฟอร์ม qc_checklist)
 * คืน null ถ้าไม่มีบันทึก หรือ array('groups' => [[title, [[label, value, tone]]]], 'comment' => '', 'checked' => n, 'bad' => n)
 * tone: ok (OK) | bad (ไม่ผ่าน/NG) | '' (ค่าวัด)
 */
if (!function_exists('mn_dj_qc_detail')) {
    function mn_dj_qc_detail($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) { return null; }
        // field => array(หมวด, ชื่อข้อ, หน่วย)
        $F = array(
            'qc_vusbvolt' => array('HARDWARE / CAMERA', 'การจ่ายไฟช่อง USB', 'V'), 'qc_vledlan' => array('HARDWARE / CAMERA', 'สถานะไฟ Lan', ''),
            'qc_vcamerafocus' => array('HARDWARE / CAMERA', 'โฟกัสกล้อง A', ''), 'qc_vledcard' => array('HARDWARE / CAMERA', 'ไฟ LED ถ่ายบัตร', ''),
            'qc_vsoundlevel' => array('HARDWARE / CAMERA', 'ระดับเสียงลำโพง', ''), 'qc_vtestprint' => array('HARDWARE / CAMERA', 'Print test', ''),
            'qc_vfirmware' => array('DATA CONNECTION', 'Firmware', ''), 'qc_vtimestart' => array('DATA CONNECTION', 'เวลาเปิดเครื่อง', ''),
            'qc_vcpuuse' => array('DATA CONNECTION', 'CPU temp.', '°C'), 'qc_vramuse' => array('DATA CONNECTION', 'RAM', 'MB'),
            'qc_vexportlan' => array('DATA CONNECTION', 'ดึงข้อมูลผ่าน LAN', ''), 'qc_vexportusb' => array('DATA CONNECTION', 'ดึงข้อมูลผ่าน Flash drive', ''),
            'qc_vtouch' => array('DATA CONNECTION', 'Touch screen', ''), 'qc_vsleepmode' => array('DATA CONNECTION', 'Sleep mode', ''),
            'qc_vnoopenclose' => array('DATA CONNECTION', 'เปิด-ปิด เครื่อง', 'ครั้ง'), 'qc_vinout' => array('DATA CONNECTION', 'IN-OUT', 'ครั้ง'),
            'qc_sscan' => array('BITSCAN / BITVEHICLE', 'ทดสอบการยิง', ''), 'qc_sphasescan' => array('BITSCAN / BITVEHICLE', 'ระยะการยิง', ''),
            'qc_ssound_shake_light' => array('BITSCAN / BITVEHICLE', 'เสียง สั่น แสง', ''), 'qc_sloaddata' => array('BITSCAN / BITVEHICLE', 'โหลดข้อมูล', ''),
            'qc_scled' => array('Smart Card', 'สถานะไฟ LED', ''), 'qc_scslotcard' => array('Smart Card', 'ความแน่นช่องเสียบบัตร', ''), 'qc_screadcard' => array('Smart Card', 'ทดสอบอ่านบัตร', ''),
            'qc_mtled' => array('Magnetic', 'สถานะไฟ LED', ''), 'qc_mttrackerror' => array('Magnetic', 'เช็คปิด Track error', ''), 'qc_mtreadcard' => array('Magnetic', 'ทดสอบอ่านบัตร', ''),
            'qc_plogo' => array('Printer', 'Logo ตรงกับหน่วยงาน', ''), 'qc_psharpness' => array('Printer', 'ความคมชัดสลิป', ''), 'qc_psoundprint' => array('Printer', 'เสียงขณะพิมพ์', ''),
            'qc_pdatacable' => array('Printer', 'มีสาย data มาด้วย', ''), 'qc_psticker_lock' => array('Printer', 'สติ๊กเกอร์ล็อคสาย', ''),
            'qc_snledred' => array('Scanner', 'ไฟเข้า (แดง)', ''), 'qc_snledblue' => array('Scanner', 'ไฟการอ่าน (ฟ้า)', ''), 'qc_snrj45' => array('Scanner', 'Jack RJ45', ''), 'qc_snreadqr' => array('Scanner', 'อ่าน QR (PR)', ''),
            'qc_swcable' => array('Scanner Wireless', 'สาย USB แน่น', ''), 'qc_swportusb' => array('Scanner Wireless', 'หยอดกาว Port USB ฐาน', ''), 'qc_swresetbutton' => array('Scanner Wireless', 'ปุ่ม Reset (BD-6530BT)', ''),
            'qc_swresetpair' => array('Scanner Wireless', 'Auto pair หลัง Reset', ''), 'qc_swhandheldledrgb' => array('Scanner Wireless', 'ไฟ Scanner ครบ RGB', ''), 'qc_swreseiveledrgb' => array('Scanner Wireless', 'ไฟฐานรับครบ RGB', ''),
            'qc_swreadqr' => array('Scanner Wireless', 'อ่าน QR (PR)', ''),
            'qc_bssoundalert' => array('BIT SUPPLY', 'เสียงเตือนปลั๊กหลุด/ไฟดับ', ''), 'qc_bsledacdc' => array('BIT SUPPLY', 'ไฟ AC DC', ''), 'qc_bspower' => array('BIT SUPPLY', 'การจ่ายไฟปกติ', ''), 'qc_bspowerprint' => array('BIT SUPPLY', 'จ่ายไฟตอน Test print', ''),
            'qc_ccled' => array('Card Cam', 'การ Focus', ''), 'qc_ccfocus' => array('Card Cam', 'สถานะไฟ LED', ''), 'qc_ccacrylic' => array('Card Cam', 'มีแผ่นใสมาด้วย', ''),
            'qc_bstled' => array('BIT STAMP', 'ความสว่าง LED', ''), 'qc_bstreadrfid' => array('BIT STAMP', 'อ่านบัตร RFiD', ''), 'qc_bstusb' => array('BIT STAMP', 'Port USB', ''),
            'qc_bstopenclose' => array('BIT STAMP', 'เทสเปิด-ปิด', ''), 'qc_bstsettingmode' => array('BIT STAMP', 'เข้าโหมด Setting', ''),
            'qc_a4focus' => array('A4 Camera', 'Fix Focus', ''), 'qc_a4position' => array('A4 Camera', 'มุมกล้อง', ''),
            'qc_kbbuttonvalue' => array('Keyboard', 'ค่าปุ่มกดถูกต้อง', ''), 'qc_kbbuttonok' => array('Keyboard', 'ปุ่มกดไม่ยุบ', ''),
            'qc_mbtranferimg' => array('Mobile', 'การโอนรูปภาพ', ''), 'qc_mbclearcache' => array('Mobile', 'Clear cache มือถือ', ''),
            'qc_rtsetok' => array('Router', 'Set OK', ''), 'qc_fcfocus' => array('FaceCam', 'Auto Focus', ''), 'qc_checkok' => array('สินค้าอื่น ๆ', 'ผลการตรวจ QC', ''),
        );
        $tables = array('logqc_bitvisitor', 'logqc_bitscan', 'logqc_printer', 'logqc_smartcard', 'logqc_magnetic', 'logqc_scanner', 'logqc_scanner_wireless',
            'logqc_bitsupply', 'logqc_cardcam', 'logqc_bitstamp', 'logqc_a4camera', 'logqc_keyboard', 'logqc_mobile', 'logqc_router', 'logqc_facecam', 'logqc_other');
        foreach ($tables as $t) {
            $stmt = @mysqli_prepare($connect, 'SELECT * FROM `' . $t . '` WHERE trp_id = ? ORDER BY logqc_id DESC LIMIT 1');
            if (!$stmt) { continue; }
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if (!$row) { continue; }
            $groups = array();
            $checked = 0;
            $bad = 0;
            foreach ($row as $k => $v) {
                if (!isset($F[$k])) { continue; }
                $v = mn_dj_txt($v);
                $tone = '';
                if ($v !== '') {
                    $checked++;
                    if (preg_match('/^(ok|yes|ผ่าน|ปกติ|มี)$/iu', $v)) { $tone = 'ok'; $v = 'ผ่าน'; }
                    elseif (preg_match('/^(ng|no|ไม่ผ่าน|เสีย|fail)/iu', $v)) { $tone = 'bad'; $bad++; }
                    elseif ($F[$k][2] !== '' && is_numeric($v)) { $v .= ' ' . $F[$k][2]; }
                }
                $groups[$F[$k][0]][] = array($F[$k][1], $v, $tone);
            }
            $out = array();
            foreach ($groups as $title => $items) { $out[] = array($title, $items); }
            return array('table' => $t, 'groups' => $out, 'comment' => isset($row['qc_comment']) ? mn_dj_txt($row['qc_comment']) : '', 'checked' => $checked, 'bad' => $bad);
        }
        return null;
    }
}

/**
 * วันที่ในตาราง repair_history (ระบบเดิม) เป็น ว/ด/ปี พ.ศ. เช่น 3/4/2563 → array('ymd' => '2020-04-03', 'show' => '03/04/2020', 'year' => '2020')
 */
if (!function_exists('mn_dj_old_date')) {
    function mn_dj_old_date($v)
    {
        $s = trim((string) $v);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            $y = (int) $m[3];
            if ($y > 2400) { $y -= 543; }
            $ymd = sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]);
            return array('ymd' => $ymd, 'show' => mn_dj_date($ymd), 'year' => (string) $y);
        }
        if (mn_dj_date($s) !== '') {
            return array('ymd' => substr($s, 0, 10), 'show' => mn_dj_date($s), 'year' => substr($s, 0, 4));
        }
        return array('ymd' => '', 'show' => $s, 'year' => '');
    }
}

/**
 * ประวัติซ่อมของ S/N (ไม่รวมใบงานปัจจุบัน) ใหม่ → เก่า สำหรับหน้าต่างประวัติ
 */
if (!function_exists('mn_dj_history')) {
    function mn_dj_history($connect, $sn, $exclude_ts_id)
    {
        $sn = trim((string) $sn);
        $out = array();
        if ($sn === '' || $sn === '-' || !($connect instanceof mysqli)) { return $out; }

        $stmt = @mysqli_prepare($connect, 'SELECT rh_date, rh_mtn_number, rh_com_enduser, rh_inform_repair, rh_repair FROM repair_history WHERE rh_sn = ? ORDER BY rh_id');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $sn);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $od = mn_dj_old_date($r['rh_date']);
                $out[] = array('old' => true, 'sort' => $od['ymd'], 'ts_id' => 0, 'no' => mn_dj_txt($r['rh_mtn_number']),
                    'recv' => $od['show'], 'year' => $od['year'],
                    'company' => mn_dj_txt($r['rh_com_enduser']), 'inform' => mn_dj_txt($r['rh_inform_repair']), 'tech' => '',
                    'parts' => array(), 'extra' => array(), 'approve' => '', 'ins' => '', 'qv' => '', 'note' => '',
                    'rate' => '', 'fix' => mn_dj_txt($r['rh_repair']), 'send' => '', 'status' => '');
            }
            mysqli_stmt_close($stmt);
        }

        $cols = array();
        for ($i = 1; $i <= 10; $i++) { $cols[] = 'r.trp_repair_no' . $i; }
        $stmt = mysqli_prepare($connect,
            'SELECT t.ts_id, t.ts_mtn_number, t.ts_enduser_com, r.trp_receive_date, r.trp_repair_inform, r.trp_repair_technician,'
            . ' r.trp_rate_date, r.trp_user_rate, ' . implode(', ', $cols) . ', r.trp_clean, r.trp_repeat_solder, r.trp_spray, r.trp_fw,'
            . ' r.trp_insurance, r.trp_qv_number, r.trp_qv_price, r.trp_status_firmma, r.trp_backup, r.trp_success_date, r.trp_user_ma,'
            . ' r.trp_send_date, r.trp_status_job'
            . ' FROM transac t INNER JOIN transac_repair r ON t.ts_id = r.trp_ts_id WHERE r.trp_sn = ? AND t.ts_id <> ? ORDER BY t.ts_id');
        if ($stmt) {
            $ex = (int) $exclude_ts_id;
            mysqli_stmt_bind_param($stmt, 'si', $sn, $ex);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $parts = array();
                for ($i = 1; $i <= 10; $i++) {
                    $p = mn_dj_txt($r['trp_repair_no' . $i]);
                    if ($p !== '') { $parts[] = $p; }
                }
                $extra = array();
                foreach (array('trp_clean', 'trp_repeat_solder', 'trp_spray', 'trp_fw') as $k) {
                    $p = mn_dj_txt($r[$k]);
                    if ($p !== '') { $extra[] = $p; }
                }
                $qv_no = mn_dj_txt($r['trp_qv_number']);
                $qv_price = mn_dj_txt($r['trp_qv_price']);
                $qv = '';
                if ($qv_no === '-') { $qv = 'ไม่เสนอราคา (ในประกัน)'; }
                elseif ($qv_no !== '') { $qv = $qv_no . ($qv_price !== '' && $qv_price !== '0' ? ' ' . $qv_price . ' บาท' : ''); }
                $tech = str_replace("\r\n", "\n", mn_dj_txt($r['trp_repair_technician']));
                $rep = mn_dj_parse_report($tech);
                if ($rep['ok']) {
                    // แบบประเมินใหม่: สรุปเฉพาะข้อที่ผิดปกติ + ความเห็นช่าง
                    $lines = array();
                    foreach ($rep['groups'] as $g) {
                        if ($g['fix'] !== '') { $lines[] = $g['name'] . ': ' . $g['fix']; }
                    }
                    $tech = trim(implode("\n", $lines) . ($rep['comment'] !== '' ? "\n" . $rep['comment'] : ''));
                }
                $date_raw = (string) $r['trp_receive_date'];
                $by_rate = mn_dj_txt($r['trp_user_rate']);
                $by_ma = mn_dj_txt($r['trp_user_ma']);
                $out[] = array('old' => false, 'sort' => $date_raw, 'ts_id' => (int) $r['ts_id'], 'no' => mn_dj_txt($r['ts_mtn_number']),
                    'recv' => mn_dj_date($date_raw), 'year' => substr($date_raw, 0, 4),
                    'company' => mn_dj_txt($r['ts_enduser_com']), 'inform' => mn_dj_txt($r['trp_repair_inform']), 'tech' => $tech,
                    'parts' => $parts, 'extra' => $extra, 'approve' => mn_dj_txt($r['trp_status_firmma']), 'ins' => mn_dj_txt($r['trp_insurance']),
                    'qv' => $qv, 'note' => mn_dj_txt($r['trp_backup']),
                    'rate' => trim(mn_dj_date($r['trp_rate_date']) . ' ' . $by_rate), 'fix' => trim(mn_dj_date($r['trp_success_date']) . ' ' . $by_ma),
                    'send' => mn_dj_date($r['trp_send_date']), 'status' => mn_dj_txt($r['trp_status_job']));
            }
            mysqli_stmt_close($stmt);
        }
        usort($out, function ($a, $b) { return strcmp($b['sort'], $a['sort']); });
        $n = count($out);
        foreach ($out as $i => &$e) { $e['n'] = $n - $i; }
        unset($e);
        return $out;
    }
}
