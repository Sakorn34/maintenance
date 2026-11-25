<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ค้นหา "วันที่ซื้อ" ของเครื่องจากหลักฐานซื้อขาย / ส่งมอบ / ติดตั้ง / ซ่อม ที่มีในระบบ เพื่อเสนอให้ผู้รับเครื่องเลือก
 * ใช้เฉพาะหลักฐานจริง ไม่ประมาณ (หน้า update_wait_hardware ผ่าน buy_date_suggest)
 *
 * แหล่งข้อมูล (อ่านอย่างเดียว):
 *  1) ใบงานซ่อมก่อนหน้าของ S/N เดียวกัน (transac_repair.trp_buy_date)
 *  2) ส่งเครื่องจากคลัง        biton_stockparts.stock_old (timestamp = วันที่ส่ง, setup_id = ไซต์งาน)
 *  3) ประวัติติดตั้ง           biton_production.installation_history (install_date / sale_date)
 *  4) ประวัติส่งมอบ            biton_setup.historical_serials (delivery_date / order_date)
 *  5) ใบสั่งติดตั้ง (ระบบใหม่)  biton_setup.po_order_part_serials → setup_orders (warranty_start_date / delivery_date)
 *  + วันที่ส่งซ่อมครั้งแรก (transac_repair / repair_history) = เครื่องต้องซื้อก่อนวันนั้น
 *
 * การเชื่อมต่อฐานอื่น — config/env.local.php (ไม่ใส่ = ลองอ่านข้ามฐานด้วยบัญชีของระบบซ่อม):
 *   'stockparts_mysql' => array('host' => 'localhost', 'user' => '...', 'password' => '...', 'name' => 'biton_stockparts'),
 *   'production_mysql' => array(... 'name' => 'biton_production'),
 *   'setup_mysql'      => array(... 'name' => 'biton_setup'),
 * แหล่งที่เชื่อมไม่ได้จะถูกข้าม (แจ้งชื่อใน off)
 */

if (!function_exists('mn_bds_valid_date')) {
    function mn_bds_valid_date($v)
    {
        $v = substr(trim((string) $v), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || $v === '0000-00-00') { return ''; }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v && $v >= '2000-01-01' && $v <= date('Y-m-d')) ? $v : '';
    }
}

if (!function_exists('mn_bds_dmy')) {
    function mn_bds_dmy($ymd)
    {
        return $ymd !== '' ? date('d/m/Y', strtotime($ymd)) : '';
    }
}

/**
 * เปิดฐานของระบบอื่น → array(mysqli|null, คำนำหน้าชื่อตาราง)
 * มีบล็อก user ใน env.local → เชื่อมด้วยบัญชีนั้น | ไม่มี → ใช้การเชื่อมต่อหลักอ่านข้ามฐาน (`ชื่อฐาน`.ตาราง)
 */
if (!function_exists('mn_bds_db')) {
    function mn_bds_db($connect, $key, $default_name)
    {
        static $cache = array();
        if (array_key_exists($key, $cache)) { return $cache[$key]; }
        $local = function_exists('maintenance_new_env_local_config') ? maintenance_new_env_local_config() : array();
        $b = isset($local[$key]) && is_array($local[$key]) ? $local[$key] : array();
        if (isset($GLOBALS['MN_BDS_CONFIG_OVERRIDE'][$key])) { $b = $GLOBALS['MN_BDS_CONFIG_OVERRIDE'][$key]; } // ใช้ตอนทดสอบใน CLI
        $name = isset($b['name']) && $b['name'] !== '' ? (string) $b['name'] : $default_name;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) { return $cache[$key] = array(null, ''); }
        if (!empty($b['user'])) {
            $m = @mysqli_connect(isset($b['host']) ? (string) $b['host'] : 'localhost', (string) $b['user'],
                isset($b['password']) ? (string) $b['password'] : '', $name, isset($b['port']) ? (int) $b['port'] : 3306);
            if (!$m) { return $cache[$key] = array(null, ''); }
            mysqli_set_charset($m, 'utf8mb4');
            return $cache[$key] = array($m, '');
        }
        return $cache[$key] = array($connect, '`' . $name . '`.');
    }
}

