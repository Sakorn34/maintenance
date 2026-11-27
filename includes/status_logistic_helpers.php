<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper สำหรับหน้า status_logistic — escape ข้อความ HTML และประกอบรายละเอียดลูกค้า/ที่อยู่จากแถว transac
 *
 * องค์ประกอบหลัก:
 * - `maintenance_status_logistic_strip_company_prefix()` ตัดคำนำหน้าบริษัท (delegate `strReplace()` ถ้ามี)
 * - `maintenance_status_logistic_h()` escape ข้อความ HTML
 * - `maintenance_status_logistic_detail_html()` สร้าง HTML ส่วน detail (เทียบ logic เดิมใน old_system/status_logistic.php)
 * - `maintenance_detail_job_header_locked()` ตรวจว่าหัวใบงานใน `detail_job` ถูกล็อกเมื่อมีแถว `transac_repair` ใด ๆ ของ `ts_id` ที่ `trp_status_job = 'เสร็จสิ้น'`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_status_logistic_strip_company_prefix')) {
    /**
     * Purpose:
     * ตัดคำนำหน้าชื่อบริษัท (เทียบ `strReplace()` ใน `function_legacy.php`) — ใช้เมื่อหน้าเรียกยังไม่ได้ `require` legacy
     *
     * Parameters สำคัญ:
     * - string $str: ข้อความต้นฉบับ
     *
     * Return value:
     * - string ข้อความหลังตัดคำนำหน้า
     */
    function maintenance_status_logistic_strip_company_prefix($str)
    {
        if (function_exists('strReplace')) {
            return strReplace($str);
        }
        $s = (string) $str;

        return str_replace('มบ.', '', str_replace('บจก.', '', str_replace('บริษัท', '', str_replace('รักษาความปลอดภัย', '', str_replace('จำกัด', '', $s)))));
    }
}

if (!function_exists('maintenance_status_logistic_h')) {
    /**
     * Purpose:
     * Escape ข้อความสำหรับแสดงใน HTML (ป้องกัน XSS)
     *
     * Parameters สำคัญ:
     * - mixed $value: ค่าที่อาจเป็น string หรือ null
     *
     * Return value:
     * - string ข้อความที่ escape แล้ว
     */
    function maintenance_status_logistic_h($value)
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('maintenance_status_logistic_normalize_company_label')) {
    /**
     * Purpose:
     * เตรียมข้อความชื่อ End user / รปภ. ก่อนส่งเข้า `maintenance_status_logistic_company_names_html()` — รองรับ utf8, strReplace, strip prefix ตามแต่ละหน้า
     *
     * Parameters สำคัญ:
     * - string $raw: ค่าจาก DB
     * - array $options: คีย์ `utf8` (bool), `str_replace` (bool), `strip_prefix` (bool)
     *
     * Return value:
     * - string ข้อความ plain (ยังไม่ escape HTML)
     */
    function maintenance_status_logistic_normalize_company_label($raw, array $options = array())
    {
        $opts = array_merge(
            array('utf8' => false, 'str_replace' => false, 'strip_prefix' => true),
            $options
        );
        $t = trim((string) $raw);
        if ($t === '') {
            return '';
        }
        if (!empty($opts['utf8'])) {
            require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
            $t = maintenance_flow_db_text_to_utf8($t);
            $t = trim($t);
        }
        if (!empty($opts['str_replace']) && function_exists('strReplace')) {
            $t = strReplace($t);
            $t = trim((string) $t);
        }
        if (!empty($opts['strip_prefix'])) {
            $t = maintenance_status_logistic_strip_company_prefix($t);
        }

        return trim((string) $t);
    }
}

