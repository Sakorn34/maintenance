<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper อัปเดตฟิลด์รายการซ่อมจากหน้า `detail_job` (เช่น `trp_sn`, `trp_product`) พร้อมตรวจล็อกและบันทึก `activity_log`
 *
 * Flow:
 * `maintenance_detail_job_update_field()` → ตรวจ `ts_id` / `trp_id` / ล็อกหัวใบงาน → switch ตาม `field_key` → UPDATE + `maintenance_activity_log_write`
 *
 * Example:
 * $r = maintenance_detail_job_update_field($connect, 12, 34, 'trp_sn', 'SN001', 'SN000');
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_detail_job_json_response')) {
    /**
     * Purpose:
     * ส่ง JSON แล้วจบสคริปต์ (ใช้กับ endpoint AJAX ของ detail_job)
     *
     * Parameters สำคัญ:
     * - array $payload: ข้อมูลที่จะ encode (เช่น ok, error)
     *
     * Return value:
     * - ไม่คืนค่า (exit)
     */
    function maintenance_detail_job_json_response(array $payload)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('maintenance_detail_job_text_has_data')) {
    /**
     * Purpose:
     * ตรวจว่าข้อความจาก DB มีค่าให้แสดง/แก้ไข (ไม่ว่างและไม่ใช่ '-')
     *
     * Parameters สำคัญ:
     * - string $value: ค่าจากฐานข้อมูล
     *
     * Return value:
     * - bool true เมื่อมีข้อมูล
     */
    function maintenance_detail_job_text_has_data($value)
    {
        $v = trim(maintenance_flow_db_text_to_utf8($value));

        return $v !== '' && $v !== '-';
    }
}

if (!function_exists('maintenance_detail_job_date_has_data')) {
    /**
     * Purpose:
     * ตรวจว่าฟิลด์วันที่มีค่าที่ parse เป็น Y-m-d ได้ (ใช้กับ input type=date)
     *
     * Parameters สำคัญ:
     * - string $value: ค่าวันที่จาก DB
     *
     * Return value:
     * - bool true เมื่อมีวันที่
     */
    function maintenance_detail_job_date_has_data($value)
    {
        return maintenance_detail_job_buy_date_for_html_input($value) !== '';
    }
}

if (!function_exists('maintenance_detail_job_group_has_machine_data')) {
    /**
     * Purpose:
     * ตรวจว่ากลุ่มข้อมูลเครื่องซ่อมมีข้อมูลให้แก้ไขแล้ว
     *
     * Parameters สำคัญ:
     * - string $product, $sn, $repair_inform: ค่าจาก `transac_repair`
     *
     * Return value:
     * - bool true เมื่อมี S/N หรือชื่อสินค้า
     */
    function maintenance_detail_job_group_has_machine_data($product, $sn, $repair_inform = '')
    {
        if (maintenance_detail_job_text_has_data($sn)) {
            return true;
        }
        if (maintenance_detail_job_text_has_data($product)) {
            return true;
        }

        return maintenance_detail_job_text_has_data($repair_inform);
    }
}

if (!function_exists('maintenance_detail_job_group_has_rate_data')) {
    /**
     * Purpose:
     * ตรวจว่ากลุ่มงานประเมินซ่อมมีข้อมูลให้แก้ไขแล้ว
     *
     * Parameters สำคัญ:
     * - array $repair_row: แถว `transac_repair` (assoc)
     *
     * Return value:
     * - bool true เมื่อมีวันที่ประเมิน ช่างประเมิน หรือผู้ประเมิน
     */
    function maintenance_detail_job_group_has_rate_data(array $repair_row)
    {
        if (maintenance_detail_job_date_has_data(isset($repair_row['trp_rate_date']) ? $repair_row['trp_rate_date'] : '')) {
            return true;
        }
        if (maintenance_detail_job_text_has_data(isset($repair_row['trp_user_rate']) ? $repair_row['trp_user_rate'] : '')) {
            return true;
        }

        return maintenance_detail_job_text_has_data(isset($repair_row['trp_repair_technician']) ? $repair_row['trp_repair_technician'] : '');
    }
}

if (!function_exists('maintenance_detail_job_group_has_qv_data')) {
    /**
     * Purpose:
     * ตรวจว่ากลุ่มใบเสนอราคามีข้อมูลให้แก้ไขแล้ว
     *
     * Parameters สำคัญ:
     * - string $qv_number, $qv_date: ค่า QV จาก `transac_repair`
     *
     * Return value:
     * - bool true เมื่อมีเลข QV หรือวันที่เสนอราคา
     */
    function maintenance_detail_job_group_has_qv_data($qv_number, $qv_date)
    {
        return maintenance_detail_job_repair_quote_offered($qv_number, $qv_date);
    }
}

if (!function_exists('maintenance_detail_job_group_has_qc_data')) {
    /**
     * Purpose:
     * ตรวจว่ากลุ่ม QC มีข้อมูลให้แก้ไขแล้ว
     *
     * Parameters สำคัญ:
     * - string $qc_date, $qc_user: ค่าจาก `transac_repair`
     *
     * Return value:
     * - bool true เมื่อมีวันที่ QC หรือผู้ QC จริง
     */
    function maintenance_detail_job_group_has_qc_data($qc_date, $qc_user = '')
    {
        if (maintenance_detail_job_repair_qc_done_locked($qc_date)) {
            return true;
        }

        return maintenance_detail_job_text_has_data($qc_user);
    }
}

if (!function_exists('maintenance_detail_job_repair_row_locked_from_status')) {
    /**
     * Purpose:
     * ตรวจว่าสถานะรายการซ่อมเป็น «เสร็จสิ้น» หรือไม่ (UTF-8)
     *
     * Parameters สำคัญ:
     * - string $status_utf8: ค่า `trp_status_job`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไขรายการนี้
     */
    function maintenance_detail_job_repair_row_locked_from_status($status_utf8)
    {
        return trim((string) $status_utf8) === 'เสร็จสิ้น';
    }
}

if (!function_exists('maintenance_detail_job_repair_quote_offered')) {
    /**
     * Purpose:
     * ตรวจว่ารายการซ่อมมีการเสนอราคาแล้ว (มีเลข QV จริง หรือมีวันที่เสนอราคาที่ parse ได้ — เทียบ timeline ขั้นเสนอราคา)
     *
     * Parameters สำคัญ:
     * - string $trp_qv_number: ค่า `trp_qv_number` (UTF-8)
     * - string $trp_qv_date: ค่า `trp_qv_date`
     *
     * Return value:
     * - bool true เมื่อเสนอราคาแล้ว ห้ามแก้ไขงานประเมินซ่อม
     */
    function maintenance_detail_job_repair_quote_offered($trp_qv_number, $trp_qv_date)
    {
        $num = trim((string) $trp_qv_number);
        if ($num !== '' && $num !== '-') {
            return true;
        }

        require_once __DIR__ . '/job_process_timeline_helpers.php';

        return maintenance_job_process_parse_date($trp_qv_date) !== null;
    }
}

