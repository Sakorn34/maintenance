<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * flow เครื่องสำรองแบบมีสถานะต่อเครื่อง + ไทม์ไลน์เหตุการณ์ (ตีกลับ / ส่งใหม่ / ลูกค้ามารับเอง / เปลี่ยน / สลับ / คืน / ยกเลิก / สูญหาย)
 * เก็บเหตุการณ์ในตาราง `cl_spare_event` (สร้างเองถ้ายังไม่มี) — ข้อมูลส่ง/คืนปัจจุบันยังอยู่ใน `transac_spare` ตามเดิม
 * เพื่อให้หน้าอื่น (รอส่งพัสดุ, รอเครื่องสำรอง, ปิดงาน) ใช้คอลัมน์เดิมได้
 *
 * สถานะ (key): prep เตรียมส่ง | ship กำลังส่ง | bounce ตีกลับ | cust อยู่กับลูกค้า | wait รอรับคืน | back คืนแล้ว | cancel ยกเลิก | lost สูญหาย/เสียหาย
 *
 * ต้องการ: maintenance_flow_sql_helpers.php, detail_job_edit_helpers.php (validate วันที่/ขนส่ง), status_logistic_helpers.php
 */

if (!function_exists('mn_spare_ensure_table')) {
    function mn_spare_ensure_table($connect)
    {
        static $done = false;
        if ($done || !($connect instanceof mysqli)) { return; }
        $done = true;
        @mysqli_query($connect, 'CREATE TABLE IF NOT EXISTS cl_spare_event ('
            . ' se_id INT AUTO_INCREMENT PRIMARY KEY,'
            . ' se_tsp_id INT NOT NULL,'
            . ' se_trp_id INT NOT NULL DEFAULT 0,'
            . ' se_ts_id INT NOT NULL DEFAULT 0,'
            . ' se_type VARCHAR(20) NOT NULL,'
            . ' se_sn VARCHAR(50) NULL,'
            . ' se_sn_old VARCHAR(50) NULL,'
            . ' se_date VARCHAR(10) NULL,'
            . ' se_send_date VARCHAR(10) NULL,'
            . ' se_logistics VARCHAR(50) NULL,'
            . ' se_tracking VARCHAR(50) NULL,'
            . ' se_sendby VARCHAR(50) NULL,'
            . ' se_reason VARCHAR(255) NULL,'
            . ' se_person VARCHAR(100) NULL,'
            . ' se_ref_tsp_id INT NOT NULL DEFAULT 0,'
            . ' se_by VARCHAR(100) NULL,'
            . ' se_at DATETIME NOT NULL,'
            . ' KEY k_tsp (se_tsp_id), KEY k_trp (se_trp_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}

/** ป้าย/สีของแต่ละสถานะ */
if (!function_exists('mn_spare_state_meta')) {
    function mn_spare_state_meta($state)
    {
        $m = array(
            'prep'   => array('เตรียมส่ง', 'fa-cube'),
            'ship'   => array('กำลังส่ง', 'fa-truck'),
            'bounce' => array('ตีกลับ รอตัดสินใจ', 'fa-reply'),
            'cust'   => array('อยู่กับลูกค้า', 'fa-home'),
            'wait'   => array('รอรับคืน', 'fa-hourglass-half'),
            'back'   => array('คืนแล้ว', 'fa-check'),
            'cancel' => array('ยกเลิก', 'fa-ban'),
            'lost'   => array('สูญหาย/เสียหาย', 'fa-question'),
        );
        return isset($m[$state]) ? $m[$state] : array($state, 'fa-circle');
    }
}

/** แปลงวันที่จากคอลัมน์เดิม (หลายรูปแบบ) → Y-m-d หรือ '' */
if (!function_exists('mn_spare_ymd')) {
    function mn_spare_ymd($v)
    {
        $v = trim((string) $v);
        if ($v === '' || strpos($v, '0000') === 0) { return ''; }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            $y = (int) $m[1];
            if ($y > 2400) { $y -= 543; }
            return sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[3]);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m)) {
            $y = (int) $m[3];
            if ($y > 2400) { $y -= 543; }
            return sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]);
        }
        return '';
    }
}

if (!function_exists('mn_spare_dmy')) {
    function mn_spare_dmy($ymd)
    {
        $ymd = mn_spare_ymd($ymd);
        return $ymd === '' ? '' : substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }
}