if (!function_exists('maintenance_status_logistic_company_names_html')) {
    /**
     * Purpose:
     * ประกอบ HTML ชื่อ End user + รปภ. แยกสีด้วย class `mn-company-enduser` / `mn-company-security` (ใช้ร่วมทุกหน้ารายการ)
     *
     * Parameters สำคัญ:
     * - string $enduser_plain: ข้อความ End user หลัง normalize แล้ว
     * - string $security_plain: ข้อความ รปภ. หลัง normalize แล้ว
     *
     * Return value:
     * - string HTML (escape แล้ว) หรือค่าว่างถ้าไม่มีทั้งคู่
     */
    function maintenance_status_logistic_company_names_html($enduser_plain, $security_plain)
    {
        $eu = trim((string) $enduser_plain);
        $sec = trim((string) $security_plain);
        $eu_html = $eu !== ''
            ? '<span class="mn-company-enduser">' . maintenance_status_logistic_h($eu) . '</span>'
            : '';
        $sec_html = $sec !== ''
            ? '<span class="mn-company-security">' . maintenance_status_logistic_h($sec) . '</span>'
            : '';

        if ($eu_html !== '' && $sec_html !== '') {
            return $eu_html . '<br />' . $sec_html . '<br />';
        }
        if ($eu_html !== '') {
            return $eu_html . '<br />';
        }
        if ($sec_html !== '') {
            return $sec_html . '<br />';
        }

        return '';
    }
}

if (!function_exists('maintenance_status_logistic_detail_html')) {
    /**
     * Purpose:
     * ประกอบ HTML รายละเอียดผู้แจ้ง / โทร / ที่อยู่ (ตัดคำนำหน้าบริษัทผ่าน `maintenance_status_logistic_strip_company_prefix()` ซึ่งอ้าง `strReplace()` จาก legacy ถ้ามี)
     *
     * Parameters สำคัญ:
     * - array $ts: แถว transac (คีย์ ts_enduser_com, ts_security_com, ts_report_name, ts_report_tel, ts_address)
     *
     * Return value:
     * - string HTML (มี tag `<br>` และ span สี)
     */
    function maintenance_status_logistic_detail_html(array $ts)
    {
        $eu = maintenance_status_logistic_normalize_company_label(
            isset($ts['ts_enduser_com']) ? $ts['ts_enduser_com'] : ''
        );
        $sec = maintenance_status_logistic_normalize_company_label(
            isset($ts['ts_security_com']) ? $ts['ts_security_com'] : ''
        );
        $block = maintenance_status_logistic_company_names_html($eu, $sec);
        $block .= maintenance_status_logistic_h(isset($ts['ts_report_name']) ? $ts['ts_report_name'] : '') . '  ';
        $block .= maintenance_status_logistic_h(isset($ts['ts_report_tel']) ? $ts['ts_report_tel'] : '') . '<br>';
        $block .= maintenance_status_logistic_h(isset($ts['ts_address']) ? $ts['ts_address'] : '') . '<br>';
        return $block;
    }
}

if (!function_exists('maintenance_status_logistic_spare_blank_options')) {
    /**
     * Purpose:
     * ดึงรายการ S/N เครื่องสำรองคงคลัง (sp_status = blank) ตามรุ่นสินค้า — logic เดียวกับ old_system/status_logistic.php
     *
     * Parameters สำคัญ:
     * - mysqli|resource $connect: connection จาก mysql_odbc_compat
     * - string $tsp_product: ชื่อรุ่นจาก tsp_product
     *
     * Return value:
     * - array ของ array('sn' => string) เรียงตามผล query
     */
    function maintenance_status_logistic_spare_blank_options($connect, $tsp_product)
    {
        $search = (string) $tsp_product;
        $sqlsp = "SELECT sp_sn FROM spare WHERE sp_product_name = '" . mysqli_real_escape_string($connect, $search) . "' AND sp_status = 'blank' ORDER BY sp_sn";
        if ($search === 'bitSupply 125' || $search === 'bitSupply 1224') {
            $sqlsp = "SELECT sp_sn FROM spare WHERE sp_product_name LIKE '%Adapter%' AND sp_status = 'blank' ORDER BY sp_sn";
        } elseif ($search === 'bitScan Pro' || $search === 'bitScan Kit' || $search === 'bitVehicle' || $search === 'bitScan AL' || $search === 'bitScan Lite') {
            $sqlsp = "SELECT sp_sn FROM spare WHERE sp_product_name LIKE '%bitScan%' AND sp_status = 'blank' ORDER BY sp_sn";
        }
        $out = array();
        $execsp = maintenance_db_odbc_exec($connect, $sqlsp);
        if (!$execsp) {
            return $out;
        }
        while ($row = maintenance_db_odbc_fetch_array($execsp)) {
            if (!empty($row['sp_sn'])) {
                $out[] = array('sn' => (string) $row['sp_sn']);
            }
        }
        return $out;
    }
}