if (!function_exists('maintenance_detail_job_repair_rate_edit_locked')) {
    /**
     * Purpose:
     * ตรวจว่าห้ามแก้ไขงานประเมินซ่อมเพราะมีการเสนอราคาแล้ว (อ่านจาก DB)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส `transac_repair.trp_id`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_rate_edit_locked($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) {
            return true;
        }

        $stmt = mysqli_prepare($connect, 'SELECT trp_qv_number, trp_qv_date FROM transac_repair WHERE trp_id = ? LIMIT 1');
        if ($stmt === false) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return true;
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return true;
        }

        $num = maintenance_flow_db_text_to_utf8(isset($row['trp_qv_number']) ? $row['trp_qv_number'] : '');
        $date = maintenance_flow_db_text_to_utf8(isset($row['trp_qv_date']) ? $row['trp_qv_date'] : '');

        return maintenance_detail_job_repair_quote_offered($num, $date);
    }
}

if (!function_exists('maintenance_detail_job_repair_ma_approved_locked')) {
    /**
     * Purpose:
     * ตรวจว่าอนุมัติการซ่อมแล้ว — ห้ามแก้ไขกลุ่มใบเสนอราคา (QV) บน detail_job
     *
     * Parameters สำคัญ:
     * - string $firm_qv_date: ค่า `trp_firm_qv_date`
     * - string $status_job: ค่า `trp_status_job` (UTF-8)
     * - string $status_firmma: ค่า `trp_status_firmma` (UTF-8)
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_ma_approved_locked($firm_qv_date, $status_job, $status_firmma = '')
    {
        require_once __DIR__ . '/job_process_timeline_helpers.php';

        if (maintenance_job_process_parse_date($firm_qv_date) !== null) {
            return true;
        }
        if (trim((string) $status_firmma) === 'อนุมัติซ่อม') {
            return true;
        }

        $job = trim((string) $status_job);
        $after_approve = array(
            'รอซ่อม',
            'รอ QC',
            'รอส่งคืนเครื่องซ่อม',
            'รอเครื่องสำรอง',
            'รอส่งสำรอง',
            'รอเครื่องซ่อม',
            'เสร็จสิ้น',
        );

        return in_array($job, $after_approve, true);
    }
}

if (!function_exists('maintenance_detail_job_repair_ma_approved_locked_db')) {
    /**
     * Purpose:
     * ตรวจว่าห้ามแก้ไข QV เพราะอนุมัติซ่อมแล้ว (อ่านจาก DB)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส `transac_repair.trp_id`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_ma_approved_locked_db($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) {
            return true;
        }

        $stmt = mysqli_prepare(
            $connect,
            'SELECT trp_firm_qv_date, trp_status_job, trp_status_firmma FROM transac_repair WHERE trp_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return true;
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return true;
        }

        $firm = maintenance_flow_db_text_to_utf8(isset($row['trp_firm_qv_date']) ? $row['trp_firm_qv_date'] : '');
        $job = maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : '');
        $firmma = maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : '');

        return maintenance_detail_job_repair_ma_approved_locked($firm, $job, $firmma);
    }
}

if (!function_exists('maintenance_detail_job_repair_qc_done_locked')) {
    /**
     * Purpose:
     * ตรวจว่า QC เครื่องแล้ว — ห้ามแก้ไขกลุ่มวันที่ซ่อม/ช่างซ่อมบน detail_job
     *
     * Parameters สำคัญ:
     * - string $qc_date: ค่า `trp_qc_date`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_qc_done_locked($qc_date)
    {
        return maintenance_detail_job_buy_date_for_html_input($qc_date) !== '';
    }
}

if (!function_exists('maintenance_detail_job_repair_qc_done_locked_db')) {
    /**
     * Purpose:
     * ตรวจว่าห้ามแก้ไขวันที่ซ่อม/ช่างซ่อมเพราะ QC แล้ว (อ่านจาก DB)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส `transac_repair.trp_id`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_qc_done_locked_db($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) {
            return true;
        }

        $stmt = mysqli_prepare($connect, 'SELECT trp_qc_date FROM transac_repair WHERE trp_id = ? LIMIT 1');
        if ($stmt === false) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return true;
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return true;
        }

        $qc = maintenance_flow_db_text_to_utf8(isset($row['trp_qc_date']) ? $row['trp_qc_date'] : '');

        return maintenance_detail_job_repair_qc_done_locked($qc);
    }
}

if (!function_exists('maintenance_detail_job_repair_returned_locked')) {
    /**
     * Purpose:
     * ตรวจว่าส่งเครื่องคืนลูกค้าแล้ว — ห้ามแก้ไขกลุ่ม QC บน detail_job
     *
     * Parameters สำคัญ:
     * - string $send_date: ค่า `trp_send_date`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_returned_locked($send_date)
    {
        return maintenance_detail_job_buy_date_for_html_input($send_date) !== '';
    }
}

if (!function_exists('maintenance_detail_job_repair_returned_locked_db')) {
    /**
     * Purpose:
     * ตรวจว่าห้ามแก้ไข QC เพราะส่งคืนลูกค้าแล้ว (อ่านจาก DB)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส `transac_repair.trp_id`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_returned_locked_db($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) {
            return true;
        }

        $stmt = mysqli_prepare($connect, 'SELECT trp_send_date FROM transac_repair WHERE trp_id = ? LIMIT 1');
        if ($stmt === false) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return true;
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return true;
        }

        $send = maintenance_flow_db_text_to_utf8(isset($row['trp_send_date']) ? $row['trp_send_date'] : '');

        return maintenance_detail_job_repair_returned_locked($send);
    }
}

if (!function_exists('maintenance_detail_job_spare_returned_locked')) {
    /**
     * Purpose:
     * ตรวจว่ารับเครื่องสำรองคืนแล้ว — ห้ามแก้ไขกลุ่มส่งคืนเครื่องซ่อมบน detail_job
     *
     * Parameters สำคัญ:
     * - string $datereturn: ค่า `tsp_datereturn` จากแถวสำรอง
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_spare_returned_locked($datereturn)
    {
        return maintenance_detail_job_buy_date_for_html_input($datereturn) !== '';
    }
}

if (!function_exists('maintenance_detail_job_spare_returned_locked_db')) {
    /**
     * Purpose:
     * ตรวจว่าห้ามแก้ไขส่งคืนเพราะมีแถวสำรองที่รับคืนแล้ว (อ่านจาก DB)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส `transac_repair.trp_id`
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_spare_returned_locked_db($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) {
            return false;
        }

        $stmt = mysqli_prepare(
            $connect,
            'SELECT tsp_datereturn FROM transac_spare WHERE tsp_trp_id = ? AND tsp_datereturn IS NOT NULL AND TRIM(tsp_datereturn) <> \'\' AND TRIM(tsp_datereturn) <> \'-\' LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return false;
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return false;
        }

        $ret = maintenance_flow_db_text_to_utf8(isset($row['tsp_datereturn']) ? $row['tsp_datereturn'] : '');

        return maintenance_detail_job_spare_returned_locked($ret);
    }
}

if (!function_exists('maintenance_detail_job_repair_row_locked')) {
    /**
     * Purpose:
     * ตรวจว่ารายการ `transac_repair` นี้ห้ามแก้ไขเพราะ `trp_status_job = เสร็จสิ้น`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัส transac
     * - int $trp_id: รหัส transac_repair
     *
     * Return value:
     * - bool true เมื่อห้ามแก้ไข
     */
    function maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)
    {
        if ($ts_id <= 0 || $trp_id <= 0 || !($connect instanceof mysqli)) {
            return true;
        }
        $stmt = mysqli_prepare($connect, 'SELECT trp_status_job FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1');
        if ($stmt === false) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return true;
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return true;
        }
        $status = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));

        return maintenance_detail_job_repair_row_locked_from_status($status);
    }
}

if (!function_exists('maintenance_detail_job_list_product_names_utf8')) {
    /**
     * Purpose:
     * ดึงรายชื่อสินค้าจาก `product_name.pn_name` เป็น UTF-8 สำหรับ validate / modal
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - string[] เรียงตาม pn_name ASC
     */
    function maintenance_detail_job_list_product_names_utf8($connect)
    {
        $out = array();
        if (!($connect instanceof mysqli)) {
            return $out;
        }
        $sql = 'SELECT pn_name FROM product_name ORDER BY pn_name ASC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if (!$exe) {
            return $out;
        }
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (!is_array($row) || !isset($row['pn_name'])) {
                continue;
            }
            $name = trim(maintenance_flow_db_text_to_utf8($row['pn_name']));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_product')) {
    /**
     * Purpose:
     * ตรวจว่าชื่อสินค้าที่เลือกอยู่ในรายการ `product_name`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $product: ชื่อสินค้า (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_product($connect, $product)
    {
        $product = trim((string) $product);
        if ($product === '') {
            return array('ok' => false, 'error' => 'กรุณาเลือกสินค้า');
        }
        $names = maintenance_detail_job_list_product_names_utf8($connect);
        foreach ($names as $name) {
            if ($name === $product) {
                return array('ok' => true, 'value' => $product);
            }
        }

        return array('ok' => false, 'error' => 'สินค้าที่เลือกไม่อยู่ในรายการระบบ');
    }
}

if (!function_exists('maintenance_detail_job_buy_date_for_html_input')) {
    /**
     * Purpose:
     * แปลง `trp_buy_date` จาก DB เป็นรูปแบบ Y-m-d สำหรับ `<input type="date">`
     *
     * Parameters สำคัญ:
     * - mixed $value: ค่าจาก DB
     *
     * Return value:
     * - string รูปแบบ Y-m-d หรือว่างถ้าแปลงไม่ได้
     */
    function maintenance_detail_job_buy_date_for_html_input($value)
    {
        $s = trim(maintenance_flow_db_text_to_utf8($value));
        if ($s === '' || $s === '-') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return maintenance_detail_job_is_valid_calendar_date($s) ? $s : '';
        }
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
            $norm = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);

            return maintenance_detail_job_is_valid_calendar_date($norm) ? $norm : '';
        }
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $s, $m)) {
            $norm = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

            return maintenance_detail_job_is_valid_calendar_date($norm) ? $norm : '';
        }
        $ts = strtotime($s);
        if ($ts !== false) {
            $norm = date('Y-m-d', $ts);

            return maintenance_detail_job_is_valid_calendar_date($norm) ? $norm : '';
        }

        return '';
    }
}

