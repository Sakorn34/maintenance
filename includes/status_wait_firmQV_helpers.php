<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `status_wait_firmQV.php` — filter บริษัท/สินค้า/QV, SELECT แบบ JOIN + prepared statement, tooltip รายการซ่อม
 *
 * องค์ประกอบหลัก:
 * - `maintenance_wait_firmqv_status_literal()` ค่า `trp_status_job` ตรง DB
 * - parse GET → `maintenance_wait_firmqv_fetch_join_rows()` ดึงแถวครั้งเดียว
 * - ใช้ `maintenance_wait_hardware_prefetch_*` จาก `status_wait_hardware_helpers.php` เพื่อลด round-trip spare/tracking
 * - `maintenance_wait_firmqv_qv_read_cell_html` สำหรับคอลัมน์ QV แบบอ่านอย่างเดียว + ลิงก์ `detail_job`
 *
 * Flow:
 * parse filters → prepare+execute list query → prefetch ตามรายการ `trp_id`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_wait_firmqv_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความสถานะงานที่หน้านี้แสดง (ต้องตรงกับ `transac_repair.trp_status_job`)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ข้อความ UTF-8
     */
    function maintenance_wait_firmqv_status_literal()
    {
        return 'รออนุมัติซ่อม';
    }
}

if (!function_exists('maintenance_wait_firmqv_parse_get_filters')) {
    /**
     * Purpose:
     * อ่าน filter จาก query string — ลำดับความสำคัญ company ก่อน แล้ว product แล้ว qv (สอดคล้อง old_system)
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET`
     *
     * Return value:
     * - array คีย์ `company`, `product`, `qv` (string ตัดช่องว่าง)
     */
    function maintenance_wait_firmqv_parse_get_filters(array $get)
    {
        return array(
            'company' => isset($get['company']) ? trim((string) $get['company']) : '',
            'product' => isset($get['product']) ? trim((string) $get['product']) : '',
            'qv' => isset($get['qv']) ? trim((string) $get['qv']) : '',
        );
    }
}

if (!function_exists('maintenance_wait_firmqv_list_select_columns_sql')) {
    /**
     * Purpose:
     * รายการคอลัมน์ใน SELECT หลัก (ไม่ใช้ `SELECT *`) สำหรับ JOIN transac + transac_repair
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ส่วนคอลัมน์หลังคำว่า SELECT
     */
    function maintenance_wait_firmqv_list_select_columns_sql()
    {
        return 't.ts_id, t.ts_mtn_number, t.ts_enduser_com, t.ts_security_com, t.ts_report_name, t.ts_report_tel, t.ts_report_line, '
            . 'tr.trp_id, tr.trp_ts_id, tr.trp_product, tr.trp_sn, tr.trp_repair_age, tr.trp_insurance, '
            . 'tr.trp_qv_date, tr.trp_qv_send, tr.trp_qv_price, tr.trp_qv_number, '
            . 'tr.trp_repair_no1, tr.trp_repair_no2, tr.trp_repair_no3, tr.trp_repair_no4, tr.trp_repair_no5, '
            . 'tr.trp_repair_no6, tr.trp_repair_no7, tr.trp_repair_no8, tr.trp_repair_no9, tr.trp_repair_no10, '
            . 'tr.trp_clean, tr.trp_repeat_solder, tr.trp_spray, tr.trp_fw';
    }
}

