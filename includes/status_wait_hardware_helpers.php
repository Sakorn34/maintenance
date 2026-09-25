<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper สำหรับหน้า `status_wait_hardware.php` — ประกอบ SQL แบบ escape, prefetch สำรอง/ติดตาม, และ HTML บล็อกติดต่อลูกค้า
 *
 * องค์ประกอบหลัก:
 * - parse filter จาก `$_GET`
 * - สร้าง SQL รายการหลัก + ดึงแถว
 * - map `transac_spare` / `tracking_history` ต่อ `trp_id` เพื่อลดรอบ query
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

if (!function_exists('maintenance_wait_hardware_allowed_insurance_values')) {
    /**
     * Purpose:
     * รายค่า `trp_insurance` ที่อนุญาตตอนรับเครื่องซ่อม (`update_wait_hardware` / `save_wait_hardware`)
     *
     * Return value:
     * - array<int, string>
     */
    function maintenance_wait_hardware_allowed_insurance_values()
    {
        return array(
            'อยู่ในประกัน',
            'หมดประกัน',
            'อยู่ในประกันซ่อม',
        );
    }
}

if (!function_exists('maintenance_wait_hardware_is_valid_insurance')) {
    /**
     * Purpose:
     * ตรวจว่าค่าประกันที่ส่งมาจากฟอร์มรับเครื่องถูกต้อง (ห้ามว่างหรือค่านอก whitelist)
     *
     * Parameters สำคัญ:
     * - string $value: ค่าจาก `w_insurance`
     *
     * Return value:
     * - bool
     */
    function maintenance_wait_hardware_is_valid_insurance($value)
    {
        return in_array(trim((string) $value), maintenance_wait_hardware_allowed_insurance_values(), true);
    }
}

if (!function_exists('maintenance_wait_hardware_insurance_option_label')) {
    /**
     * Purpose:
     * ข้อความแสดงใน `<select>` ประกัน — บางค่า DB ใช้ label สั้นกว่าในฟอร์ม
     *
     * Parameters สำคัญ:
     * - string $value: ค่า `trp_insurance`
     *
     * Return value:
     * - string
     */
    function maintenance_wait_hardware_insurance_option_label($value)
    {
        if ($value === 'อยู่ในประกันซ่อม') {
            return 'ประกันซ่อม 3 เดือน';
        }

        return (string) $value;
    }
}

if (!function_exists('maintenance_wait_hardware_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความสถานะงานที่หน้านี้แสดง (ต้องตรงกับค่าใน `transac_repair.trp_status_job`)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ข้อความสถานะ UTF-8
     */
    function maintenance_wait_hardware_status_literal()
    {
        return 'รอเครื่องซ่อม';
    }
}

if (!function_exists('maintenance_wait_hardware_parse_get_filters')) {
    /**
     * Purpose:
     * อ่านและตัดช่องว่าง filter จาก query string
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET`
     *
     * Return value:
     * - array คีย์ `company`, `product` (string)
     */
    function maintenance_wait_hardware_parse_get_filters(array $get)
    {
        $company = isset($get['company']) ? trim((string) $get['company']) : '';
        $product = isset($get['product']) ? trim((string) $get['product']) : '';

        return array('company' => $company, 'product' => $product);
    }
}

if (!function_exists('maintenance_wait_hardware_build_list_sql')) {
    /**
     * Purpose:
     * ประกอบ SELECT รายการ transac + transac_repair ตามสถานะและ filter บริษัท/สินค้า (escape ผ่าน mysqli)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (ตัวแปร `$connect` จาก bootstrap)
     * - string $company: ค่า filter บริษัท (ว่าง = ไม่กรอง)
     * - string $product: ค่า filter สินค้า (ว่าง = ไม่กรอง; ถ้ามี company จะไม่ใช้ product)
     *
     * Return value:
     * - string คำสั่ง SQL
     */
    function maintenance_wait_hardware_build_list_sql($connect, $company, $product)
    {
        $status = maintenance_wait_hardware_status_literal();
        $st = mysqli_real_escape_string($connect, $status);
        $sql = "SELECT * FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id "
            . "WHERE transac_repair.trp_status_job = '" . $st . "' ";
        if ($company !== '') {
            $c = mysqli_real_escape_string($connect, $company);
            $sql .= "AND (transac.ts_enduser_com = '" . $c . "' OR transac.ts_security_com = '" . $c . "') ";
        } elseif ($product !== '') {
            $p = mysqli_real_escape_string($connect, $product);
            $sql .= "AND transac_repair.trp_product = '" . $p . "' ";
        }

        return $sql;
    }
}

if (!function_exists('maintenance_wait_hardware_fetch_join_rows')) {
    /**
     * Purpose:
     * รัน SQL รายการหลักแล้วคืนเป็น array ของแถว
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (ตัวแปร `$connect` จาก bootstrap)
     * - string $sql: SELECT
     *
     * Return value:
     * - array ของ array แถวผลลัพธ์
     */
    function maintenance_wait_hardware_fetch_join_rows($connect, $sql)
    {
        $rows = array();
        $exe = mysqli_query($connect, $sql);
        if ($exe === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_wait_hardware: Error Query [' . $sql . ']: ' . mysqli_error($connect));
            }
            exit('Error Query [' . htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') . ']: ' . htmlspecialchars(mysqli_error($connect), ENT_QUOTES, 'UTF-8'));
        }
        while ($r = mysqli_fetch_array($exe, MYSQLI_ASSOC)) {
            $rows[] = $r;
        }
        mysqli_free_result($exe);

        return $rows;
    }
}

