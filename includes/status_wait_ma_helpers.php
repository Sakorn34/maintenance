<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `status_wait_ma.php` — filter บริษัท/สินค้า, SELECT JOIN + prepared statement, HTML รายการซ่อม/ติดต่อ
 *
 * องค์ประกอบหลัก:
 * - สถานะ `trp_status_job = รอซ่อม`
 * - `maintenance_wait_ma_fetch_join_rows()` ดึงรายการหลัก
 *
 * Flow:
 * parse GET → prepare + execute → คืนแถวสำหรับ render ตาราง
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_wait_ma_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความสถานะงานที่หน้านี้แสดง (ต้องตรงกับ `transac_repair.trp_status_job`)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_wait_ma_status_literal()
    {
        return 'รอซ่อม';
    }
}

if (!function_exists('maintenance_wait_ma_parse_get_filters')) {
    /**
     * Purpose:
     * อ่าน filter จาก query string (บริษัทก่อน แล้วสินค้า — สอดคล้อง old_system)
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET`
     *
     * Return value:
     * - array คีย์ `company`, `product` (string)
     */
    function maintenance_wait_ma_parse_get_filters(array $get)
    {
        return array(
            'company' => isset($get['company']) ? trim((string) $get['company']) : '',
            'product' => isset($get['product']) ? trim((string) $get['product']) : '',
        );
    }
}

if (!function_exists('maintenance_wait_ma_list_select_columns_sql')) {
    /**
     * Purpose:
     * รายการคอลัมน์ใน SELECT หลัก (ไม่ใช้ `SELECT *`)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ส่วนคอลัมน์หลังคำว่า SELECT
     */
    function maintenance_wait_ma_list_select_columns_sql()
    {
        return 't.ts_id, t.ts_mtn_number, t.ts_enduser_com, t.ts_security_com, t.ts_report_name, t.ts_report_tel, t.ts_report_line, '
            . 'tr.trp_id, tr.trp_product, tr.trp_sn, tr.trp_repair_age, tr.trp_insurance, '
            . 'tr.trp_firm_qv_date, tr.trp_status_firmma, tr.trp_backup, '
            . 'tr.trp_repair_no1, tr.trp_repair_no2, tr.trp_repair_no3, tr.trp_repair_no4, tr.trp_repair_no5, '
            . 'tr.trp_repair_no6, tr.trp_repair_no7, tr.trp_repair_no8, tr.trp_repair_no9, tr.trp_repair_no10, '
            . 'tr.trp_clean, tr.trp_repeat_solder, tr.trp_spray, tr.trp_fw';
    }
}

if (!function_exists('maintenance_wait_ma_fetch_join_rows')) {
    /**
     * Purpose:
     * ดึงรายการ transac + transac_repair ตามสถานะ «รอซ่อม» และ filter (mysqli_prepare)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $filters: ผลจาก `maintenance_wait_ma_parse_get_filters()`
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_wait_ma_fetch_join_rows($connect, array $filters)
    {
        $status = maintenance_wait_ma_status_literal();
        $cols = maintenance_wait_ma_list_select_columns_sql();
        $from = ' FROM transac t INNER JOIN transac_repair tr ON t.ts_id = tr.trp_ts_id ';
        $where = ' WHERE tr.trp_status_job = ? ';
        $types = 's';
        $bind = array($status);

        if ($filters['company'] !== '') {
            $where .= ' AND (t.ts_enduser_com = ? OR t.ts_security_com = ?) ';
            $types .= 'ss';
            $bind[] = $filters['company'];
            $bind[] = $filters['company'];
        } elseif ($filters['product'] !== '') {
            $where .= ' AND tr.trp_product = ? ';
            $types .= 's';
            $bind[] = $filters['product'];
        }

        $sql = 'SELECT ' . $cols . $from . $where . ' ORDER BY tr.trp_firm_qv_date ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_wait_ma prepare: ' . mysqli_error($connect));
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

if (!function_exists('maintenance_wait_ma_contact_html')) {
    /**
     * Purpose:
     * สร้าง HTML คอลัมน์บริษัท/ผู้แจ้ง + Line (สอดคล้อง old_system/status_wait_ma.php)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join
     *
     * Return value:
     * - string HTML (ข้อความจาก DB ผ่าน `maintenance_status_logistic_h`)
     */
    function maintenance_wait_ma_contact_html(array $row)
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
        $block = maintenance_status_logistic_company_names_html($eu, $sec);
        $block .= 'ติดต่อ : ' . maintenance_status_logistic_h(isset($row['ts_report_name']) ? (string) $row['ts_report_name'] : '') . ' <br>'
            . maintenance_status_logistic_h(isset($row['ts_report_tel']) ? (string) $row['ts_report_tel'] : '')
            . '  Line : ' . maintenance_status_logistic_h(isset($row['ts_report_line']) ? (string) $row['ts_report_line'] : '')
            . '<br>';

        return $block;
    }
}

if (!function_exists('maintenance_wait_ma_repair_list_html')) {
    /**
     * Purpose:
     * ประกอบ HTML รายการซ่อมในคอลัมน์ (รายการหลัก + clean/repeat/spray/fw เมื่อมีค่า)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join
     *
     * Return value:
     * - string HTML
     */
    function maintenance_wait_ma_repair_list_html(array $row)
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

        return implode('<br />', $parts);
    }
}