if (!function_exists('maintenance_wait_firmqv_fetch_join_rows')) {
    /**
     * Purpose:
     * ดึงรายการ transac + transac_repair ตามสถานะและ filter (mysqli_prepare + bind)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $filters: ผลจาก `maintenance_wait_firmqv_parse_get_filters()`
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_wait_firmqv_fetch_join_rows($connect, array $filters)
    {
        $status = maintenance_wait_firmqv_status_literal();
        $cols = maintenance_wait_firmqv_list_select_columns_sql();
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
        } elseif ($filters['qv'] !== '') {
            $where .= ' AND tr.trp_qv_number = ? ';
            $types .= 's';
            $bind[] = $filters['qv'];
        }

        $sql = 'SELECT ' . $cols . $from . $where . ' ORDER BY tr.trp_qv_date ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_wait_firmQV prepare: ' . mysqli_error($connect));
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

if (!function_exists('maintenance_wait_firmqv_contact_html')) {
    /**
     * Purpose:
     * สร้าง HTML คอลัมน์บริษัท/ผู้แจ้ง + Line (สอดคล้อง old_system/status_wait_firmQV.php)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join
     *
     * Return value:
     * - string HTML ที่ escape แล้วผ่าน `maintenance_status_logistic_h` สำหรับข้อความจาก DB
     */
    function maintenance_wait_firmqv_contact_html(array $row)
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

if (!function_exists('maintenance_wait_firmqv_repair_tooltip_plain')) {
    /**
     * Purpose:
     * ประกอบข้อความ plain สำหรับ attribute `title` ของแถว (รายการซ่อม + รายละเอียดเสริม)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join ที่มี trp_repair_no* และ trp_clean ฯลฯ
     *
     * Return value:
     * - string ข้อความหลายบรรทัด (ยังไม่ escape สำหรับ HTML — ให้ห่อด้วย `maintenance_status_logistic_h` ตอนใส่ attribute)
     */
    function maintenance_wait_firmqv_repair_tooltip_plain(array $row)
    {
        $lines = array();
        $lines[] = 'รายการซ่อม';
        for ($n = 1; $n <= 10; $n++) {
            $k = 'trp_repair_no' . $n;
            $v = isset($row[$k]) ? trim((string) $row[$k]) : '';
            if ($v !== '') {
                $lines[] = $v;
            }
        }
        $extras = array(
            'trp_clean' => isset($row['trp_clean']) ? trim((string) $row['trp_clean']) : '',
            'trp_repeat_solder' => isset($row['trp_repeat_solder']) ? trim((string) $row['trp_repeat_solder']) : '',
            'trp_spray' => isset($row['trp_spray']) ? trim((string) $row['trp_spray']) : '',
            'trp_fw' => isset($row['trp_fw']) ? trim((string) $row['trp_fw']) : '',
        );
        foreach ($extras as $v) {
            if ($v !== '') {
                $lines[] = $v;
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('maintenance_wait_firmqv_qv_read_cell_html')) {
    /**
     * Purpose:
     * ประกอบ HTML คอลัมน์ «ข้อมูลเสนอราคา» แบบอ่านอย่างเดียว + ลิงก์ไปหน้า `detail_job` (แก้ใบเสนอราคาที่รายละเอียดงาน)
     *
     * Parameters สำคัญ:
     * - string $qv_date: `trp_qv_date`
     * - string $qv_send: `trp_qv_send`
     * - string $qv_price: `trp_qv_price`
     * - string $qv_number: `trp_qv_number`
     * - int $ts_id: `transac.ts_id`
     *
     * Return value:
     * - string HTML (ส่วนข้อความ escape แล้ว)
     */
    function maintenance_wait_firmqv_qv_read_cell_html($qv_date, $qv_send, $qv_price, $qv_number, $ts_id)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';

        $dash = '—';
        $d = trim((string) $qv_date) !== '' ? maintenance_status_logistic_h((string) $qv_date) : $dash;
        $s = trim((string) $qv_send) !== '' ? maintenance_status_logistic_h((string) $qv_send) : $dash;
        $p = trim((string) $qv_price) !== '' ? maintenance_status_logistic_h((string) $qv_price) : $dash;
        $n = trim((string) $qv_number) !== '' ? maintenance_status_logistic_h((string) $qv_number) : $dash;
        $id = (int) $ts_id;

        $link = '<a class="mn-status-wait-firmQV-edit-link" target="_blank" href="detail_job?ts_id=' . $id . '" title="แก้ไขใบเสนอราคา — หน้ารายละเอียดงาน">'
            . '<span class="mn-status-wait-firmQV-edit-link__inner"><i class="fa fa-pencil-square-o" aria-hidden="true"></i> แก้ไข</span></a>';

        return '<div class="mn-status-wait-firmQV-qv-read">วันที่ ' . $d . '<br />'
            . 'ส่งโดย ' . $s . '<br />'
            . 'ราคา ' . $p . '<br />'
            . 'เลขที่ ' . $n . '<br />'
            . $link
            . '</div>';
    }
}
