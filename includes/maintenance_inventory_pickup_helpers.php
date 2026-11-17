<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ผูกงานซ่อม (เลขใบซ่อม + S/N เครื่องซ่อม) กับสถานะเบิกอะไหล่ในระบบ inventory
 * และสร้าง URL ไปหน้า `pickup_ma.php` พร้อม prefill หัวเอกสาร
 *
 * องค์ประกอบหลัก:
 * - รูปแบบหมายเหตุ inventory: `{ts_enduser_com} | SN:{trp_sn}`
 * - เลขใบงานใน inventory = 7 ตัวแรกของ `ts_mtn_number` (สอดคล้อง pickup_ma)
 * - สถานะ: `none` | `pre` | `issued`
 *
 * Flow:
 * `status_wait_ma.php` ดึงรายการ → `maintenance_inventory_pickup_status_map_for_rows()` → render ปุ่มต่อแถว
 */

require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/../config/inventory_mysql.php';
require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/status_logistic_helpers.php';

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

/** @var string ประเภทการเบิกใน inventory สำหรับงานซ่อมลูกค้า */
define('MAINTENANCE_INV_PICKUP_TYPE_REPAIR', 'เบิกซ่อมลูกค้า');

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_inventory_pickup_mtn_key')) {
    /**
     * Purpose:
     * แปลงเลขใบซ่อมเป็นเลขที่ใบงานที่ inventory เก็บ (7 ตัวแรก)
     *
     * Parameters สำคัญ:
     * - string $ts_mtn_number: ค่าจาก transac
     *
     * Return value:
     * - string
     */
    function maintenance_inventory_pickup_mtn_key($ts_mtn_number)
    {
        $mtn = trim((string) $ts_mtn_number);
        if ($mtn === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($mtn, 0, 7, 'UTF-8');
        }

        return substr($mtn, 0, 7);
    }
}

if (!function_exists('maintenance_inventory_pickup_sn_marker')) {
    /**
     * Purpose:
     * ส่วนท้ายของหมายเหตุที่ระบุ S/N ซ่อม (ใช้ match กับ pre_remarks / log_remarks)
     *
     * Parameters สำคัญ:
     * - string $trp_sn: S/N ซ่อม
     *
     * Return value:
     * - string เช่น `| SN:BS12345`
     */
    function maintenance_inventory_pickup_sn_marker($trp_sn)
    {
        $sn = trim((string) $trp_sn);

        return '| SN:' . $sn;
    }
}

if (!function_exists('maintenance_inventory_pickup_build_remarks')) {
    /**
     * Purpose:
     * ประกอบหมายเหตุหัวเอกสารเบิก — End user + S/N
     *
     * Parameters สำคัญ:
     * - string $ts_enduser_com: บริษัท End user
     * - string $trp_sn: S/N ซ่อม
     *
     * Return value:
     * - string
     */
    function maintenance_inventory_pickup_build_remarks($ts_enduser_com, $trp_sn)
    {
        $eu = trim(maintenance_flow_db_text_to_utf8($ts_enduser_com));
        $marker = maintenance_inventory_pickup_sn_marker($trp_sn);
        if ($eu === '') {
            return ltrim($marker, ' |');
        }

        return $eu . ' ' . $marker;
    }
}

if (!function_exists('maintenance_inventory_pickup_remarks_match_sn')) {
    /**
     * Purpose:
     * ตรวจว่า remarks ใน inventory ผูกกับ S/N ซ่อมที่ต้องการ
     *
     * Parameters สำคัญ:
     * - string $remarks: pre_remarks หรือ log_remarks
     * - string $trp_sn: S/N จาก maintenance
     *
     * Return value:
     * - bool
     */
    function maintenance_inventory_pickup_remarks_match_sn($remarks, $trp_sn)
    {
        $marker = maintenance_inventory_pickup_sn_marker($trp_sn);
        $sn = trim((string) $trp_sn);
        if ($sn === '' || $marker === '| SN:') {
            return false;
        }
        $text = trim(maintenance_flow_db_text_to_utf8($remarks));
        if ($text === '') {
            return false;
        }
        if (substr($text, -strlen($marker)) === $marker) {
            return true;
        }

        return strpos($text, $marker) !== false;
    }
}

