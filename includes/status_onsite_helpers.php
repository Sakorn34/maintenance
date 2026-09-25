<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ดึงและประกอบข้อมูลหน้า `status_onsite` — JOIN หลักแบบ prepared, batch ดึง spare / tracking ลด round-trip
 * ต้องโหลด `function_legacy.php` แล้ว (ใช้ `strReplace()`, `cal_wait_date()`, `cal_tracking_due()`)
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_status_onsite_iconv')) {
    /**
     * Purpose:
     * คืนข้อความสำหรับแสดงผล — ถ้าเป็น UTF-8 อยู่แล้ว (ข้อมูลจาก MySQL หลัง charset utf8) ไม่แปลงซ้ำ
     * ถ้าไม่ใช่ UTF-8 ลองแปลงจาก TIS-620 (ข้อมูล legacy Access) เป็น UTF-8
     *
     * Parameters สำคัญ:
     * - string $value: ข้อความจากฐานข้อมูล
     *
     * Return value:
     * - string UTF-8
     */
    function maintenance_status_onsite_iconv($value)
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

if (!function_exists('maintenance_status_onsite_area_summary')) {
    /**
     * Purpose:
     * สรุปบรรทัดที่อยู่สำหรับคอลัมน์บริษัท (ตัด prefix ต./ตำบล/ถ. แบบเดียวกับเจตนา legacy แต่แก้ index ที่ผิด)
     *
     * Parameters สำคัญ:
     * - string $ts_address: ที่อยู่เต็มจาก `transac.ts_address`
     *
     * Return value:
     * - string ข้อความสั้น ๆ สำหรับแสดงใต้บรรทัดลูกค้า
     */
    function maintenance_status_onsite_area_summary($ts_address)
    {
        $a = (string) $ts_address;
        if ($a === '') {
            return '';
        }
        $pick = function ($hay, $needle, $prefix) {
            if (function_exists('mb_stripos')) {
                $p = mb_stripos($hay, $needle, 0, 'UTF-8');
                if ($p !== false) {
                    $rest = mb_substr($hay, $p + mb_strlen($needle, 'UTF-8'), null, 'UTF-8');

                    return $prefix . $rest;
                }
            } else {
                $p = stripos($hay, $needle);
                if ($p !== false) {
                    return $prefix . substr($hay, $p + strlen($needle));
                }
            }

            return null;
        };
        foreach (array(array('ต.', 'ตำบล'), array('ตำบล', 'ตำบล'), array('ถ.', 'ถนน'), array('ถนน', 'ถนน')) as $pair) {
            $got = $pick($a, $pair[0], $pair[1]);
            if ($got !== null) {
                return $got;
            }
        }

        return $a;
    }
}

if (!function_exists('maintenance_status_onsite_bind_stmt_params')) {
    /**
     * Purpose:
     * bind พารามิเตอร์แบบ dynamic types ให้ `mysqli_stmt`
     *
     * Parameters สำคัญ:
     * - mysqli_stmt $stmt: statement
     * - string $types: string ชนิด mysqli เช่น `ssss`
     * - array $params: ค่าเรียงตาม types
     *
     * Return value:
     * - bool สำเร็จหรือไม่
     */
    function maintenance_status_onsite_bind_stmt_params($stmt, $types, array $params)
    {
        $refs = array();
        $refs[] = $types;
        foreach ($params as $i => $_v) {
            $refs[] = &$params[$i];
        }

        return call_user_func_array(array($stmt, 'bind_param'), $refs);
    }
}