if (!function_exists('maintenance_wait_hardware_prefetch_spare_by_trp_ids')) {
    /**
     * Purpose:
     * ดึงแถว `transac_spare` ตามรายการ `trp_id` (แถวแรกต่อ id ถ้ามีหลายแถว)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (ตัวแปร `$connect` จาก bootstrap)
     * - array $trp_ids: รายการ int trp_id
     *
     * Return value:
     * - array map `trp_id` => แถว spare (หรือไม่มีคีย์ถ้าไม่พบ)
     */
    function maintenance_wait_hardware_prefetch_spare_by_trp_ids($connect, array $trp_ids)
    {
        $map = array();
        $ids = array();
        foreach ($trp_ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        $list = array_keys($ids);
        if ($list === array()) {
            return $map;
        }
        $in = implode(',', $list);
        $sql = 'SELECT * FROM transac_spare WHERE tsp_trp_id IN (' . $in . ')';
        $exe = mysqli_query($connect, $sql);
        if ($exe === false) {
            return $map;
        }
        while ($r = mysqli_fetch_array($exe, MYSQLI_ASSOC)) {
            $tid = isset($r['tsp_trp_id']) ? (int) $r['tsp_trp_id'] : 0;
            if ($tid > 0 && !isset($map[$tid])) {
                $map[$tid] = $r;
            }
        }
        mysqli_free_result($exe);

        return $map;
    }
}

if (!function_exists('maintenance_wait_hardware_prefetch_tracking_latest_by_trp_ids')) {
    /**
     * Purpose:
     * ดึงแถวติดตามล่าสุดต่อ `trp_id` โดยเรียง `th_date_nextdue` DESC แล้วเก็บแถวแรกต่อ id
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (ตัวแปร `$connect` จาก bootstrap)
     * - array $trp_ids: รายการ int trp_id
     *
     * Return value:
     * - array map `trp_id` => แถว tracking_history
     */
    function maintenance_wait_hardware_prefetch_tracking_latest_by_trp_ids($connect, array $trp_ids)
    {
        $map = array();
        $ids = array();
        foreach ($trp_ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        $list = array_keys($ids);
        if ($list === array()) {
            return $map;
        }
        $in = implode(',', $list);
        $sql = 'SELECT * FROM tracking_history WHERE th_trp_id IN (' . $in . ') ORDER BY th_date_nextdue DESC';
        $exe = mysqli_query($connect, $sql);
        if ($exe === false) {
            return $map;
        }
        while ($r = mysqli_fetch_array($exe, MYSQLI_ASSOC)) {
            $tid = isset($r['th_trp_id']) ? (int) $r['th_trp_id'] : 0;
            if ($tid > 0 && !isset($map[$tid])) {
                $map[$tid] = $r;
            }
        }
        mysqli_free_result($exe);

        return $map;
    }
}

if (!function_exists('maintenance_wait_hardware_contact_html')) {
    /**
     * Purpose:
     * สร้าง HTML คอลัมน์บริษัท/ผู้แจ้ง (เทียบ logic เดิมใน old_system แต่ใช้ UTF-8 + escape)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join ที่มี ts_enduser_com, ts_security_com, ts_report_name, ts_report_tel
     *
     * Return value:
     * - string HTML
     */
    function maintenance_wait_hardware_contact_html(array $row)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $eu = maintenance_status_logistic_normalize_company_label(
            isset($row['ts_enduser_com']) ? $row['ts_enduser_com'] : '',
            array('utf8' => true)
        );
        $sec = maintenance_status_logistic_normalize_company_label(
            isset($row['ts_security_com']) ? $row['ts_security_com'] : '',
            array('utf8' => true)
        );
        $block = maintenance_status_logistic_company_names_html($eu, $sec);
        $block .= maintenance_status_logistic_h(maintenance_flow_db_text_to_utf8(isset($row['ts_report_name']) ? $row['ts_report_name'] : '')) . '  ';
        $block .= maintenance_status_logistic_h(maintenance_flow_db_text_to_utf8(isset($row['ts_report_tel']) ? $row['ts_report_tel'] : '')) . '<br>';

        return $block;
    }
}

if (!function_exists('maintenance_wait_hardware_contact_plain_for_spare')) {
    /**
     * Purpose:
     * ข้อความ plain สำหรับส่งต่อ `edit_spare` (ไม่มี span สี) — ใช้ในช่อง hidden
     *
     * Parameters สำคัญ:
     * - array $row: แถว join เดียวกับ `maintenance_wait_hardware_contact_html`
     *
     * Return value:
     * - string ข้อความ plain
     */
    function maintenance_wait_hardware_contact_plain_for_spare(array $row)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $eu = trim(maintenance_flow_db_text_to_utf8(isset($row['ts_enduser_com']) ? $row['ts_enduser_com'] : ''));
        $sec = trim(maintenance_flow_db_text_to_utf8(isset($row['ts_security_com']) ? $row['ts_security_com'] : ''));
        $parts = array();
        if ($eu !== '') {
            $parts[] = maintenance_status_logistic_strip_company_prefix($eu);
        }
        if ($sec !== '') {
            $parts[] = maintenance_status_logistic_strip_company_prefix($sec);
        }
        $line = implode("\n", $parts);
        $line .= "\n" . maintenance_flow_db_text_to_utf8(isset($row['ts_report_name']) ? $row['ts_report_name'] : '') . '  '
            . maintenance_flow_db_text_to_utf8(isset($row['ts_report_tel']) ? $row['ts_report_tel'] : '');

        return $line;
    }
}