/** เหตุการณ์ทั้งหมดของรายการซ่อม → array( tsp_id => array(event, ...) ) เรียงตาม se_id */
if (!function_exists('mn_spare_events_by_trp')) {
    function mn_spare_events_by_trp($connect, $trp_id)
    {
        $out = array();
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) { return $out; }
        mn_spare_ensure_table($connect);
        $stmt = @mysqli_prepare($connect, 'SELECT * FROM cl_spare_event WHERE se_trp_id = ? ORDER BY se_id');
        if (!$stmt) { return $out; }
        mysqli_stmt_bind_param($stmt, 'i', $trp_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[(int) $r['se_tsp_id']][] = $r;
        }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

if (!function_exists('mn_spare_events_for_tsp')) {
    function mn_spare_events_for_tsp($connect, $tsp_id)
    {
        $out = array();
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) { return $out; }
        mn_spare_ensure_table($connect);
        $stmt = @mysqli_prepare($connect, 'SELECT * FROM cl_spare_event WHERE se_tsp_id = ? ORDER BY se_id');
        if (!$stmt) { return $out; }
        mysqli_stmt_bind_param($stmt, 'i', $tsp_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) { $out[] = $r; }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

/**
 * สถานะของเครื่องสำรอง 1 แถว
 * $tsp: แถว transac_spare (คอลัมน์ tsp_*) | $events: เหตุการณ์ของแถวนั้น | $trp_status: trp_status_job ของรายการซ่อม
 */
if (!function_exists('mn_spare_state')) {
    function mn_spare_state(array $tsp, array $events, $trp_status = '')
    {
        $has = array();
        $last = '';
        $last_bounce = 0;
        $last_delivered = 0;
        foreach ($events as $e) {
            $t = (string) $e['se_type'];
            $has[$t] = true;
            $last = $t;
            if ($t === 'bounce') { $last_bounce = (int) $e['se_id']; }
            if ($t === 'delivered' || $t === 'pickup') { $last_delivered = (int) $e['se_id']; }
        }
        if (!empty($has['cancel'])) { return 'cancel'; }
        if (!empty($has['lost'])) { return 'lost'; }
        if (mn_spare_ymd(isset($tsp['tsp_datereturn']) ? $tsp['tsp_datereturn'] : '') !== '') { return 'back'; }
        if (!empty($has['swap_out'])) { return 'wait'; }
        $send = mn_spare_ymd(isset($tsp['tsp_datesend']) ? $tsp['tsp_datesend'] : '');
        if ($send === '') {
            return $last === 'bounce' ? 'bounce' : 'prep';
        }
        if (trim((string) $trp_status) === 'รอเครื่องสำรอง') { return 'wait'; }
        $log = trim((string) (isset($tsp['tsp_logistics']) ? $tsp['tsp_logistics'] : ''));
        if (strcasecmp($log, 'Customer') === 0) { return 'cust'; }
        if ($last_delivered > $last_bounce) { return 'cust'; }
        // ส่งเกิน 7 วันแล้ว ถือว่าถึงลูกค้า (ไม่บังคับกด "ถึงลูกค้าแล้ว")
        $days = (int) floor((time() - strtotime($send . ' 00:00:00')) / 86400);
        return $days <= 7 ? 'ship' : 'cust';
    }
}

/**
 * ไทม์ไลน์ของเครื่องสำรอง 1 แถว → array of array('kind','title','text','date','by','tone')
 * tone: gray | ship | bad | blue | warn | ok | vio
 */
if (!function_exists('mn_spare_timeline')) {
    function mn_spare_timeline(array $tsp, array $events, $state)
    {
        $tx = function ($v) { return trim(maintenance_flow_db_text_to_utf8((string) $v)); };
        $items = array();
        $n_send = 0;
        $pickup = null;
        foreach ($events as $e) {
            $t = (string) $e['se_type'];
            $d = mn_spare_dmy($e['se_date']);
            $by = trim((string) $e['se_by']);
            if ($t === 'change_sn' && stripos((string) $e['se_sn_old'], 'Waiting') === 0) {
                $items[] = array('kind' => $t, 'title' => 'ระบุ S/N', 'text' => (string) $e['se_sn'], 'date' => $d, 'by' => $by, 'tone' => 'gray');
            } elseif ($t === 'change_sn') {
                $items[] = array('kind' => $t, 'title' => 'เปลี่ยน S/N', 'text' => 'จาก ' . $e['se_sn_old'] . ' เป็น ' . $e['se_sn'] . ($e['se_reason'] !== '' && $e['se_reason'] !== null ? ' เพราะ ' . $e['se_reason'] : ''), 'date' => $d, 'by' => $by, 'tone' => 'gray');
            } elseif ($t === 'swap_in') {
                $items[] = array('kind' => $t, 'title' => 'ส่งแทนเครื่อง ' . $e['se_sn_old'], 'text' => (string) $e['se_reason'], 'date' => $d, 'by' => $by, 'tone' => 'gray');
            } elseif ($t === 'bounce') {
                $n_send++;
                $items[] = array('kind' => 'send', 'title' => 'ส่ง ครั้งที่ ' . $n_send, 'text' => trim($e['se_logistics'] . ' ' . $e['se_tracking']), 'tracking' => (string) $e['se_tracking'], 'logistics' => (string) $e['se_logistics'],
                    'date' => mn_spare_dmy($e['se_send_date']), 'by' => (string) $e['se_sendby'], 'tone' => 'ship');
                $items[] = array('kind' => $t, 'title' => 'ตีกลับ', 'text' => (string) $e['se_reason'], 'date' => $d, 'by' => $by !== '' ? 'รับเข้า ' . $by : '', 'tone' => 'bad');
            } elseif ($t === 'pickup') {
                $pickup = $e;
            }
        }
        $send = mn_spare_ymd(isset($tsp['tsp_datesend']) ? $tsp['tsp_datesend'] : '');
        if ($send !== '') {
            $log = $tx(isset($tsp['tsp_logistics']) ? $tsp['tsp_logistics'] : '');
            if (strcasecmp($log, 'Customer') === 0) {
                $items[] = array('kind' => 'pickup', 'title' => 'ลูกค้ามารับเอง', 'text' => $pickup ? (string) $pickup['se_person'] : '', 'date' => mn_spare_dmy($send),
                    'by' => $tx(isset($tsp['tsp_sendby']) ? $tsp['tsp_sendby'] : ''), 'tone' => 'blue');
            } else {
                $n_send++;
                $trk = trim((string) (isset($tsp['tsp_tracking']) ? $tsp['tsp_tracking'] : ''));
                if ($trk === '0') { $trk = ''; }
                $items[] = array('kind' => 'send', 'title' => $n_send > 1 ? 'ส่งใหม่ ครั้งที่ ' . $n_send : 'ส่ง', 'text' => trim($log . ' ' . $trk), 'tracking' => $trk, 'logistics' => $log,
                    'date' => mn_spare_dmy($send), 'by' => $tx(isset($tsp['tsp_sendby']) ? $tsp['tsp_sendby'] : ''), 'tone' => 'ship');
            }
        }
        foreach ($events as $e) {
            $t = (string) $e['se_type'];
            $d = mn_spare_dmy($e['se_date']);
            $by = trim((string) $e['se_by']);
            if ($t === 'delivered') {
                $items[] = array('kind' => $t, 'title' => 'ถึงลูกค้าแล้ว', 'text' => '', 'date' => $d, 'by' => $by, 'tone' => 'blue');
            } elseif ($t === 'swap_out') {
                $items[] = array('kind' => $t, 'title' => 'สลับออก', 'text' => 'ส่ง ' . $e['se_sn'] . ' ไปแทน' . ($e['se_reason'] !== '' && $e['se_reason'] !== null ? ' เพราะ ' . $e['se_reason'] : ''), 'date' => $d, 'by' => $by, 'tone' => 'warn');
            } elseif ($t === 'lost') {
                $items[] = array('kind' => $t, 'title' => 'สูญหาย/เสียหาย', 'text' => (string) $e['se_reason'], 'date' => $d, 'by' => $by, 'tone' => 'vio');
            } elseif ($t === 'cancel') {
                $items[] = array('kind' => $t, 'title' => 'ยกเลิกสำรอง', 'text' => (string) $e['se_reason'], 'date' => $d, 'by' => $by, 'tone' => 'gray');
            }
        }
        // ระบบเก่าเก็บการเปลี่ยนไว้ในหมายเหตุ "change [S/N]"
        if (isset($tsp['tsp_remarks']) && preg_match('/^change \[(.+)\]$/', trim((string) $tsp['tsp_remarks']), $m)) {
            $items[] = array('kind' => 'legacy', 'title' => 'เปลี่ยนเป็น ' . $m[1], 'text' => '(บันทึกจากระบบเก่า)', 'date' => '', 'by' => '', 'tone' => 'warn');
        }
        if ($state === 'back') {
            $items[] = array('kind' => 'return', 'title' => 'รับคืนแล้ว', 'text' => '', 'date' => mn_spare_dmy(isset($tsp['tsp_datereturn']) ? $tsp['tsp_datereturn'] : ''),
                'by' => $tx(isset($tsp['tsp_receiveby']) ? $tsp['tsp_receiveby'] : ''), 'tone' => 'ok');
        }
        return $items;
    }
}

/** ปุ่มที่กดได้ในแต่ละสถานะ → array(action => label) */
if (!function_exists('mn_spare_actions')) {
    function mn_spare_actions($state, array $tsp)
    {
        $log = trim((string) (isset($tsp['tsp_logistics']) ? $tsp['tsp_logistics'] : ''));
        switch ($state) {
            case 'prep':
                return array('send' => 'ส่ง', 'pickup' => 'ลูกค้ารับเอง', 'change' => 'เปลี่ยน S/N', 'cancel' => 'ยกเลิกสำรอง');
            case 'ship':
                return array('delivered' => 'ถึงลูกค้าแล้ว', 'bounce' => 'ตีกลับ', 'return' => 'รับคืน', 'swap' => 'สลับเครื่อง', 'lost' => 'สูญหาย/เสียหาย');
            case 'bounce':
                return array('send' => 'ส่งใหม่', 'pickup' => 'ลูกค้ามารับเอง', 'change' => 'เปลี่ยนเป็นเครื่องอื่น', 'cancel' => 'ไม่ต้องส่งแล้ว');
            case 'cust':
                $a = array('return' => 'รับคืน', 'swap' => 'สลับเครื่อง');
                if (strcasecmp($log, 'Customer') !== 0) { $a['bounce'] = 'ตีกลับ'; }
                $a['lost'] = 'สูญหาย/เสียหาย';
                return $a;
            case 'wait':
                return array('return' => 'รับคืน', 'lost' => 'สูญหาย/เสียหาย');
        }
        return array();
    }
}

/** จำนวนเครื่องสำรองที่ยังค้าง (ยังไม่คืน/ยกเลิก/สูญหาย) — ใช้ตัดสินว่าปิดงานได้หรือยัง */
if (!function_exists('mn_spare_outstanding_count')) {
    function mn_spare_outstanding_count($connect, $trp_id, $exclude_tsp_id = 0)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) { return 0; }
        $events = mn_spare_events_by_trp($connect, $trp_id);
        $stmt = mysqli_prepare($connect, 'SELECT tsp_id, tsp_sn, tsp_datesend, tsp_datereturn, tsp_logistics FROM transac_spare WHERE tsp_trp_id = ?');
        if (!$stmt) { return 0; }
        mysqli_stmt_bind_param($stmt, 'i', $trp_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $n = 0;
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $id = (int) $r['tsp_id'];
            if ($id === (int) $exclude_tsp_id) { continue; }
            if (mn_spare_ymd($r['tsp_datereturn']) !== '') { continue; }
            $ev = isset($events[$id]) ? $events[$id] : array();
            $st = mn_spare_state($r, $ev, '');
            if ($st === 'cancel' || $st === 'lost' || $st === 'back') { continue; }
            // แถว "Waiting for spare" ที่ไม่เคยส่ง = ไม่มีเครื่องจริงผูกอยู่ ไม่นับ
            // (เตรียมส่ง/ตีกลับ ที่มี S/N จริง ยังนับ เพราะเครื่องยังไม่กลับคลัง ต้องกดยกเลิก/รับคืนก่อน)
            if (mn_spare_ymd($r['tsp_datesend']) === '' && stripos(trim((string) $r['tsp_sn']), 'Waiting') === 0) { continue; }
            if (trim((string) $r['tsp_sn']) === '' && mn_spare_ymd($r['tsp_datesend']) === '') { continue; }
            $n++;
        }
        mysqli_stmt_close($stmt);
        return $n;
    }
}