if (!function_exists('maintenance_status_onsite_fetch_product_options')) {
    /**
     * Purpose:
     * รายการ `trp_product` ที่มีงานสถานะรอซ่อม/รอสำรอง (สำหรับ dropdown)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array ของ string
     */
    function maintenance_status_onsite_fetch_product_options($connect)
    {
        $s1 = 'รอเครื่องซ่อม';
        $s2 = 'รอเครื่องสำรอง';
        $sql = 'SELECT DISTINCT trp_product FROM transac_repair WHERE trp_status_job IN (?, ?) ORDER BY trp_product ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 'ss', $s1, $s2);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res !== false) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (!empty($row['trp_product'])) {
                    $out[] = (string) $row['trp_product'];
                }
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_status_onsite_fetch_main_rows')) {
    /**
     * Purpose:
     * ดึงแถวหลัก transac + transac_repair ตามสถานะและตัวกรองที่อยู่ / product
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $addr_filter: ถ้าไม่ว่าง ใช้ `LIKE` กับ `ts_address`
     * - string $product_filter: ถ้าไม่ว่าง กรอง `trp_product` เท่ากับค่าที่ส่งมา
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_status_onsite_fetch_main_rows($connect, $addr_filter, $product_filter)
    {
        $st1 = 'รอเครื่องซ่อม';
        $st2 = 'รอเครื่องสำรอง';
        $sql = 'SELECT t.ts_id, t.ts_mtn_number, t.ts_enduser_com, t.ts_security_com, t.ts_report_name, t.ts_report_tel, t.ts_address, t.ts_date, '
            . 'r.trp_id, r.trp_ts_id, r.trp_product, r.trp_sn, r.trp_repair_inform, r.trp_status_job '
            . 'FROM transac t INNER JOIN transac_repair r ON t.ts_id = r.trp_ts_id '
            . 'WHERE (r.trp_status_job = ? OR r.trp_status_job = ?)';
        $types = 'ss';
        $params = array($st1, $st2);
        $addr_filter = trim((string) $addr_filter);
        $product_filter = trim((string) $product_filter);
        if ($addr_filter !== '') {
            $sql .= ' AND t.ts_address LIKE ?';
            $types .= 's';
            $params[] = '%' . $addr_filter . '%';
        }
        if ($product_filter !== '') {
            $sql .= ' AND r.trp_product = ?';
            $types .= 's';
            $params[] = $product_filter;
        }
        $sql .= ' ORDER BY r.trp_status_job ASC';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        maintenance_status_onsite_bind_stmt_params($stmt, $types, $params);
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

if (!function_exists('maintenance_status_onsite_fetch_spare_map')) {
    /**
     * Purpose:
     * คืน map `trp_id` => แถว spare ล่าสุด (เลือก `tsp_id` สูงสุดต่อ `tsp_trp_id`)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int[] $trp_ids: รายการ `trp_id`
     *
     * Return value:
     * - array key int trp_id => array|null
     */
    function maintenance_status_onsite_fetch_spare_map($connect, array $trp_ids)
    {
        $trp_ids = array_values(array_unique(array_map('intval', $trp_ids)));
        $trp_ids = array_filter($trp_ids, function ($v) {
            return $v > 0;
        });
        if ($trp_ids === array()) {
            return array();
        }
        $in = implode(',', $trp_ids);
        $sql = 'SELECT tsp_trp_id, tsp_sn, tsp_id FROM transac_spare WHERE tsp_trp_id IN (' . $in . ') ORDER BY tsp_trp_id ASC, tsp_id DESC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $map = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            $tid = isset($row['tsp_trp_id']) ? (int) $row['tsp_trp_id'] : 0;
            if ($tid > 0 && !isset($map[$tid])) {
                $map[$tid] = $row;
            }
        }

        return $map;
    }
}

if (!function_exists('maintenance_status_onsite_fetch_tracking_map')) {
    /**
     * Purpose:
     * คืน map `trp_id` => แถว tracking ล่าสุดตาม `th_date_nextdue` DESC
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int[] $trp_ids: รายการ `trp_id`
     *
     * Return value:
     * - array key int trp_id => array|null
     */
    function maintenance_status_onsite_fetch_tracking_map($connect, array $trp_ids)
    {
        $trp_ids = array_values(array_unique(array_map('intval', $trp_ids)));
        $trp_ids = array_filter($trp_ids, function ($v) {
            return $v > 0;
        });
        if ($trp_ids === array()) {
            return array();
        }
        $in = implode(',', $trp_ids);
        $sql = 'SELECT th_trp_id, th_date_tracking, th_date_nextdue, th_status_job, th_detail, th_user FROM tracking_history WHERE th_trp_id IN ('
            . $in . ') ORDER BY th_trp_id ASC, th_date_nextdue DESC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $map = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            $tid = isset($row['th_trp_id']) ? (int) $row['th_trp_id'] : 0;
            if ($tid > 0 && !isset($map[$tid])) {
                $map[$tid] = $row;
            }
        }

        return $map;
    }
}

