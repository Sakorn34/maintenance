<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `status_logistic_pickup.php` — รายการ `transac_repair` ที่แจ้งเข้ารับแล้วแต่ยังไม่มีวันที่รับจริง JOIN `transac` และ tracking สำรองล่าสุดต่อ `trp_id`
 *
 * Flow:
 * `maintenance_status_logistic_pickup_fetch_rows($connect)` → array แถวสำหรับตาราง
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_status_logistic_pickup_fetch_rows')) {
    /**
     * Purpose:
     * ดึงรายการแจ้งเข้ารับพัสดุ (มี `trp_contact_pickup` แต่ `trp_date_pickup` ยังว่าง) พร้อมข้อมูลลูกค้าและ tracking จาก `transac_spare` ล่าสุด
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (`$connect` จาก bootstrap)
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_status_logistic_pickup_fetch_rows($connect)
    {
        $sql = 'SELECT tr.trp_id, tr.trp_ts_id, tr.trp_product, tr.trp_sn, tr.trp_contact_pickup, '
            . 't.ts_id, t.ts_enduser_com, t.ts_security_com, t.ts_report_name, t.ts_report_tel, t.ts_address, '
            . '(SELECT sp.tsp_tracking FROM transac_spare sp WHERE sp.tsp_trp_id = tr.trp_id ORDER BY sp.tsp_id DESC LIMIT 1) AS spare_tsp_tracking '
            . 'FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id '
            . 'WHERE tr.trp_contact_pickup IS NOT NULL AND tr.trp_date_pickup IS NULL '
            . 'ORDER BY tr.trp_id ASC';
        $rows = array();
        $exe = mysqli_query($connect, $sql);
        if ($exe === false) {
            if (function_exists('maintenance_new_db_fail')) {
                maintenance_new_db_fail('status_logistic_pickup: ' . mysqli_error($connect));
            }
            exit('Error Query [' . htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') . ']');
        }
        while ($r = mysqli_fetch_assoc($exe)) {
            $rows[] = $r;
        }
        mysqli_free_result($exe);

        return $rows;
    }
}

if (!function_exists('maintenance_status_logistic_pickup_contact_html')) {
    /**
     * Purpose:
     * ประกอบ HTML คอลัมน์บริษัท/ผู้ติดต่อ/ที่อยู่ (ไม่รวม Line — สอดคล้องหน้าเก่า)
     *
     * Parameters สำคัญ:
     * - array $row: แถวที่มีฟิลด์ `ts_*`
     *
     * Return value:
     * - string HTML
     */
    function maintenance_status_logistic_pickup_contact_html(array $row)
    {
        require_once __DIR__ . '/status_wait_hardware_helpers.php';
        require_once __DIR__ . '/status_logistic_helpers.php';
        $block = maintenance_wait_hardware_contact_html($row);
        $addr = isset($row['ts_address']) ? (string) $row['ts_address'] : '';
        $block .= '<br>' . maintenance_status_logistic_h($addr);

        return $block;
    }
}
