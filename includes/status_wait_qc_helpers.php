<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `status_wait_qc.php` — สถานะ «รอ QC», filter สินค้า (กรณี bitScan ใช้ LIKE), กรองชื่อ `ts_enduser_com` / `ts_security_com` แบบตรงกับค่าใน select, รายชื่อใน select จากงานที่รอ QC เท่านั้น
 *
 * Flow:
 * parse GET → `maintenance_wait_qc_fetch_wait_qc_company_name_list()` (ตัวเลือก select) + `maintenance_wait_qc_fetch_join_rows()` → render ตาราง
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_wait_qc_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_status_job` ที่หน้านี้แสดง (ต้องตรงกับฐานข้อมูล)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_wait_qc_status_literal()
    {
        return 'รอ QC';
    }
}

if (!function_exists('maintenance_wait_qc_parse_get_filters')) {
    /**
     * Purpose:
     * อ่าน filter สินค้าและค่า `company` จาก query string (ชื่อตรงจาก select — กรอง `ts_enduser_com` / `ts_security_com`)
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET` — รองรับคีย์ `product`, `company`
     *
     * Return value:
     * - array คีย์ `product` (string), `company` (string ชื่อตรงจาก select หรือว่าง = ไม่กรอง)
     */
    function maintenance_wait_qc_parse_get_filters(array $get)
    {
        return array(
            'product' => isset($get['product']) ? trim((string) $get['product']) : '',
            'company' => isset($get['company']) ? trim((string) $get['company']) : '',
        );
    }
}

if (!function_exists('maintenance_wait_qc_product_sql_fragment')) {
    /**
     * Purpose:
     * สร้างเงื่อนไข SQL สำหรับกรอง `trp_product` ตาม filter สินค้า (เทียบ legacy bitScan ใช้ LIKE)
     *
     * Parameters สำคัญ:
     * - array $filters: คีย์ `product`
     *
     * Return value:
     * - array คีย์ `sql` (string ต่อท้าย WHERE), `types` (string สำหรับ bind_param), `values` (array ค่า bind)
     */
    function maintenance_wait_qc_product_sql_fragment(array $filters)
    {
        $product = isset($filters['product']) ? trim((string) $filters['product']) : '';
        if ($product === '') {
            return array('sql' => '', 'types' => '', 'values' => array());
        }
        if (stripos($product, 'bitScan') !== false) {
            return array(
                'sql' => ' AND tr.trp_product LIKE ? ',
                'types' => 's',
                'values' => array('%bitScan%'),
            );
        }

        return array(
            'sql' => ' AND tr.trp_product = ? ',
            'types' => 's',
            'values' => array($product),
        );
    }
}

if (!function_exists('maintenance_wait_qc_fetch_wait_qc_company_name_list')) {
    /**
     * Purpose:
     * ดึงรายชื่อไม่ซ้ำจาก `ts_enduser_com` และ `ts_security_com` เฉพาะแถวที่ `trp_status_job = รอ QC` (ใช้เติม `<select>` — ไม่อ่านคีย์ `company` ใน filter)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $filters: ผลจาก `maintenance_wait_qc_parse_get_filters()` (ใช้เฉพาะ `product`)
     *
     * Return value:
     * - array ของ string ชื่อเรียงแล้ว (จำกัด 500 รายการ)
     */
    function maintenance_wait_qc_fetch_wait_qc_company_name_list($connect, array $filters)
    {
        $status = maintenance_wait_qc_status_literal();
        $frag = maintenance_wait_qc_product_sql_fragment($filters);
        $sql = 'SELECT u.nm FROM ('
            . ' SELECT DISTINCT TRIM(t.ts_enduser_com) AS nm'
            . ' FROM transac t INNER JOIN transac_repair tr ON t.ts_id = tr.trp_ts_id'
            . ' WHERE tr.trp_status_job = ?' . $frag['sql']
            . " AND TRIM(COALESCE(t.ts_enduser_com, '')) <> ''"
            . ' UNION'
            . ' SELECT DISTINCT TRIM(t.ts_security_com) AS nm'
            . ' FROM transac t INNER JOIN transac_repair tr ON t.ts_id = tr.trp_ts_id'
            . ' WHERE tr.trp_status_job = ?' . $frag['sql']
            . " AND TRIM(COALESCE(t.ts_security_com, '')) <> ''"
            . ') AS u ORDER BY u.nm LIMIT 500';
        $types = 's' . $frag['types'] . 's' . $frag['types'];
        $bind = array_merge(array($status), $frag['values'], array($status), $frag['values']);
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_wait_qc company list prepare: ' . mysqli_error($connect));
            }
            exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$bind);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                if (isset($r['nm']) && trim((string) $r['nm']) !== '') {
                    $out[] = (string) $r['nm'];
                }
            }
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_wait_qc_list_select_columns_sql')) {
    /**
     * Purpose:
     * รายการคอลัมน์ใน SELECT หลัก (ไม่ใช้ `SELECT *`)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ส่วนหลัง SELECT
     */
    function maintenance_wait_qc_list_select_columns_sql()
    {
        return 't.ts_id, t.ts_mtn_number, t.ts_enduser_com, t.ts_security_com, '
            . 'tr.trp_id, tr.trp_product, tr.trp_sn, tr.trp_repair_age, tr.trp_insurance, '
            . 'tr.trp_repair_inform, tr.trp_repair_technician, '
            . 'tr.trp_repair_no1, tr.trp_repair_no2, tr.trp_repair_no3, tr.trp_repair_no4, tr.trp_repair_no5, '
            . 'tr.trp_repair_no6, tr.trp_repair_no7, tr.trp_repair_no8, tr.trp_repair_no9, tr.trp_repair_no10, '
            . 'tr.trp_clean, tr.trp_repeat_solder, tr.trp_spray, tr.trp_fw, '
            . 'tr.trp_success_date';
    }
}