if (!function_exists('maintenance_status_onsite_company_block_html')) {
    /**
     * Purpose:
     * สร้าง HTML บล็อกบริษัท + ผู้แจ้ง + บรรทัดพื้นที่ (มี tag สี — ห้ามห่อ `htmlspecialchars` ซ้ำทั้งก้อนเมื่อ echo)
     *
     * Parameters สำคัญ:
     * - array $row: แถวจาก `maintenance_status_onsite_fetch_main_rows`
     *
     * Return value:
     * - string HTML
     */
    function maintenance_status_onsite_company_block_html(array $row)
    {
        $eu = isset($row['ts_enduser_com']) ? (string) $row['ts_enduser_com'] : '';
        $sec = isset($row['ts_security_com']) ? (string) $row['ts_security_com'] : '';
        $name = isset($row['ts_report_name']) ? (string) $row['ts_report_name'] : '';
        $tel = isset($row['ts_report_tel']) ? (string) $row['ts_report_tel'] : '';
        $addr = isset($row['ts_address']) ? (string) $row['ts_address'] : '';

        $detail = '';
        if ($eu !== '' && $sec !== '') {
            $detail .= htmlspecialchars(maintenance_status_onsite_iconv(strReplace($eu)), ENT_QUOTES, 'UTF-8');
            $detail .= '<br><span style="color:#0000FF">' . htmlspecialchars(maintenance_status_onsite_iconv(strReplace($sec)), ENT_QUOTES, 'UTF-8') . '</span><br>';
        } elseif ($eu !== '' && $sec === '') {
            $detail .= htmlspecialchars(maintenance_status_onsite_iconv(strReplace($eu)), ENT_QUOTES, 'UTF-8') . '<br>';
        } elseif ($eu === '' && $sec !== '') {
            $detail .= '<span style="color:#0000FF">' . htmlspecialchars(maintenance_status_onsite_iconv(strReplace($sec)), ENT_QUOTES, 'UTF-8') . '</span><br>';
        }
        $detail .= htmlspecialchars($name . '  ' . $tel, ENT_QUOTES, 'UTF-8') . '<br>';
        $area = maintenance_status_onsite_area_summary($addr);
        $detail .= '<br><span style="color:#0000FF">' . htmlspecialchars($area, ENT_QUOTES, 'UTF-8') . '</span>';

        return $detail;
    }
}

if (!function_exists('maintenance_status_onsite_tracking_title_attr')) {
    /**
     * Purpose:
     * สร้างค่า `title` สำหรับคอลัมน์ติดตาม (ข้อความหลายบรรทัด)
     *
     * Parameters สำคัญ:
     * - array|null $th: แถวจาก `tracking_history` หรือ null
     *
     * Return value:
     * - string ปลอดภัยสำหรับ attribute
     */
    function maintenance_status_onsite_tracking_title_attr($th)
    {
        if ($th === null || !is_array($th)) {
            return '';
        }
        $parts = array(
            'ติดตามล่าสุด :: ' . maintenance_status_onsite_iconv(isset($th['th_date_tracking']) ? $th['th_date_tracking'] : ''),
            'ติดตามครั้งถัดไป :: ' . maintenance_status_onsite_iconv(isset($th['th_date_nextdue']) ? $th['th_date_nextdue'] : ''),
            'เรื่องที่ตาม :: ' . maintenance_status_onsite_iconv(isset($th['th_status_job']) ? $th['th_status_job'] : ''),
            'รายละเอียด :: ' . maintenance_status_onsite_iconv(isset($th['th_detail']) ? $th['th_detail'] : ''),
            'ผู้ติดตาม :: ' . maintenance_status_onsite_iconv(isset($th['th_user']) ? $th['th_user'] : ''),
        );
        $t = implode("\n", $parts);

        return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('maintenance_status_onsite_editspare_plain')) {
    /**
     * Purpose:
     * ข้อความลูกค้า/ผู้แจ้งแบบ plain สำหรับส่งต่อใน URL หรือ hidden field
     *
     * Parameters สำคัญ:
     * - array $row: แถวหลัก
     *
     * Return value:
     * - string
     */
    function maintenance_status_onsite_editspare_plain(array $row)
    {
        $eu = isset($row['ts_enduser_com']) ? (string) $row['ts_enduser_com'] : '';
        $sec = isset($row['ts_security_com']) ? (string) $row['ts_security_com'] : '';
        $name = isset($row['ts_report_name']) ? (string) $row['ts_report_name'] : '';
        $tel = isset($row['ts_report_tel']) ? (string) $row['ts_report_tel'] : '';
        $lines = array();
        if ($eu !== '' && $sec !== '') {
            $lines[] = strReplace($eu);
            $lines[] = strReplace($sec);
        } elseif ($eu !== '') {
            $lines[] = strReplace($eu);
        } elseif ($sec !== '') {
            $lines[] = strReplace($sec);
        }
        $lines[] = $name . '  ' . $tel;

        return implode("\n", $lines);
    }
}
