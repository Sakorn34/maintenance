<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper โหมด «เพิ่มอุปกรณ์งานซ่อมเดิม» บนหน้า add_job — คัดเลือก Transac ที่ยังไม่มีแถว Transac_repair ในสถานะที่ปิดงานแล้ว
 *
 * Flow:
 * `maintenance_add_job_existing_transac_blocked_statuses()` → `maintenance_add_job_existing_transac_is_eligible()` / `maintenance_add_job_existing_transac_list()`
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

if (!defined('MAINTENANCE_ADD_JOB_EXISTING_LIST_LIMIT')) {
    /** จำนวนแถวสูงสุดใน modal เลือกงานเดิม */
    define('MAINTENANCE_ADD_JOB_EXISTING_LIST_LIMIT', 150);
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_add_job_existing_transac_blocked_statuses')) {
    /**
     * Purpose:
     * รายการ `trp_status_job` ที่ถ้ามีอย่างน้อยหนึ่งแถวต่อ `ts_id` แล้วงานนั้นไม่ให้เพิ่มเครื่องผ่านโหมดนี้
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array ของ string สถานะ
     */
    function maintenance_add_job_existing_transac_blocked_statuses()
    {
        return array(
            'รออนุมัติซ่อม',
            'รอซ่อม',
            'รอส่งคืนเครื่องซ่อม',
            'รอเครื่องสำรอง',
            'เสร็จสิ้น',
        );
    }
}

if (!function_exists('maintenance_add_job_existing_transac_blocked_sql_in')) {
    /**
     * Purpose:
     * สร้างชุดค่าใน IN (...) สำหรับสถานะที่ escape แล้ว
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - string เช่น `'เสร็จสิ้น','รอซ่อม'`
     */
    function maintenance_add_job_existing_transac_blocked_sql_in($connect)
    {
        $parts = array();
        foreach (maintenance_add_job_existing_transac_blocked_statuses() as $st) {
            $parts[] = "'" . mysqli_real_escape_string($connect, $st) . "'";
        }
        return implode(',', $parts);
    }
}

if (!function_exists('maintenance_add_job_existing_transac_is_eligible')) {
    /**
     * Purpose:
     * ตรวจว่า `ts_id` ยังเพิ่มเครื่องซ่อมได้ (ไม่มีแถว repair ในสถานะที่บล็อก)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัส Transac
     *
     * Return value:
     * - bool true เมื่อเพิ่มได้
     */
    function maintenance_add_job_existing_transac_is_eligible($connect, $ts_id)
    {
        $ts_id = (int) $ts_id;
        if ($ts_id <= 0 || !$connect) {
            return false;
        }
        $in = maintenance_add_job_existing_transac_blocked_sql_in($connect);
        $sql = 'SELECT COUNT(*) AS c FROM transac_repair WHERE trp_ts_id = ' . $ts_id
            . ' AND trp_status_job IN (' . $in . ')';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if (!$exe) {
            return false;
        }
        $row = maintenance_db_odbc_fetch_array($exe);
        $c = $row && isset($row['c']) ? (int) $row['c'] : 0;
        return $c === 0;
    }
}

if (!function_exists('maintenance_add_job_existing_transac_fetch_one')) {
    /**
     * Purpose:
     * ดึงแถว Transac หนึ่งรายการสำหรับแสดงสรุปหลังเลือก
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $ts_id: รหัส Transac
     *
     * Return value:
     * - array|null แถว associative หรือ null
     */
    function maintenance_add_job_existing_transac_fetch_one($connect, $ts_id)
    {
        $ts_id = (int) $ts_id;
        if ($ts_id <= 0 || !$connect) {
            return null;
        }
        $sql = 'SELECT ts_id, ts_mtn_number, ts_enduser_com, ts_security_com, ts_date FROM transac WHERE ts_id = ' . $ts_id . ' LIMIT 1';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if (!$exe) {
            return null;
        }
        $row = maintenance_db_odbc_fetch_array($exe);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('maintenance_add_job_existing_transac_list')) {
    /**
     * Purpose:
     * รายการ Transac ที่เลือกเพิ่มเครื่องได้ — ไม่มี repair ในสถานะที่บล็อก; รองรับค้นหาเลขที่ซ่อม/บริษัท
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $term: คำค้น (ว่าง = ทั้งหมดภายใน limit)
     *
     * Return value:
     * - array ของแถว associative
     */
    function maintenance_add_job_existing_transac_list($connect, $term = '')
    {
        if (!$connect) {
            return array();
        }
        $in = maintenance_add_job_existing_transac_blocked_sql_in($connect);
        $conds = array(
            'NOT EXISTS (SELECT 1 FROM transac_repair r WHERE r.trp_ts_id = t.ts_id AND r.trp_status_job IN (' . $in . '))',
        );
        $term = trim((string) $term);
        if ($term !== '') {
            $e = mysqli_real_escape_string($connect, $term);
            $conds[] = "(t.ts_mtn_number LIKE '%" . $e . "%' OR t.ts_enduser_com LIKE '%" . $e . "%' OR t.ts_security_com LIKE '%" . $e . "%')";
        }
        $where = ' WHERE ' . implode(' AND ', $conds);
        $limit = (int) MAINTENANCE_ADD_JOB_EXISTING_LIST_LIMIT;
        $sql = 'SELECT t.ts_id, t.ts_mtn_number, t.ts_enduser_com, t.ts_security_com, t.ts_date'
            . ' FROM transac t' . $where
            . ' ORDER BY t.ts_id DESC LIMIT ' . $limit;
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if (!$exe) {
            return array();
        }
        $out = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