if (!function_exists('maintenance_wait_hardware_tracking_title_attr')) {
    /**
     * Purpose:
     * ประกอบข้อความ `title` ของลิงก์ติดตาม (หลายบรรทัด)
     *
     * Parameters สำคัญ:
     * - array $th: แถว tracking_history หรือแถวว่าง
     *
     * Return value:
     * - string ข้อความสำหรับ attribute title (ไม่มี HTML)
     */
    function maintenance_wait_hardware_tracking_title_attr(array $th)
    {
        if ($th === array()) {
            return '';
        }
        $lines = array();
        $lines[] = 'ติดตามล่าสุด :: ' . (isset($th['th_date_tracking']) ? (string) $th['th_date_tracking'] : '');
        $lines[] = 'ติดตามครั้งถัดไป :: ' . (isset($th['th_date_nextdue']) ? (string) $th['th_date_nextdue'] : '');
        $lines[] = 'เรื่องที่ตาม :: ' . (isset($th['th_status_job']) ? (string) $th['th_status_job'] : '');
        $lines[] = 'รายละเอียด :: ' . (isset($th['th_detail']) ? (string) $th['th_detail'] : '');
        $lines[] = 'ผู้ติดตาม :: ' . (isset($th['th_user']) ? (string) $th['th_user'] : '');

        return implode("\n", $lines);
    }
}