/**
 * หน้าเครื่องในระบบ production (รวมประวัติคลัง / ส่งมอบ / ติดตั้ง / เช่า ของ S/N) — เปิดด้วย ?code=S/N
 * ที่อยู่ระบบ production: คีย์ production_web_base ใน config/env.local.php (ค่าเริ่มต้น /production/finishgoogs_ma_update)
 */
if (!function_exists('mn_bds_production_asset_url')) {
    function mn_bds_production_asset_url($sn)
    {
        $local = function_exists('maintenance_new_env_local_config') ? maintenance_new_env_local_config() : array();
        $base = isset($local['production_web_base']) && trim((string) $local['production_web_base']) !== '' ? rtrim(trim((string) $local['production_web_base']), '/') : '/production/finishgoogs_ma_update';
        return $base . '/asset.php?code=' . rawurlencode(trim((string) $sn));
    }
}

/** สถานะการเชื่อมต่อแหล่งหลักฐาน (หน้า settings) → array( array(label, key, db, ok, note) ) */
if (!function_exists('mn_bds_source_status')) {
    function mn_bds_source_status($connect)
    {
        $list = array(
            array('ส่งเครื่องจากคลัง', 'stockparts_mysql', 'biton_stockparts', array('stock_old')),
            array('ประวัติติดตั้ง', 'production_mysql', 'biton_production', array('installation_history')),
            array('ส่งมอบ / ใบสั่งติดตั้ง', 'setup_mysql', 'biton_setup', array('historical_serials', 'po_order_part_serials', 'setup_orders')),
        );
        $local = function_exists('maintenance_new_env_local_config') ? maintenance_new_env_local_config() : array();
        $out = array();
        foreach ($list as $s) {
            list($db, $p) = mn_bds_db($connect, $s[1], $s[2]);
            $missing = array();
            foreach ($s[3] as $tb) {
                $r = $db ? @mysqli_query($db, 'SELECT 1 FROM ' . $p . '`' . $tb . '` LIMIT 1') : false;
                if (!$r) { $missing[] = $tb; } else { mysqli_free_result($r); }
            }
            $configured = isset($local[$s[1]]['user']) && $local[$s[1]]['user'] !== '' && $local[$s[1]]['user'] !== 'CHANGE_ME';
            $note = !$db ? 'เชื่อมต่อไม่ได้ ตรวจ user/password ในบล็อก ' . $s[1]
                : ($missing ? 'อ่านตาราง ' . implode(', ', $missing) . ' ไม่ได้' . ($configured ? '' : ' (ยังไม่ได้ตั้งบล็อก ' . $s[1] . ')') : '');
            $out[] = array('label' => $s[0], 'key' => $s[1], 'db' => $s[2], 'ok' => $db && !$missing, 'note' => $note);
        }
        return $out;
    }
}

/** SELECT แบบ prepared ที่ล้มเหลวได้เงียบ ๆ (ฐาน/ตาราง/สิทธิ์ไม่มี) → array(rows|null) */
if (!function_exists('mn_bds_select')) {
    function mn_bds_select($db, $sql, $sn)
    {
        if (!$db) { return null; }
        $stmt = @mysqli_prepare($db, $sql);
        if (!$stmt) { return null; }
        mysqli_stmt_bind_param($stmt, 's', $sn);
        if (!@mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return null; }
        $res = mysqli_stmt_get_result($stmt);
        $rows = array();
        while ($res && ($r = mysqli_fetch_assoc($res))) { $rows[] = $r; }
        mysqli_stmt_close($stmt);
        return $rows;
    }
}

/**
 * → array(
 *   'items' => [ {date, sources: [{title, detail}], warn} ]  (เรียงตามจำนวนแหล่งที่ยืนยัน)
 *   'hints' => [ข้อความ], 'limit' => 'Y-m-d', 'off' => [แหล่งที่เชื่อมไม่ได้]
 * )
 */
