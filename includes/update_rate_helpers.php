<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper สำหรับหน้า `update_rate` — ดึงข้อมูลใบซ่อมคู่ transac, map รุ่นสินค้าไปกลุ่ม `Rate.r_product`, และดึงรายการราคา/อาการซ่อมด้วย prepared statement
 *
 * Flow:
 * `maintenance_update_rate_fetch_bundle` → แสดงหัวฟอร์ม; `maintenance_update_rate_resolve_rate_product` + `maintenance_update_rate_fetch_rate_options` → เติม select รายการ 1–10
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_update_rate_normalize_bundle_row')) {
    /**
     * Purpose:
     * แปลงฟิลด์ข้อความในแถว bundle เป็น UTF-8 ก่อนแสดงในฟอร์มประเมิน
     *
     * Parameters สำคัญ:
     * - array $row: แถวจาก `maintenance_update_rate_fetch_bundle`
     *
     * Return value:
     * - array แถวที่แปลงแล้ว
     */
    function maintenance_update_rate_normalize_bundle_row(array $row)
    {
        $text_keys = array(
            'trp_insurance',
            'trp_receive_date',
            'trp_product',
            'trp_sn',
            'trp_buy_date',
            'trp_repair_age',
            'trp_repair_inform',
            'trp_repair_technician',
            'trp_rate_date',
            'trp_repair_no1',
            'trp_repair_no2',
            'trp_repair_no3',
            'trp_repair_no4',
            'trp_repair_no5',
            'trp_repair_no6',
            'trp_repair_no7',
            'trp_repair_no8',
            'trp_repair_no9',
            'trp_repair_no10',
            'trp_clean',
            'trp_repeat_solder',
            'trp_spray',
            'trp_fw',
            'trp_user_rate',
            'trp_repair_remarks',
            'trp_status_job',
            'ts_enduser_com',
            'ts_security_com',
            'ts_remarks',
        );
        foreach ($text_keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $row[$key] = maintenance_flow_db_text_to_utf8($row[$key]);
        }

        return $row;
    }
}

if (!function_exists('maintenance_update_rate_rate_date_for_input')) {
    /**
     * Purpose:
     * แปลง `trp_rate_date` เป็น Y-m-d สำหรับ `<input type="date">`
     *
     * Parameters สำคัญ:
     * - mixed $value: ค่าจาก DB (หลัง normalize แล้วหรือยังไม่ก็ได้)
     *
     * Return value:
     * - string รูปแบบ Y-m-d หรือว่าง
     */
    function maintenance_update_rate_rate_date_for_input($value)
    {
        require_once __DIR__ . '/detail_job_edit_helpers.php';

        return maintenance_detail_job_buy_date_for_html_input($value);
    }
}

if (!function_exists('maintenance_update_rate_repair_no_selected')) {
    /**
     * Purpose:
     * อ่านค่ารายการซ่อมที่บันทึกไว้ (`trp_repair_noN`) เป็น UTF-8
     *
     * Parameters สำคัญ:
     * - array $row: แถว bundle
     * - int $slot: ลำดับ 1–10
     *
     * Return value:
     * - string
     */
    function maintenance_update_rate_repair_no_selected($row, $slot)
    {
        if (!is_array($row)) {
            return '';
        }
        $n = (int) $slot;
        if ($n < 1 || $n > 10) {
            return '';
        }
        $key = 'trp_repair_no' . $n;

        return trim((string) (isset($row[$key]) ? $row[$key] : ''));
    }
}

if (!function_exists('maintenance_update_rate_checkbox_checked')) {
    /**
     * Purpose:
     * ตรวจว่าควรติ๊ก checkbox ตามค่าที่เคยบันทึก (ไม่ว่าง)
     *
     * Parameters สำคัญ:
     * - array $row: แถว bundle
     * - string $key: ชื่อคอลัมน์ เช่น `trp_clean`
     *
     * Return value:
     * - bool
     */
    function maintenance_update_rate_checkbox_checked($row, $key)
    {
        if (!is_array($row)) {
            return false;
        }

        return trim((string) (isset($row[$key]) ? $row[$key] : '')) !== '';
    }
}

if (!function_exists('maintenance_update_rate_render_rate_select')) {
    /**
     * Purpose:
     * สร้าง HTML `<select>` รายการซ่อม พร้อมค่าที่เคยประเมิน (ถ้ามี)
     *
     * Parameters สำคัญ:
     * - int $slot: ลำดับ 1–10
     * - array $rate_options: รายการจาก `Rate`
     * - string $selected: ค่าที่บันทึกไว้
     *
     * Return value:
     * - string HTML
     */
    function maintenance_update_rate_render_rate_select($slot, array $rate_options, $selected)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';

        $id = (int) $slot;
        $selected = trim((string) $selected);
        $html = '<select class="custom-select mn-update-rate-select" name="rate_list' . $id . '" id="rate_list' . $id . '"';
        if ($id >= 2) {
            $html .= ' onchange="mnUpdateRateListRate(' . $id . ')"';
        }
        $html .= '>';
        if ($selected !== '') {
            $html .= '<option value="' . maintenance_status_logistic_h($selected) . '" selected="selected">'
                . maintenance_status_logistic_h($selected) . '</option>';
        } else {
            $html .= '<option value=""></option>';
        }
        foreach ($rate_options as $rv) {
            $rv = trim((string) $rv);
            if ($rv === '' || $rv === $selected) {
                continue;
            }
            $html .= '<option value="' . maintenance_status_logistic_h($rv) . '">' . maintenance_status_logistic_h($rv) . '</option>';
        }
        $html .= '<option value=""></option>';
        $html .= '</select>';

        return $html;
    }
}

