<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper สำหรับ `insert_job.php` — อ่าน/กำหนด `trp_id` หลัง INSERT `transac_repair` ให้ถูกต้องทั้งกรณีมีและไม่มี AUTO_INCREMENT
 *
 * องค์ประกอบหลัก:
 * - `maintenance_insert_job_max_positive_trp_id()` — MAX(trp_id) ที่ > 0
 * - `maintenance_insert_job_assign_trp_id_for_row()` — UPDATE แถวที่ trp_id = 0 ตาม ts_id + product (+ sn / status)
 * - `maintenance_insert_job_last_trp_id()` — mysqli_insert_id แล้ว fallback กำหนดเลขเมื่อ insert_id เป็น 0
 *
 * Flow:
 * `insert_job` เรียกหลัง INSERT repair แต่ละเครื่อง พร้อม trp_ts_id, product, sn, status — ค่าที่ได้ใช้เป็น `tsp_trp_id`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_insert_job_max_positive_trp_id')) {
    /**
     * Purpose:
     * หา `trp_id` สูงสุดที่มากกว่า 0 ในตาราง `transac_repair`
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection
     *
     * Return value:
     * - int
     */
    function maintenance_insert_job_max_positive_trp_id($mysqli)
    {
        if (!($mysqli instanceof mysqli)) {
            return 0;
        }
        $res = mysqli_query($mysqli, 'SELECT COALESCE(MAX(trp_id), 0) AS mx FROM transac_repair WHERE trp_id > 0');
        if (!$res) {
            return 0;
        }
        $row = mysqli_fetch_assoc($res);
        mysqli_free_result($res);

        return isset($row['mx']) ? (int) $row['mx'] : 0;
    }
}

if (!function_exists('maintenance_insert_job_lookup_trp_id_by_row')) {
    /**
     * Purpose:
     * ค้น `trp_id` จากคีย์ธุรกิจของแถว repair (ใช้หลังกำหนดเลขหรือเมื่อ insert_id ไม่น่าเชื่อถือ)
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection
     * - int $ts_id: `trp_ts_id`
     * - string $product: `trp_product`
     * - string $sn: `trp_sn`
     * - string $status_job: `trp_status_job`
     *
     * Return value:
     * - int trp_id หรือ 0
     */
    function maintenance_insert_job_lookup_trp_id_by_row($mysqli, $ts_id, $product, $sn = '', $status_job = '')
    {
        $ts_id = (int) $ts_id;
        if ($ts_id < 1 || $product === '') {
            return 0;
        }
        $sql = 'SELECT trp_id FROM transac_repair WHERE trp_ts_id = ' . $ts_id . ' AND trp_id > 0'
            . " AND TRIM(COALESCE(trp_product, '')) = TRIM('" . mysqli_real_escape_string($mysqli, $product) . "')";
        if ($sn !== '') {
            $sql .= " AND TRIM(COALESCE(trp_sn, '')) = TRIM('" . mysqli_real_escape_string($mysqli, $sn) . "')";
        }
        if ($status_job !== '') {
            $sql .= " AND TRIM(COALESCE(trp_status_job, '')) = TRIM('" . mysqli_real_escape_string($mysqli, $status_job) . "')";
        }
        $sql .= ' ORDER BY trp_id DESC LIMIT 1';
        $res = mysqli_query($mysqli, $sql);
        if (!$res) {
            return 0;
        }
        $row = mysqli_fetch_assoc($res);
        mysqli_free_result($res);

        return (is_array($row) && !empty($row['trp_id'])) ? (int) $row['trp_id'] : 0;
    }
}

