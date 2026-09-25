<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `qc_checklist.php` — แปลงข้อความ TIS-620/UTF-8, สร้าง HTML ส่วนหัวตาราง (บริษัท / รายการซ่อม)
 *
 * Flow:
 * หลังดึงแถว join แล้วเรียกฟังก์ชันสร้าง string HTML → ส่งเข้า `includes/qc_checklist_form_rows.inc.php`
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/status_logistic_helpers.php';
require_once __DIR__ . '/check_history_repair_helpers.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_qc_checklist_db_text_to_utf8')) {
    /**
     * Purpose:
     * แปลงข้อความจากคอลัมน์ DB (TIS-620 หรือ UTF-8) เป็น UTF-8 สำหรับแสดงผล — ถ้า `mb_check_encoding` ยืนยัน UTF-8 แล้วคืนตามเดิม; มิฉะนั้น iconv จาก TIS-620 พร้อม `//IGNORE`
     *
     * Parameters สำคัญ:
     * - mixed $value: ค่าจาก mysqli
     *
     * Return value:
     * - string
     */
    function maintenance_qc_checklist_db_text_to_utf8($value)
    {
        if ($value === null || $value === false) {
            return '';
        }
        $s = (string) $value;
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $c = @iconv('TIS-620', 'UTF-8//IGNORE', $s);

        return $c !== false ? $c : $s;
    }
}

if (!function_exists('maintenance_qc_checklist_fetch_row')) {
    /**
     * Purpose:
     * ดึงแถว join สำหรับหน้า QC checklist — รวมฟิลด์รายการซ่อมและหมายเหตุช่างที่ฟอร์มต้องแสดง
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection mysqli
     * - int $trp_id: รหัสแถว `transac_repair`
     *
     * Return value:
     * - array|null แถว join หรือ null ถ้าไม่พบ
     */
    function maintenance_qc_checklist_fetch_row($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) {
            return null;
        }
        $sql = 'SELECT tr.trp_id AS trp_id, tr.trp_ts_id AS trp_ts_id, tr.trp_product AS trp_product, tr.trp_sn AS trp_sn, '
            . 'tr.trp_repair_inform AS trp_repair_inform, tr.trp_repair_age AS trp_repair_age, tr.trp_insurance AS trp_insurance, '
            . 'tr.trp_backup AS trp_backup, tr.trp_buy_date AS trp_buy_date, tr.trp_receive_date AS trp_receive_date, '
            . 'tr.trp_status_job AS trp_status_job, tr.trp_user_recive_ma AS trp_user_recive_ma, '
            . 'tr.trp_user_rate AS trp_user_rate, tr.trp_user_ma AS trp_user_ma, tr.trp_repair_technician AS trp_repair_technician, '
            . 'tr.trp_repair_no1, tr.trp_repair_no2, tr.trp_repair_no3, tr.trp_repair_no4, tr.trp_repair_no5, '
            . 'tr.trp_repair_no6, tr.trp_repair_no7, tr.trp_repair_no8, tr.trp_repair_no9, tr.trp_repair_no10, '
            . 'tr.trp_clean, tr.trp_repeat_solder, tr.trp_spray, tr.trp_fw, tr.trp_repair_remarks, '
            . 'ts.ts_id AS ts_id, ts.ts_mtn_number AS ts_mtn_number, ts.ts_enduser_com AS ts_enduser_com, ts.ts_security_com AS ts_security_com, '
            . 'ts.ts_report_name AS ts_report_name, ts.ts_report_tel AS ts_report_tel, ts.ts_address AS ts_address '
            . 'FROM transac_repair tr INNER JOIN transac ts ON ts.ts_id = tr.trp_ts_id WHERE tr.trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = ($res !== false) ? mysqli_fetch_assoc($res) : null;
        if ($res !== false) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('maintenance_qc_checklist_company_detail_html')) {
    /**
     * Purpose:
     * สร้าง HTML บล็อก «บริษัท/ผู้ติดต่อ» ตาม logic เดิมใน `old_system/qc_checklist.php`
     *
     * Parameters สำคัญ:
     * - array $row: แถวรวม `transac_repair` + `transac` (มี `ts_enduser_com`, `ts_security_com`)
     *
     * Return value:
     * - string HTML (มี tag `<br>`, `<span>` — แสดงด้วย `echo` ตรง ๆ ห้ามห่อ `htmlspecialchars` ซ้ำทั้งก้อน)
     */
    function maintenance_qc_checklist_company_detail_html(array $row)
    {
        $end = isset($row['ts_enduser_com']) ? (string) $row['ts_enduser_com'] : '';
        $sec = isset($row['ts_security_com']) ? (string) $row['ts_security_com'] : '';
        $endStrip = function_exists('strReplace') ? strReplace($end) : $end;
        $secStrip = function_exists('strReplace') ? strReplace($sec) : $sec;
        $endUtf = maintenance_qc_checklist_db_text_to_utf8($endStrip);
        $secUtf = maintenance_qc_checklist_db_text_to_utf8($secStrip);
        if ($end !== '' && $sec !== '') {
            return maintenance_status_logistic_h($endUtf) . '<br><span style="color:#0000FF">'
                . maintenance_status_logistic_h($secUtf) . '</span><br>';
        }
        if ($end !== '' && $sec === '') {
            return maintenance_status_logistic_h($endUtf) . '<br>';
        }
        if ($end === '' && $sec !== '') {
            return '<span style="color:#0000FF">' . maintenance_status_logistic_h($secUtf) . '</span><br>';
        }

        return '';
    }
}