/** tsp_id ที่ต้องส่ง (เตรียมส่ง/ตีกลับ) จาก flow ใหม่ แม้รายการซ่อมเลยขั้น «รอส่งสำรอง» แล้ว — ให้หน้ารอส่งพัสดุแสดง */
if (!function_exists('mn_spare_pending_send_ids')) {
    function mn_spare_pending_send_ids($connect)
    {
        $ids = array();
        if (!($connect instanceof mysqli)) { return $ids; }
        mn_spare_ensure_table($connect);
        $sql = 'SELECT s.tsp_id, s.tsp_datesend, s.tsp_datereturn, s.tsp_logistics, s.tsp_sn FROM transac_spare s'
            . ' INNER JOIN transac_repair r ON r.trp_id = s.tsp_trp_id'
            . " WHERE (s.tsp_datesend IS NULL OR TRIM(s.tsp_datesend) = '') AND r.trp_status_job <> 'เสร็จสิ้น'"
            . ' AND s.tsp_id IN (SELECT se_tsp_id FROM cl_spare_event)';
        $res = @mysqli_query($connect, $sql);
        $rows = array();
        while ($res && ($r = mysqli_fetch_assoc($res))) { $rows[] = $r; }
        foreach ($rows as $r) {
            $st = mn_spare_state($r, mn_spare_events_for_tsp($connect, (int) $r['tsp_id']), '');
            if ($st === 'prep' || $st === 'bounce') { $ids[] = (int) $r['tsp_id']; }
        }
        return $ids;
    }
}

