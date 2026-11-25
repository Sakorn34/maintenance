<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ทะเบียนประกันต่อ S/N — ประกัน 3 ประเภท เป็นช่วงวันที่ เริ่ม–สิ้นสุด แล้วตรวจว่าครอบคลุม "วันรับเครื่อง" หรือไม่
 *  - new      ประกันเครื่องใหม่: เริ่มวันที่ซื้อ (หรือวันเริ่มรับประกันในใบสั่งติดตั้ง) จำนวนเดือนจากใบสั่งติดตั้ง ถ้าไม่มีใช้เดือนตามรุ่น (product_warranty)
 *  - repair   ประกันงานซ่อม: เริ่มวันซ่อมเสร็จของงานที่อนุมัติซ่อม ครอบคลุมทั้งเครื่อง จำนวนวันตามค่า repair_warranty_days (หน้า settings)
 *  - extended ประกันซื้อเพิ่ม: เก็บในตาราง cl_warranty — เริ่มต่อท้ายประกันเครื่องใหม่/ซื้อเพิ่มที่ยังเหลือ (ไม่นับประกันงานซ่อม) ถ้าหมดแล้วเริ่มวันที่ซื้อ
 *    ที่มา: (1) รายการ "ซื้อประกันเพิ่ม N เดือน" ในใบงานที่อนุมัติและซ่อมเสร็จ → สร้างอัตโนมัติ (mn_wr_sync_jobs)
 *           (2) บันทึกเองที่หน้า warranty_registry
 * ค่าในช่องประกัน: เครื่องใหม่/ซื้อเพิ่มยังเหลือ → อยู่ในประกัน | เหลือแค่ประกันซ่อม → อยู่ในประกันซ่อม | ไม่เหลือ → หมดประกัน
 */

require_once __DIR__ . '/warranty_settings_helpers.php';
if (!function_exists('mn_sys_setting')) { require_once __DIR__ . '/system_settings_helpers.php'; }

/** รายการซ่อมที่เป็นการขายประกันเพิ่ม → จำนวนเดือน หรือ 0 */
if (!function_exists('mn_wr_item_months')) {
    function mn_wr_item_months($item)
    {
        return preg_match('/^\s*ซื้อประกันเพิ่ม\s*(\d{1,3})\s*เดือน/u', (string) $item, $m) ? (int) $m[1] : 0;
    }
}