if (!function_exists('maintenance_status_logistic_spare_pending_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_status_job` สำหรับรายการรอส่งเครื่องสำรองบนหน้า `status_logistic`
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_status_logistic_spare_pending_status_literal()
    {
        return 'รอส่งสำรอง';
    }
}

if (!function_exists('maintenance_status_logistic_repair_return_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_status_job` สำหรับรายการรอส่งคืนเครื่องซ่อมบนหน้า `status_logistic`
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_status_logistic_repair_return_status_literal()
    {
        return 'รอส่งคืนเครื่องซ่อม';
    }
}

if (!function_exists('maintenance_status_logistic_wait_hardware_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_status_job` หลังส่งเครื่องสำรองสำเร็จ (กรณี flow ปกติจาก «รอส่งสำรอง»)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_status_logistic_wait_hardware_status_literal()
    {
        return 'รอเครื่องซ่อม';
    }
}

if (!function_exists('maintenance_status_logistic_apply_repair_status_after_spare_send')) {
    /**
     * Purpose:
     * หลังบันทึกขนส่งเครื่องสำรอง — เปลี่ยน `trp_status_job` เป็น «รอเครื่องซ่อม» เฉพาะเมื่อรายการซ่อมยังอยู่ «รอส่งสำรอง»
     * (กรณีขอสำรองภายหลังที่ลูกค้าส่งเครื่องซ่อมมาแล้ว สถานะอาจเป็นอย่างอื่น — ไม่แก้สถานะ)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: `transac_repair.trp_id` จาก `transac_spare.tsp_trp_id`
     *
     * Return value:
     * - bool true เมื่อมีการ UPDATE สถานะ, false เมื่อข้ามหรือไม่พบแถวที่ตรงเงื่อนไข
     */
    function maintenance_status_logistic_apply_repair_status_after_spare_send($connect, $trp_id)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return false;
        }

        $from_status = maintenance_status_logistic_spare_pending_status_literal();
        $to_status = maintenance_status_logistic_wait_hardware_status_literal();

        $sql = 'UPDATE transac_repair SET trp_status_job = ? WHERE trp_id = ? AND trp_status_job = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, 'sis', $to_status, $trp_id, $from_status);
        $ok = mysqli_stmt_execute($stmt);
        $affected = $ok ? mysqli_stmt_affected_rows($stmt) : 0;
        mysqli_stmt_close($stmt);

        return $ok && $affected > 0;
    }
}

if (!function_exists('maintenance_status_logistic_repair_has_sent_spare')) {
    /**
     * Purpose:
     * ตรวจว่ารายการซ่อมมีแถว `transac_spare` ที่บันทึกวันส่ง (`tsp_datesend`) แล้วหรือไม่
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: `transac_repair.trp_id`
     *
     * Return value:
     * - bool true เมื่อมีวันส่งสำรองแล้ว
     */
    function maintenance_status_logistic_repair_has_sent_spare($connect, $trp_id)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return false;
        }

        $sql = 'SELECT 1 FROM transac_spare WHERE tsp_trp_id = ?'
            . " AND tsp_datesend IS NOT NULL AND TRIM(tsp_datesend) <> '' LIMIT 1";
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        $res = mysqli_stmt_get_result($stmt);
        $has = $res && mysqli_fetch_assoc($res);
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return (bool) $has;
    }
}