if (!function_exists('maintenance_detail_job_is_valid_calendar_date')) {
    /**
     * Purpose:
     * ตรวจว่าเป็นวันที่ปฏิทินที่ใช้งานได้ (ไม่รับ 0000-00-00 และปีต่ำกว่า 1900)
     *
     * Parameters สำคัญ:
     * - string $ymd: รูปแบบ Y-m-d
     *
     * Return value:
     * - bool true เมื่อวันที่ใช้ได้
     */
    function maintenance_detail_job_is_valid_calendar_date($ymd)
    {
        $ymd = trim((string) $ymd);
        if ($ymd === '' || $ymd === '0000-00-00') {
            return false;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return false;
        }
        $y = (int) $m[1];
        if ($y < 1900) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], $y);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_buy_date')) {
    /**
     * Purpose:
     * ตรวจรูปแบบวันที่ซื้อจาก `<input type="date">` (Y-m-d) — อนุญาตค่าว่าง
     *
     * Parameters สำคัญ:
     * - string $date: วันที่ (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_buy_date($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => true, 'value' => '');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่ซื้อไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่ซื้อไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_repair_inform')) {
    /**
     * Purpose:
     * ตรวจข้อความอาการ (`trp_repair_inform`) ก่อนบันทึก — สอดคล้องคอลัมน์ varchar(255)
     *
     * Parameters สำคัญ:
     * - string $inform: ข้อความอาการ (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_repair_inform($inform)
    {
        $inform = trim((string) $inform);
        if ($inform === '') {
            return array('ok' => false, 'error' => 'กรุณากรอกอาการ');
        }
        $max = 255;
        $len = function_exists('mb_strlen') ? mb_strlen($inform, 'UTF-8') : strlen($inform);
        if ($len > $max) {
            return array('ok' => false, 'error' => 'อาการต้องไม่เกิน ' . $max . ' ตัวอักษร');
        }

        return array('ok' => true, 'value' => $inform);
    }
}

if (!function_exists('maintenance_detail_job_validate_repair_sn')) {
    /**
     * Purpose:
     * ตรวจรูปแบบ S/N เครื่องซ่อม (ตัวอักษร/ตัวเลข/ขีด -, ตัวแรกเป็น A-Z, a-z หรือ 0-9)
     *
     * Parameters สำคัญ:
     * - string $sn: ค่าที่ผู้ใช้กรอก
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_repair_sn($sn)
    {
        $sn = trim((string) $sn);
        if ($sn === '') {
            return array('ok' => false, 'error' => 'กรุณากรอก S/N เครื่องซ่อม');
        }
        if (!preg_match('/^[A-Za-z0-9\-]+$/', $sn)) {
            return array('ok' => false, 'error' => 'S/N ใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ ตัวเลข และเครื่องหมาย -');
        }
        $c = ord($sn[0]);
        $firstOk = ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || ($c >= 48 && $c <= 57);
        if (!$firstOk) {
            return array('ok' => false, 'error' => 'S/N ต้องขึ้นต้นด้วย [A-Z, 0-9, a-z] เท่านั้น');
        }

        return array('ok' => true, 'value' => $sn);
    }
}

if (!function_exists('maintenance_detail_job_update_trp_sn')) {
    /**
     * Purpose:
     * อัปเดต `transac_repair.trp_sn` สำหรับแถวที่อยู่ใน `ts_id` ที่ระบุ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัส transac
     * - int $trp_id: รหัส transac_repair
     * - string $new_sn: S/N ใหม่ (UTF-8)
     * - string $old_sn_client: ค่าเดิมจากฝั่ง client (สำหรับ log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_trp_sn($connect, $ts_id, $trp_id, $new_sn, $old_sn_client)
    {
        $valid = maintenance_detail_job_validate_repair_sn($new_sn);
        if (!$valid['ok']) {
            return array('ok' => false, 'error' => $valid['error']);
        }
        $new_sn = $valid['value'];

        $stmtSel = mysqli_prepare($connect, 'SELECT trp_sn FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1');
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_sn']) ? $row['trp_sn'] : ''));
        $old_client = trim((string) $old_sn_client);
        if ($new_sn === $old_db) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare($connect, 'UPDATE transac_repair SET trp_sn = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1');
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'sii', $new_sn, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_trp_sn', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'field' => 'trp_sn',
                'old_db' => $old_db,
                'old_client' => $old_client,
                'new' => $new_sn,
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_update_trp_product')) {
    /**
     * Purpose:
     * อัปเดต `transac_repair.trp_product` สำหรับแถวที่อยู่ใน `ts_id` ที่ระบุ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัส transac
     * - int $trp_id: รหัส transac_repair
     * - string $new_product: ชื่อสินค้าใหม่ (UTF-8)
     * - string $old_product_client: ค่าเดิมจาก client
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_trp_product($connect, $ts_id, $trp_id, $new_product, $old_product_client)
    {
        $valid = maintenance_detail_job_validate_trp_product($connect, $new_product);
        if (!$valid['ok']) {
            return array('ok' => false, 'error' => $valid['error']);
        }
        $new_product = $valid['value'];

        $stmtSel = mysqli_prepare($connect, 'SELECT trp_product FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1');
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        $old_client = trim((string) $old_product_client);
        if ($new_product === $old_db) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare($connect, 'UPDATE transac_repair SET trp_product = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1');
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'sii', $new_product, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_trp_product', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'field' => 'trp_product',
                'old_db' => $old_db,
                'old_client' => $old_client,
                'new' => $new_product,
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_update_trp_repair_inform')) {
    /**
     * Purpose:
     * อัปเดต `transac_repair.trp_repair_inform` สำหรับแถวที่อยู่ใน `ts_id` ที่ระบุ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัส transac
     * - int $trp_id: รหัส transac_repair
     * - string $new_inform: อาการใหม่ (UTF-8)
     * - string $old_inform_client: ค่าเดิมจาก client
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_trp_repair_inform($connect, $ts_id, $trp_id, $new_inform, $old_inform_client)
    {
        $valid = maintenance_detail_job_validate_trp_repair_inform($new_inform);
        if (!$valid['ok']) {
            return array('ok' => false, 'error' => $valid['error']);
        }
        $new_inform = $valid['value'];

        $stmtSel = mysqli_prepare($connect, 'SELECT trp_repair_inform FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1');
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_repair_inform']) ? $row['trp_repair_inform'] : ''));
        $old_client = trim((string) $old_inform_client);
        if ($new_inform === $old_db) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare($connect, 'UPDATE transac_repair SET trp_repair_inform = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1');
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'sii', $new_inform, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_trp_repair_inform', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'field' => 'trp_repair_inform',
                'old_db' => $old_db,
                'old_client' => $old_client,
                'new' => $new_inform,
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_update_repair_machine_bundle')) {
    /**
     * Purpose:
     * อัปเดตสินค้า + S/N + อาการ + วันที่ซื้อของรายการซ่อมหนึ่งแถวพร้อมกัน (modal รวมบนหน้า detail_job)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, int $trp_id: รหัสใบงาน / รายการซ่อม
     * - string $product, $sn, $inform, $buy_date: ค่าใหม่ (UTF-8, วันที่ซื้อเป็น Y-m-d)
     * - string $old_product, $old_sn, $old_inform, $old_buy_date: ค่าเดิมจาก client (สำหรับ log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_repair_machine_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $product,
        $sn,
        $inform,
        $buy_date,
        $old_product,
        $old_sn,
        $old_inform,
        $old_buy_date
    ) {
        require_once __DIR__ . '/cal_lifetime_helpers.php';
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }

        $validProduct = maintenance_detail_job_validate_trp_product($connect, $product);
        if (!$validProduct['ok']) {
            return array('ok' => false, 'error' => $validProduct['error']);
        }
        $validSn = maintenance_detail_job_validate_repair_sn($sn);
        if (!$validSn['ok']) {
            return array('ok' => false, 'error' => $validSn['error']);
        }
        $validInform = maintenance_detail_job_validate_trp_repair_inform($inform);
        if (!$validInform['ok']) {
            return array('ok' => false, 'error' => $validInform['error']);
        }
        $validBuyDate = maintenance_detail_job_validate_trp_buy_date($buy_date);
        if (!$validBuyDate['ok']) {
            return array('ok' => false, 'error' => $validBuyDate['error']);
        }

        $product = $validProduct['value'];
        $sn = $validSn['value'];
        $inform = $validInform['value'];
        $buy_date = $validBuyDate['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT trp_product, trp_sn, trp_repair_inform, trp_buy_date, trp_repair_age FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $oldDbProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        $oldDbSn = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_sn']) ? $row['trp_sn'] : ''));
        $oldDbInform = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_repair_inform']) ? $row['trp_repair_inform'] : ''));
        $oldDbBuyDate = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_buy_date']) ? $row['trp_buy_date'] : ''));
        $oldDbBuyDateNorm = maintenance_detail_job_buy_date_for_html_input($oldDbBuyDate);
        if ($oldDbBuyDateNorm !== '') {
            $oldDbBuyDate = $oldDbBuyDateNorm;
        }
        $oldDbAge = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_repair_age']) ? $row['trp_repair_age'] : ''));
        $repairAge = $oldDbAge;
        if ($buy_date !== $oldDbBuyDate) {
            $repairAge = $buy_date !== '' ? maintenance_cal_lifetime_plain_from_buy_date($buy_date) : '';
        }
        if ($product === $oldDbProduct && $sn === $oldDbSn && $inform === $oldDbInform && $buy_date === $oldDbBuyDate) {
            return array('ok' => true, 'unchanged' => true);
        }

        mysqli_begin_transaction($connect);
        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_repair SET trp_product = ?, trp_sn = ?, trp_repair_inform = ?, trp_buy_date = ?, trp_repair_age = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            mysqli_rollback($connect);

            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'sssssii', $product, $sn, $inform, $buy_date, $repairAge, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            mysqli_rollback($connect);

            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_repair_machine', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'old_db' => array(
                    'trp_product' => $oldDbProduct,
                    'trp_sn' => $oldDbSn,
                    'trp_repair_inform' => $oldDbInform,
                    'trp_buy_date' => $oldDbBuyDate,
                    'trp_repair_age' => $oldDbAge,
                ),
                'old_client' => array(
                    'trp_product' => trim((string) $old_product),
                    'trp_sn' => trim((string) $old_sn),
                    'trp_repair_inform' => trim((string) $old_inform),
                    'trp_buy_date' => trim((string) $old_buy_date),
                ),
                'new' => array(
                    'trp_product' => $product,
                    'trp_sn' => $sn,
                    'trp_repair_inform' => $inform,
                    'trp_buy_date' => $buy_date,
                    'trp_repair_age' => $repairAge,
                ),
            ),
        ));

        mysqli_commit($connect);

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_list_logistics_utf8')) {
    /**
     * Purpose:
     * รายชื่อขนส่งจาก `logistics_carrier` เป็น UTF-8 สำหรับ validate / modal
     *
     * Parameters สำคัญ:
     * - mysqli|null $connect: connection (ถ้าไม่ส่งจะใช้ global `$connect`)
     *
     * Return value:
     * - string[]
     */
    function maintenance_detail_job_list_logistics_utf8($connect = null)
    {
        if (!($connect instanceof mysqli)) {
            global $connect;
        }
        require_once __DIR__ . '/logistics_helpers.php';
        if ($connect instanceof mysqli) {
            return maintenance_logistics_fetch_active_names($connect);
        }

        return maintenance_logistics_legacy_fallback_names();
    }
}