/** ป้ายบอกที่มาในหน้ารอส่งพัสดุ: ตีกลับ / ส่งแทนเครื่องอื่น */
if (!function_exists('mn_spare_resend_badge')) {
    function mn_spare_resend_badge($connect, $tsp_id)
    {
        $ev = mn_spare_events_for_tsp($connect, (int) $tsp_id);
        if (!$ev) { return ''; }
        $last = end($ev);
        if ($last['se_type'] === 'bounce') {
            return '<span class="mn-spare-badge is-bounce"><i class="fa fa-reply" aria-hidden="true"></i> ตีกลับ รอส่งใหม่' . ($last['se_reason'] !== '' && $last['se_reason'] !== null ? ' (' . htmlspecialchars($last['se_reason'], ENT_QUOTES, 'UTF-8') . ')' : '') . '</span>';
        }
        foreach ($ev as $e) {
            if ($e['se_type'] === 'swap_in') {
                return '<span class="mn-spare-badge"><i class="fa fa-exchange" aria-hidden="true"></i> ส่งแทนเครื่อง ' . htmlspecialchars((string) $e['se_sn_old'], ENT_QUOTES, 'UTF-8') . '</span>';
            }
        }
        return '';
    }
}

if (!function_exists('mn_spare_log')) {
    function mn_spare_log($connect, array $f)
    {
        mn_spare_ensure_table($connect);
        $cols = array('se_tsp_id', 'se_trp_id', 'se_ts_id', 'se_type', 'se_sn', 'se_sn_old', 'se_date', 'se_send_date', 'se_logistics', 'se_tracking', 'se_sendby', 'se_reason', 'se_person', 'se_ref_tsp_id', 'se_by');
        $vals = array();
        foreach ($cols as $c) {
            $v = isset($f[$c]) ? $f[$c] : (in_array($c, array('se_tsp_id', 'se_trp_id', 'se_ts_id', 'se_ref_tsp_id'), true) ? 0 : '');
            $vals[] = $v;
        }
        $sql = 'INSERT INTO cl_spare_event (' . implode(',', $cols) . ', se_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', NOW())';
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { throw new RuntimeException('เตรียมบันทึกประวัติสำรองไม่สำเร็จ'); }
        $types = 'iiissssssssssis';
        mysqli_stmt_bind_param($stmt, $types, $vals[0], $vals[1], $vals[2], $vals[3], $vals[4], $vals[5], $vals[6], $vals[7], $vals[8], $vals[9], $vals[10], $vals[11], $vals[12], $vals[13], $vals[14]);
        if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); throw new RuntimeException('บันทึกประวัติสำรองไม่สำเร็จ'); }
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('mn_spare_set_stock')) {
    function mn_spare_set_stock($connect, $sn, $status)
    {
        $sn = trim((string) $sn);
        if ($sn === '' || stripos($sn, 'Waiting') === 0) { return; }
        $stmt = mysqli_prepare($connect, 'UPDATE spare SET sp_status = ? WHERE sp_sn = ? LIMIT 1');
        if (!$stmt) { throw new RuntimeException('อัปเดตคลังสำรองไม่สำเร็จ'); }
        mysqli_stmt_bind_param($stmt, 'ss', $status, $sn);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('mn_spare_valid_date')) {
    function mn_spare_valid_date($d, $label)
    {
        $d = trim((string) $d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('กรุณาระบุ' . $label);
        }
        return $d;
    }
}

if (!function_exists('mn_spare_txt')) {
    function mn_spare_txt($v, $max)
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $max, 'UTF-8');
    }
}