if (!function_exists('maintenance_status_logistic_apply_repair_status_on_edit_spare')) {
    /**
     * Purpose:
     * หลังบันทึก S/N สำรองจาก `edit_spare` — ตั้ง `trp_status_job = รอส่งสำรอง` เฉพาะ flow ก่อนส่งสำรอง
     * (รอเครื่องซ่อม + ยังไม่เคยส่งสำรอง) — กรณีขอสำรองทีหลัง/สถานะขั้นต่อไปแล้ว **ไม่แก้สถานะ**
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: `transac_repair.trp_id`
     * - int $ts_id: `transac_repair.trp_ts_id`
     *
     * Return value:
     * - bool true เมื่อ UPDATE เป็น «รอส่งสำรอง» สำเร็จ, false เมื่อข้ามหรือไม่ต้องเปลี่ยน
     */
    function maintenance_status_logistic_apply_repair_status_on_edit_spare($connect, $trp_id, $ts_id)
    {
        $trp_id = (int) $trp_id;
        $ts_id = (int) $ts_id;
        if ($trp_id < 1 || $ts_id < 1 || !($connect instanceof mysqli)) {
            return false;
        }

        $pending = maintenance_status_logistic_spare_pending_status_literal();
        $wait_hw = maintenance_status_logistic_wait_hardware_status_literal();

        $stmt = mysqli_prepare(
            $connect,
            'SELECT trp_status_job FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? AND trp_id > 0 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        if (!is_array($row) || !isset($row['trp_status_job'])) {
            return false;
        }

        $current = trim((string) $row['trp_status_job']);
        if ($current === $pending) {
            return false;
        }
        if ($current !== $wait_hw) {
            return false;
        }
        if (maintenance_status_logistic_repair_has_sent_spare($connect, $trp_id)) {
            return false;
        }

        $sql = 'UPDATE transac_repair SET trp_status_job = ? WHERE trp_id = ? AND trp_ts_id = ? AND trp_status_job = ? LIMIT 1';
        $st_up = mysqli_prepare($connect, $sql);
        if ($st_up === false) {
            return false;
        }
        mysqli_stmt_bind_param($st_up, 'siis', $pending, $trp_id, $ts_id, $wait_hw);
        $ok = mysqli_stmt_execute($st_up);
        $affected = $ok ? mysqli_stmt_affected_rows($st_up) : 0;
        mysqli_stmt_close($st_up);

        return $ok && $affected > 0;
    }
}

if (!function_exists('maintenance_status_logistic_relink_orphan_spare_rows')) {
    /**
     * Purpose:
     * ผูก `transac_spare.tsp_trp_id` ที่เป็น 0/NULL กลับไปที่ `transac_repair.trp_id` ตาม `tsp_ts_id` + ชื่อสินค้า — แก้ข้อมูลจากบั๊ก insert_job เดิม
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - int จำนวนแถวที่ UPDATE
     */
    function maintenance_status_logistic_relink_orphan_spare_rows($connect)
    {
        $status = maintenance_status_logistic_spare_pending_status_literal();
        $sql = 'UPDATE transac_spare tsp INNER JOIN transac_repair tr'
            . ' ON tr.trp_ts_id = tsp.tsp_ts_id'
            . ' AND TRIM(COALESCE(tr.trp_product, \'\')) = TRIM(COALESCE(tsp.tsp_product, \'\'))'
            . ' AND tr.trp_status_job = ?'
            . ' SET tsp.tsp_trp_id = tr.trp_id'
            . ' WHERE tr.trp_id > 0 AND (tsp.tsp_trp_id IS NULL OR tsp.tsp_trp_id = 0)';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 's', $status);
        mysqli_stmt_execute($stmt);
        $n = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        return $n;
    }
}

if (!function_exists('maintenance_status_logistic_ensure_spare_rows_for_pending_repairs')) {
    /**
     * Purpose:
     * สร้างแถว `transac_spare` ขั้นต่ำเมื่อ `transac_repair` เป็น «รอส่งสำรอง» แต่ยังไม่มี spare ที่ `tsp_trp_id` ชี้ถูก — ให้หน้า logistic แสดงและบันทึกขนส่งได้
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - int จำนวนแถวที่ INSERT
     */
    function maintenance_status_logistic_ensure_spare_rows_for_pending_repairs($connect)
    {
        $status = maintenance_status_logistic_spare_pending_status_literal();
        $sql = 'INSERT INTO transac_spare (tsp_ts_id, tsp_trp_id, tsp_product, tsp_sn)'
            . ' SELECT tr.trp_ts_id, tr.trp_id, tr.trp_product, COALESCE(tr.trp_sn, \'\')'
            . ' FROM transac_repair tr'
            . ' LEFT JOIN transac_spare tsp ON tsp.tsp_trp_id = tr.trp_id AND tr.trp_id > 0'
            . ' WHERE tr.trp_status_job = ? AND tr.trp_id > 0 AND tsp.tsp_id IS NULL';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 's', $status);
        mysqli_stmt_execute($stmt);
        $n = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        return $n;
    }
}

