<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ดึงประวัติการส่งสำรองตาม S/N (`transac_spare` + `transac`) สำหรับหน้า `check_history_spare` — prepared statement, คอลัมน์ระบุชัด
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_check_history_spare_iconv')) {
    /**
     * Purpose:
     * คืนข้อความ UTF-8 สำหรับฟิลด์ comment จาก `transac` — ถ้าเป็น UTF-8 อยู่แล้วไม่แปลงซ้ำ; ถ้าไม่ใช่ลองจาก TIS-620 (legacy)
     *
     * Parameters สำคัญ:
     * - string $value: ค่าจากฐานข้อมูล
     *
     * Return value:
     * - string สำหรับแสดงใน HTML (ยังต้อง `htmlspecialchars` ตอน echo)
     */
    function maintenance_check_history_spare_iconv($value)
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

if (!function_exists('maintenance_check_history_spare_fetch_rows')) {
    /**
     * Purpose:
     * ดึงแถวประวัติส่งสำรองที่ `tsp_sn` ตรงกับค่าที่ระบุ เรียงตาม `tsp_ts_id`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $sn: หมายเลข S/N (trim แล้วจากหน้าเรียก)
     *
     * Return value:
     * - array ของแถว associative (คีย์ tsp_* / ts_* ตาม SELECT)
     */
    function maintenance_check_history_spare_fetch_rows($connect, $sn)
    {
        $sql = 'SELECT tsp.tsp_product, tsp.tsp_sn, tsp.tsp_datesend, tsp.tsp_logistics, tsp.tsp_tracking, '
            . 'tsp.tsp_sendby, tsp.tsp_datereturn, tsp.tsp_receiveby, '
            . 'ts.ts_security_com, ts.ts_enduser_com '
            . 'FROM transac ts '
            . 'INNER JOIN transac_spare tsp ON ts.ts_id = tsp.tsp_ts_id '
            . 'WHERE tsp.tsp_sn = ? '
            . 'ORDER BY tsp.tsp_ts_id ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 's', $sn);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = array();
        if ($res !== false) {
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $rows;
    }
}