if (!function_exists('maintenance_detail_job_validate_tsp_datesend')) {
    /**
     * Purpose:
     * ตรวจวันที่ส่งสำรอง (Y-m-d) — ต้องไม่ว่าง
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก `<input type="date">`
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_tsp_datesend($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่ส่งสำรอง');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่ส่งสำรองไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่ส่งสำรองไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_validate_tsp_logistics')) {
    /**
     * Purpose:
     * ตรวจชื่อขนส่งต้องอยู่ในรายการระบบ
     *
     * Parameters สำคัญ:
     * - string $logistics: ชื่อขนส่ง (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_tsp_logistics($logistics, $connect = null)
    {
        if (!($connect instanceof mysqli)) {
            global $connect;
        }
        require_once __DIR__ . '/logistics_helpers.php';
        $logistics = trim((string) $logistics);
        if ($logistics === '') {
            return array('ok' => false, 'error' => 'กรุณาเลือกขนส่ง');
        }
        if ($connect instanceof mysqli && !maintenance_logistics_is_active_name($connect, $logistics)) {
            return array('ok' => false, 'error' => 'ขนส่งที่เลือกไม่อยู่ในรายการระบบ');
        }

        return array('ok' => true, 'value' => $logistics);
    }
}

if (!function_exists('maintenance_detail_job_validate_tsp_sendby')) {
    /**
     * Purpose:
     * ตรวจชื่อผู้ส่งก่อนบันทึก
     *
     * Parameters สำคัญ:
     * - string $sendby: ชื่อผู้ส่ง (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_tsp_sendby($sendby)
    {
        $sendby = trim((string) $sendby);
        if ($sendby === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุผู้ส่ง');
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($sendby, 'UTF-8') > 100) {
                return array('ok' => false, 'error' => 'ผู้ส่งต้องไม่เกิน 100 ตัวอักษร');
            }
        } elseif (strlen($sendby) > 100) {
            return array('ok' => false, 'error' => 'ผู้ส่งต้องไม่เกิน 100 ตัวอักษร');
        }

        return array('ok' => true, 'value' => $sendby);
    }
}

if (!function_exists('maintenance_detail_job_update_spare_send_bundle')) {
    /**
     * Purpose:
     * อัปเดตวันที่ส่งสำรอง / ขนส่ง / ผู้ส่ง ของแถว `transac_spare` หนึ่งรายการ (modal บน detail_job)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, $trp_id, $tsp_id: รหัสใบงาน / รายการซ่อม / แถวสำรอง
     * - string $datesend, $logistics, $sendby: ค่าใหม่ (UTF-8, วันที่ Y-m-d)
     * - string $old_datesend, $old_logistics, $old_sendby: ค่าเดิมจาก client (log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_spare_send_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $tsp_id,
        $datesend,
        $logistics,
        $sendby,
        $old_datesend,
        $old_logistics,
        $old_sendby
    ) {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0 || $tsp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการสำรองไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }

        $validDate = maintenance_detail_job_validate_tsp_datesend($datesend);
        if (!$validDate['ok']) {
            return array('ok' => false, 'error' => $validDate['error']);
        }
        $validLog = maintenance_detail_job_validate_tsp_logistics($logistics, $connect);
        if (!$validLog['ok']) {
            return array('ok' => false, 'error' => $validLog['error']);
        }
        $validSend = maintenance_detail_job_validate_tsp_sendby($sendby);
        if (!$validSend['ok']) {
            return array('ok' => false, 'error' => $validSend['error']);
        }

        $datesend = $validDate['value'];
        $logistics = $validLog['value'];
        $sendby = $validSend['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT tsp_datesend, tsp_logistics, tsp_sendby FROM transac_spare WHERE tsp_id = ? AND tsp_ts_id = ? AND tsp_trp_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'iii', $tsp_id, $ts_id, $trp_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการส่งสำรองในใบงานนี้');
        }

        $old_db_date = maintenance_detail_job_buy_date_for_html_input(isset($row['tsp_datesend']) ? $row['tsp_datesend'] : '');
        $old_db_log = trim(maintenance_flow_db_text_to_utf8(isset($row['tsp_logistics']) ? $row['tsp_logistics'] : ''));
        $old_db_send = trim(maintenance_flow_db_text_to_utf8(isset($row['tsp_sendby']) ? $row['tsp_sendby'] : ''));

        if ($datesend === $old_db_date && $logistics === $old_db_log && $sendby === $old_db_send) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_spare SET tsp_datesend = ?, tsp_logistics = ?, tsp_sendby = ? WHERE tsp_id = ? AND tsp_ts_id = ? AND tsp_trp_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'sssiii', $datesend, $logistics, $sendby, $tsp_id, $ts_id, $trp_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        $log_payload = array(
            'ts_id' => $ts_id,
            'trp_id' => $trp_id,
            'tsp_id' => $tsp_id,
            'old' => array(
                'tsp_datesend' => $old_db_date,
                'tsp_logistics' => $old_db_log,
                'tsp_sendby' => $old_db_send,
            ),
            'old_client' => array(
                'tsp_datesend' => trim((string) $old_datesend),
                'tsp_logistics' => trim((string) $old_logistics),
                'tsp_sendby' => trim((string) $old_sendby),
            ),
            'new' => array(
                'tsp_datesend' => $datesend,
                'tsp_logistics' => $logistics,
                'tsp_sendby' => $sendby,
            ),
        );

        maintenance_activity_log_write($connect, 'detail_job_update_spare_send', array(
            'entity_type' => 'transac_spare',
            'entity_id' => (string) $tsp_id,
            'payload' => $log_payload,
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_should_show_early_spare_return_link')) {
    /**
     * Purpose:
     * ตรวจว่าควรแสดงลิงก์ «ลูกค้าคืนสำรองก่อนปิดงาน» — เมื่อมีสำรองส่งแล้วและยังไม่ส่งคืนเครื่องซ่อมให้ลูกค้า
     *
     * Parameters สำคัญ:
     * - bool $repair_row_edit_locked: รายการซ่อมล็อก (เสร็จสิ้น)
     * - bool $repair_returned_locked: มี `trp_send_date` แล้ว
     * - int $tsp_id: รหัสแถวสำรอง
     * - bool $spare_send_has_data: มีวันที่ส่งสำรองแล้ว
     * - bool $spare_returned_locked: มี `tsp_datereturn` แล้ว (บันทึกรับคืนก่อนปิดงาน)
     *
     * Return value:
     * - bool true เมื่อควรแสดงปุ่ม
     */
    function maintenance_detail_job_should_show_early_spare_return_link(
        $repair_row_edit_locked,
        $repair_returned_locked,
        $tsp_id,
        $spare_send_has_data,
        $spare_returned_locked = false
    ) {
        if ($repair_row_edit_locked || $repair_returned_locked || $spare_returned_locked) {
            return false;
        }
        if ((int) $tsp_id < 1 || !$spare_send_has_data) {
            return false;
        }

        return true;
    }
}