if (!function_exists('maintenance_status_logistic_mysqli_stmt_fetch_assoc')) {
    /**
     * Purpose:
     * อ่านแถวเดียวจาก prepared statement — รองรับ PHP ที่ไม่มี mysqlnd (ไม่มี `mysqli_stmt_get_result`)
     *
     * Parameters สำคัญ:
     * - mysqli_stmt $stmt: statement หลัง execute แล้ว
     *
     * Return value:
     * - array|false แถว associative หรือ false
     */
    function maintenance_status_logistic_mysqli_stmt_fetch_assoc($stmt)
    {
        if (!($stmt instanceof mysqli_stmt)) {
            return false;
        }
        if (function_exists('mysqli_stmt_get_result')) {
            $res = @mysqli_stmt_get_result($stmt);
            if ($res instanceof mysqli_result) {
                return mysqli_fetch_assoc($res);
            }
        }
        $meta = mysqli_stmt_result_metadata($stmt);
        if (!$meta instanceof mysqli_result) {
            return false;
        }
        $fields = array();
        while ($f = mysqli_fetch_field($meta)) {
            $fields[] = $f->name;
        }
        mysqli_free_result($meta);
        if ($fields === array()) {
            return false;
        }
        $bind = array();
        $row = array();
        foreach ($fields as $name) {
            $row[$name] = null;
            $bind[] = &$row[$name];
        }
        call_user_func_array(array($stmt, 'bind_result'), $bind);
        if (!mysqli_stmt_fetch($stmt)) {
            return false;
        }
        $out = array();
        foreach ($row as $k => $v) {
            $out[$k] = $v;
        }

        return $out;
    }
}

if (!function_exists('maintenance_status_logistic_max_positive_tsp_id')) {
    /**
     * Purpose:
     * หา `tsp_id` สูงสุดที่มากกว่า 0 — ใช้กำหนดค่าใหม่เมื่อตารางไม่มี AUTO_INCREMENT (พบบ่อยบน production หลังย้ายจาก Access)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - int ค่า MAX หรือ 0 เมื่อยังไม่มีแถวที่ tsp_id > 0
     */
    function maintenance_status_logistic_max_positive_tsp_id($connect)
    {
        $res = mysqli_query($connect, 'SELECT COALESCE(MAX(tsp_id), 0) AS mx FROM transac_spare WHERE tsp_id > 0');
        if (!$res) {
            return 0;
        }
        $row = mysqli_fetch_assoc($res);
        mysqli_free_result($res);

        return isset($row['mx']) ? (int) $row['mx'] : 0;
    }
}

if (!function_exists('maintenance_status_logistic_fix_zero_tsp_ids')) {
    /**
     * Purpose:
     * กำหนด `tsp_id` ใหม่ให้แถวที่ `tsp_id` เป็น 0/NULL — แก้ข้อมูล production ที่ INSERT ไม่ระบุคีย์หลักจึงได้ 0 (dev ที่ import จาก MDB มักมี tsp_id ครบอยู่แล้ว)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - int จำนวนแถวที่ UPDATE
     */
    function maintenance_status_logistic_fix_zero_tsp_ids($connect)
    {
        $sql = 'SELECT tsp_ts_id, tsp_trp_id, tsp_product, tsp_sn FROM transac_spare'
            . ' WHERE tsp_id IS NULL OR tsp_id <= 0'
            . ' ORDER BY tsp_ts_id ASC, tsp_trp_id ASC, tsp_product ASC, tsp_sn ASC';
        $res = mysqli_query($connect, $sql);
        if (!$res) {
            return 0;
        }
        $orphans = array();
        while ($r = mysqli_fetch_assoc($res)) {
            $orphans[] = $r;
        }
        mysqli_free_result($res);
        if ($orphans === array()) {
            return 0;
        }

        $fixed = 0;
        foreach ($orphans as $row) {
            $trp_id = isset($row['tsp_trp_id']) ? (int) $row['tsp_trp_id'] : 0;
            $product = isset($row['tsp_product']) ? (string) $row['tsp_product'] : '';
            if ($trp_id > 0) {
                $assigned = maintenance_status_logistic_assign_tsp_id_for_trp($connect, $trp_id, $product);
                if ($assigned > 0) {
                    $fixed++;
                }
            }
        }

        return $fixed;
    }
}

