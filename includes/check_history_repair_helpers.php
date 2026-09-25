<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * สร้าง HTML ประวัติการซ่อมตาม S/N แบบเดียวกับ `check_history_repair()` ใน `function_legacy.php`
 * แต่ใช้ prepared statement สำหรับค่า S/N — ต้องโหลด `function_legacy.php` แล้ว (ใช้ `strReplace()`)
 *
 * Flow:
 * หน้า `check_history_repair.php` เรียก `maintenance_check_history_repair_sn_html()` แล้ว `echo` ผลลัพธ์ (มี tag HTML อยู่แล้ว ห้ามห่อ `htmlspecialchars` ซ้ำทั้งก้อน)
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_check_history_tis620_to_utf8')) {
    /**
     * Purpose:
     * คืนข้อความสำหรับแสดงใน HTML — ถ้าเป็น UTF-8 อยู่แล้ว (MySQL utf8mb4) ไม่แปลงซ้ำ; ถ้าไม่ใช่ลองจาก TIS-620 (legacy)
     *
     * Parameters สำคัญ:
     * - string $value: ข้อความจากคอลัมน์ฐานข้อมูล
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_check_history_tis620_to_utf8($value)
    {
        $s = (string) $value;
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_check_encoding')) {
            if (mb_check_encoding($s, 'UTF-8')) {
                return $s;
            }
        } else {
            return $s;
        }
        $out = @iconv('TIS-620', 'UTF-8//IGNORE', $s);

        return ($out !== false && $out !== '') ? $out : $s;
    }
}

if (!function_exists('maintenance_check_history_repair_transac_row_html')) {
    /**
     * Purpose:
     * ประกอบ HTML หนึ่งรายการจากแถว join `transac` + `transac_repair` สำหรับแสดงในประวัติซ่อม
     *
     * Parameters สำคัญ:
     * - array $result_ctrp: แถว join ที่มีฟิลด์ ts_* และ trp_*
     * - string $heading_label: ข้อความหัวข้อ เช่น `ครั้งที่ 1` หรือ `ครั้งล่าสุด`
     *
     * Return value:
     * - string HTML (มี tag — echo ตรง ๆ ห้ามห่อ htmlspecialchars ซ้ำทั้งก้อน)
     */
    function maintenance_check_history_repair_transac_row_html(array $result_ctrp, $heading_label)
    {
        $repair_history = "<span style='color:#FF0000'><b>"
            . htmlspecialchars((string) $heading_label, ENT_QUOTES, 'UTF-8')
            . '</b></span><br>';
        $repair_history .= '<b>วันที่รับซ่อม</b> ' . htmlspecialchars((string) $result_ctrp['trp_receive_date'], ENT_QUOTES, 'UTF-8')
            . '  <b>เลขงานซ่อม</b> ' . htmlspecialchars((string) $result_ctrp['ts_mtn_number'], ENT_QUOTES, 'UTF-8') . '<br>';
        $repair_history .= '<b>บริษัท</b> ' . htmlspecialchars(maintenance_check_history_tis620_to_utf8(strReplace($result_ctrp['ts_security_com'])), ENT_QUOTES, 'UTF-8')
            . ' -- ' . htmlspecialchars(maintenance_check_history_tis620_to_utf8(strReplace($result_ctrp['ts_enduser_com'])), ENT_QUOTES, 'UTF-8') . '<br>';
        $repair_history .= '<b>สินค้า</b> ' . htmlspecialchars((string) $result_ctrp['trp_product'], ENT_QUOTES, 'UTF-8')
            . ' [ ' . htmlspecialchars((string) $result_ctrp['trp_sn'], ENT_QUOTES, 'UTF-8') . ' ] <br>';
        $repair_history .= '<b>อาการที่แจ้ง : </b>' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_inform']), ENT_QUOTES, 'UTF-8') . '<br>';
        $repair_history .= '<b>ประเมินโดน : </b>' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_user_rate']), ENT_QUOTES, 'UTF-8') . '<br>';
        $repair_history .= '<b>การประเมิน : </b>' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_technician']), ENT_QUOTES, 'UTF-8') . '<br>';
        $list_repair = '';
        if ($result_ctrp['trp_repair_no1'] !== '' && $result_ctrp['trp_repair_no1'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no1']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no2'] !== '' && $result_ctrp['trp_repair_no2'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no2']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no3'] !== '' && $result_ctrp['trp_repair_no3'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no3']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no4'] !== '' && $result_ctrp['trp_repair_no4'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no4']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no5'] !== '' && $result_ctrp['trp_repair_no5'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no5']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no6'] !== '' && $result_ctrp['trp_repair_no6'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no6']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no7'] !== '' && $result_ctrp['trp_repair_no7'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no7']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no8'] !== '' && $result_ctrp['trp_repair_no8'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no8']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no9'] !== '' && $result_ctrp['trp_repair_no9'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no9']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repair_no10'] !== '' && $result_ctrp['trp_repair_no10'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_no10']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_clean'] !== '' && $result_ctrp['trp_clean'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_clean']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_repeat_solder'] !== '' && $result_ctrp['trp_repeat_solder'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repeat_solder']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_spray'] !== '' && $result_ctrp['trp_spray'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_spray']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        if ($result_ctrp['trp_fw'] !== '' && $result_ctrp['trp_fw'] !== null) {
            $list_repair .= htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_fw']), ENT_QUOTES, 'UTF-8') . ', ';
        }
        $list_repair .= "<span style='color:#FF0000'>" . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_repair_remarks']), ENT_QUOTES, 'UTF-8') . '</span>';
        $repair_history .= '<b>รายการซ่อม : </b>' . $list_repair . '<br>';
        $repair_history .= '<b>ซ่อมโดย : </b>' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_user_ma']), ENT_QUOTES, 'UTF-8') . '<br>';
        $repair_history .= '<b>สถานซ่อม : </b><span style="color:#0000FF">' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_ctrp['trp_status_job']), ENT_QUOTES, 'UTF-8') . '</span><br><br>';

        return $repair_history;
    }
}