if (!function_exists('maintenance_detail_job_should_show_early_spare_return_done_notice')) {
    /**
     * Purpose:
     * ตรวจว่าควรแสดงข้อความ «ส่งสำรองคืนแล้วก่อนปิดงาน» แทนปุ่มบันทึกรับคืนก่อนปิดงาน
     *
     * Parameters สำคัญ:
     * - bool $repair_row_edit_locked: รายการซ่อมล็อก (เสร็จสิ้น)
     * - bool $repair_returned_locked: มี `trp_send_date` แล้ว
     * - bool $spare_returned_locked: มี `tsp_datereturn` แล้ว
     *
     * Return value:
     * - bool true เมื่อควรแสดงข้อความ
     */
    function maintenance_detail_job_should_show_early_spare_return_done_notice(
        $repair_row_edit_locked,
        $repair_returned_locked,
        $spare_returned_locked
    ) {
        if ($repair_row_edit_locked || $repair_returned_locked) {
            return false;
        }

        return $spare_returned_locked;
    }
}

if (!function_exists('maintenance_detail_job_validate_tsp_datereturn')) {
    /**
     * Purpose:
     * ตรวจวันที่รับเครื่องสำรองคืน (Y-m-d)
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก `<input type="date">`
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_tsp_datereturn($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่รับสำรองคืน');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่รับสำรองคืนไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่รับสำรองคืนไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_update_spare_early_return_bundle')) {
    /**
     * Purpose:
     * บันทึกรับเครื่องสำรองคืนก่อนปิดงาน (`tsp_datereturn`, `tsp_receiveby`) — ไม่เปลี่ยน `trp_status_job` เป็นเสร็จสิ้น
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, $trp_id, $tsp_id: รหัสใบงาน / ซ่อม / สำรอง
     * - string $datereturn, $receiveby: ค่าใหม่
     * - string $old_datereturn, $old_receiveby: ค่าเดิมจาก client (log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_spare_early_return_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $tsp_id,
        $datereturn,
        $receiveby,
        $old_datereturn,
        $old_receiveby
    ) {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0 || $tsp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการสำรองไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }
        if (maintenance_detail_job_repair_returned_locked_db($connect, $trp_id)) {
            return array('ok' => false, 'error' => 'ส่งคืนเครื่องซ่อมให้ลูกค้าแล้ว ไม่สามารถบันทึกรับสำรองก่อนปิดงานได้');
        }

        $validDate = maintenance_detail_job_validate_tsp_datereturn($datereturn);
        if (!$validDate['ok']) {
            return array('ok' => false, 'error' => $validDate['error']);
        }
        $validRecv = maintenance_detail_job_validate_tsp_sendby($receiveby);
        if (!$validRecv['ok']) {
            return array('ok' => false, 'error' => $validRecv['error']);
        }

        $datereturn = $validDate['value'];
        $receiveby = $validRecv['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT tsp_datereturn, tsp_receiveby, tsp_datesend, tsp_sn FROM transac_spare WHERE tsp_id = ? AND tsp_ts_id = ? AND tsp_trp_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'iii', $tsp_id, $ts_id, $trp_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการสำรองในใบงานนี้');
        }

        if (!maintenance_detail_job_date_has_data(isset($row['tsp_datesend']) ? $row['tsp_datesend'] : '')) {
            return array('ok' => false, 'error' => 'ยังไม่มีวันที่ส่งสำรอง — ไม่สามารถบันทึกรับคืนก่อนปิดงานได้');
        }

        $old_db_date = maintenance_detail_job_buy_date_for_html_input(isset($row['tsp_datereturn']) ? $row['tsp_datereturn'] : '');
        $old_db_recv = trim(maintenance_flow_db_text_to_utf8(isset($row['tsp_receiveby']) ? $row['tsp_receiveby'] : ''));
        $tsp_sn = trim(maintenance_flow_db_text_to_utf8(isset($row['tsp_sn']) ? $row['tsp_sn'] : ''));

        if ($datereturn === $old_db_date && $receiveby === $old_db_recv) {
            return array('ok' => true, 'unchanged' => true);
        }

        mysqli_begin_transaction($connect);

        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_spare SET tsp_datereturn = ?, tsp_receiveby = ? WHERE tsp_id = ? AND tsp_ts_id = ? AND tsp_trp_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            mysqli_rollback($connect);

            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'ssiii', $datereturn, $receiveby, $tsp_id, $ts_id, $trp_id);
        $okUp = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$okUp || $affected < 1) {
            mysqli_rollback($connect);

            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        if ($tsp_sn !== '') {
            $blank = 'blank';
            $stmtSp = mysqli_prepare($connect, 'UPDATE spare SET sp_status = ? WHERE sp_sn = ? LIMIT 1');
            if ($stmtSp === false) {
                mysqli_rollback($connect);

                return array('ok' => false, 'error' => 'เตรียมคำสั่งคืนสถานะ SN สำรองไม่สำเร็จ');
            }
            mysqli_stmt_bind_param($stmtSp, 'ss', $blank, $tsp_sn);
            if (!mysqli_stmt_execute($stmtSp)) {
                mysqli_stmt_close($stmtSp);
                mysqli_rollback($connect);

                return array('ok' => false, 'error' => 'คืนสถานะ SN สำรองในคลังไม่สำเร็จ');
            }
            mysqli_stmt_close($stmtSp);
        }

        mysqli_commit($connect);

        maintenance_activity_log_write($connect, 'detail_job_update_spare_early_return', array(
            'entity_type' => 'transac_spare',
            'entity_id' => (string) $tsp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'tsp_id' => $tsp_id,
                'old' => array(
                    'tsp_datereturn' => $old_db_date,
                    'tsp_receiveby' => $old_db_recv,
                ),
                'old_client' => array(
                    'tsp_datereturn' => trim((string) $old_datereturn),
                    'tsp_receiveby' => trim((string) $old_receiveby),
                ),
                'new' => array(
                    'tsp_datereturn' => $datereturn,
                    'tsp_receiveby' => $receiveby,
                ),
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_receive_date')) {
    /**
     * Purpose:
     * ตรวจวันที่รับเครื่องซ่อม (Y-m-d) — ต้องไม่ว่าง
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก `<input type="date">`
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_receive_date($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่รับเครื่อง');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่รับเครื่องไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่รับเครื่องไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_user_recive_ma')) {
    /**
     * Purpose:
     * ตรวจชื่อผู้รับเครื่องซ่อมก่อนบันทึก (อนุญาตว่าง)
     *
     * Parameters สำคัญ:
     * - string $user_recive_ma: ชื่อผู้รับ (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_user_recive_ma($user_recive_ma)
    {
        $v = trim((string) $user_recive_ma);
        if ($v === '') {
            return array('ok' => true, 'value' => '');
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($v, 'UTF-8') > 100) {
                return array('ok' => false, 'error' => 'ผู้รับต้องไม่เกิน 100 ตัวอักษร');
            }
        } elseif (strlen($v) > 100) {
            return array('ok' => false, 'error' => 'ผู้รับต้องไม่เกิน 100 ตัวอักษร');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_update_repair_receive_bundle')) {
    /**
     * Purpose:
     * อัปเดตวันที่รับเครื่องซ่อม + ผู้รับ (`transac_repair`) จาก modal บน detail_job
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, int $trp_id: รหัสใบงาน / รายการซ่อม
     * - string $receive_date: วันที่รับ (Y-m-d)
     * - string $user_recive_ma: ผู้รับ (UTF-8)
     * - string $old_receive_date, $old_user_recive_ma: ค่าเดิมจาก client (log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_repair_receive_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $receive_date,
        $user_recive_ma,
        $old_receive_date,
        $old_user_recive_ma
    ) {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }
        if (maintenance_detail_job_repair_rate_edit_locked($connect, $trp_id)) {
            return array('ok' => false, 'error' => 'ไม่สามารถแก้ไขวันที่รับเครื่องได้ เนื่องจากมีการเสนอราคาซ่อมแล้ว');
        }

        $validDate = maintenance_detail_job_validate_trp_receive_date($receive_date);
        if (!$validDate['ok']) {
            return array('ok' => false, 'error' => isset($validDate['error']) ? $validDate['error'] : 'วันที่รับเครื่องไม่ถูกต้อง');
        }
        $validUser = maintenance_detail_job_validate_trp_user_recive_ma($user_recive_ma);
        if (!$validUser['ok']) {
            return array('ok' => false, 'error' => $validUser['error']);
        }

        $receive_date = $validDate['value'];
        $user_recive_ma = $validUser['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT trp_receive_date, trp_user_recive_ma FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db_date = maintenance_detail_job_buy_date_for_html_input(isset($row['trp_receive_date']) ? $row['trp_receive_date'] : '');
        $old_db_user = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_user_recive_ma']) ? $row['trp_user_recive_ma'] : ''));

        if ($receive_date === $old_db_date && $user_recive_ma === $old_db_user) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_repair SET trp_receive_date = ?, trp_user_recive_ma = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'ssii', $receive_date, $user_recive_ma, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_repair_receive', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'old' => array(
                    'trp_receive_date' => $old_db_date,
                    'trp_user_recive_ma' => $old_db_user,
                ),
                'old_client' => array(
                    'trp_receive_date' => trim((string) $old_receive_date),
                    'trp_user_recive_ma' => trim((string) $old_user_recive_ma),
                ),
                'new' => array(
                    'trp_receive_date' => $receive_date,
                    'trp_user_recive_ma' => $user_recive_ma,
                ),
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_qv_number')) {
    /**
     * Purpose:
     * ตรวจเลขใบเสนอราคา (QV) ก่อนบันทึกจาก detail_job
     *
     * Parameters สำคัญ:
     * - string $qv_number: เลข QV (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_qv_number($qv_number)
    {
        $v = strtoupper(trim((string) $qv_number));
        if ($v === '' || $v === '-') {
            return array('ok' => false, 'error' => 'กรุณาระบุหมายเลขใบเสนอราคา');
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($v, 'UTF-8') > 12) {
                return array('ok' => false, 'error' => 'หมายเลขใบเสนอราคาต้องไม่เกิน 12 ตัวอักษร');
            }
        } elseif (strlen($v) > 12) {
            return array('ok' => false, 'error' => 'หมายเลขใบเสนอราคาต้องไม่เกิน 12 ตัวอักษร');
        }
        if (!preg_match('/^[A-Z0-9]+$/', $v)) {
            return array('ok' => false, 'error' => 'หมายเลขใบเสนอราคาใช้ได้เฉพาะตัวอักษรภาษาอังกฤษและตัวเลข');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_qv_send')) {
    /**
     * Purpose:
     * ตรวจช่องทางส่งใบเสนอราคา (Mail / Line / Fax)
     *
     * Parameters สำคัญ:
     * - string $qv_send: ค่า `trp_qv_send`
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_qv_send($qv_send)
    {
        $v = trim((string) $qv_send);
        $allowed = array('Mail', 'Line', 'Fax');
        if ($v === '' || !in_array($v, $allowed, true)) {
            return array('ok' => false, 'error' => 'กรุณาระบุรูปแบบการส่ง (Mail / Line / Fax)');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_qv_price')) {
    /**
     * Purpose:
     * ตรวจจำนวนเงินเสนอราคา (ตัวเลข อนุญาตทศนิยม)
     *
     * Parameters สำคัญ:
     * - string $qv_price: ค่า `trp_qv_price`
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_qv_price($qv_price)
    {
        $v = trim((string) $qv_price);
        if ($v === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุจำนวนเงิน');
        }
        $normalized = str_replace(',', '', $v);
        if (!is_numeric($normalized)) {
            return array('ok' => false, 'error' => 'จำนวนเงินต้องเป็นตัวเลข');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_qv_date')) {
    /**
     * Purpose:
     * ตรวจวันที่เสนอราคา (บังคับ, รูปแบบ Y-m-d)
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก input type=date
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_qv_date($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่เสนอราคา');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่เสนอราคาไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่เสนอราคาไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_optional_date')) {
    /**
     * Purpose:
     * ตรวจวันที่แบบ Y-m-d (อนุญาตว่าง)
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก input type=date
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_optional_date($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => true, 'value' => '');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่ไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่ไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_update_repair_qv_bundle')) {
    /**
     * Purpose:
     * อัปเดตกลุ่มใบเสนอราคา (`trp_qv_*`, `trp_firm_qv_date`) จาก modal บน detail_job — ไม่เปลี่ยน `trp_status_job`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, int $trp_id: รหัสใบงาน / รายการซ่อม
     * - string $qv_number, $qv_date, $qv_send, $qv_price, $firm_qv_date: ค่าใหม่ (UTF-8 / Y-m-d)
     * - string $old_*: ค่าเดิมจาก client (log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_repair_qv_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $qv_number,
        $qv_date,
        $qv_send,
        $qv_price,
        $firm_qv_date,
        $old_qv_number,
        $old_qv_date,
        $old_qv_send,
        $old_qv_price,
        $old_firm_qv_date
    ) {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }
        if (maintenance_detail_job_repair_ma_approved_locked_db($connect, $trp_id)) {
            return array('ok' => false, 'error' => 'ไม่สามารถแก้ไขใบเสนอราคาได้ เนื่องจากมีการอนุมัติการซ่อมแล้ว');
        }

        $validNum = maintenance_detail_job_validate_trp_qv_number($qv_number);
        if (!$validNum['ok']) {
            return array('ok' => false, 'error' => isset($validNum['error']) ? $validNum['error'] : 'หมายเลขใบเสนอราคาไม่ถูกต้อง');
        }
        $validDate = maintenance_detail_job_validate_trp_qv_date($qv_date);
        if (!$validDate['ok']) {
            return array('ok' => false, 'error' => isset($validDate['error']) ? $validDate['error'] : 'วันที่เสนอราคาไม่ถูกต้อง');
        }
        $validSend = maintenance_detail_job_validate_trp_qv_send($qv_send);
        if (!$validSend['ok']) {
            return array('ok' => false, 'error' => $validSend['error']);
        }
        $validPrice = maintenance_detail_job_validate_trp_qv_price($qv_price);
        if (!$validPrice['ok']) {
            return array('ok' => false, 'error' => $validPrice['error']);
        }
        $validFirm = maintenance_detail_job_validate_trp_optional_date($firm_qv_date);
        if (!$validFirm['ok']) {
            return array('ok' => false, 'error' => isset($validFirm['error']) ? $validFirm['error'] : 'วันที่เสนอราคาแนนไม่ถูกต้อง');
        }

        $qv_number = $validNum['value'];
        $qv_date = $validDate['value'];
        $qv_send = $validSend['value'];
        $qv_price = $validPrice['value'];
        $firm_qv_date = $validFirm['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT trp_qv_number, trp_qv_date, trp_qv_send, trp_qv_price, trp_firm_qv_date FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db_num = strtoupper(trim(maintenance_flow_db_text_to_utf8(isset($row['trp_qv_number']) ? $row['trp_qv_number'] : '')));
        $old_db_date = maintenance_detail_job_buy_date_for_html_input(isset($row['trp_qv_date']) ? $row['trp_qv_date'] : '');
        $old_db_send = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_qv_send']) ? $row['trp_qv_send'] : ''));
        $old_db_price = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_qv_price']) ? $row['trp_qv_price'] : ''));
        $old_db_firm = maintenance_detail_job_buy_date_for_html_input(isset($row['trp_firm_qv_date']) ? $row['trp_firm_qv_date'] : '');

        if ($qv_number === $old_db_num
            && $qv_date === $old_db_date
            && $qv_send === $old_db_send
            && $qv_price === $old_db_price
            && $firm_qv_date === $old_db_firm
        ) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_repair SET trp_qv_number = ?, trp_qv_date = ?, trp_qv_send = ?, trp_qv_price = ?, trp_firm_qv_date = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'sssssii', $qv_number, $qv_date, $qv_send, $qv_price, $firm_qv_date, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_repair_qv', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'old' => array(
                    'trp_qv_number' => $old_db_num,
                    'trp_qv_date' => $old_db_date,
                    'trp_qv_send' => $old_db_send,
                    'trp_qv_price' => $old_db_price,
                    'trp_firm_qv_date' => $old_db_firm,
                ),
                'old_client' => array(
                    'trp_qv_number' => trim((string) $old_qv_number),
                    'trp_qv_date' => trim((string) $old_qv_date),
                    'trp_qv_send' => trim((string) $old_qv_send),
                    'trp_qv_price' => trim((string) $old_qv_price),
                    'trp_firm_qv_date' => trim((string) $old_firm_qv_date),
                ),
                'new' => array(
                    'trp_qv_number' => $qv_number,
                    'trp_qv_date' => $qv_date,
                    'trp_qv_send' => $qv_send,
                    'trp_qv_price' => $qv_price,
                    'trp_firm_qv_date' => $firm_qv_date,
                ),
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_success_date')) {
    /**
     * Purpose:
     * ตรวจวันที่ซ่อมเสร็จ (บังคับ, รูปแบบ Y-m-d)
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก input type=date
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_success_date($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่ซ่อม');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่ซ่อมไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่ซ่อมไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_user_ma')) {
    /**
     * Purpose:
     * ตรวจชื่อช่างซ่อมก่อนบันทึก (บังคับ)
     *
     * Parameters สำคัญ:
     * - string $user_ma: ชื่อช่างซ่อม (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_user_ma($user_ma)
    {
        $v = trim((string) $user_ma);
        if ($v === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุช่างซ่อม');
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($v, 'UTF-8') > 100) {
                return array('ok' => false, 'error' => 'ช่างซ่อมต้องไม่เกิน 100 ตัวอักษร');
            }
        } elseif (strlen($v) > 100) {
            return array('ok' => false, 'error' => 'ช่างซ่อมต้องไม่เกิน 100 ตัวอักษร');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_update_repair_ma_bundle')) {
    /**
     * Purpose:
     * อัปเดตวันที่ซ่อม + ช่างซ่อม (`transac_repair`) จาก modal บน detail_job — ไม่เปลี่ยน `trp_status_job`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, int $trp_id: รหัสใบงาน / รายการซ่อม
     * - string $success_date: วันที่ซ่อม (Y-m-d)
     * - string $user_ma: ช่างซ่อม (UTF-8)
     * - string $old_success_date, $old_user_ma: ค่าเดิมจาก client (log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_repair_ma_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $success_date,
        $user_ma,
        $old_success_date,
        $old_user_ma
    ) {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }
        if (maintenance_detail_job_repair_qc_done_locked_db($connect, $trp_id)) {
            return array('ok' => false, 'error' => 'ไม่สามารถแก้ไขวันที่ซ่อมได้ เนื่องจากมีการ QC เครื่องแล้ว');
        }

        $validDate = maintenance_detail_job_validate_trp_success_date($success_date);
        if (!$validDate['ok']) {
            return array('ok' => false, 'error' => isset($validDate['error']) ? $validDate['error'] : 'วันที่ซ่อมไม่ถูกต้อง');
        }
        $validUser = maintenance_detail_job_validate_trp_user_ma($user_ma);
        if (!$validUser['ok']) {
            return array('ok' => false, 'error' => $validUser['error']);
        }

        $success_date = $validDate['value'];
        $user_ma = $validUser['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT trp_success_date, trp_user_ma FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db_date = maintenance_detail_job_buy_date_for_html_input(isset($row['trp_success_date']) ? $row['trp_success_date'] : '');
        $old_db_user = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_user_ma']) ? $row['trp_user_ma'] : ''));

        if ($success_date === $old_db_date && $user_ma === $old_db_user) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_repair SET trp_success_date = ?, trp_user_ma = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'ssii', $success_date, $user_ma, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_repair_ma', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'old' => array(
                    'trp_success_date' => $old_db_date,
                    'trp_user_ma' => $old_db_user,
                ),
                'old_client' => array(
                    'trp_success_date' => trim((string) $old_success_date),
                    'trp_user_ma' => trim((string) $old_user_ma),
                ),
                'new' => array(
                    'trp_success_date' => $success_date,
                    'trp_user_ma' => $user_ma,
                ),
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_qc_date')) {
    /**
     * Purpose:
     * ตรวจวันที่ QC (บังคับ, รูปแบบ Y-m-d)
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก input type=date
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_qc_date($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่ QC');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่ QC ไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่ QC ไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_qc_user')) {
    /**
     * Purpose:
     * ตรวจชื่อผู้ QC ก่อนบันทึก (บังคับ)
     *
     * Parameters สำคัญ:
     * - string $qc_user: ชื่อผู้ QC (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_qc_user($qc_user)
    {
        $v = trim((string) $qc_user);
        if ($v === '' || $v === '-') {
            return array('ok' => false, 'error' => 'กรุณาระบุผู้ QC');
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($v, 'UTF-8') > 100) {
                return array('ok' => false, 'error' => 'ผู้ QC ต้องไม่เกิน 100 ตัวอักษร');
            }
        } elseif (strlen($v) > 100) {
            return array('ok' => false, 'error' => 'ผู้ QC ต้องไม่เกิน 100 ตัวอักษร');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_qc_comment')) {
    /**
     * Purpose:
     * ตรวจ Comment QC ก่อนบันทึก (อนุญาตว่าง)
     *
     * Parameters สำคัญ:
     * - string $qc_comment: ข้อความ (UTF-8)
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_qc_comment($qc_comment)
    {
        $v = trim((string) $qc_comment);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($v, 'UTF-8') > 500) {
                return array('ok' => false, 'error' => 'Comment QC ต้องไม่เกิน 500 ตัวอักษร');
            }
        } elseif (strlen($v) > 500) {
            return array('ok' => false, 'error' => 'Comment QC ต้องไม่เกิน 500 ตัวอักษร');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_update_repair_qc_bundle')) {
    /**
     * Purpose:
     * อัปเดตวันที่ QC + ผู้ QC + Comment (`transac_repair`) จาก modal บน detail_job — ไม่เปลี่ยน `trp_status_job`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, int $trp_id: รหัสใบงาน / รายการซ่อม
     * - string $qc_date, $qc_user, $qc_comment: ค่าใหม่
     * - string $old_qc_date, $old_qc_user, $old_qc_comment: ค่าเดิมจาก client (log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_repair_qc_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $qc_date,
        $qc_user,
        $qc_comment,
        $old_qc_date,
        $old_qc_user,
        $old_qc_comment
    ) {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }
        if (maintenance_detail_job_repair_returned_locked_db($connect, $trp_id)) {
            return array('ok' => false, 'error' => 'ไม่สามารถแก้ไขข้อมูล QC ได้ เนื่องจากส่งเครื่องคืนลูกค้าแล้ว');
        }

        $validDate = maintenance_detail_job_validate_trp_qc_date($qc_date);
        if (!$validDate['ok']) {
            return array('ok' => false, 'error' => isset($validDate['error']) ? $validDate['error'] : 'วันที่ QC ไม่ถูกต้อง');
        }
        $validUser = maintenance_detail_job_validate_trp_qc_user($qc_user);
        if (!$validUser['ok']) {
            return array('ok' => false, 'error' => $validUser['error']);
        }
        $validComment = maintenance_detail_job_validate_trp_qc_comment($qc_comment);
        if (!$validComment['ok']) {
            return array('ok' => false, 'error' => $validComment['error']);
        }

        $qc_date = $validDate['value'];
        $qc_user = $validUser['value'];
        $qc_comment = $validComment['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT trp_qc_date, trp_qc_user, trp_qc_comment FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db_date = maintenance_detail_job_buy_date_for_html_input(isset($row['trp_qc_date']) ? $row['trp_qc_date'] : '');
        $old_db_user = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_qc_user']) ? $row['trp_qc_user'] : ''));
        $old_db_comment = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_qc_comment']) ? $row['trp_qc_comment'] : ''));

        if ($qc_date === $old_db_date && $qc_user === $old_db_user && $qc_comment === $old_db_comment) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_repair SET trp_qc_date = ?, trp_qc_user = ?, trp_qc_comment = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'sssii', $qc_date, $qc_user, $qc_comment, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_repair_qc', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'old' => array(
                    'trp_qc_date' => $old_db_date,
                    'trp_qc_user' => $old_db_user,
                    'trp_qc_comment' => $old_db_comment,
                ),
                'old_client' => array(
                    'trp_qc_date' => trim((string) $old_qc_date),
                    'trp_qc_user' => trim((string) $old_qc_user),
                    'trp_qc_comment' => trim((string) $old_qc_comment),
                ),
                'new' => array(
                    'trp_qc_date' => $qc_date,
                    'trp_qc_user' => $qc_user,
                    'trp_qc_comment' => $qc_comment,
                ),
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_send_date')) {
    /**
     * Purpose:
     * ตรวจวันที่ส่งคืนเครื่องซ่อม (บังคับ, รูปแบบ Y-m-d)
     *
     * Parameters สำคัญ:
     * - string $date: วันที่จาก input type=date
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_send_date($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่ส่งคืน');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('ok' => false, 'error' => 'รูปแบบวันที่ส่งคืนไม่ถูกต้อง');
        }
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return array('ok' => false, 'error' => 'วันที่ส่งคืนไม่ถูกต้อง');
        }

        return array('ok' => true, 'value' => $date);
    }
}

if (!function_exists('maintenance_detail_job_validate_trp_tracking')) {
    /**
     * Purpose:
     * ตรวจ tracking ส่งคืน (อนุญาตว่าง)
     *
     * Parameters สำคัญ:
     * - string $tracking: ค่า `trp_tracking`
     *
     * Return value:
     * - array{ok:bool, value?:string, error?:string}
     */
    function maintenance_detail_job_validate_trp_tracking($tracking)
    {
        $v = trim((string) $tracking);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($v, 'UTF-8') > 100) {
                return array('ok' => false, 'error' => 'Tracking ต้องไม่เกิน 100 ตัวอักษร');
            }
        } elseif (strlen($v) > 100) {
            return array('ok' => false, 'error' => 'Tracking ต้องไม่เกิน 100 ตัวอักษร');
        }

        return array('ok' => true, 'value' => $v);
    }
}