if (!function_exists('maintenance_status_logistic_lookup_tsp_id_by_trp')) {
    /**
     * Purpose:
     * ค้น `tsp_id` จาก `tsp_trp_id` (และ S/N เดิมถ้ามี) — ใช้ mysqli_query ตรงเพื่อลดปัญหา mysqlnd บน production
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส transac_repair
     * - string $tsp_sn_old: S/N เดิม (ว่างได้)
     *
     * Return value:
     * - int tsp_id หรือ 0
     */
    function maintenance_status_logistic_lookup_tsp_id_by_trp($connect, $trp_id, $tsp_sn_old = '')
    {
        $trp_id = (int) $trp_id;
        if ($trp_id <= 0) {
            return 0;
        }
        $sql = 'SELECT tsp_id FROM transac_spare WHERE tsp_trp_id = ' . $trp_id . ' AND tsp_id > 0';
        if ($tsp_sn_old !== '') {
            $sql .= " AND TRIM(COALESCE(tsp_sn, '')) = TRIM('" . mysqli_real_escape_string($connect, $tsp_sn_old) . "')";
        }
        $sql .= ' ORDER BY tsp_id DESC LIMIT 1';
        $res = mysqli_query($connect, $sql);
        if (!$res) {
            return 0;
        }
        $row = mysqli_fetch_assoc($res);
        mysqli_free_result($res);

        return (is_array($row) && !empty($row['tsp_id'])) ? (int) $row['tsp_id'] : 0;
    }
}

if (!function_exists('maintenance_status_logistic_assign_tsp_id_for_trp')) {
    /**
     * Purpose:
     * กำหนด `tsp_id` ให้แถว spare ที่ `tsp_trp_id` ตรงแต่ `tsp_id` ยังเป็น 0 — ทีละงาน ไม่สแกนทั้งตาราง (ใช้ตอนบันทึก S/N)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส transac_repair
     * - string $tsp_product: ชื่อรุ่น (ช่วยแยกแถว)
     *
     * Return value:
     * - int tsp_id ที่กำหนดแล้ว หรือ 0
     */
    function maintenance_status_logistic_assign_tsp_id_for_trp($connect, $trp_id, $tsp_product = '')
    {
        $trp_id = (int) $trp_id;
        if ($trp_id <= 0) {
            return 0;
        }
        $existing = maintenance_status_logistic_lookup_tsp_id_by_trp($connect, $trp_id);
        if ($existing > 0) {
            return $existing;
        }
        $next = maintenance_status_logistic_max_positive_tsp_id($connect) + 1;
        $sql = 'UPDATE transac_spare SET tsp_id = ' . (int) $next
            . ' WHERE (tsp_id IS NULL OR tsp_id <= 0) AND tsp_trp_id = ' . $trp_id;
        if ($tsp_product !== '') {
            $sql .= " AND TRIM(COALESCE(tsp_product, '')) = TRIM('" . mysqli_real_escape_string($connect, $tsp_product) . "')";
        }
        $sql .= ' LIMIT 1';
        if (!mysqli_query($connect, $sql) || mysqli_affected_rows($connect) < 1) {
            return 0;
        }

        return $next;
    }
}