if (!function_exists('maintenance_update_rate_resolve_rate_product')) {
    /**
     * Purpose:
     * แปลงชื่อสินค้าจาก `transac_repair.trp_product` เป็นค่า `r_product` ใน `Rate` ตามกฎเดิมจาก `old_system/update_rate.php`
     *
     * Parameters สำคัญ:
     * - string $trp_product: ชื่อรุ่นจากแถวซ่อม
     *
     * Return value:
     * - string ค่า `r_product` สำหรับ WHERE
     */
    function maintenance_update_rate_resolve_rate_product($trp_product)
    {
        $p = trim((string) $trp_product);
        if ($p === '') {
            return '';
        }

        if ($p === 'bitVisitor Plus' || $p === 'bitVisitor M' || $p === 'bitMonitor' || $p === 'bitQueue' || $p === 'bitRealtime') {
            return 'bitVisitor Plus-M';
        }

        if ($p === 'bitScan' || $p === 'bitScan Pro' || $p === 'bitScan Kit' || $p === 'bitVehicle' || $p === 'bitScan Lite'
            || $p === 'bitScan AL Online' || $p === 'bitScan AL Office' || $p === 'bitScan AL') {
            return 'bitScan';
        }

        if (stripos($p, 'Adapter') !== false) {
            return 'Adapter';
        }

        if (stripos($p, 'Scanner') !== false || $p === 'Scanner Wireless') {
            return 'Scanner';
        }

        if (stripos($p, 'bitSupply') !== false || $p === 'bitSupply 1224' || $p === 'bitSupply 125') {
            return 'bitSupply';
        }

        return $p;
    }
}

if (!function_exists('maintenance_update_rate_fetch_rate_options')) {
    /**
     * Purpose:
     * ดึงค่า `r_rate` จาก `Rate` ตาม `r_product` (prepared)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $r_product: ค่าจาก `maintenance_update_rate_resolve_rate_product`
     *
     * Return value:
     * - array<int, string> รายการข้อความ rate เรียงตามคอลัมน์
     */
    function maintenance_update_rate_fetch_rate_options($connect, $r_product)
    {
        $r = (string) $r_product;
        if ($r === '') {
            return array();
        }

        $sql = 'SELECT r_rate FROM rate WHERE r_product = ? ORDER BY r_product ASC, r_rate ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }

        mysqli_stmt_bind_param($stmt, 's', $r);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (!isset($row['r_rate']) || $row['r_rate'] === '') {
                    continue;
                }
                $rate_utf8 = trim(maintenance_flow_db_text_to_utf8($row['r_rate']));
                if ($rate_utf8 !== '') {
                    $out[] = $rate_utf8;
                }
            }
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_update_rate_fetch_bundle')) {
    /**
     * Purpose:
     * ดึงแถว `transac_repair` JOIN `transac` ตาม `trp_id` ครั้งเดียว (prepared)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัสแถวซ่อม
     *
     * Return value:
     * - array<string, mixed>|null แถวรวมฟิลด์ที่หน้าประเมินต้องใช้ หรือ null ถ้าไม่พบ
     */
    function maintenance_update_rate_fetch_bundle($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) {
            return null;
        }

        $sql = 'SELECT tr.trp_id, tr.trp_ts_id, tr.trp_insurance, tr.trp_receive_date, tr.trp_product, tr.trp_sn, '
            . 'tr.trp_buy_date, tr.trp_repair_age, tr.trp_repair_inform, tr.trp_repair_technician, tr.trp_rate_date, '
            . 'tr.trp_repair_no1, tr.trp_repair_no2, tr.trp_repair_no3, tr.trp_repair_no4, tr.trp_repair_no5, '
            . 'tr.trp_repair_no6, tr.trp_repair_no7, tr.trp_repair_no8, tr.trp_repair_no9, tr.trp_repair_no10, '
            . 'tr.trp_clean, tr.trp_repeat_solder, tr.trp_spray, tr.trp_fw, tr.trp_user_rate, tr.trp_repair_remarks, tr.trp_status_job, '
            . 'ts.ts_enduser_com, ts.ts_security_com, ts.ts_remarks '
            . 'FROM transac_repair tr INNER JOIN transac ts ON ts.ts_id = tr.trp_ts_id WHERE tr.trp_id = ? LIMIT 1';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!is_array($row)) {
            return null;
        }

        return maintenance_update_rate_normalize_bundle_row($row);
    }
}