if (!function_exists('maintenance_qc_checklist_list_repair_html')) {
    /**
     * Purpose:
     * สร้าง HTML บล็อก «รายการที่เปลี่ยน/ซ่อม» — แสดงรายการซ่อมที่เสนอ/บันทึก และ `trp_repair_remarks` (สีแดง) หรือข้อความ «ไม่มี Comment» เมื่อว่าง
     *
     * Parameters สำคัญ:
     * - array $result_trp: แถว `transac_repair` (join) ที่มีฟิลด์รายการซ่อมและ remarks
     *
     * Return value:
     * - string HTML (มี `<span style="color:#008000">` นำหน้า — echo ตรง ๆ ห้ามห่อ htmlspecialchars ซ้ำทั้งก้อน)
     */
    function maintenance_qc_checklist_list_repair_html(array $result_trp)
    {
        $html = '<span style="color:#008000">รายการที่เปลี่ยน/ซ่อม</span><br>';
        $lines = array();
        for ($i = 1; $i <= 10; $i++) {
            $k = 'trp_repair_no' . $i;
            if (!empty($result_trp[$k])) {
                $lines[] = maintenance_status_logistic_h(maintenance_qc_checklist_db_text_to_utf8($result_trp[$k]));
            }
        }
        foreach (array('trp_clean', 'trp_repeat_solder', 'trp_spray', 'trp_fw') as $k) {
            if (!empty($result_trp[$k])) {
                $lines[] = maintenance_status_logistic_h(maintenance_qc_checklist_db_text_to_utf8($result_trp[$k]));
            }
        }
        if ($lines !== array()) {
            $html .= implode('<br>', $lines);
        }
        $remarks = isset($result_trp['trp_repair_remarks'])
            ? trim(maintenance_qc_checklist_db_text_to_utf8($result_trp['trp_repair_remarks']))
            : '';
        $html .= ($lines !== array() ? '<br>' : '');
        if ($remarks !== '') {
            $html .= '<span style="color:#FF0000">' . maintenance_status_logistic_h($remarks) . '</span>';
        } else {
            $html .= '<span style="color:#FF0000">' . maintenance_status_logistic_h('ไม่มี Comment') . '</span>';
        }

        return $html;
    }
}

if (!function_exists('maintenance_qc_checklist_history_html')) {
    /**
     * Purpose:
     * สร้าง HTML ประวัติซ่อมสำหรับหน้า QC — แสดงรายการแบบ «ครั้งที่ 1, 2, …» รวมงานปัจจุบัน (ไม่แยกหัวข้อ «ครั้งล่าสุด»)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection mysqli
     * - array $current_row: แถว join `transac_repair` + `transac` ของงานที่กำลัง QC (ใช้ดึง S/N)
     *
     * Return value:
     * - string HTML (มี tag — echo ตรง ๆ ห้ามห่อ htmlspecialchars ซ้ำทั้งก้อน)
     */
    function maintenance_qc_checklist_history_html($connect, array $current_row)
    {
        $sn = isset($current_row['trp_sn']) ? trim((string) $current_row['trp_sn']) : '';
        if ($sn === '' || $sn === '-') {
            return 'ไม่พบประวัติการซ่อม';
        }

        return maintenance_check_history_repair_sn_html($connect, $sn, '');
    }
}