if (!function_exists('maintenance_wait_qc_fetch_join_rows')) {
    /**
     * Purpose:
     * ดึงรายการ transac + transac_repair ที่สถานะ «รอ QC» และกรองสินค้า (ถ้ามีคำว่า bitScan ใน filter ใช้ `LIKE '%bitScan%'` สอดคล้อง old_system) และกรองชื่อแบบ `TRIM(...) = ?` กับ `ts_enduser_com` หรือ `ts_security_com` เมื่อมีคีย์ `company`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $filters: ผลจาก `maintenance_wait_qc_parse_get_filters()` (คีย์ `product`, `company`)
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_wait_qc_fetch_join_rows($connect, array $filters)
    {
        $status = maintenance_wait_qc_status_literal();
        $cols = maintenance_wait_qc_list_select_columns_sql();
        $from = ' FROM transac t INNER JOIN transac_repair tr ON t.ts_id = tr.trp_ts_id ';
        $where = ' WHERE tr.trp_status_job = ? ';
        $types = 's';
        $bind = array($status);

        $frag = maintenance_wait_qc_product_sql_fragment($filters);
        $where .= $frag['sql'];
        $types .= $frag['types'];
        foreach ($frag['values'] as $v) {
            $bind[] = $v;
        }

        $company = isset($filters['company']) ? trim((string) $filters['company']) : '';
        if ($company !== '') {
            $where .= ' AND (TRIM(t.ts_enduser_com) = ? OR TRIM(t.ts_security_com) = ?) ';
            $types .= 'ss';
            $bind[] = $company;
            $bind[] = $company;
        }

        $sql = 'SELECT ' . $cols . $from . $where . ' ORDER BY tr.trp_success_date ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_wait_qc prepare: ' . mysqli_error($connect));
            }
            exit('Prepare failed: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$bind);
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

if (!function_exists('maintenance_wait_qc_contact_html')) {
    /**
     * Purpose:
     * HTML คอลัมน์บริษัท (ไม่มีบรรทัดผู้แจ้ง — สอดคล้อง old_system ที่ comment บรรทัด report ออก)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join
     *
     * Return value:
     * - string HTML
     */
    function maintenance_wait_qc_contact_html(array $row)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $eu = maintenance_status_logistic_normalize_company_label(
            isset($row['ts_enduser_com']) ? $row['ts_enduser_com'] : '',
            array('str_replace' => true)
        );
        $sec = maintenance_status_logistic_normalize_company_label(
            isset($row['ts_security_com']) ? $row['ts_security_com'] : '',
            array('str_replace' => true)
        );

        return maintenance_status_logistic_company_names_html($eu, $sec);
    }
}

if (!function_exists('maintenance_wait_qc_repair_list_html')) {
    /**
     * Purpose:
     * ข้อความรายการซ่อมแบบคั่นด้วย comma (สอดคล้อง old_system — รวม trp_clean/repeat/spray/fw เมื่อมีค่า)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join
     *
     * Return value:
     * - string HTML (escape แล้ว)
     */
    function maintenance_wait_qc_repair_list_html(array $row)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $parts = array();
        for ($n = 1; $n <= 10; $n++) {
            $k = 'trp_repair_no' . $n;
            $v = isset($row[$k]) ? trim((string) $row[$k]) : '';
            if ($v !== '') {
                $parts[] = maintenance_status_logistic_h($v);
            }
        }
        foreach (array('trp_clean', 'trp_repeat_solder', 'trp_spray', 'trp_fw') as $k) {
            $v = isset($row[$k]) ? trim((string) $row[$k]) : '';
            if ($v !== '') {
                $parts[] = maintenance_status_logistic_h($v);
            }
        }
        if ($parts === array()) {
            return '';
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('maintenance_wait_qc_insurance_expired_literal')) {
    /**
     * Purpose:
     * ค่า `trp_insurance` ที่ถือว่า «หมดประกัน» สำหรับสีแดงในตาราง
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string
     */
    function maintenance_wait_qc_insurance_expired_literal()
    {
        return 'หมดประกัน';
    }
}
