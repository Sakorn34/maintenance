<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper สำหรับหน้า `status_offer_price.php` — รายการงาน «รอเสนอราคา» ที่ `trp_insurance = หมดประกัน` พร้อม filter บริษัท/สินค้า
 * ประกอบ SQL แบบระบุคอลัมน์ (รวม `trp_qv_*` สำหรับแสดงในตารางรายการ) และสร้าง HTML รายการซ่อมต่อแถว
 *
 * Flow:
 * parse `$_GET` → `maintenance_status_offer_price_build_list_sql` → `maintenance_wait_hardware_fetch_join_rows`
 *
 * Example:
 * `maintenance_status_offer_price_parse_get_filters($_GET)` แล้วส่งค่าเข้า `maintenance_status_offer_price_build_list_sql`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_status_offer_price_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_status_job` ที่หน้านี้แสดง (ต้องตรงกับค่าในฐานข้อมูล)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string เช่น `รอเสนอราคา`
     */
    function maintenance_status_offer_price_status_literal()
    {
        return 'รอเสนอราคา';
    }
}

if (!function_exists('maintenance_status_offer_price_insurance_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_insurance` ที่หน้านี้กรองคงที่ (ตรงกับ logic เดิมใน `old_system/status_offer_price.php`)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string `หมดประกัน`
     */
    function maintenance_status_offer_price_insurance_literal()
    {
        return 'หมดประกัน';
    }
}

if (!function_exists('maintenance_status_offer_price_parse_get_filters')) {
    /**
     * Purpose:
     * อ่าน filter บริษัท/สินค้าจาก query string
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET`
     *
     * Return value:
     * - array คีย์ `company`, `product` (string)
     */
    function maintenance_status_offer_price_parse_get_filters(array $get)
    {
        $company = isset($get['company']) ? trim((string) $get['company']) : '';
        $product = isset($get['product']) ? trim((string) $get['product']) : '';

        return array('company' => $company, 'product' => $product);
    }
}

if (!function_exists('maintenance_status_offer_price_build_list_sql')) {
    /**
     * Purpose:
     * ประกอบ SELECT `transac` JOIN `transac_repair` ตามสถานะ + ประกันหมด + filter บริษัทหรือสินค้า (escape ผ่าน mysqli)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (`$connect` จาก bootstrap)
     * - string $company: filter บริษัท (ว่าง = ไม่กรองด้วยบริษัท)
     * - string $product: filter สินค้า (ใช้เมื่อไม่มี company — สอดคล้องลำดับเดิม)
     *
     * Return value:
     * - string คำสั่ง SQL
     */
    function maintenance_status_offer_price_build_list_sql($connect, $company, $product)
    {
        $status = maintenance_status_offer_price_status_literal();
        $ins = maintenance_status_offer_price_insurance_literal();
        $st = mysqli_real_escape_string($connect, $status);
        $insEsc = mysqli_real_escape_string($connect, $ins);

        $cols = 'transac.ts_id, transac.ts_mtn_number, transac.ts_enduser_com, transac.ts_security_com, '
            . 'transac.ts_report_name, transac.ts_report_tel, transac.ts_report_line, transac.ts_address, '
            . 'transac_repair.trp_id, transac_repair.trp_ts_id, transac_repair.trp_rate_date, transac_repair.trp_product, '
            . 'transac_repair.trp_sn, transac_repair.trp_repair_age, transac_repair.trp_insurance, transac_repair.trp_repair_technician, '
            . 'transac_repair.trp_repair_no1, transac_repair.trp_repair_no2, transac_repair.trp_repair_no3, transac_repair.trp_repair_no4, '
            . 'transac_repair.trp_repair_no5, transac_repair.trp_repair_no6, transac_repair.trp_repair_no7, transac_repair.trp_repair_no8, '
            . 'transac_repair.trp_repair_no9, transac_repair.trp_repair_no10, transac_repair.trp_clean, transac_repair.trp_repeat_solder, '
            . 'transac_repair.trp_spray, transac_repair.trp_fw, transac_repair.trp_repair_remarks, '
            . 'transac_repair.trp_qv_number, transac_repair.trp_qv_date, transac_repair.trp_qv_send, transac_repair.trp_qv_price';

        $sql = 'SELECT ' . $cols . ' FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id '
            . "WHERE transac_repair.trp_status_job = '" . $st . "' AND transac_repair.trp_insurance = '" . $insEsc . "' ";

        if ($company !== '') {
            $c = mysqli_real_escape_string($connect, $company);
            $sql .= "AND (transac.ts_enduser_com = '" . $c . "' OR transac.ts_security_com = '" . $c . "') ";
        } elseif ($product !== '') {
            $p = mysqli_real_escape_string($connect, $product);
            $sql .= "AND transac_repair.trp_product = '" . $p . "' ";
        }

        $sql .= 'ORDER BY transac_repair.trp_id ASC';

        return $sql;
    }
}