if (!function_exists('mn_wr_ensure_table')) {
    function mn_wr_ensure_table($connect)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $col = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_warranty` ('
            . '`wr_id` INT NOT NULL AUTO_INCREMENT,'
            . '`wr_sn` VARCHAR(100) ' . $col . ' NOT NULL,'
            . '`wr_product` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`wr_months` INT NOT NULL,'
            . '`wr_buy_date` DATE NOT NULL,'
            . '`wr_start` DATE NOT NULL,'
            . '`wr_end` DATE NOT NULL,'
            . '`wr_source` VARCHAR(10) ' . $col . ' NOT NULL DEFAULT \'manual\','
            . '`wr_trp_id` INT NOT NULL DEFAULT 0,'
            . '`wr_ref` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`wr_note` VARCHAR(300) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`wr_by` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`wr_at` DATETIME NULL DEFAULT NULL,'
            . '`wr_void` TINYINT NOT NULL DEFAULT 0,'
            . '`wr_void_reason` VARCHAR(300) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`wr_void_by` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`wr_void_at` DATETIME NULL DEFAULT NULL,'
            . 'PRIMARY KEY (`wr_id`),'
            . 'INDEX `idx_wr_sn` (`wr_sn`),'
            . 'INDEX `idx_wr_trp` (`wr_trp_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

if (!function_exists('mn_wr_norm')) {
    function mn_wr_norm($sn)
    {
        return strtoupper(trim((string) $sn));
    }
}

if (!function_exists('mn_wr_date')) {
    function mn_wr_date($v)
    {
        $v = substr(trim((string) $v), 0, 10);
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
        return ($d && $d->format('Y-m-d') === $v && $v > '1990-01-01') ? $v : '';
    }
}

if (!function_exists('mn_wr_shift')) {
    /** วันที่ + ช่วงเวลา (เช่น '+12 months', '-1 day') → Y-m-d */
    function mn_wr_shift($ymd, $modify)
    {
        $d = DateTime::createFromFormat('!Y-m-d', $ymd);
        $d->modify($modify);
        return $d->format('Y-m-d');
    }
}

if (!function_exists('mn_wr_dmy')) {
    function mn_wr_dmy($ymd)
    {
        return $ymd !== '' ? date('d/m/Y', strtotime($ymd)) : '';
    }
}

if (!function_exists('mn_wr_days_between')) {
    function mn_wr_days_between($a, $b)
    {
        return (int) round((strtotime($b) - strtotime($a)) / 86400);
    }
}

/**
 * ประกันเครื่องใหม่ → array(start, end, months, from) หรือ null
 * ใบสั่งติดตั้ง (biton_setup) ที่มีวันเริ่มรับประกัน/จำนวนเดือน ใช้ก่อน — ไม่มีใช้วันที่ซื้อ + เดือนตามรุ่น
 */
if (!function_exists('mn_wr_new_coverage')) {
    function mn_wr_new_coverage($connect, $sn, $product, $buy_date)
    {
        $start = mn_wr_date($buy_date);
        $months = maintenance_warranty_fetch_months_for_product($connect, (string) $product);
        $from = 'เดือนตามรุ่น';
        if ($months === null || (int) $months < 1) {
            // รุ่นที่ยังไม่ได้ตั้งระยะเวลาประกัน → ใช้ค่าเริ่มต้นในหน้า settings
            $months = (int) mn_sys_setting($connect, 'default_warranty_months');
            $from = 'ค่าเริ่มต้น (รุ่นนี้ยังไม่ได้ตั้ง)';
        }
        if (!function_exists('mn_bds_db')) { require_once __DIR__ . '/buy_date_suggest_helpers.php'; }
        list($db, $p) = mn_bds_db($connect, 'setup_mysql', 'biton_setup');
        $rows = mn_bds_select($db, 'SELECT o.warranty_start_date, o.warranty_period, o.po_number FROM ' . $p . 'po_order_part_serials ps'
            . ' JOIN ' . $p . 'setup_orders o ON o.id = ps.order_id WHERE UPPER(TRIM(ps.serial_number)) = UPPER(?)'
            . ' AND (ps.issue_type IS NULL OR ps.issue_type <> \'claim\') ORDER BY ps.issue_date DESC LIMIT 1', (string) $sn);
        if ($rows) {
            $ws = mn_wr_date($rows[0]['warranty_start_date']);
            if ($ws !== '') { $start = $ws; }
            if ((int) $rows[0]['warranty_period'] > 0) { $months = (int) $rows[0]['warranty_period']; }
            $from = 'ใบสั่งติดตั้ง' . (trim((string) $rows[0]['po_number']) !== '' ? ' PO ' . trim($rows[0]['po_number']) : '');
        }
        if ($start === '' || $months === null || (int) $months < 1) { return null; }
        // เหมือนระบบเดิม: ครอบคลุมถึงวันที่ซื้อ + จำนวนเดือน (รวมวันนั้น)
        return array('start' => $start, 'end' => mn_wr_shift($start, '+' . (int) $months . ' months'), 'months' => (int) $months, 'from' => $from);
    }
}

/** ประกันซื้อเพิ่มที่ยังไม่ยกเลิกของ S/N */
if (!function_exists('mn_wr_extended_rows')) {
    function mn_wr_extended_rows($connect, $sn)
    {
        mn_wr_ensure_table($connect);
        $stmt = mysqli_prepare($connect, 'SELECT * FROM cl_warranty WHERE wr_sn = ? AND wr_void = 0 ORDER BY wr_start, wr_id');
        $n = mn_wr_norm($sn);
        mysqli_stmt_bind_param($stmt, 's', $n);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        while ($res && ($r = mysqli_fetch_assoc($res))) { $out[] = $r; }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

/**
 * วันเริ่มของประกันซื้อเพิ่ม: ต่อท้ายประกันเครื่องใหม่/ซื้อเพิ่มที่ยังเหลือ ณ วันที่ซื้อ (ไม่นับประกันงานซ่อม) ถ้าไม่เหลือ = วันที่ซื้อ
 */
if (!function_exists('mn_wr_chain_start')) {
    function mn_wr_chain_start($connect, $sn, $product, $buy_date, $purchase)
    {
        $last = '';
        $new = mn_wr_new_coverage($connect, $sn, $product, $buy_date);
        if ($new && $new['end'] >= $purchase) { $last = $new['end']; }
        foreach (mn_wr_extended_rows($connect, $sn) as $r) {
            if ($r['wr_end'] >= $purchase && $r['wr_end'] > $last) { $last = $r['wr_end']; }
        }
        return $last !== '' ? mn_wr_shift($last, '+1 day') : $purchase;
    }
}

/** บันทึกประกันซื้อเพิ่ม → array(ok, error, row) */
if (!function_exists('mn_wr_add')) {
    function mn_wr_add($connect, array $in)
    {
        mn_wr_ensure_table($connect);
        $sn = mn_wr_norm(isset($in['sn']) ? $in['sn'] : '');
        $months = isset($in['months']) ? (int) $in['months'] : 0;
        $purchase = mn_wr_date(isset($in['purchase']) ? $in['purchase'] : '');
        if ($sn === '') { return array(false, 'ใส่ S/N', null); }
        if ($months < 1 || $months > 120) { return array(false, 'เลือกแพ็กเกจประกัน', null); }
        if ($purchase === '') { return array(false, 'ใส่วันที่ซื้อประกัน', null); }
        $product = trim((string) (isset($in['product']) ? $in['product'] : ''));
        $buy = mn_wr_date(isset($in['buy_date']) ? $in['buy_date'] : '');
        if ($product === '' || $buy === '') {
            $last = mn_wr_last_job($connect, $sn);
            if ($product === '') { $product = $last['product']; }
            if ($buy === '') { $buy = $last['buy_date']; }
        }
        $start = mn_wr_chain_start($connect, $sn, $product, $buy, $purchase);
        $end = mn_wr_shift(mn_wr_shift($start, '+' . $months . ' months'), '-1 day');
        $source = isset($in['source']) && $in['source'] === 'job' ? 'job' : 'manual';
        $trp = isset($in['trp_id']) ? (int) $in['trp_id'] : 0;
        $ref = mb_substr(trim((string) (isset($in['ref']) ? $in['ref'] : '')), 0, 100, 'UTF-8');
        $note = mb_substr(trim((string) (isset($in['note']) ? $in['note'] : '')), 0, 300, 'UTF-8');
        $by = mb_substr(trim((string) (isset($in['by']) ? $in['by'] : '')), 0, 100, 'UTF-8');
        $now = date('Y-m-d H:i:s');
        $stmt = mysqli_prepare($connect,
            'INSERT INTO cl_warranty (wr_sn, wr_product, wr_months, wr_buy_date, wr_start, wr_end, wr_source, wr_trp_id, wr_ref, wr_note, wr_by, wr_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) { return array(false, 'บันทึกไม่สำเร็จ', null); }
        mysqli_stmt_bind_param($stmt, 'ssissssissss', $sn, $product, $months, $purchase, $start, $end, $source, $trp, $ref, $note, $by, $now);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) { return array(false, 'บันทึกไม่สำเร็จ', null); }
        return array(true, '', array('id' => (int) mysqli_insert_id($connect), 'start' => $start, 'end' => $end));
    }
}

if (!function_exists('mn_wr_void')) {
    function mn_wr_void($connect, $id, $reason, $by)
    {
        mn_wr_ensure_table($connect);
        $reason = mb_substr(trim((string) $reason), 0, 300, 'UTF-8');
        if ($reason === '') { return array(false, 'ใส่เหตุผลที่ยกเลิก'); }
        $stmt = mysqli_prepare($connect, 'UPDATE cl_warranty SET wr_void = 1, wr_void_reason = ?, wr_void_by = ?, wr_void_at = ? WHERE wr_id = ? AND wr_void = 0');
        $who = mb_substr(trim((string) $by), 0, 100, 'UTF-8');
        $now = date('Y-m-d H:i:s');
        $i = (int) $id;
        mysqli_stmt_bind_param($stmt, 'sssi', $reason, $who, $now, $i);
        mysqli_stmt_execute($stmt);
        $n = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        return array($n > 0, $n > 0 ? '' : 'ไม่พบรายการ หรือยกเลิกไปแล้ว');
    }
}

/** ใบงานล่าสุดของ S/N → array(product, buy_date) */
if (!function_exists('mn_wr_last_job')) {
    function mn_wr_last_job($connect, $sn)
    {
        $out = array('product' => '', 'buy_date' => '');
        $stmt = mysqli_prepare($connect, 'SELECT trp_product, trp_buy_date FROM transac_repair WHERE UPPER(TRIM(trp_sn)) = ? ORDER BY trp_id DESC LIMIT 20');
        $n = mn_wr_norm($sn);
        mysqli_stmt_bind_param($stmt, 's', $n);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            if ($out['product'] === '' && trim((string) $r['trp_product']) !== '') { $out['product'] = trim($r['trp_product']); }
            if ($out['buy_date'] === '' && mn_wr_date($r['trp_buy_date']) !== '') { $out['buy_date'] = mn_wr_date($r['trp_buy_date']); }
        }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

/**
 * สร้างประกันซื้อเพิ่มจากใบงานที่มีรายการ "ซื้อประกันเพิ่ม N เดือน" ที่อนุมัติซ่อมและซ่อมเสร็จแล้ว (ยังไม่เคยสร้าง)
 * $sn = '' → ทุกใบงาน (หน้าทะเบียนประกัน) | วันที่ซื้อประกัน = วันซ่อมเสร็จ
 */
if (!function_exists('mn_wr_sync_jobs')) {
    function mn_wr_sync_jobs($connect, $sn = '')
    {
        mn_wr_ensure_table($connect);
        $like = array();
        for ($i = 1; $i <= 10; $i++) { $like[] = 'r.trp_repair_no' . $i . ' LIKE \'ซื้อประกันเพิ่ม%\''; }
        $sql = 'SELECT r.* , COALESCE(t.ts_mtn_number, \'\') AS mtn, COALESCE(r.trp_qv_number, \'\') AS qv FROM transac_repair r LEFT JOIN transac t ON t.ts_id = r.trp_ts_id'
            . ' WHERE (' . implode(' OR ', $like) . ') AND TRIM(r.trp_status_firmma) = \'อนุมัติซ่อม\' AND r.trp_success_date <> \'\''
            . ($sn !== '' ? ' AND UPPER(TRIM(r.trp_sn)) = ?' : '') . ' ORDER BY r.trp_success_date, r.trp_id';
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return 0; }
        if ($sn !== '') { $n = mn_wr_norm($sn); mysqli_stmt_bind_param($stmt, 's', $n); }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $jobs = array();
        while ($res && ($r = mysqli_fetch_assoc($res))) { $jobs[] = $r; }
        mysqli_stmt_close($stmt);
        $made = 0;
        foreach ($jobs as $j) {
            $done = mysqli_prepare($connect, 'SELECT wr_months FROM cl_warranty WHERE wr_source = \'job\' AND wr_trp_id = ?');
            $tid = (int) $j['trp_id'];
            mysqli_stmt_bind_param($done, 'i', $tid);
            mysqli_stmt_execute($done);
            $dres = mysqli_stmt_get_result($done);
            $have = array();
            while ($dres && ($d = mysqli_fetch_row($dres))) { $have[] = (int) $d[0]; }
            mysqli_stmt_close($done);
            for ($i = 1; $i <= 10; $i++) {
                $m = mn_wr_item_months(isset($j['trp_repair_no' . $i]) ? $j['trp_repair_no' . $i] : '');
                $k = array_search($m, $have, true);
                if ($m < 1) { continue; }
                if ($k !== false) { unset($have[$k]); continue; } // สร้างไปแล้ว (รายการซ้ำในใบงานเดียวนับตามจำนวน)
                $purchase = mn_wr_date($j['trp_success_date']);
                if ($purchase === '') { continue; }
                $ref = trim('งาน ' . trim($j['mtn']) . (trim($j['qv']) !== '' && trim($j['qv']) !== '-' ? ' ' . trim($j['qv']) : ''));
                list($ok) = mn_wr_add($connect, array('sn' => $j['trp_sn'], 'product' => $j['trp_product'], 'buy_date' => $j['trp_buy_date'],
                    'months' => $m, 'purchase' => $purchase, 'source' => 'job', 'trp_id' => $tid, 'ref' => $ref,
                    'note' => 'จากรายการซ่อม "' . trim($j['trp_repair_no' . $i]) . '"', 'by' => 'ระบบ'));
                if ($ok) { $made++; }
            }
        }
        return $made;
    }
}

/**
 * ประวัติประกันของ S/N ณ วันอ้างอิง (วันรับเครื่อง) → array ของ
 *  {type, title, start, end, ref, active, left (วันเหลือ), ago (หมดมาแล้วกี่วัน)} เรียงจากสิ้นสุดล่าสุด
 */
if (!function_exists('mn_wr_timeline')) {
    function mn_wr_timeline($connect, $sn, $product, $buy_date, $exclude_trp_id, $ref_date)
    {
        $sn = trim((string) $sn);
        if ($sn === '') { return array(); }
        $ref = mn_wr_date($ref_date) !== '' ? mn_wr_date($ref_date) : date('Y-m-d');
        mn_wr_sync_jobs($connect, $sn);
        $items = array();
        $new = mn_wr_new_coverage($connect, $sn, $product, $buy_date);
        if ($new) {
            $items[] = array('type' => 'new', 'title' => 'ประกันเครื่องใหม่ ' . maintenance_warranty_months_label($new['months']),
                'start' => $new['start'], 'end' => $new['end'], 'ref' => $new['from']);
        }
        foreach (mn_wr_extended_rows($connect, $sn) as $r) {
            $items[] = array('type' => 'extended', 'title' => 'ซื้อประกันเพิ่ม ' . (int) $r['wr_months'] . ' เดือน',
                'start' => $r['wr_start'], 'end' => $r['wr_end'],
                'ref' => trim(($r['wr_ref'] !== '' ? $r['wr_ref'] : ($r['wr_source'] === 'job' ? 'จากใบงาน' : 'บันทึกเอง')) . ' (ซื้อ ' . mn_wr_dmy($r['wr_buy_date']) . ')'));
        }
        $days = (int) mn_sys_setting($connect, 'repair_warranty_days');
        $stmt = mysqli_prepare($connect, 'SELECT r.trp_id, r.trp_success_date, COALESCE(t.ts_mtn_number, \'\') AS mtn FROM transac_repair r'
            . ' LEFT JOIN transac t ON t.ts_id = r.trp_ts_id WHERE UPPER(TRIM(r.trp_sn)) = ? AND r.trp_id <> ?'
            . ' AND TRIM(r.trp_status_firmma) = \'อนุมัติซ่อม\' AND r.trp_success_date <> \'\' ORDER BY r.trp_success_date DESC LIMIT 10');
        $n = mn_wr_norm($sn);
        $ex = (int) $exclude_trp_id;
        mysqli_stmt_bind_param($stmt, 'si', $n, $ex);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $s = mn_wr_date($r['trp_success_date']);
            if ($s === '' || $s > $ref) { continue; }
            $items[] = array('type' => 'repair', 'title' => 'ประกันงานซ่อม ' . $days . ' วัน', 'start' => $s, 'end' => mn_wr_shift($s, '+' . $days . ' days'),
                'ref' => trim($r['mtn']) !== '' ? 'งาน ' . trim($r['mtn']) : 'ใบงาน ' . $r['trp_id']);
        }
        mysqli_stmt_close($stmt);
        foreach ($items as &$it) {
            $it['active'] = $it['start'] <= $ref && $ref <= $it['end'];
            $it['future'] = $it['start'] > $ref;
            $it['left'] = $it['active'] ? mn_wr_days_between($ref, $it['end']) : 0;
            $it['ago'] = $it['end'] < $ref ? mn_wr_days_between($it['end'], $ref) : 0;
        }
        unset($it);
        usort($items, function ($a, $b) { return strcmp($b['end'], $a['end']); });
        return $items;
    }
}

/**
 * ค่าในช่องประกันจากประวัติประกัน → array(value|null, hint)
 * เครื่องใหม่/ซื้อเพิ่มยังเหลือ → อยู่ในประกัน (บอกที่มา) | เหลือแค่งานซ่อม → อยู่ในประกันซ่อม | มีข้อมูลแต่หมดทุกอัน → หมดประกัน
 */
if (!function_exists('mn_wr_status')) {
    function mn_wr_status(array $timeline, $has_buy_date, $product_months)
    {
        $full = null;
        $repair = null;
        foreach ($timeline as $it) {
            if (!$it['active']) { continue; }
            if ($it['type'] === 'repair') { if (!$repair || $it['end'] > $repair['end']) { $repair = $it; } continue; }
            if (!$full || $it['end'] > $full['end']) { $full = $it; }
        }
        if ($full) {
            return array('อยู่ในประกัน', $full['title'] . ' เหลือ ' . $full['left'] . ' วัน (ถึง ' . mn_wr_dmy($full['end']) . ') ' . $full['ref']);
        }
        if ($repair) {
            return array('อยู่ในประกันซ่อม', $repair['title'] . ' เหลือ ' . $repair['left'] . ' วัน (' . $repair['ref'] . ' ถึง ' . mn_wr_dmy($repair['end']) . ')');
        }
        $last = null;
        $next = null;
        foreach ($timeline as $it) {
            if ($it['type'] === 'repair') { continue; }
            if ($it['future']) { if (!$next || $it['start'] < $next['start']) { $next = $it; } continue; }
            if (!$last || $it['end'] > $last['end']) { $last = $it; }
        }
        if ($last || $next) {
            $hint = $last ? 'หมดประกันแล้ว ' . $last['ago'] . ' วัน (' . $last['title'] . ' ถึง ' . mn_wr_dmy($last['end']) . ')' : 'ไม่มีประกันที่ใช้อยู่';
            if ($next) { $hint .= ' | ' . $next['title'] . ' เริ่ม ' . mn_wr_dmy($next['start']); }
            return array('หมดประกัน', $hint);
        }
        if (!$has_buy_date) { return array(null, 'ไม่มีวันที่ซื้อ เลือกเอง'); }
        if ($product_months === null) { return array(null, 'รุ่นนี้ยังไม่ได้ตั้งระยะเวลาประกัน เลือกเอง'); }
        return array(null, 'เลือกเอง');
    }
}