if (!function_exists('mn_bds_suggest')) {
    function mn_bds_suggest($connect, $sn, $trp_id)
    {
        $sn = trim((string) $sn);
        $out = array('items' => array(), 'hints' => array(), 'limit' => '', 'off' => array());
        if ($sn === '') { return $out; }
        $t = (int) $trp_id;
        $found = array(); // date => [ [rank, title, detail], ... ]
        // ลิงก์ไปหน้าที่มา: ใบงานซ่อม → detail_job | คลัง/ติดตั้ง/ส่งมอบ → หน้าเครื่องในระบบ production (รวมประวัติทุกระบบของ S/N)
        $prod_url = mn_bds_production_asset_url($sn);
        $add = function ($date, $rank, $title, $detail, $url = '', $url_label = '') use (&$found) {
            $d = mn_bds_valid_date($date);
            if ($d === '') { return; }
            foreach (isset($found[$d]) ? $found[$d] : array() as $s) { if ($s[1] === $title && $s[2] === $detail) { return; } }
            $found[$d][] = array($rank, $title, $detail, $url, $url_label);
        };
        $join = function (array $parts) {
            return implode(' | ', array_filter(array_map('trim', $parts), function ($p) { return $p !== ''; }));
        };

        // 1) ใบงานซ่อมก่อนหน้า + วันที่ส่งซ่อมครั้งแรก
        $first_receive = '';
        $first_from = '';
        $stmt = mysqli_prepare($connect,
            'SELECT r.trp_id, r.trp_ts_id, r.trp_buy_date, r.trp_receive_date, COALESCE(t.ts_mtn_number, \'\') AS mtn'
            . ' FROM transac_repair r LEFT JOIN transac t ON t.ts_id = r.trp_ts_id'
            . ' WHERE UPPER(TRIM(r.trp_sn)) = UPPER(?) AND r.trp_id <> ? ORDER BY r.trp_id');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $sn, $t);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $rec = mn_bds_valid_date($r['trp_receive_date']);
                $job = trim($r['mtn']) !== '' ? 'งาน ' . trim($r['mtn']) : 'ใบงาน ' . $r['trp_id'];
                if ($rec !== '' && ($first_receive === '' || $rec < $first_receive)) { $first_receive = $rec; $first_from = $job; }
                $add($r['trp_buy_date'], 1, 'ประวัติซ่อม', $job . ($rec !== '' ? ' (รับซ่อม ' . mn_bds_dmy($rec) . ')' : ''),
                    (int) $r['trp_ts_id'] > 0 ? 'detail_job?ts_id=' . (int) $r['trp_ts_id'] : '', $job);
            }
            mysqli_stmt_close($stmt);
        }
        $old = mn_bds_select($connect, 'SELECT MIN(rh_date) AS d FROM repair_history WHERE UPPER(TRIM(rh_sn)) = UPPER(?)', $sn);
        $old_d = $old ? mn_bds_valid_date($old[0]['d']) : '';
        if ($old_d !== '' && ($first_receive === '' || $old_d < $first_receive)) { $first_receive = $old_d; $first_from = 'ประวัติซ่อมเดิม'; }

        // 2) ส่งเครื่องจากคลัง (stockparts)
        list($db, $p) = mn_bds_db($connect, 'stockparts_mysql', 'biton_stockparts');
        $rows = mn_bds_select($db, 'SELECT `timestamp` AS d, model, setup_id, security_company FROM ' . $p . 'stock_old'
            . ' WHERE UPPER(TRIM(serial_number)) = UPPER(?) ORDER BY `timestamp`', $sn);
        if ($rows === null) { $out['off'][] = 'ระบบคลังเครื่อง (biton_stockparts)'; }
        foreach ((array) $rows as $r) {
            $add($r['d'], 2, 'ส่งเครื่องจากคลัง', $join(array($r['setup_id'], $r['security_company'])), $prod_url, 'ระบบผลิต');
        }

        // 3) ประวัติติดตั้ง (production)
        list($db, $p) = mn_bds_db($connect, 'production_mysql', 'biton_production');
        $rows = mn_bds_select($db, 'SELECT install_date, sale_date, invoice, end_user, employer, security_company FROM ' . $p . 'installation_history'
            . ' WHERE UPPER(TRIM(serial)) = UPPER(?) ORDER BY install_date', $sn);
        if ($rows === null) { $out['off'][] = 'ระบบผลิต/ติดตั้ง (biton_production)'; }
        foreach ((array) $rows as $r) {
            $who = $join(array($r['end_user'] !== '' ? $r['end_user'] : $r['employer'], $r['security_company']));
            $inv = trim((string) $r['invoice']) !== '' ? 'Invoice ' . trim($r['invoice']) : '';
            $add($r['sale_date'], 2, 'วันที่ขาย (ประวัติติดตั้ง)', $join(array($who, $inv)), $prod_url, 'ระบบผลิต');
            $add($r['install_date'], 3, 'ติดตั้ง', $join(array($who, $inv)), $prod_url, 'ระบบผลิต');
        }

        // 4–5) ส่งมอบ / ใบสั่งติดตั้ง (setup)
        list($db, $p) = mn_bds_db($connect, 'setup_mysql', 'biton_setup');
        $rows = mn_bds_select($db, 'SELECT delivery_date, order_date, company_name, customer_name, po_number FROM ' . $p . 'historical_serials'
            . ' WHERE UPPER(TRIM(serial_number)) = UPPER(?) ORDER BY delivery_date', $sn);
        if ($rows === null) { $out['off'][] = 'ระบบติดตั้ง (biton_setup)'; }
        foreach ((array) $rows as $r) {
            $who = $join(array($r['company_name'], $r['customer_name'], trim((string) $r['po_number']) !== '' ? 'PO ' . $r['po_number'] : ''));
            $add($r['delivery_date'], 3, 'ส่งมอบ', $who, $prod_url, 'ระบบผลิต');
            $add($r['order_date'], 4, 'วันที่สั่งซื้อ', $who, $prod_url, 'ระบบผลิต');
        }
        $rows = mn_bds_select($db, 'SELECT ps.issue_date, ps.issue_type, o.po_number, o.po_date, o.customer_name, o.company_name, o.delivery_date, o.warranty_start_date'
            . ' FROM ' . $p . 'po_order_part_serials ps LEFT JOIN ' . $p . 'setup_orders o ON o.id = ps.order_id'
            . ' WHERE UPPER(TRIM(ps.serial_number)) = UPPER(?) AND (ps.issue_type IS NULL OR ps.issue_type <> \'claim\') ORDER BY ps.issue_date', $sn);
        foreach ((array) $rows as $r) {
            $who = $join(array($r['company_name'] !== null && $r['company_name'] !== '' ? $r['company_name'] : $r['customer_name'],
                trim((string) $r['po_number']) !== '' ? 'PO ' . $r['po_number'] : ''));
            $add($r['warranty_start_date'], 1, 'วันเริ่มรับประกัน (ใบสั่งติดตั้ง)', $who, $prod_url, 'ระบบผลิต');
            $add($r['delivery_date'], 3, 'ส่งมอบ (ใบสั่งติดตั้ง)', $who, $prod_url, 'ระบบผลิต');
            $add($r['issue_date'], 4, 'เบิกเครื่องออก (ใบสั่งติดตั้ง)', $who, $prod_url, 'ระบบผลิต');
        }

        if ($first_receive !== '') {
            $out['limit'] = $first_receive;
            $out['hints'][] = 'เครื่องนี้ส่งซ่อมครั้งแรก ' . mn_bds_dmy($first_receive) . ' (' . $first_from . ') จึงซื้อก่อนวันนั้น';
        }

        // รวมหลักฐานวันเดียวกัน — เรียง: ยืนยันหลายแหล่งก่อน แล้วตามความน่าเชื่อถือ แล้ววันที่เก่ากว่า
        foreach ($found as $date => $list) {
            usort($list, function ($a, $b) { return $a[0] - $b[0]; });
            $out['items'][] = array('date' => $date, 'rank' => $list[0][0],
                'sources' => array_map(function ($s) { return array('title' => $s[1], 'detail' => $s[2], 'url' => $s[3], 'url_label' => $s[4]); }, $list),
                'warn' => $out['limit'] !== '' && $date > $out['limit'] ? 'หลังวันที่ส่งซ่อมครั้งแรก ตรวจสอบก่อนใช้' : '');
        }
        usort($out['items'], function ($a, $b) {
            $n = count($b['sources']) - count($a['sources']);
            if ($n !== 0) { return $n; }
            if ($a['rank'] !== $b['rank']) { return $a['rank'] - $b['rank']; }
            return strcmp($a['date'], $b['date']);
        });
        return $out;
    }
}
