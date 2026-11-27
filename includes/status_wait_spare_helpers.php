<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `status_wait_spare.php` — รายการงาน «รอเครื่องสำรอง» ที่ JOIN `transac_spare` + `transac_repair` + `transac`
 * filter สินค้า/ที่อยู่ด้วย mysqli_prepare, prefetch `tracking_history` ต่อ `trp_id`
 *
 * Flow:
 * parse `$_GET` → `maintenance_status_wait_spare_fetch_join_rows()` → prefetch tracking (หน้าเรียก)
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_status_wait_spare_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_status_job` สำหรับหน้านี้ (ต้องตรงกับ `title-h` / query เดิม)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string `รอเครื่องสำรอง`
     */
    function maintenance_status_wait_spare_status_literal()
    {
        return 'รอเครื่องสำรอง';
    }
}

if (!function_exists('maintenance_status_wait_spare_parse_get_filters')) {
    /**
     * Purpose:
     * อ่าน filter จาก query string — ถ้ามีทั้ง `product` และ `addr` ให้ใช้ `product` ก่อน (สอดคล้อง `old_system/status_wait_spare.php`)
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET`
     *
     * Return value:
     * - array คีย์ `product`, `addr` (string)
     */
    function maintenance_status_wait_spare_parse_get_filters(array $get)
    {
        return array(
            'product' => isset($get['product']) ? trim((string) $get['product']) : '',
            'addr' => isset($get['addr']) ? trim((string) $get['addr']) : '',
        );
    }
}

if (!function_exists('maintenance_status_wait_spare_list_select_columns_sql')) {
    /**
     * Purpose:
     * รายการคอลัมน์ใน SELECT หลัก (ไม่ใช้ `SELECT *`)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ส่วนหลังคำว่า SELECT
     */
    function maintenance_status_wait_spare_list_select_columns_sql()
    {
        return 't.ts_id, t.ts_enduser_com, t.ts_security_com, t.ts_report_name, t.ts_report_tel, t.ts_report_line, t.ts_address, '
            . 'tr.trp_id, tr.trp_send_date, tr.trp_contact_pickup, tr.trp_date_pickup, '
            . 'sp.tsp_id, sp.tsp_trp_id, sp.tsp_product, sp.tsp_sn';
    }
}

if (!function_exists('maintenance_status_wait_spare_fetch_join_rows')) {
    /**
     * Purpose:
     * ดึงแถวรายการหลักด้วย prepared statement ตามสถานะและ filter
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $filters: ผลจาก `maintenance_status_wait_spare_parse_get_filters()`
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_status_wait_spare_fetch_join_rows($connect, array $filters)
    {
        $status = maintenance_status_wait_spare_status_literal();
        $cols = maintenance_status_wait_spare_list_select_columns_sql();
        $from = ' FROM ((transac_repair tr INNER JOIN transac_spare sp ON sp.tsp_trp_id = tr.trp_id) '
            . 'INNER JOIN transac t ON t.ts_id = tr.trp_ts_id) ';
        // ไม่แสดงเครื่องที่รับคืน/ยกเลิก/สูญหายแล้ว (ใบงานที่สลับเครื่องสำรองมีหลายแถว)
        require_once __DIR__ . '/spare_flow_helpers.php';
        mn_spare_ensure_table($connect);
        $where = ' WHERE tr.trp_status_job = ? '
            . " AND (sp.tsp_datereturn IS NULL OR TRIM(sp.tsp_datereturn) = '') "
            . " AND sp.tsp_id NOT IN (SELECT se_tsp_id FROM cl_spare_event WHERE se_type IN ('lost', 'cancel')) ";
        $types = 's';
        $bind = array($status);

        if ($filters['product'] !== '') {
            $where .= ' AND tr.trp_product = ? ';
            $types .= 's';
            $bind[] = $filters['product'];
        } elseif ($filters['addr'] !== '') {
            $where .= ' AND t.ts_address LIKE ? ';
            $types .= 's';
            $bind[] = '%' . $filters['addr'] . '%';
        }

        $sql = 'SELECT ' . $cols . $from . $where . ' ORDER BY tr.trp_id ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_wait_spare prepare: ' . mysqli_error($connect));
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
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('maintenance_status_wait_spare_contact_block_html')) {
    /**
     * Purpose:
     * HTML คอลัมน์บริษัท/ผู้ติดต่อ/Line + ส่วนที่อยู่ไฮไลต์ (ตำบล/อำเภอถ้ามี)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join
     *
     * Return value:
     * - string HTML
     */
    function maintenance_status_wait_spare_contact_block_html(array $row)
    {
        require_once __DIR__ . '/status_offer_price_helpers.php';
        require_once __DIR__ . '/status_logistic_helpers.php';
        $base = maintenance_status_offer_price_contact_block_html($row);
        $addr = isset($row['ts_address']) ? (string) $row['ts_address'] : '';
        $base .= maintenance_status_wait_spare_address_highlight_html($addr);

        return $base;
    }
}

if (!function_exists('maintenance_status_wait_spare_address_highlight_html')) {
    /**
     * Purpose:
     * แสดงท้ายที่อยู่แบบไฮไลต์สีน้ำเงิน — เริ่มจากคำว่า `ตำบล` / `ต.` หรือ `อำเภอ` / `อ.` ถ้าไม่พบให้แสดงทั้งบรรทัด
     *
     * Parameters สำคัญ:
     * - string $address: ที่อยู่เต็มจาก `ts_address`
     *
     * Return value:
     * - string HTML (ว่างถ้าไม่มีที่อยู่)
     */
    function maintenance_status_wait_spare_address_highlight_html($address)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $addr = trim((string) $address);
        if ($addr === '') {
            return '';
        }
        $start = null;
        $useMb = function_exists('mb_stripos') && function_exists('mb_substr');
        foreach (array('ตำบล', 'ต.') as $needle) {
            $p = $useMb ? mb_stripos($addr, $needle, 0, 'UTF-8') : stripos($addr, $needle);
            if ($p !== false) {
                $start = $p;
                break;
            }
        }
        if ($start === null) {
            foreach (array('อำเภอ', 'อ.') as $needle) {
                $p = $useMb ? mb_stripos($addr, $needle, 0, 'UTF-8') : stripos($addr, $needle);
                if ($p !== false) {
                    $start = $p;
                    break;
                }
            }
        }
        $fragment = $start !== null
            ? ($useMb ? mb_substr($addr, $start, null, 'UTF-8') : substr($addr, $start))
            : $addr;

        return '<br><span style="color:#0000FF">' . maintenance_status_logistic_h($fragment) . '</span>';
    }
}