if (!function_exists('maintenance_status_logistic_resolve_tsp_id')) {
    /**
     * Purpose:
     * คืน `tsp_id` ที่ใช้ UPDATE ได้ — ถ้า GET ส่ง 0 ให้ค้นจาก `tsp_trp_id` แล้วกำหนดเลขให้แถวที่ยังเป็น 0 (ไม่เรียก fix ทั้งตาราง เพื่อลด 500 บน production)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: จาก query string
     * - int $trp_id: รหัส `transac_repair`
     * - string $tsp_sn_old: S/N เดิมในแถว spare
     * - int $tsp_ts_id: รหัส transac (สำรองเมื่อไม่มี trp_id ใน URL)
     * - string $tsp_product: ชื่อรุ่น (สำรองคู่กับ tsp_ts_id)
     *
     * Return value:
     * - int tsp_id ที่ > 0 หรือ 0 เมื่อหาไม่ได้
     */
    function maintenance_status_logistic_resolve_tsp_id($connect, $tsp_id, $trp_id, $tsp_sn_old = '', $tsp_ts_id = 0, $tsp_product = '')
    {
        if ($tsp_id > 0) {
            return $tsp_id;
        }
        $trp_id = (int) $trp_id;
        if ($trp_id > 0) {
            $found = maintenance_status_logistic_lookup_tsp_id_by_trp($connect, $trp_id, $tsp_sn_old);
            if ($found > 0) {
                return $found;
            }

            return maintenance_status_logistic_assign_tsp_id_for_trp($connect, $trp_id, (string) $tsp_product);
        }
        $tsp_ts_id = (int) $tsp_ts_id;
        $tsp_product = (string) $tsp_product;
        if ($tsp_ts_id > 0 && $tsp_product !== '') {
            $sql = 'SELECT tsp_id FROM transac_spare WHERE tsp_ts_id = ' . $tsp_ts_id
                . " AND TRIM(COALESCE(tsp_product, '')) = TRIM('" . mysqli_real_escape_string($connect, $tsp_product) . "')"
                . ' AND tsp_id > 0 ORDER BY tsp_id DESC LIMIT 1';
            $res = mysqli_query($connect, $sql);
            if ($res) {
                $row = mysqli_fetch_assoc($res);
                mysqli_free_result($res);
                if (is_array($row) && !empty($row['tsp_id'])) {
                    return (int) $row['tsp_id'];
                }
            }
            $next = maintenance_status_logistic_max_positive_tsp_id($connect) + 1;
            $sql2 = 'UPDATE transac_spare SET tsp_id = ' . (int) $next
                . ' WHERE (tsp_id IS NULL OR tsp_id <= 0) AND tsp_ts_id = ' . $tsp_ts_id
                . " AND TRIM(COALESCE(tsp_product, '')) = TRIM('" . mysqli_real_escape_string($connect, $tsp_product) . "')"
                . ' LIMIT 1';
            if (mysqli_query($connect, $sql2) && mysqli_affected_rows($connect) > 0) {
                return $next;
            }
        }

        return 0;
    }
}

if (!function_exists('maintenance_status_logistic_fetch_spare_pending_rows')) {
    /**
     * Purpose:
     * ดึงรายการเครื่องสำรองที่รอส่ง — อิง `transac_repair` สถานะ «รอส่งสำรอง» แล้ว JOIN `transac_spare` และ `transac` (ไม่รวมงานที่ไม่มีใบงานหลัก)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของแถว associative (คีย์จาก `transac_spare` + `trp_row_comment`)
     */
    function maintenance_status_logistic_fetch_spare_pending_rows($connect)
    {
        maintenance_status_logistic_relink_orphan_spare_rows($connect);
        maintenance_status_logistic_ensure_spare_rows_for_pending_repairs($connect);
        maintenance_status_logistic_fix_zero_tsp_ids($connect);

        $status = maintenance_status_logistic_spare_pending_status_literal();
        // flow เครื่องสำรองใหม่: เครื่องที่ตีกลับ/ส่งแทนเครื่องอื่น ต้องส่ง แม้รายการซ่อมเลยขั้น «รอส่งสำรอง» แล้ว
        $extra = '';
        if (function_exists('mn_spare_pending_send_ids')) {
            $ids = array_map('intval', mn_spare_pending_send_ids($connect));
            if ($ids) { $extra = ' OR tsp.tsp_id IN (' . implode(',', $ids) . ')'; }
        }
        // ระบุคอลัมน์แบบ explicit เพื่อให้ key ใน result ชัดเจน (โดยเฉพาะ `tsp_id`)
        $sql = 'SELECT '
            . 'tsp.tsp_id AS tsp_id, tsp.tsp_ts_id AS tsp_ts_id, tsp.tsp_trp_id AS tsp_trp_id, '
            . 'tsp.tsp_product AS tsp_product, tsp.tsp_sn AS tsp_sn, '
            . 'tsp.tsp_datesend AS tsp_datesend, tsp.tsp_logistics AS tsp_logistics, tsp.tsp_tracking AS tsp_tracking, '
            . 'tsp.tsp_sendby AS tsp_sendby, tsp.tsp_datereturn AS tsp_datereturn, tsp.tsp_remarks AS tsp_remarks, tsp.tsp_receiveby AS tsp_receiveby, '
            . 'tr.trp_ts_id AS trp_ts_id, tr.trp_other_comment AS trp_row_comment'
            . ' FROM transac_repair tr'
            . ' INNER JOIN transac ts ON ts.ts_id = tr.trp_ts_id AND tr.trp_ts_id > 0'
            . ' INNER JOIN transac_spare tsp ON tsp.tsp_trp_id = tr.trp_id'
            . ' WHERE (tr.trp_status_job = ?' . $extra . ') AND tr.trp_id > 0'
            . " AND (tsp.tsp_datereturn IS NULL OR TRIM(tsp.tsp_datereturn) = '')"
            . ' ORDER BY tsp.tsp_id ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 's', $status);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = array();
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = $r;
            }
        }
        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('maintenance_status_logistic_fetch_repair_return_rows')) {
    /**
     * Purpose:
     * ดึงรายการรอส่งคืนเครื่องซ่อม — เฉพาะ `transac_repair` ที่มีแถว `transac` คู่กัน (`trp_ts_id` ชี้ใบงานจริง)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของแถว associative จาก `transac_repair`
     */
    function maintenance_status_logistic_fetch_repair_return_rows($connect)
    {
        $status = maintenance_status_logistic_repair_return_status_literal();
        $sql = 'SELECT tr.trp_id, tr.trp_ts_id, tr.trp_product, tr.trp_sn, tr.trp_other_comment, tr.trp_tracking, tr.trp_qc_date'
            . ' FROM transac_repair tr'
            . ' INNER JOIN transac ts ON ts.ts_id = tr.trp_ts_id AND tr.trp_ts_id > 0'
            . ' WHERE tr.trp_status_job = ? AND tr.trp_id > 0'
            . ' ORDER BY tr.trp_qc_date ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 's', $status);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = array();
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = $r;
            }
        }
        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('maintenance_status_logistic_print_label_query')) {
    /**
     * Purpose:
     * สร้าง query string สำหรับลิงก์พิมพ์ใบปะหน้า (encode ค่า UTF-8)
     *
     * Parameters สำคัญ:
     * - array $parts: คีย์ user_addr, tel_addr, send_address, site_addr
     *
     * Return value:
     * - string query ไม่รวม ?
     */
    function maintenance_status_logistic_print_label_query(array $parts)
    {
        return 'user_addr=' . rawurlencode(isset($parts['user_addr']) ? (string) $parts['user_addr'] : '')
            . '&tel_addr=' . rawurlencode(isset($parts['tel_addr']) ? (string) $parts['tel_addr'] : '')
            . '&send_address=' . rawurlencode(isset($parts['send_address']) ? (string) $parts['send_address'] : '')
            . '&site_addr=' . rawurlencode(isset($parts['site_addr']) ? (string) $parts['site_addr'] : '');
    }
}