if (!function_exists('maintenance_status_offer_price_contact_block_html')) {
    /**
     * Purpose:
     * สร้าง HTML บล็อกบริษัท/ผู้ติดต่อ/Line สำหรับคอลัมน์ลูกค้า (ต่อจาก `maintenance_wait_hardware_contact_html` ด้วย Line)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join ที่มีฟิลด์ ts_*
     *
     * Return value:
     * - string HTML (ส่วนที่ต้อง escape ผ่าน `maintenance_status_logistic_h` แล้ว)
     */
    function maintenance_status_offer_price_contact_block_html(array $row)
    {
        require_once __DIR__ . '/status_wait_hardware_helpers.php';
        require_once __DIR__ . '/status_logistic_helpers.php';
        $base = maintenance_wait_hardware_contact_html($row);
        $line = isset($row['ts_report_line']) ? (string) $row['ts_report_line'] : '';
        $base .= ' Line : ' . maintenance_status_logistic_h($line) . '<br>';

        return $base;
    }
}

if (!function_exists('maintenance_status_offer_price_repair_list_html')) {
    /**
     * Purpose:
     * ประกอบรายการซ่อม/หมายเหตุช่างเป็น HTML แบบเดียวกับหน้าเก่า (หลายบรรทัด + remarks สีแดง)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join ที่มี trp_repair_no*, trp_clean, trp_fw ฯลฯ
     *
     * Return value:
     * - string HTML
     */
    function maintenance_status_offer_price_repair_list_html(array $row)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $lines = array();
        for ($n = 1; $n <= 10; $n++) {
            $k = 'trp_repair_no' . $n;
            if (!empty($row[$k])) {
                $lines[] = maintenance_status_logistic_h((string) $row[$k]);
            }
        }
        foreach (array('trp_clean', 'trp_repeat_solder', 'trp_spray', 'trp_fw') as $k) {
            if (!empty($row[$k])) {
                $lines[] = maintenance_status_logistic_h((string) $row[$k]);
            }
        }
        $remarks = isset($row['trp_repair_remarks']) ? (string) $row['trp_repair_remarks'] : '';
        $html = implode('<br>', $lines);
        if ($remarks !== '') {
            $html .= ($html !== '' ? '<br>' : '') . '<span style="color:#FF0000">' . maintenance_status_logistic_h($remarks) . '</span>';
        }

        return $html;
    }
}

if (!function_exists('maintenance_status_offer_price_tech_short')) {
    /**
     * Purpose:
     * ผลประเมินจากหน้าประเมินแบบใหม่ ("1) กลุ่ม" + "   - ข้อ: ผล" ...) ยาวมากในตาราง — ตัดเหลือ
     * ความเห็นช่าง (ข้อความหลัง "// ") แยกข้อ "1. 2. 3." คนละบรรทัด
     * ไม่มีความเห็นช่าง → ใช้ "สรุปรายการที่เสนอเปลี่ยน/ซ่อม" แทน; ข้อความแบบเดิมคืนทั้งหมด
     *
     * Return value:
     * - array('short' => ข้อความที่แสดง, 'cut' => true เมื่อตัดผลตรวจรายข้อออก)
     */
    function maintenance_status_offer_price_tech_short($tech)
    {
        $text = trim(str_replace("\r\n", "\n", (string) $tech));
        $out = array('short' => $text, 'cut' => false);
        if (!preg_match('/^\d+\) /m', $text) || !preg_match('/^\s+-\s.+?:\s/mu', $text)) {
            return $out;
        }
        $short = '';
        $k = strpos($text, "\n// ");
        if ($k !== false) {
            $short = trim(substr($text, $k + 4));
            // "1.xxx 2.xxx" → ขึ้นบรรทัดใหม่ก่อนเลขข้อ (ไม่แตะเลขทศนิยม/เวอร์ชัน เช่น 2.4.2)
            $short = preg_replace('/(?<=\S)[ \t]+(?=\d{1,2}\.(?!\d))/u', "\n", $short);
        } elseif (preg_match('/^(?:สรุปรายการที่เสนอ|สรุป:)/mu', $text, $m, PREG_OFFSET_CAPTURE)) {
            $short = trim(substr($text, $m[0][1]));
        }
        if ($short === '') { return $out; }
        return array('short' => $short, 'cut' => true);
    }
}