if (!function_exists('maintenance_detail_job_update_repair_return_bundle')) {
    /**
     * Purpose:
     * อัปเดตวันที่ส่งคืน + ขนส่ง + tracking + ผู้ส่ง (`transac_repair`) จาก modal บน detail_job — ไม่เปลี่ยน `trp_status_job`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id, int $trp_id: รหัสใบงาน / รายการซ่อม
     * - string $send_date, $logistics, $tracking, $sendby: ค่าใหม่
     * - string $old_*: ค่าเดิมจาก client (log)
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_repair_return_bundle(
        $connect,
        $ts_id,
        $trp_id,
        $send_date,
        $logistics,
        $tracking,
        $sendby,
        $old_send_date,
        $old_logistics,
        $old_tracking,
        $old_sendby
    ) {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }
        if ($trp_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
        }
        if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
            return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
        }
        if (maintenance_detail_job_spare_returned_locked_db($connect, $trp_id)) {
            return array('ok' => false, 'error' => 'ไม่สามารถแก้ไขข้อมูลส่งคืนได้ เนื่องจากได้รับเครื่องสำรองคืนแล้ว');
        }

        $validDate = maintenance_detail_job_validate_trp_send_date($send_date);
        if (!$validDate['ok']) {
            return array('ok' => false, 'error' => isset($validDate['error']) ? $validDate['error'] : 'วันที่ส่งคืนไม่ถูกต้อง');
        }
        $validLog = maintenance_detail_job_validate_tsp_logistics($logistics, $connect);
        if (!$validLog['ok']) {
            return array('ok' => false, 'error' => $validLog['error']);
        }
        $validTrack = maintenance_detail_job_validate_trp_tracking($tracking);
        if (!$validTrack['ok']) {
            return array('ok' => false, 'error' => $validTrack['error']);
        }
        $validSend = maintenance_detail_job_validate_tsp_sendby($sendby);
        if (!$validSend['ok']) {
            return array('ok' => false, 'error' => $validSend['error']);
        }

        $send_date = $validDate['value'];
        $logistics = $validLog['value'];
        $tracking = $validTrack['value'];
        $sendby = $validSend['value'];

        $stmtSel = mysqli_prepare(
            $connect,
            'SELECT trp_send_date, trp_logistics, trp_tracking, trp_sendby FROM transac_repair WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtSel === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอ่านไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtSel, 'ii', $trp_id, $ts_id);
        if (!mysqli_stmt_execute($stmtSel)) {
            mysqli_stmt_close($stmtSel);

            return array('ok' => false, 'error' => 'อ่านข้อมูลไม่สำเร็จ');
        }
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmtSel);
        mysqli_stmt_close($stmtSel);
        if (!is_array($row)) {
            return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมในใบงานนี้');
        }

        $old_db_date = maintenance_detail_job_buy_date_for_html_input(isset($row['trp_send_date']) ? $row['trp_send_date'] : '');
        $old_db_log = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_logistics']) ? $row['trp_logistics'] : ''));
        $old_db_track = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_tracking']) ? $row['trp_tracking'] : ''));
        $old_db_send = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_sendby']) ? $row['trp_sendby'] : ''));

        if ($send_date === $old_db_date
            && $logistics === $old_db_log
            && $tracking === $old_db_track
            && $sendby === $old_db_send
        ) {
            return array('ok' => true, 'unchanged' => true);
        }

        $stmtUp = mysqli_prepare(
            $connect,
            'UPDATE transac_repair SET trp_send_date = ?, trp_logistics = ?, trp_tracking = ?, trp_sendby = ? WHERE trp_id = ? AND trp_ts_id = ? LIMIT 1'
        );
        if ($stmtUp === false) {
            return array('ok' => false, 'error' => 'เตรียมคำสั่งอัปเดตไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtUp, 'ssssii', $send_date, $logistics, $tracking, $sendby, $trp_id, $ts_id);
        $ok = mysqli_stmt_execute($stmtUp);
        $affected = mysqli_stmt_affected_rows($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$ok || $affected < 1) {
            return array('ok' => false, 'error' => 'บันทึกไม่สำเร็จ');
        }

        maintenance_activity_log_write($connect, 'detail_job_update_repair_return', array(
            'entity_type' => 'transac_repair',
            'entity_id' => (string) $trp_id,
            'payload' => array(
                'ts_id' => $ts_id,
                'trp_id' => $trp_id,
                'old' => array(
                    'trp_send_date' => $old_db_date,
                    'trp_logistics' => $old_db_log,
                    'trp_tracking' => $old_db_track,
                    'trp_sendby' => $old_db_send,
                ),
                'old_client' => array(
                    'trp_send_date' => trim((string) $old_send_date),
                    'trp_logistics' => trim((string) $old_logistics),
                    'trp_tracking' => trim((string) $old_tracking),
                    'trp_sendby' => trim((string) $old_sendby),
                ),
                'new' => array(
                    'trp_send_date' => $send_date,
                    'trp_logistics' => $logistics,
                    'trp_tracking' => $tracking,
                    'trp_sendby' => $sendby,
                ),
            ),
        ));

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_detail_job_update_field')) {
    /**
     * Purpose:
     * จุดรวมอัปเดตฟิลด์จากหน้า detail_job ตาม `field_key` (ขยายเพิ่มจุดอื่นในอนาคตได้)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัส transac
     * - int $trp_id: รหัส transac_repair (0 ได้เฉพาะฟิลด์ระดับ transac ในอนาคต)
     * - string $field_key: รหัสฟิลด์ เช่น `trp_sn`
     * - string $new_value: ค่าใหม่
     * - string $old_value: ค่าเดิมจาก client
     *
     * Return value:
     * - array{ok:bool, error?:string, unchanged?:bool}
     */
    function maintenance_detail_job_update_field($connect, $ts_id, $trp_id, $field_key, $new_value, $old_value)
    {
        if ($ts_id <= 0) {
            return array('ok' => false, 'error' => 'รหัสใบงานไม่ถูกต้อง');
        }
        if (maintenance_detail_job_header_locked($connect, $ts_id)) {
            return array('ok' => false, 'error' => 'ใบงานนี้ปิดการแก้ไขแล้ว (มีรายการซ่อมสถานะเสร็จสิ้น)');
        }

        $field_key = trim((string) $field_key);
        switch ($field_key) {
            case 'trp_sn':
                if ($trp_id <= 0) {
                    return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
                }
                if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
                    return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
                }

                return maintenance_detail_job_update_trp_sn($connect, $ts_id, $trp_id, $new_value, $old_value);
            case 'trp_product':
                if ($trp_id <= 0) {
                    return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
                }
                if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
                    return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
                }

                return maintenance_detail_job_update_trp_product($connect, $ts_id, $trp_id, $new_value, $old_value);
            case 'trp_repair_inform':
                if ($trp_id <= 0) {
                    return array('ok' => false, 'error' => 'รหัสรายการซ่อมไม่ถูกต้อง');
                }
                if (maintenance_detail_job_repair_row_locked($connect, $ts_id, $trp_id)) {
                    return array('ok' => false, 'error' => 'รายการซ่อมนี้มีสถานะเสร็จสิ้นแล้ว ไม่สามารถแก้ไขได้');
                }

                return maintenance_detail_job_update_trp_repair_inform($connect, $ts_id, $trp_id, $new_value, $old_value);
            default:
                return array('ok' => false, 'error' => 'ไม่รองรับการแก้ไขฟิลด์นี้');
        }
    }
}