if (!function_exists('maintenance_status_logistic_row')) {
    /**
     * Purpose:
     * อ่านค่าจากแถวผลลัพธ์ DB แบบปลอดภัย (รองรับ PHP ที่ไม่มี operator ??)
     *
     * Parameters สำคัญ:
     * - array|null $row: แถว associative หรือ null
     * - string $key: ชื่อคอลัมน์
     * - mixed $default: ค่าเมื่อไม่มีคีย์
     *
     * Return value:
     * - mixed ค่าจากแถวหรือ default
     */
    function maintenance_status_logistic_row($row, $key, $default = '')
    {
        if (!is_array($row)) {
            return $default;
        }
        return array_key_exists($key, $row) ? $row[$key] : $default;
    }
}

if (!function_exists('maintenance_detail_job_header_locked')) {
    /**
     * Purpose:
     * ใช้กับหน้า `detail_job` — คืน true เมื่อห้ามแก้ไขฟิลด์หัว `transac` (เมื่อมีอย่างน้อยหนึ่งแถว `transac_repair` ของ `ts_id` ที่ `trp_status_job = 'เสร็จสิ้น'`)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (compat `odbc_exec`)
     * - int $ts_id: รหัส transac
     *
     * Return value:
     * - bool true เมื่อล็อก (ห้ามแก้ไข)
     */
    function maintenance_detail_job_header_locked($connect, $ts_id)
    {
        if ($ts_id <= 0 || !$connect) {
            return false;
        }
        $done = mysqli_real_escape_string($connect, 'เสร็จสิ้น');
        $sql = 'SELECT trp_id FROM transac_repair WHERE trp_ts_id = ' . (int) $ts_id
            . " AND trp_status_job = '" . $done . "' LIMIT 1";
        $exe = maintenance_db_odbc_exec($connect, $sql);
        $row = $exe ? maintenance_db_odbc_fetch_array($exe) : false;
        return is_array($row) && isset($row['trp_id']);
    }
}