if (!function_exists('maintenance_insert_job_assign_trp_id_for_row')) {
    /**
     * Purpose:
     * กำหนด `trp_id` ให้แถว repair ที่ยังเป็น 0/NULL ตาม ts_id + product (+ sn / status) — ใช้เมื่อตารางยังไม่มี AUTO_INCREMENT
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection
     * - int $ts_id: `trp_ts_id`
     * - string $product: `trp_product`
     * - string $sn: `trp_sn`
     * - string $status_job: `trp_status_job`
     *
     * Return value:
     * - int trp_id ที่กำหนดแล้ว หรือ 0
     */
    function maintenance_insert_job_assign_trp_id_for_row($mysqli, $ts_id, $product, $sn = '', $status_job = '')
    {
        $ts_id = (int) $ts_id;
        if ($ts_id < 1 || $product === '' || !($mysqli instanceof mysqli)) {
            return 0;
        }
        $existing = maintenance_insert_job_lookup_trp_id_by_row($mysqli, $ts_id, $product, $sn, $status_job);
        if ($existing > 0) {
            return $existing;
        }
        $next = maintenance_insert_job_max_positive_trp_id($mysqli) + 1;
        $sql = 'UPDATE transac_repair SET trp_id = ' . (int) $next
            . ' WHERE (trp_id IS NULL OR trp_id <= 0) AND trp_ts_id = ' . $ts_id
            . " AND TRIM(COALESCE(trp_product, '')) = TRIM('" . mysqli_real_escape_string($mysqli, $product) . "')";
        if ($sn !== '') {
            $sql .= " AND TRIM(COALESCE(trp_sn, '')) = TRIM('" . mysqli_real_escape_string($mysqli, $sn) . "')";
        }
        if ($status_job !== '') {
            $sql .= " AND TRIM(COALESCE(trp_status_job, '')) = TRIM('" . mysqli_real_escape_string($mysqli, $status_job) . "')";
        }
        $sql .= ' LIMIT 1';
        if (!mysqli_query($mysqli, $sql) || mysqli_affected_rows($mysqli) < 1) {
            return maintenance_insert_job_lookup_trp_id_by_row($mysqli, $ts_id, $product, $sn, $status_job);
        }

        return $next;
    }
}

if (!function_exists('maintenance_insert_job_last_trp_id')) {
    /**
     * Purpose:
     * คืน `trp_id` ของแถว `transac_repair` ที่ INSERT ล่าสุด — ใช้ `mysqli_insert_id` ก่อน แล้วกำหนดเลขเองเมื่อไม่มี AUTO_INCREMENT
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection เดียวกับที่เพิ่งรัน INSERT
     * - int $ts_id: `trp_ts_id` ของแถวที่เพิ่ง insert (แนะนำส่งทุกครั้ง)
     * - string $product: `trp_product`
     * - string $sn: `trp_sn`
     * - string $status_job: `trp_status_job`
     *
     * Return value:
     * - string รหัสเป็นตัวเลข (ถ้าไม่พบคืน `'0'`)
     */
    function maintenance_insert_job_last_trp_id($mysqli, $ts_id = 0, $product = '', $sn = '', $status_job = '')
    {
        if (!($mysqli instanceof mysqli)) {
            return '0';
        }
        if (mysqli_affected_rows($mysqli) < 1) {
            return '0';
        }
        $id = (int) mysqli_insert_id($mysqli);
        if ($id > 0) {
            return (string) $id;
        }
        $ts_id = (int) $ts_id;
        if ($ts_id > 0 && $product !== '') {
            $assigned = maintenance_insert_job_assign_trp_id_for_row($mysqli, $ts_id, (string) $product, (string) $sn, (string) $status_job);
            if ($assigned > 0) {
                return (string) $assigned;
            }
        }
        if ($ts_id > 0 && $product !== '') {
            $found = maintenance_insert_job_lookup_trp_id_by_row($mysqli, $ts_id, (string) $product, (string) $sn, (string) $status_job);
            if ($found > 0) {
                return (string) $found;
            }
        }

        return '0';
    }
}

if (!function_exists('maintenance_insert_job_request_input')) {
    /**
     * Purpose:
     * อ่านพารามิเตอร์จากฟอร์ม add_job — ใช้ POST เป็นหลัก (รองรับ `&` ในข้อความ) fallback GET สำหรับลิงก์เดิม
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array คีย์ => ค่า string/scalar
     */
    function maintenance_insert_job_request_input()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST' && !empty($_POST)) {
            return $_POST;
        }

        return isset($_GET) && is_array($_GET) ? $_GET : array();
    }
}

if (!function_exists('maintenance_insert_job_input_string')) {
    /**
     * Purpose:
     * อ่าน string จาก input array ของ insert_job
     *
     * Parameters สำคัญ:
     * - array $input: มักจาก `maintenance_insert_job_request_input()`
     * - string $key: ชื่อพารามิเตอร์
     * - string $default: ค่าเมื่อไม่มีคีย์
     *
     * Return value:
     * - string
     */
    function maintenance_insert_job_input_string(array $input, $key, $default = '')
    {
        return isset($input[$key]) ? (string) $input[$key] : $default;
    }
}

if (!function_exists('maintenance_insert_job_esc')) {
    /**
     * Purpose:
     * escape ค่า string ก่อนต่อใน SQL แบบ legacy ของ insert_job
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection
     * - string $value: ค่าดิบ
     *
     * Return value:
     * - string ที่ escape แล้ว
     */
    function maintenance_insert_job_esc($mysqli, $value)
    {
        if (!($mysqli instanceof mysqli)) {
            return (string) $value;
        }

        return mysqli_real_escape_string($mysqli, (string) $value);
    }
}