if (!function_exists('maintenance_inventory_pickup_row_cache_key')) {
    /**
     * Purpose:
     * คีย์ cache สำหรับ map สถานะต่อแถว transac_repair
     *
     * Parameters สำคัญ:
     * - string $mtn_key: 7 ตัวแรกของเลขใบซ่อม
     * - string $trp_sn: S/N
     *
     * Return value:
     * - string
     */
    function maintenance_inventory_pickup_row_cache_key($mtn_key, $trp_sn)
    {
        return $mtn_key . "\0" . trim((string) $trp_sn);
    }
}

if (!function_exists('maintenance_inventory_pickup_fetch_pre_rows')) {
    /**
     * Purpose:
     * ดึงแถว pre_stock ที่เกี่ยวกับเลขใบงานชุดที่ส่งมา (เบิกซ่อมลูกค้า)
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - array $mtn_keys: รายการ ts_mtn_number (ไม่ซ้ำ)
     *
     * Return value:
     * - array รายการ associative ts_mtn_number, pre_remarks, pre_status
     */
    function maintenance_inventory_pickup_fetch_pre_rows($inv, array $mtn_keys)
    {
        $keys = array_values(array_filter(array_unique(array_map('strval', $mtn_keys)), 'strlen'));
        if (count($keys) === 0) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types = str_repeat('s', count($keys));
        $sql = 'SELECT `ts_mtn_number`, `pre_remarks`, `pre_status` FROM `pre_stock`'
            . ' WHERE `pre_type` = ? AND `ts_mtn_number` IN (' . $placeholders . ')';
        $stmt = mysqli_prepare($inv, $sql);
        if ($stmt === false) {
            return array();
        }
        $bind = array_merge(array(MAINTENANCE_INV_PICKUP_TYPE_REPAIR), $keys);
        $refs = array($stmt, 's' . $types);
        foreach ($bind as $i => $v) {
            $refs[] = &$bind[$i];
        }
        call_user_func_array('mysqli_stmt_bind_param', $refs);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $out[] = $row;
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_inventory_pickup_fetch_log_rows')) {
    /**
     * Purpose:
     * ดึงแถว log_stock ที่จ่ายแล้วสำหรับเลขใบงานชุดที่ส่งมา
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - array $mtn_keys: รายการ ts_mtn_number
     *
     * Return value:
     * - array รายการ associative ts_mtn_number, log_remarks
     */
    function maintenance_inventory_pickup_fetch_log_rows($inv, array $mtn_keys)
    {
        $keys = array_values(array_filter(array_unique(array_map('strval', $mtn_keys)), 'strlen'));
        if (count($keys) === 0) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types = str_repeat('s', count($keys));
        $sql = 'SELECT `ts_mtn_number`, `log_remarks`, `pre_id`, `log_date` FROM `log_stock`'
            . ' WHERE `log_type` LIKE ? AND `ts_mtn_number` IN (' . $placeholders . ')';
        $stmt = mysqli_prepare($inv, $sql);
        if ($stmt === false) {
            return array();
        }
        $likeType = 'เบิก%';
        $bind = array_merge(array($likeType), $keys);
        $refs = array($stmt, 's' . $types);
        foreach ($bind as $i => $v) {
            $refs[] = &$bind[$i];
        }
        call_user_func_array('mysqli_stmt_bind_param', $refs);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $out[] = $row;
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_inventory_pickup_resolve_status')) {
    /**
     * Purpose:
     * คืนสถานะเบิกอะไหล่ของเครื่องหนึ่งตามข้อมูล inventory ที่โหลดแล้ว
     *
     * Parameters สำคัญ:
     * - string $mtn_key: เลขใบงาน 7 ตัว
     * - string $trp_sn: S/N ซ่อม
     * - array $pre_rows: จาก maintenance_inventory_pickup_fetch_pre_rows
     * - array $log_rows: จาก maintenance_inventory_pickup_fetch_log_rows
     *
     * Return value:
     * - string `none` | `pre` | `issued`
     */
    function maintenance_inventory_pickup_resolve_status($mtn_key, $trp_sn, array $pre_rows, array $log_rows)
    {
        $key = trim((string) $mtn_key);
        if ($key === '') {
            return 'none';
        }
        $hasIssued = false;
        $hasPre = false;
        foreach ($log_rows as $row) {
            $rowKey = isset($row['ts_mtn_number']) ? trim((string) $row['ts_mtn_number']) : '';
            if ($rowKey !== $key) {
                continue;
            }
            if (maintenance_inventory_pickup_remarks_match_sn(isset($row['log_remarks']) ? $row['log_remarks'] : '', $trp_sn)) {
                $hasIssued = true;
                break;
            }
        }
        foreach ($pre_rows as $row) {
            $rowKey = isset($row['ts_mtn_number']) ? trim((string) $row['ts_mtn_number']) : '';
            if ($rowKey !== $key) {
                continue;
            }
            $preStatus = isset($row['pre_status']) ? trim((string) $row['pre_status']) : '';
            if ($preStatus !== 'pre') {
                continue;
            }
            if (maintenance_inventory_pickup_remarks_match_sn(isset($row['pre_remarks']) ? $row['pre_remarks'] : '', $trp_sn)) {
                $hasPre = true;
                break;
            }
        }
        if ($hasPre) {
            return 'pre';
        }
        if ($hasIssued) {
            return 'issued';
        }

        return 'none';
    }
}

if (!function_exists('maintenance_inventory_pickup_resolve_issued_pre_id')) {
    /**
     * Purpose:
     * หาเลขเอกสาร pre_id ล่าสุดที่จ่ายแล้ว สำหรับคู่เลขใบงาน + S/N
     *
     * Parameters สำคัญ:
     * - string $mtn_key: เลขใบงาน 7 ตัว
     * - string $trp_sn: S/N ซ่อม
     * - array $log_rows: จาก maintenance_inventory_pickup_fetch_log_rows
     *
     * Return value:
     * - string pre_id หรือค่าว่าง
     */
    function maintenance_inventory_pickup_resolve_issued_pre_id($mtn_key, $trp_sn, array $log_rows)
    {
        $key = trim((string) $mtn_key);
        if ($key === '') {
            return '';
        }
        $bestPreId = '';
        $bestSort = '';
        foreach ($log_rows as $row) {
            $rowKey = isset($row['ts_mtn_number']) ? trim((string) $row['ts_mtn_number']) : '';
            if ($rowKey !== $key) {
                continue;
            }
            if (!maintenance_inventory_pickup_remarks_match_sn(isset($row['log_remarks']) ? $row['log_remarks'] : '', $trp_sn)) {
                continue;
            }
            $preId = isset($row['pre_id']) ? trim((string) $row['pre_id']) : '';
            if ($preId === '') {
                continue;
            }
            $logDate = isset($row['log_date']) ? trim((string) $row['log_date']) : '';
            if ($logDate > $bestSort || ($logDate === $bestSort && $preId > $bestPreId)) {
                $bestSort = $logDate;
                $bestPreId = $preId;
            }
        }

        return $bestPreId;
    }
}

if (!function_exists('maintenance_inventory_pickup_maps_for_rows')) {
    /**
     * Purpose:
     * สร้าง map สถานะและเลขเอกสาร (เมื่อจ่ายแล้ว) ต่อแถวรอซ่อม
     *
     * Parameters สำคัญ:
     * - array $join_rows: แถวจาก maintenance_wait_ma_fetch_join_rows
     *
     * Return value:
     * - array คีย์ `status`, `pre_id` — แต่ละเป็น map ตาม maintenance_inventory_pickup_row_cache_key
     */
    function maintenance_inventory_pickup_maps_for_rows(array $join_rows)
    {
        $statusMap = array();
        $preIdMap = array();
        $mtn_keys = array();
        foreach ($join_rows as $row) {
            $mtn = isset($row['ts_mtn_number']) ? (string) $row['ts_mtn_number'] : '';
            $sn = isset($row['trp_sn']) ? (string) $row['trp_sn'] : '';
            $mtn_key = maintenance_inventory_pickup_mtn_key($mtn);
            $cache_key = maintenance_inventory_pickup_row_cache_key($mtn_key, $sn);
            $statusMap[$cache_key] = 'none';
            $preIdMap[$cache_key] = '';
            if ($mtn_key !== '') {
                $mtn_keys[] = $mtn_key;
            }
        }
        $inv = maintenance_new_inventory_mysqli_connect();
        if (!$inv) {
            return array('status' => $statusMap, 'pre_id' => $preIdMap);
        }
        $pre_rows = maintenance_inventory_pickup_fetch_pre_rows($inv, $mtn_keys);
        $log_rows = maintenance_inventory_pickup_fetch_log_rows($inv, $mtn_keys);
        mysqli_close($inv);

        foreach ($join_rows as $row) {
            $mtn = isset($row['ts_mtn_number']) ? (string) $row['ts_mtn_number'] : '';
            $sn = isset($row['trp_sn']) ? (string) $row['trp_sn'] : '';
            $mtn_key = maintenance_inventory_pickup_mtn_key($mtn);
            $cache_key = maintenance_inventory_pickup_row_cache_key($mtn_key, $sn);
            $status = maintenance_inventory_pickup_resolve_status($mtn_key, $sn, $pre_rows, $log_rows);
            $statusMap[$cache_key] = $status;
            if ($status === 'issued') {
                $preIdMap[$cache_key] = maintenance_inventory_pickup_resolve_issued_pre_id($mtn_key, $sn, $log_rows);
            }
        }

        return array('status' => $statusMap, 'pre_id' => $preIdMap);
    }
}

if (!function_exists('maintenance_inventory_pickup_status_map_for_rows')) {
    /**
     * Purpose:
     * สร้าง map สถานะเบิกอะไหล่สำหรับแต่ละแถวรายการรอซ่อม (คีย์ = maintenance_inventory_pickup_row_cache_key)
     *
     * Parameters สำคัญ:
     * - array $join_rows: แถวจาก maintenance_wait_ma_fetch_join_rows
     *
     * Return value:
     * - array map string => `none`|`pre`|`issued` (ว่างเมื่อเชื่อม inventory ไม่ได้)
     */
    function maintenance_inventory_pickup_status_map_for_rows(array $join_rows)
    {
        $maps = maintenance_inventory_pickup_maps_for_rows($join_rows);

        return $maps['status'];
    }
}

if (!function_exists('maintenance_inventory_pickup_valid_pre_id')) {
    /**
     * Purpose:
     * ตรวจรูปแบบเลขเอกสาร pre_id จาก inventory
     *
     * Parameters สำคัญ:
     * - string $preId: เลขเอกสาร
     *
     * Return value:
     * - bool
     */
    function maintenance_inventory_pickup_valid_pre_id($preId)
    {
        return (bool) preg_match('/^[0-9A-Za-z-]{1,32}$/', trim((string) $preId));
    }
}

if (!function_exists('maintenance_inventory_pickup_format_log_datetime')) {
    /**
     * Purpose:
     * จัดรูปแบบ log_date ของ inventory (YYMMDD-HHMMSS) สำหรับแสดงบน UI
     *
     * Parameters สำคัญ:
     * - string $logDate: ค่าจาก log_stock.log_date
     *
     * Return value:
     * - string
     */
    function maintenance_inventory_pickup_format_log_datetime($logDate)
    {
        $logDate = trim((string) $logDate);
        if (preg_match('/^(\d{2})(\d{2})(\d{2})-(\d{2})(\d{2})/', $logDate, $m)) {
            $yearCe = 2000 + (int) $m[1];
            $beYear = $yearCe + 543;

            return sprintf('%02d/%02d/%d %s:%s', (int) $m[3], (int) $m[2], $beYear, $m[4], $m[5]);
        }

        return $logDate;
    }
}

if (!function_exists('maintenance_inventory_pickup_part_label')) {
    /**
     * Purpose:
     * ชื่อ part สำหรับแสดงจากค่า log_part
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - string $logPartRaw: ค่า log_part
     *
     * Return value:
     * - string
     */
    function maintenance_inventory_pickup_part_label($inv, $logPartRaw)
    {
        $logPartRaw = trim((string) $logPartRaw);
        if ($logPartRaw === '') {
            return '—';
        }
        $asId = filter_var($logPartRaw, FILTER_VALIDATE_INT);
        if ($asId === false || (string) $asId !== $logPartRaw || $asId < 1) {
            return $logPartRaw;
        }
        $stmt = mysqli_prepare($inv, 'SELECT `part_name` FROM `part` WHERE `part_id` = ? LIMIT 1');
        if ($stmt === false) {
            return 'Part #' . (int) $asId;
        }
        $pid = (int) $asId;
        mysqli_stmt_bind_param($stmt, 'i', $pid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $partName);
        $name = '';
        if (mysqli_stmt_fetch($stmt)) {
            $name = trim((string) $partName);
        }
        mysqli_stmt_close($stmt);

        return $name !== '' ? $name : ('Part #' . $pid);
    }
}

if (!function_exists('maintenance_inventory_pickup_fetch_requester_name')) {
    /**
     * Purpose:
     * อ่านชื่อผู้ขอเบิกจาก pre_stock
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection
     * - string $preId: เลขเอกสาร
     *
     * Return value:
     * - string
     */
    function maintenance_inventory_pickup_fetch_requester_name($inv, $preId)
    {
        $preId = trim((string) $preId);
        if (!maintenance_inventory_pickup_valid_pre_id($preId)) {
            return '';
        }
        $stmt = mysqli_prepare($inv, 'SELECT MIN(`pre_by`) AS requested_by FROM `pre_stock` WHERE `pre_id` = ? LIMIT 1');
        if ($stmt === false) {
            return '';
        }
        mysqli_stmt_bind_param($stmt, 's', $preId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $requestedBy);
        $name = '';
        if (mysqli_stmt_fetch($stmt)) {
            $name = trim((string) $requestedBy);
        }
        mysqli_stmt_close($stmt);

        return $name;
    }
}

if (!function_exists('maintenance_inventory_pickup_fetch_issued_lines_payload')) {
    /**
     * Purpose:
     * ดึงข้อมูล JSON สำหรับ popup รายการอะไหล่ที่เบิกแล้ว
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - string $preId: เลขเอกสาร
     *
     * Return value:
     * - array|null payload เมื่อพบข้อมูล หรือ null
     */
    function maintenance_inventory_pickup_fetch_issued_lines_payload($inv, $preId)
    {
        $preId = trim((string) $preId);
        if (!maintenance_inventory_pickup_valid_pre_id($preId)) {
            return null;
        }
        $sql = 'SELECT `log_part`, `log_product`, `log_amount`, `log_remarks`
                FROM `log_stock`
                WHERE `pre_id` = ? AND `log_type` LIKE ?
                ORDER BY `log_part` ASC, `log_product` ASC';
        $stmt = mysqli_prepare($inv, $sql);
        if ($stmt === false) {
            return null;
        }
        $typeLike = 'เบิก%';
        mysqli_stmt_bind_param($stmt, 'ss', $preId, $typeLike);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return null;
        }
        $res = mysqli_stmt_get_result($stmt);
        $lines = array();
        $remarks = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $remark = trim(isset($row['log_remarks']) ? (string) $row['log_remarks'] : '');
                if ($remark !== '') {
                    $remarks[$remark] = true;
                }
                $lines[] = array(
                    'part_name' => maintenance_inventory_pickup_part_label($inv, isset($row['log_part']) ? $row['log_part'] : ''),
                    'product' => trim(isset($row['log_product']) ? (string) $row['log_product'] : ''),
                    'amount' => trim(isset($row['log_amount']) ? (string) $row['log_amount'] : ''),
                    'remarks' => $remark,
                );
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (count($lines) === 0) {
            return null;
        }

        $docDraft = array(
            'pre_id' => $preId,
            'lines' => $lines,
        );
        maintenance_inventory_pickup_merge_tech_lines($inv, $docDraft);
        $lines = $docDraft['lines'];
        $lineCountTech = isset($docDraft['line_count_tech']) ? (int) $docDraft['line_count_tech'] : 0;

        $headerSql = 'SELECT MIN(`log_date`) AS log_date, MIN(`log_type`) AS log_type, MIN(`log_by`) AS approved_by
                      FROM `log_stock` WHERE `pre_id` = ? AND `log_type` LIKE ? LIMIT 1';
        $hstmt = mysqli_prepare($inv, $headerSql);
        $headerDate = '';
        $headerType = '';
        $approvedBy = '';
        if ($hstmt !== false) {
            mysqli_stmt_bind_param($hstmt, 'ss', $preId, $typeLike);
            mysqli_stmt_execute($hstmt);
            mysqli_stmt_bind_result($hstmt, $headerDate, $headerType, $approvedBy);
            mysqli_stmt_fetch($hstmt);
            mysqli_stmt_close($hstmt);
        }

        return array(
            'ok' => true,
            'status' => 'issued',
            'pre_id' => $preId,
            'pre_date' => maintenance_inventory_pickup_format_log_datetime($headerDate),
            'requested_by' => maintenance_inventory_pickup_fetch_requester_name($inv, $preId),
            'approved_by' => trim((string) $approvedBy),
            'pre_type' => trim((string) $headerType),
            'remarks' => implode(' / ', array_keys($remarks)),
            'line_count' => count($lines),
            'line_count_tech' => $lineCountTech,
            'lines' => $lines,
        );
    }
}

if (!function_exists('maintenance_inventory_pickup_list_issued_pre_ids_for_machine')) {
    /**
     * Purpose:
     * รวบรวมเลขเอกสารที่จ่ายแล้วทั้งหมดของคู่เลขใบงาน + S/N (เรียงใหม่สุดก่อน)
     *
     * Parameters สำคัญ:
     * - string $mtn_key: เลขใบงาน 7 ตัว
     * - string $trp_sn: S/N ซ่อม
     * - array $log_rows: จาก maintenance_inventory_pickup_fetch_log_rows
     *
     * Return value:
     * - array<int, string> รายการ pre_id
     */
    function maintenance_inventory_pickup_list_issued_pre_ids_for_machine($mtn_key, $trp_sn, array $log_rows)
    {
        $key = trim((string) $mtn_key);
        if ($key === '') {
            return array();
        }
        $sortByPreId = array();
        foreach ($log_rows as $row) {
            $rowKey = isset($row['ts_mtn_number']) ? trim((string) $row['ts_mtn_number']) : '';
            if ($rowKey !== $key) {
                continue;
            }
            if (!maintenance_inventory_pickup_remarks_match_sn(isset($row['log_remarks']) ? $row['log_remarks'] : '', $trp_sn)) {
                continue;
            }
            $preId = isset($row['pre_id']) ? trim((string) $row['pre_id']) : '';
            if ($preId === '') {
                continue;
            }
            $logDate = isset($row['log_date']) ? trim((string) $row['log_date']) : '';
            if (!isset($sortByPreId[$preId]) || $logDate > $sortByPreId[$preId]) {
                $sortByPreId[$preId] = $logDate;
            }
        }
        if (count($sortByPreId) === 0) {
            return array();
        }
        arsort($sortByPreId, SORT_STRING);

        return array_keys($sortByPreId);
    }
}

if (!function_exists('maintenance_inventory_pickup_fetch_pre_stock_tech_lines')) {
    /**
     * Purpose:
     * ดึงรายการอะไหล่คลังช่างที่ผูกกับเลขเอกสาร pre (ตาราง pre_stock_tech ใน inventory)
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - string $preId: เลขเอกสาร
     *
     * Return value:
     * - array<int, array<string, string>> รายการในรูปแบบเดียวกับบรรทัดคลังกลาง
     */
    function maintenance_inventory_pickup_fetch_pre_stock_tech_lines($inv, $preId)
    {
        $preId = trim((string) $preId);
        if (!($inv instanceof mysqli) || $preId === '' || !maintenance_inventory_pickup_valid_pre_id($preId)) {
            return array();
        }

        $sql = 'SELECT `part_name`, `product_name`, `pre_amount`, `line_sn`, `parts_doc_no`
                FROM `pre_stock_tech` WHERE `pre_id` = ? ORDER BY `id` ASC';
        $stmt = mysqli_prepare($inv, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 's', $preId);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $lines = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $partName = trim(isset($row['part_name']) ? (string) $row['part_name'] : '');
                $productName = trim(isset($row['product_name']) ? (string) $row['product_name'] : '');
                $amount = trim(isset($row['pre_amount']) ? (string) $row['pre_amount'] : '');
                $sn = trim(isset($row['line_sn']) ? (string) $row['line_sn'] : '');
                $docNo = trim(isset($row['parts_doc_no']) ? (string) $row['parts_doc_no'] : '');
                $remark = $sn;
                if ($docNo !== '' && $remark === '') {
                    $remark = $docNo;
                } elseif ($docNo !== '') {
                    $remark = $remark . ' · ' . $docNo;
                }
                $lines[] = array(
                    'line_kind' => 'tech',
                    'part_name' => $partName !== '' ? $partName : '—',
                    'product' => $productName !== '' ? $productName : 'คลังช่าง',
                    'amount' => $amount !== '' ? $amount : '—',
                    'remarks' => $remark,
                );
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $lines;
    }
}

if (!function_exists('maintenance_inventory_pickup_merge_tech_lines')) {
    /**
     * Purpose:
     * รวมรายการคลังช่างเข้า payload เอกสารเบิก
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - array<string, mixed> $doc: เอกสารที่มีคีย์ lines
     *
     * Return value:
     * - void แก้ $doc โดยอ้างอิง
     */
    function maintenance_inventory_pickup_merge_tech_lines($inv, array &$doc)
    {
        if (!isset($doc['pre_id']) || !isset($doc['lines']) || !is_array($doc['lines'])) {
            return;
        }
        $preId = trim((string) $doc['pre_id']);
        $techLines = maintenance_inventory_pickup_fetch_pre_stock_tech_lines($inv, $preId);
        if ($techLines === array()) {
            return;
        }
        $doc['lines'] = array_merge($doc['lines'], $techLines);
        $doc['line_count'] = count($doc['lines']);
        $doc['line_count_tech'] = count($techLines);
    }
}

if (!function_exists('maintenance_inventory_pickup_fetch_pending_documents_for_machine')) {
    /**
     * Purpose:
     * ดึงเอกสารรอคลังจ่ายอะไหล่ (pre_stock, pre_status=pre) ที่ผูกกับเลขใบงาน + S/N
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - string $mtn_key: เลขใบงาน 7 ตัว
     * - string $trp_sn: S/N ซ่อม
     *
     * Return value:
     * - array<int, array> รายการเอกสารพร้อม lines
     */
    function maintenance_inventory_pickup_fetch_pending_documents_for_machine($inv, $mtn_key, $trp_sn)
    {
        $key = trim((string) $mtn_key);
        if ($key === '') {
            return array();
        }
        $sql = 'SELECT `pre_id`, `pre_date`, `pre_product`, `pre_part`, `pre_amount`, `pre_remarks`, `pre_by`, `pre_status`
                FROM `pre_stock`
                WHERE `pre_type` = ? AND `ts_mtn_number` = ? AND TRIM(`pre_status`) = ?
                ORDER BY `pre_id` DESC, `pre_part` ASC';
        $stmt = mysqli_prepare($inv, $sql);
        if ($stmt === false) {
            return array();
        }
        $type = MAINTENANCE_INV_PICKUP_TYPE_REPAIR;
        $statusPre = 'pre';
        mysqli_stmt_bind_param($stmt, 'sss', $type, $key, $statusPre);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $grouped = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (!maintenance_inventory_pickup_remarks_match_sn(isset($row['pre_remarks']) ? $row['pre_remarks'] : '', $trp_sn)) {
                    continue;
                }
                $preId = isset($row['pre_id']) ? trim((string) $row['pre_id']) : '';
                if ($preId === '') {
                    continue;
                }
                if (!isset($grouped[$preId])) {
                    $grouped[$preId] = array(
                        'pre_id' => $preId,
                        'pre_date' => trim(isset($row['pre_date']) ? (string) $row['pre_date'] : ''),
                        'requested_by' => trim(isset($row['pre_by']) ? (string) $row['pre_by'] : ''),
                        'pre_type' => $type,
                        'status' => 'pending',
                        'remarks' => '',
                        'lines' => array(),
                    );
                }
                $remark = trim(isset($row['pre_remarks']) ? (string) $row['pre_remarks'] : '');
                if ($grouped[$preId]['remarks'] === '' && $remark !== '') {
                    $grouped[$preId]['remarks'] = $remark;
                }
                $grouped[$preId]['lines'][] = array(
                    'part_name' => maintenance_inventory_pickup_part_label($inv, isset($row['pre_part']) ? $row['pre_part'] : ''),
                    'product' => trim(isset($row['pre_product']) ? (string) $row['pre_product'] : ''),
                    'amount' => trim(isset($row['pre_amount']) ? (string) $row['pre_amount'] : ''),
                    'remarks' => $remark,
                );
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        $documents = array();
        foreach ($grouped as $doc) {
            maintenance_inventory_pickup_merge_tech_lines($inv, $doc);
            $doc['line_count'] = count($doc['lines']);
            if ($doc['line_count'] > 0) {
                $doc['pre_date'] = maintenance_inventory_pickup_format_log_datetime($doc['pre_date']);
                $documents[] = $doc;
            }
        }

        return $documents;
    }
}

if (!function_exists('maintenance_inventory_pickup_fetch_machine_summary_payload')) {
    /**
     * Purpose:
     * สรุปการเบิกทั้งเอกสารรอจ่ายและจ่ายแล้ว ต่อเครื่อง (เลขใบงาน + S/N)
     *
     * Parameters สำคัญ:
     * - mysqli $inv: connection inventory
     * - string $mtn_key: เลขใบงาน 7 ตัว
     * - string $trp_sn: S/N ซ่อม
     *
     * Return value:
     * - array|null payload สำหรับ popup
     */
    function maintenance_inventory_pickup_fetch_machine_summary_payload($inv, $mtn_key, $trp_sn)
    {
        $key = trim((string) $mtn_key);
        if ($key === '') {
            return null;
        }
        $pending = maintenance_inventory_pickup_fetch_pending_documents_for_machine($inv, $key, $trp_sn);
        $log_rows = maintenance_inventory_pickup_fetch_log_rows($inv, array($key));
        $issuedIds = maintenance_inventory_pickup_list_issued_pre_ids_for_machine($key, $trp_sn, $log_rows);
        $issued = array();
        foreach ($issuedIds as $preId) {
            $doc = maintenance_inventory_pickup_fetch_issued_lines_payload($inv, $preId);
            if ($doc !== null) {
                $issued[] = $doc;
            }
        }
        if (count($pending) === 0 && count($issued) === 0) {
            return null;
        }
        $remarks = '';
        if (count($pending) > 0 && !empty($pending[0]['remarks'])) {
            $remarks = (string) $pending[0]['remarks'];
        } elseif (count($issued) > 0 && !empty($issued[0]['remarks'])) {
            $remarks = (string) $issued[0]['remarks'];
        }

        return array(
            'ok' => true,
            'mode' => 'machine',
            'mtn_key' => $key,
            'trp_sn' => trim((string) $trp_sn),
            'remarks' => $remarks,
            'pending_documents' => $pending,
            'issued_documents' => $issued,
        );
    }
}

if (!function_exists('maintenance_inventory_pickup_url')) {
    /**
     * Purpose:
     * URL ไปหน้าเบิก inventory พร้อม prefill no_repair + detail
     *
     * Parameters สำคัญ:
     * - string $ts_mtn_number: เลขใบซ่อมเต็ม (ส่งเป็น no_repair — ฝั่ง inventory ตัด 7 ตัวเอง)
     * - string $ts_enduser_com: End user
     * - string $trp_sn: S/N ซ่อม
     *
     * Return value:
     * - string absolute path เช่น `/inventory/pickup_ma.php?...`
     */
    function maintenance_inventory_pickup_url($ts_mtn_number, $ts_enduser_com, $trp_sn)
    {
        $base = maintenance_new_inventory_web_base();
        $remarks = maintenance_inventory_pickup_build_remarks($ts_enduser_com, $trp_sn);
        $qs = http_build_query(
            array(
                'no_repair' => trim((string) $ts_mtn_number),
                'detail' => $remarks,
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return rtrim($base, '/') . '/pickup_ma.php?' . $qs;
    }
}

if (!function_exists('maintenance_inventory_pickup_button_meta')) {
    /**
     * Purpose:
     * ข้อความและ class CSS ของปุ่มเบิกอะไหล่ตามสถานะ
     *
     * Parameters สำคัญ:
     * - string $status: `none` | `pre` | `issued`
     *
     * Return value:
     * - array{label: string, class: string, clickable: bool}
     */
    function maintenance_inventory_pickup_button_meta($status)
    {
        $status = (string) $status;
        if ($status === 'issued') {
            return array(
                'label' => 'เบิกอะไหล่แล้ว',
                'class' => 'mn-wait-ma-pickup-btn mn-wait-ma-pickup-btn--issued',
                'clickable' => true,
            );
        }
        if ($status === 'pre') {
            return array(
                'label' => 'รอคลังจ่ายอะไหล่',
                'class' => 'mn-wait-ma-pickup-btn mn-wait-ma-pickup-btn--pre',
                'clickable' => true,
            );
        }

        return array(
            'label' => 'ยังไม่ได้เบิกอะไหล่',
            'class' => 'mn-wait-ma-pickup-btn mn-wait-ma-pickup-btn--none',
            'clickable' => true,
        );
    }
}
