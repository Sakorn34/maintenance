<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ลิงก์ QR ให้มือถืออัปโหลดรูปเข้าใบงานโดยไม่ต้องล็อกอิน (หน้า photo_upload)
 * - 1 ลิงก์ = 1 ใบงาน, รหัสสุ่ม 32 ตัว, ใช้ได้ตามค่า qr_hours ในหน้า settings (เริ่มต้น 3 ชั่วโมง) หรือจนบันทึกการประเมิน / กดปิดลิงก์ / เสนอราคาแล้ว
 * - หน้าประเมินบนคอมส่ง "ข้อที่แนบรูปได้" + "ข้อที่เปิดอยู่" (focus) มาเก็บไว้ ให้มือถือเปลี่ยนข้อตามหน้าจอคอม
 *
 * ตาราง cl_photo_link: cpl_token, cpl_trp_id, cpl_by, cpl_created, cpl_expires, cpl_revoked, cpl_focus, cpl_items (JSON [{ci, mode}])
 */


if (!function_exists('mn_plink_ensure_table')) {
    function mn_plink_ensure_table($connect)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $col = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_photo_link` ('
            . '`cpl_token` CHAR(32) ' . $col . ' NOT NULL,'
            . '`cpl_trp_id` INT NOT NULL,'
            . '`cpl_by` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cpl_created` DATETIME NOT NULL,'
            . '`cpl_expires` DATETIME NOT NULL,'
            . '`cpl_revoked` TINYINT NOT NULL DEFAULT 0,'
            . '`cpl_focus` INT NOT NULL DEFAULT -1,'
            . '`cpl_items` TEXT ' . $col . ' NULL,'
            . 'PRIMARY KEY (`cpl_token`),'
            . 'INDEX `idx_cpl_trp` (`cpl_trp_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

if (!function_exists('mn_plink_out')) {
    /** ข้อมูลลิงก์ที่ส่งให้หน้าคอม */
    function mn_plink_out(array $row)
    {
        $exp = strtotime((string) $row['cpl_expires']);
        return array('token' => (string) $row['cpl_token'], 'expires' => $exp * 1000, 'expiresText' => date('H:i', $exp));
    }
}

/** ลิงก์ที่ยังใช้ได้ของใบงาน (ล่าสุด) หรือ null */
if (!function_exists('mn_plink_active')) {
    function mn_plink_active($connect, $trp_id)
    {
        mn_plink_ensure_table($connect);
        $stmt = mysqli_prepare($connect,
            'SELECT cpl_token, cpl_trp_id, cpl_by, cpl_expires, cpl_focus, cpl_items FROM cl_photo_link'
            . ' WHERE cpl_trp_id = ? AND cpl_revoked = 0 AND cpl_expires > NOW() ORDER BY cpl_expires DESC LIMIT 1');
        if (!$stmt) { return null; }
        $t = (int) $trp_id;
        mysqli_stmt_bind_param($stmt, 'i', $t);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }
}

if (!function_exists('mn_plink_revoke')) {
    function mn_plink_revoke($connect, $trp_id)
    {
        mn_plink_ensure_table($connect);
        $stmt = mysqli_prepare($connect, 'UPDATE cl_photo_link SET cpl_revoked = 1 WHERE cpl_trp_id = ? AND cpl_revoked = 0');
        if (!$stmt) { return false; }
        $t = (int) $trp_id;
        mysqli_stmt_bind_param($stmt, 'i', $t);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

/**
 * ลิงก์ของใบงาน: ใช้ลิงก์เดิมถ้ายังเหลือเวลาเกิน 30 นาที ($renew = true → ปิดลิงก์เดิมแล้วสร้างใหม่)
 * → array(row|null, error)
 */
if (!function_exists('mn_plink_create')) {
    function mn_plink_create($connect, $trp_id, $by, $renew = false)
    {
        mn_plink_ensure_table($connect);
        $t = (int) $trp_id;
        if ($t < 1) { return array(null, 'ไม่พบใบงาน'); }
        if ($renew) {
            mn_plink_revoke($connect, $t);
        } else {
            $cur = mn_plink_active($connect, $t);
            if ($cur && strtotime((string) $cur['cpl_expires']) - time() > 30 * 60) { return array($cur, ''); }
        }
        $token = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        if (!function_exists('mn_sys_setting')) { require_once __DIR__ . '/system_settings_helpers.php'; }
        $exp = date('Y-m-d H:i:s', time() + (int) mn_sys_setting($connect, 'qr_hours') * 3600); // ปรับได้ที่หน้า settings
        $who = mb_substr(trim((string) $by), 0, 80, 'UTF-8');
        $stmt = mysqli_prepare($connect, 'INSERT INTO cl_photo_link (cpl_token, cpl_trp_id, cpl_by, cpl_created, cpl_expires) VALUES (?, ?, ?, ?, ?)');
        if (!$stmt) { return array(null, 'สร้างลิงก์ไม่สำเร็จ'); }
        mysqli_stmt_bind_param($stmt, 'sisss', $token, $t, $who, $now, $exp);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) { return array(null, 'สร้างลิงก์ไม่สำเร็จ'); }
        return array(array('cpl_token' => $token, 'cpl_trp_id' => $t, 'cpl_by' => $who, 'cpl_expires' => $exp, 'cpl_focus' => -1, 'cpl_items' => null), '');
    }
}

/** แปลงรายการข้อจากหน้าคอม → JSON สั้น [{ci, mode}] (กรองค่าแปลก ๆ ทิ้ง) */
if (!function_exists('mn_plink_clean_items')) {
    function mn_plink_clean_items($raw)
    {
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        $out = array();
        $seen = array();
        if (is_array($list)) {
            foreach ($list as $x) {
                if (!is_array($x) || !isset($x['ci'])) { continue; }
                $ci = (int) $x['ci'];
                if ($ci < 1 || isset($seen[$ci])) { continue; }
                $seen[$ci] = true;
                $out[] = array('ci' => $ci, 'mode' => max(0, min(2, isset($x['mode']) ? (int) $x['mode'] : 1)));
                if (count($out) >= 200) { break; }
            }
        }
        return json_encode($out);
    }
}

/** หน้าคอมแจ้งข้อที่เปิดอยู่ + ข้อที่แนบรูปได้ (เฉพาะลิงก์ของใบงานนี้) */
if (!function_exists('mn_plink_set_state')) {
    function mn_plink_set_state($connect, $token, $trp_id, $focus, $items_raw)
    {
        mn_plink_ensure_table($connect);
        $tok = (string) $token;
        if (!preg_match('/^[a-f0-9]{32}$/', $tok)) { return false; }
        $f = max(-1, (int) $focus);
        $items = mn_plink_clean_items($items_raw);
        $t = (int) $trp_id;
        $stmt = mysqli_prepare($connect, 'UPDATE cl_photo_link SET cpl_focus = ?, cpl_items = ? WHERE cpl_token = ? AND cpl_trp_id = ? AND cpl_revoked = 0');
        if (!$stmt) { return false; }
        mysqli_stmt_bind_param($stmt, 'issi', $f, $items, $tok, $t);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

/**
 * ตรวจรหัสจากมือถือ → array(row|null, error)
 * row มีข้อมูลใบงาน (mtn, product, sn) ด้วย — ปฏิเสธเมื่อหมดอายุ / ถูกปิด / เสนอราคาแล้ว
 */
if (!function_exists('mn_plink_get')) {
    function mn_plink_get($connect, $token)
    {
        $tok = (string) $token;
        if (!preg_match('/^[a-f0-9]{32}$/', $tok)) { return array(null, 'ลิงก์ไม่ถูกต้อง'); }
        mn_plink_ensure_table($connect);
        $stmt = mysqli_prepare($connect,
            'SELECT l.cpl_token, l.cpl_trp_id, l.cpl_by, l.cpl_expires, l.cpl_revoked, l.cpl_focus, l.cpl_items,'
            . ' COALESCE(t.ts_mtn_number, \'\') AS mtn, COALESCE(r.trp_product, \'\') AS product, COALESCE(r.trp_sn, \'\') AS sn,'
            . ' COALESCE(r.trp_qv_number, \'\') AS qv_number, COALESCE(r.trp_qv_date, \'\') AS qv_date'
            . ' FROM cl_photo_link l JOIN transac_repair r ON r.trp_id = l.cpl_trp_id LEFT JOIN transac t ON t.ts_id = r.trp_ts_id'
            . ' WHERE l.cpl_token = ?');
        if (!$stmt) { return array(null, 'ตรวจลิงก์ไม่สำเร็จ'); }
        mysqli_stmt_bind_param($stmt, 's', $tok);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$row) { return array(null, 'ลิงก์ไม่ถูกต้อง'); }
        if ((int) $row['cpl_revoked'] === 1) { return array(null, 'ลิงก์นี้ถูกปิดแล้ว'); }
        if (strtotime((string) $row['cpl_expires']) <= time()) { return array(null, 'ลิงก์หมดอายุแล้ว'); }
        if (function_exists('maintenance_detail_job_repair_quote_offered')
            && maintenance_detail_job_repair_quote_offered(
                maintenance_flow_db_text_to_utf8($row['qv_number']), maintenance_flow_db_text_to_utf8($row['qv_date']))) {
            return array(null, 'ใบงานนี้เสนอราคาแล้ว แนบรูปเพิ่มไม่ได้');
        }
        return array($row, '');
    }
}

/**
 * ข้อที่มือถือแสดง: ตามที่หน้าคอมส่งมา + ข้อที่มีรูปอยู่แล้ว + ข้อใน QR ($extra_ci) + รูปประกอบเพิ่มเติม (ci 0)
 * → array( array(ci, mode, title, group), ... )
 */
if (!function_exists('mn_plink_items')) {
    function mn_plink_items($connect, array $link, array $photos, $extra_ci = 0)
    {
        $list = json_decode((string) $link['cpl_items'], true);
        $list = is_array($list) ? $list : array();
        $mode = array();
        foreach ($list as $x) { $mode[(int) $x['ci']] = (int) $x['mode']; }
        foreach ($photos as $p) { if ($p['ci'] > 0 && !isset($mode[$p['ci']])) { $mode[$p['ci']] = 1; } }
        if ((int) $extra_ci > 0 && !isset($mode[(int) $extra_ci])) { $mode[(int) $extra_ci] = 1; }
        if ((int) $link['cpl_focus'] > 0 && !isset($mode[(int) $link['cpl_focus']])) { $mode[(int) $link['cpl_focus']] = 1; }

        $names = array();
        $ids = array_keys($mode);
        if ($ids) {
            $sql = 'SELECT i.ci_id, i.ci_question, COALESCE(g.cg_name, \'\') AS g FROM cl_item i LEFT JOIN cl_group g ON g.cg_id = i.ci_group_id'
                . ' WHERE i.ci_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt) {
                $bind = array($stmt, str_repeat('i', count($ids)));
                foreach ($ids as $k => $v) { $bind[] = &$ids[$k]; }
                call_user_func_array('mysqli_stmt_bind_param', $bind);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($r = mysqli_fetch_assoc($res))) { $names[(int) $r['ci_id']] = array((string) $r['ci_question'], (string) $r['g']); }
                mysqli_stmt_close($stmt);
            }
        }
        $out = array();
        foreach ($mode as $ci => $m) {
            if (!isset($names[$ci])) { continue; }
            $out[] = array('ci' => $ci, 'mode' => $m, 'title' => $names[$ci][0], 'group' => $names[$ci][1]);
        }
        $out[] = array('ci' => 0, 'mode' => 1, 'title' => 'รูปประกอบเพิ่มเติม', 'group' => '');
        return $out;
    }
}