if (!function_exists('maintenance_check_history_repair_sn_html')) {
    /**
     * Purpose:
     * ดึงประวัติจาก `repair_history` + `transac`/`transac_repair` ตาม S/N แล้วประกอบเป็น HTML
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection mysqli
     * - string $sn: หมายเลข S/N
     * - string $exclude_ts_id: ถ้าไม่ว่าง จะข้ามแถวที่ `ts_id` ตรงกับค่านี้ (เทียบเท่า logic เดิมเมื่อส่ง ts_id เข้ามา)
     *
     * Return value:
     * - string HTML หรือข้อความว่าไม่พบประวัติ
     */
    function maintenance_check_history_repair_sn_html($connect, $sn, $exclude_ts_id = '')
    {
        $sn = trim((string) $sn);
        if ($sn === '' || $sn === '-') {
            return 'ไม่พบประวัติการซ่อม';
        }

        $repair_history = '';
        $num = 1;

        $sql_rh = 'SELECT * FROM repair_history WHERE rh_sn = ?';
        $stmt_rh = mysqli_prepare($connect, $sql_rh);
        if ($stmt_rh === false) {
            return 'ไม่พบประวัติการซ่อม';
        }
        mysqli_stmt_bind_param($stmt_rh, 's', $sn);
        mysqli_stmt_execute($stmt_rh);
        $res_rh = mysqli_stmt_get_result($stmt_rh);
        if ($res_rh !== false) {
            while ($result_rh = mysqli_fetch_array($res_rh, MYSQLI_ASSOC)) {
                $repair_history .= "<span style='color:#FF0000'><b>ครั้งที่ " . $num . "</b></span><br>";
                $num++;
                $repair_history .= '<b>วันที่แจ้งซ่อม</b> ' . htmlspecialchars((string) $result_rh['rh_date'], ENT_QUOTES, 'UTF-8')
                    . '  <b>เลขงานซ่อม</b> ' . htmlspecialchars((string) $result_rh['rh_mtn_number'], ENT_QUOTES, 'UTF-8') . '<br>';
                $repair_history .= '<b>บริษัท</b> ' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_rh['rh_com_security']), ENT_QUOTES, 'UTF-8')
                    . ' -- ' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_rh['rh_com_enduser']), ENT_QUOTES, 'UTF-8') . '<br>';
                $repair_history .= '<b>สินค้า</b> ' . htmlspecialchars((string) $result_rh['rh_product'], ENT_QUOTES, 'UTF-8')
                    . ' [ ' . htmlspecialchars((string) $result_rh['rh_sn'], ENT_QUOTES, 'UTF-8') . ' ] <br>';
                $repair_history .= '<b>ข้อมูลการซ่อม</b> <br>อาการที่แจ้ง : '
                    . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_rh['rh_inform_repair']), ENT_QUOTES, 'UTF-8')
                    . '<br> แก้ไขโดย : ' . htmlspecialchars(maintenance_check_history_tis620_to_utf8($result_rh['rh_repair']), ENT_QUOTES, 'UTF-8') . '<br><br>';
            }
            mysqli_free_result($res_rh);
        }
        mysqli_stmt_close($stmt_rh);

        $sql_ctrp = 'SELECT * FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id WHERE transac_repair.trp_sn = ?';
        $stmt_ct = mysqli_prepare($connect, $sql_ctrp);
        if ($stmt_ct === false) {
            if ($repair_history === '') {
                return 'ไม่พบประวัติการซ่อม';
            }

            return $repair_history;
        }
        mysqli_stmt_bind_param($stmt_ct, 's', $sn);
        mysqli_stmt_execute($stmt_ct);
        $res_ct = mysqli_stmt_get_result($stmt_ct);
        if ($res_ct !== false) {
            while ($result_ctrp = mysqli_fetch_array($res_ct, MYSQLI_ASSOC)) {
                $ts_row = isset($result_ctrp['ts_id']) ? (string) $result_ctrp['ts_id'] : '';
                if ($exclude_ts_id !== '' && $ts_row === (string) $exclude_ts_id) {
                    continue;
                }
                $repair_history .= maintenance_check_history_repair_transac_row_html($result_ctrp, 'ครั้งที่ ' . $num);
                $num++;
            }
            mysqli_free_result($res_ct);
        }
        mysqli_stmt_close($stmt_ct);

        if ($repair_history === '') {
            return 'ไม่พบประวัติการซ่อม';
        }

        return $repair_history;
    }
}
