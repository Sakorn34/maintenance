<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper สำหรับหน้า flow รับเครื่อง/สำรอง/pickup/tracking — ดึงข้อมูลแบบ JOIN ครั้งเดียว และ parse GET แบบปลอดภัย
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_flow_get_int')) {
    /**
     * Purpose:
     * อ่านจำนวนเต็มจาก array (มักเป็น `$_GET` / `$_POST`) พร้อมค่า default
     *
     * Parameters สำคัญ:
     * - array $src: แหล่งข้อมูล
     * - string $key: ชื่อ key
     * - int $default: ค่าถ้าไม่มีหรือไม่ใช่ตัวเลข
     *
     * Return value:
     * - int >= 0
     */
    function maintenance_flow_get_int(array $src, $key, $default = 0)
    {
        if (!isset($src[$key])) {
            return (int) $default;
        }
        $v = filter_var($src[$key], FILTER_VALIDATE_INT);

        return $v === false ? (int) $default : max(0, (int) $v);
    }
}

if (!function_exists('maintenance_flow_db_text_to_utf8')) {
    /**
     * Purpose:
     * แปลงข้อความจากคอลัมน์ DB (TIS-620 หรือ UTF-8) เป็น UTF-8 สำหรับแสดงในหน้า flow — เทียบ `maintenance_qc_checklist_db_text_to_utf8()`
     *
     * Parameters สำคัญ:
     * - mixed $value: ค่าจาก mysqli
     *
     * Return value:
     * - string
     */
    function maintenance_flow_db_text_to_utf8($value)
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

if (!function_exists('maintenance_flow_mysqli_stmt_fetch_assoc')) {
    /**
     * Purpose:
     * อ่านแถวเดียวจาก prepared statement — รองรับ PHP ที่ไม่มี mysqlnd (`mysqli_stmt_get_result`)
     *
     * Parameters สำคัญ:
     * - mysqli_stmt $stmt: statement หลัง execute
     *
     * Return value:
     * - array|false
     */
    function maintenance_flow_mysqli_stmt_fetch_assoc($stmt)
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
        if (!($meta instanceof mysqli_result)) {
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

if (!function_exists('maintenance_flow_fetch_repair_with_transac')) {
    /**
     * Purpose:
     * ดึงแถว `transac_repair` คู่กับ `transac` ตาม `trp_id` ในคำสั่งเดียว (ลด round-trip)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัสแถวซ่อม
     *
     * Return value:
     * - array|null แถวรวมคีย์ของทั้งสองตาราง (รวม `ts_mtn_number` สำหรับแสดงเลขงาน) หรือ null ถ้าไม่พบ
     */
    function maintenance_flow_fetch_repair_with_transac($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) {
            return null;
        }
        $sql = 'SELECT tr.trp_id AS trp_id, tr.trp_ts_id AS trp_ts_id, tr.trp_product AS trp_product, tr.trp_sn AS trp_sn, '
            . 'tr.trp_repair_inform AS trp_repair_inform, tr.trp_repair_age AS trp_repair_age, tr.trp_insurance AS trp_insurance, '
            . 'tr.trp_backup AS trp_backup, tr.trp_buy_date AS trp_buy_date, tr.trp_receive_date AS trp_receive_date, '
            . 'tr.trp_status_job AS trp_status_job, tr.trp_user_recive_ma AS trp_user_recive_ma, '
            . 'ts.ts_mtn_number AS ts_mtn_number, ts.ts_enduser_com AS ts_enduser_com, ts.ts_security_com AS ts_security_com, '
            . 'ts.ts_report_name AS ts_report_name, ts.ts_report_tel AS ts_report_tel, ts.ts_address AS ts_address '
            . 'FROM transac_repair tr INNER JOIN transac ts ON ts.ts_id = tr.trp_ts_id WHERE tr.trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('maintenance_flow_fetch_transac_row')) {
    /**
     * Purpose:
     * ดึงแถว `transac` ตาม `ts_id`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัสใบงาน
     *
     * Return value:
     * - array|null
     */
    function maintenance_flow_fetch_transac_row($connect, $ts_id)
    {
        $id = (int) $ts_id;
        if ($id < 1) {
            return null;
        }
        $sql = 'SELECT ts_id, ts_enduser_com, ts_security_com, ts_report_name, ts_report_tel FROM transac WHERE ts_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('maintenance_flow_fetch_tracking_history_rows')) {
    /**
     * Purpose:
     * ดึงประวัติติดตามตาม `th_trp_id` เรียงวันที่ (ไม่ JOIN โต๊ะที่ไม่จำเป็น)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัสแถวซ่อม
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_flow_fetch_tracking_history_rows($connect, $trp_id)
    {
        $id = (int) $trp_id;
        $out = array();
        if ($id < 1) {
            return $out;
        }
        $sql = 'SELECT th_date_tracking, th_date_nextdue, th_status_job, th_detail, th_user '
            . 'FROM tracking_history WHERE th_trp_id = ? ORDER BY th_date_tracking ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return $out;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $out[] = $r;
            }
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}