/**
 * ทำรายการ 1 ครั้ง (เรียกใน transaction ของ caller ไม่ได้ — ฟังก์ชันนี้เปิด/ปิด transaction เอง)
 * $in: tsp_id, action, date, logistics, tracking, sendby, reason, person, new_sn
 * คืน array('ok' => bool, 'error' => string)
 */
if (!function_exists('mn_spare_do_action')) {
    function mn_spare_do_action($connect, array $in, $who)
    {
        mn_spare_ensure_table($connect);
        $tsp_id = isset($in['tsp_id']) ? (int) $in['tsp_id'] : 0;
        $action = isset($in['action']) ? (string) $in['action'] : '';
        $stmt = mysqli_prepare($connect, 'SELECT s.*, r.trp_status_job, r.trp_product FROM transac_spare s INNER JOIN transac_repair r ON r.trp_id = s.tsp_trp_id WHERE s.tsp_id = ? LIMIT 1');
        if (!$stmt) { return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ'); }
        mysqli_stmt_bind_param($stmt, 'i', $tsp_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $tsp = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$tsp) { return array('ok' => false, 'error' => 'ไม่พบรายการเครื่องสำรอง'); }
        $trp_status = trim(maintenance_flow_db_text_to_utf8($tsp['trp_status_job']));
        if ($trp_status === 'เสร็จสิ้น') { return array('ok' => false, 'error' => 'งานนี้เสร็จสิ้นแล้ว แก้เครื่องสำรองไม่ได้'); }
        $events = mn_spare_events_for_tsp($connect, $tsp_id);
        $state = mn_spare_state($tsp, $events, $trp_status);
        $allowed = mn_spare_actions($state, $tsp);
        if (!isset($allowed[$action])) { return array('ok' => false, 'error' => 'สถานะตอนนี้ (' . mn_spare_state_meta($state)[0] . ') ทำรายการนี้ไม่ได้ ลองรีเฟรชหน้า'); }

        $trp_id = (int) $tsp['tsp_trp_id'];
        $ts_id = (int) $tsp['tsp_ts_id'];
        $sn = trim(maintenance_flow_db_text_to_utf8($tsp['tsp_sn']));
        $who = mn_spare_txt($who, 100);
        $base = array('se_tsp_id' => $tsp_id, 'se_trp_id' => $trp_id, 'se_ts_id' => $ts_id, 'se_sn' => $sn, 'se_by' => $who);
        $reason = mn_spare_txt(isset($in['reason']) ? $in['reason'] : '', 255);

        try {
            mysqli_begin_transaction($connect);
            switch ($action) {
                case 'send':
                case 'pickup':
                    $date = mn_spare_valid_date(isset($in['date']) ? $in['date'] : '', $action === 'send' ? 'วันที่ส่ง' : 'วันที่ลูกค้ามารับ');
                    if ($action === 'send') {
                        $v = maintenance_detail_job_validate_tsp_logistics(isset($in['logistics']) ? $in['logistics'] : '', $connect);
                        if (!$v['ok']) { throw new InvalidArgumentException($v['error']); }
                        $log = $v['value'];
                        $trk = mn_spare_txt(isset($in['tracking']) ? $in['tracking'] : '', 50);
                        $by = mn_spare_txt(isset($in['sendby']) ? $in['sendby'] : '', 50);
                        if ($by === '') { $by = mb_substr($who, 0, 50, 'UTF-8'); }
                    } else {
                        $log = 'Customer';
                        $trk = '0';
                        $by = mb_substr($who, 0, 50, 'UTF-8');
                        $person = mn_spare_txt(isset($in['person']) ? $in['person'] : '', 100);
                        if ($person === '') { throw new InvalidArgumentException('กรุณาระบุชื่อคนมารับ'); }
                        mn_spare_log($connect, $base + array('se_type' => 'pickup', 'se_date' => $date, 'se_person' => $person));
                    }
                    $st = mysqli_prepare($connect, 'UPDATE transac_spare SET tsp_datesend = ?, tsp_logistics = ?, tsp_tracking = ?, tsp_sendby = ? WHERE tsp_id = ? LIMIT 1');
                    mysqli_stmt_bind_param($st, 'ssssi', $date, $log, $trk, $by, $tsp_id);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                    if (function_exists('maintenance_status_logistic_apply_repair_status_after_spare_send')) {
                        maintenance_status_logistic_apply_repair_status_after_spare_send($connect, $trp_id);
                    }
                    if ($action === 'send' && $trk !== '' && function_exists('maintenance_sendtracking_history_upsert_row')) {
                        maintenance_sendtracking_history_upsert_row($connect, 'spare', $tsp_id, $ts_id, $trp_id, $trk, $log, $date, $by);
                    }
                    break;

                case 'delivered':
                    $date = mn_spare_valid_date(isset($in['date']) ? $in['date'] : '', 'วันที่ถึงลูกค้า');
                    mn_spare_log($connect, $base + array('se_type' => 'delivered', 'se_date' => $date));
                    break;

                case 'bounce':
                    $date = mn_spare_valid_date(isset($in['date']) ? $in['date'] : '', 'วันที่ได้รับพัสดุคืน');
                    if ($reason === '') { throw new InvalidArgumentException('กรุณาเลือกเหตุผลที่ตีกลับ'); }
                    // เก็บข้อมูลการส่งครั้งที่ตีกลับไว้ในเหตุการณ์ แล้วล้างช่องส่งให้ส่งใหม่ได้
                    mn_spare_log($connect, $base + array('se_type' => 'bounce', 'se_date' => $date, 'se_reason' => $reason,
                        'se_send_date' => mn_spare_ymd($tsp['tsp_datesend']),
                        'se_logistics' => mn_spare_txt(maintenance_flow_db_text_to_utf8($tsp['tsp_logistics']), 50),
                        'se_tracking' => mn_spare_txt($tsp['tsp_tracking'], 50),
                        'se_sendby' => mn_spare_txt(maintenance_flow_db_text_to_utf8($tsp['tsp_sendby']), 50)));
                    $st = mysqli_prepare($connect, 'UPDATE transac_spare SET tsp_datesend = NULL, tsp_logistics = NULL, tsp_tracking = NULL, tsp_sendby = NULL WHERE tsp_id = ? LIMIT 1');
                    mysqli_stmt_bind_param($st, 'i', $tsp_id);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                    break;

                case 'cancel':
                case 'return':
                    $date = mn_spare_valid_date(isset($in['date']) ? $in['date'] : '', $action === 'return' ? 'วันที่รับคืน' : 'วันที่ยกเลิก');
                    $recv = mn_spare_txt(isset($in['person']) ? $in['person'] : '', 50);
                    if ($recv === '') { $recv = mb_substr($who, 0, 50, 'UTF-8'); }
                    if ($action === 'cancel') {
                        mn_spare_log($connect, $base + array('se_type' => 'cancel', 'se_date' => $date, 'se_reason' => $reason));
                    }
                    $st = mysqli_prepare($connect, 'UPDATE transac_spare SET tsp_datereturn = ?, tsp_receiveby = ? WHERE tsp_id = ? LIMIT 1');
                    mysqli_stmt_bind_param($st, 'ssi', $date, $recv, $tsp_id);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                    mn_spare_set_stock($connect, $sn, 'blank');
                    mn_spare_close_if_done($connect, $trp_id, $trp_status);
                    break;

                case 'lost':
                    $date = mn_spare_valid_date(isset($in['date']) ? $in['date'] : '', 'วันที่');
                    if ($reason === '') { throw new InvalidArgumentException('กรุณาระบุรายละเอียด'); }
                    mn_spare_log($connect, $base + array('se_type' => 'lost', 'se_date' => $date, 'se_reason' => $reason));
                    mn_spare_set_stock($connect, $sn, 'lost');
                    mn_spare_close_if_done($connect, $trp_id, $trp_status);
                    break;

                case 'change':
                case 'swap':
                    $new_sn = mn_spare_txt(isset($in['new_sn']) ? $in['new_sn'] : '', 50);
                    if ($new_sn === '' || $new_sn === $sn) { throw new InvalidArgumentException('กรุณาเลือก S/N เครื่องใหม่'); }
                    if ($reason === '') { throw new InvalidArgumentException('กรุณาเลือกเหตุผลที่เปลี่ยน'); }
                    $chk = mysqli_prepare($connect, "SELECT sp_status FROM spare WHERE sp_sn = ? LIMIT 1");
                    mysqli_stmt_bind_param($chk, 's', $new_sn);
                    mysqli_stmt_execute($chk);
                    $cr = mysqli_stmt_get_result($chk);
                    $crow = $cr ? mysqli_fetch_assoc($cr) : null;
                    mysqli_stmt_close($chk);
                    if (!$crow || trim((string) $crow['sp_status']) !== 'blank') { throw new InvalidArgumentException('S/N ' . $new_sn . ' ไม่ว่างในคลังแล้ว เลือกเครื่องอื่น'); }
                    $today = date('Y-m-d');
                    if ($action === 'change' && $state === 'prep') {
                        // ยังไม่เคยออกจากบริษัท: เปลี่ยน S/N ในรายการเดิม เครื่องเดิมกลับคลัง
                        $st = mysqli_prepare($connect, 'UPDATE transac_spare SET tsp_sn = ? WHERE tsp_id = ? LIMIT 1');
                        mysqli_stmt_bind_param($st, 'si', $new_sn, $tsp_id);
                        mysqli_stmt_execute($st);
                        mysqli_stmt_close($st);
                        mn_spare_log($connect, array_merge($base, array('se_type' => 'change_sn', 'se_sn' => $new_sn, 'se_sn_old' => $sn, 'se_date' => $today, 'se_reason' => $reason)));
                        mn_spare_set_stock($connect, $sn, 'blank');
                        if (stripos($sn, 'Waiting') === 0 && function_exists('maintenance_status_logistic_apply_repair_status_on_edit_spare')) {
                            maintenance_status_logistic_apply_repair_status_on_edit_spare($connect, $trp_id, $ts_id);
                        }
                    } else {
                        // ตีกลับ → เปลี่ยนเครื่อง: เครื่องเดิมอยู่กับเรา ยกเลิกแล้วกลับคลัง
                        // สลับที่ลูกค้า: เครื่องเดิมรอรับคืน (ยังไม่ว่างในคลัง)
                        $prod = (string) $tsp['tsp_product'];
                        $st = mysqli_prepare($connect, 'INSERT INTO transac_spare (tsp_ts_id, tsp_trp_id, tsp_product, tsp_sn) VALUES (?,?,?,?)');
                        mysqli_stmt_bind_param($st, 'iiss', $ts_id, $trp_id, $prod, $new_sn);
                        mysqli_stmt_execute($st);
                        $new_id = (int) mysqli_insert_id($connect);
                        mysqli_stmt_close($st);
                        if ($new_id < 1) { throw new RuntimeException('เพิ่มเครื่องสำรองใหม่ไม่สำเร็จ'); }
                        if ($action === 'change') {
                            mn_spare_log($connect, $base + array('se_type' => 'cancel', 'se_date' => $today, 'se_reason' => 'เปลี่ยนเป็น ' . $new_sn . ' เพราะ ' . $reason, 'se_ref_tsp_id' => $new_id));
                            $recv = mb_substr($who, 0, 50, 'UTF-8');
                            $st = mysqli_prepare($connect, 'UPDATE transac_spare SET tsp_datereturn = ?, tsp_receiveby = ? WHERE tsp_id = ? LIMIT 1');
                            mysqli_stmt_bind_param($st, 'ssi', $today, $recv, $tsp_id);
                            mysqli_stmt_execute($st);
                            mysqli_stmt_close($st);
                            mn_spare_set_stock($connect, $sn, 'blank');
                        } else {
                            mn_spare_log($connect, array_merge($base, array('se_type' => 'swap_out', 'se_date' => $today, 'se_reason' => $reason, 'se_sn' => $new_sn, 'se_sn_old' => $sn, 'se_ref_tsp_id' => $new_id)));
                        }
                        mn_spare_log($connect, array('se_tsp_id' => $new_id, 'se_trp_id' => $trp_id, 'se_ts_id' => $ts_id, 'se_type' => 'swap_in', 'se_sn' => $new_sn, 'se_sn_old' => $sn,
                            'se_date' => $today, 'se_reason' => $reason, 'se_ref_tsp_id' => $tsp_id, 'se_by' => $who));
                    }
                    mn_spare_set_stock($connect, $new_sn, 'use');
                    break;

                default:
                    throw new InvalidArgumentException('ไม่รู้จักรายการนี้');
            }
            mysqli_commit($connect);
        } catch (InvalidArgumentException $e) {
            mysqli_rollback($connect);
            return array('ok' => false, 'error' => $e->getMessage());
        } catch (Throwable $e) {
            mysqli_rollback($connect);
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage());
        }
        if (function_exists('maintenance_activity_log_write')) {
            maintenance_activity_log_write($connect, 'spare_flow_' . $action, array('entity_type' => 'transac_spare', 'entity_id' => (string) $tsp_id,
                'payload' => array('ts_id' => $ts_id, 'trp_id' => $trp_id, 'state_before' => $state, 'in' => array_intersect_key($in, array_flip(array('date', 'logistics', 'tracking', 'sendby', 'reason', 'person', 'new_sn'))))));
        }
        return array('ok' => true);
    }
}

/** ส่งเครื่องซ่อมคืนแล้ว (รอเครื่องสำรอง) และไม่มีสำรองค้าง → ปิดงานเป็นเสร็จสิ้น */
if (!function_exists('mn_spare_close_if_done')) {
    function mn_spare_close_if_done($connect, $trp_id, $trp_status)
    {
        if (trim((string) $trp_status) !== 'รอเครื่องสำรอง') { return; }
        if (mn_spare_outstanding_count($connect, $trp_id) > 0) { return; }
        $done = 'เสร็จสิ้น';
        $wait = 'รอเครื่องสำรอง';
        $st = mysqli_prepare($connect, 'UPDATE transac_repair SET trp_status_job = ? WHERE trp_id = ? AND trp_status_job = ? LIMIT 1');
        $id = (int) $trp_id;
        mysqli_stmt_bind_param($st, 'sis', $done, $id, $wait);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
}
