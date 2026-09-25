<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ดึงข้อมูล `transac` + `transac_repair` + สรุป `transac_spare` ตาม `trp_id` แล้วประกอบโครง timeline แนวตั้งสำหรับหน้า `job_process.php`
 * รองรับงานที่ไม่มีแถว `transac_spare` (ไม่เน้นตัวเลขรอขั้นเครื่องซ่อม, ข้ามขั้นรอเครื่องสำรอง, จบที่ส่งคืน + สถานะเสร็จสิ้น)
 *
 * Flow:
 * `maintenance_job_process_fetch_bundle($connect, $trp_id)` → แถวดิบ + flag `has_spare_rows`
 * `maintenance_job_process_build_timeline($bundle, $today_ymd)` → โครงขั้นตอน + meta สำหรับ render
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_job_process_parse_date')) {
    /**
     * Purpose:
     * แปลงสตริงวันที่จาก DB เป็น `DateTime` โซน Asia/Bangkok หรือ null ถ้าไม่ใช่วันที่ใช้ได้
     *
     * Parameters สำคัญ:
     * - string $ymd: ค่าที่คาดว่าเป็น `Y-m-d`
     *
     * Return value:
     * - DateTime|null
     */
    function maintenance_job_process_parse_date($ymd)
    {
        $s = trim((string) $ymd);
        if ($s === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $s, new DateTimeZone('Asia/Bangkok'));
        if ($dt === false) {
            return null;
        }
        $errors = array('warning_count' => 0, 'error_count' => 0);
        if (method_exists('DateTime', 'getLastErrors')) {
            $errors = DateTime::getLastErrors();
        }
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $dt;
    }
}

if (!function_exists('maintenance_job_process_days_diff')) {
    /**
     * Purpose:
     * คำนวณจำนวนวันปฏิทินระหว่างสองวันที่ (จาก `$from` ถึง `$to`, ไม่รวมเวลา)
     *
     * Parameters สำคัญ:
     * - DateTime|null $from: วันเริ่ม
     * - DateTime|null $to: วันสิ้นสุด
     *
     * Return value:
     * - int|null ถ้าไม่ครบคู่จะคืน null
     */
    function maintenance_job_process_days_diff($from, $to)
    {
        if (!($from instanceof DateTime) || !($to instanceof DateTime)) {
            return null;
        }
        $from0 = clone $from;
        $to0 = clone $to;
        $from0->setTime(0, 0, 0);
        $to0->setTime(0, 0, 0);

        return (int) $from0->diff($to0)->format('%r%a');
    }
}

if (!function_exists('maintenance_job_process_stmt_fetch_assoc')) {
    /**
     * Purpose:
     * อ่านแถวเดียวจาก prepared statement — รองรับ PHP ที่ไม่มี mysqlnd
     *
     * Parameters สำคัญ:
     * - mysqli_stmt $stmt: statement หลัง execute
     *
     * Return value:
     * - array|false
     */
    function maintenance_job_process_stmt_fetch_assoc($stmt)
    {
        if (function_exists('maintenance_flow_mysqli_stmt_fetch_assoc')) {
            return maintenance_flow_mysqli_stmt_fetch_assoc($stmt);
        }
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

if (!function_exists('maintenance_job_process_line_logistics_tracking')) {
    /**
     * Purpose:
     * สร้างรายการบรรทัด timeline แบบขนส่ง + tracking (ให้ render เป็น input คลิกได้เหมือน detail_job)
     *
     * Parameters สำคัญ:
     * - string $logistics: ชื่อขนส่ง
     * - string $tracking: เลข tracking
     * - string $line_key: คีย์อ้างอิงภายใน (เช่น spare_123) — ไม่แสดงบนหน้า
     *
     * Return value:
     * - array โครง `kind` = logistics_tracking
     */
    function maintenance_job_process_line_logistics_tracking($logistics, $tracking, $line_key)
    {
        return array(
            'kind' => 'logistics_tracking',
            'logistics' => trim((string) $logistics),
            'tracking' => trim((string) $tracking),
            'line_key' => (string) $line_key,
        );
    }
}

if (!function_exists('maintenance_job_process_render_timeline_line')) {
    /**
     * Purpose:
     * แสดงบรรทัด timeline — สตริงธรรมดาหรือแถวขนส่ง/tracking ที่คลิก copy และเปิดหน้า track
     *
     * Parameters สำคัญ:
     * - string|array $line: จาก `lines` ใน timeline
     *
     * Return value:
     * - void (echo HTML ที่ escape แล้วตามมาตรฐาน)
     */
    function maintenance_job_process_render_timeline_line($line)
    {
        if (is_array($line) && isset($line['kind']) && $line['kind'] === 'logistics_tracking') {
            $lg = isset($line['logistics']) ? (string) $line['logistics'] : '';
            $tk = isset($line['tracking']) ? (string) $line['tracking'] : '';

            echo '<span class="mn-jp-logistics-inline">';
            echo 'ขนส่ง: ';
            echo maintenance_status_logistic_h($lg !== '' ? $lg : '—');
            echo ' · Tracking: ';
            if ($tk !== '') {
                $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
                if (defined('JSON_HEX_AMP')) {
                    $jsonFlags |= JSON_HEX_AMP;
                }
                if (defined('JSON_UNESCAPED_UNICODE')) {
                    $jsonFlags |= JSON_UNESCAPED_UNICODE;
                }
                $tkJs = json_encode($tk, $jsonFlags);
                $logJs = json_encode($lg, $jsonFlags);
                echo '<span role="button" tabindex="0" class="mn-jp-tracking-link" onclick="copy_tracking(';
                echo htmlspecialchars($tkJs, ENT_QUOTES, 'UTF-8');
                echo ', ';
                echo htmlspecialchars($logJs, ENT_QUOTES, 'UTF-8');
                echo ');" title="คลิกเพื่อ copy และเปิดหน้า track ขนส่งในหน้าต่างใหม่">';
                echo maintenance_status_logistic_h($tk);
                echo '</span>';
            } else {
                echo maintenance_status_logistic_h('—');
            }
            echo '</span>';

            return;
        }

        if (is_array($line) && isset($line['kind']) && $line['kind'] === 'spare_activate') {
            maintenance_job_process_echo_spare_activate_line($line);

            return;
        }

        if (is_array($line) && isset($line['kind']) && $line['kind'] === 'spare_activate_quota') {
            $quotaMsg = isset($line['message']) && trim((string) $line['message']) !== ''
                ? trim((string) $line['message'])
                : 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนดแล้ว';
            echo '<span class="mn-jp-activate-quota-msg" role="status">';
            echo maintenance_status_logistic_h($quotaMsg);
            echo '</span>';

            return;
        }

        echo maintenance_status_logistic_h(is_string($line) ? $line : '');
    }
}

if (!function_exists('maintenance_job_process_format_date_display')) {
    /**
     * Purpose:
     * แปลงวันที่เป็นรูปแบบแสดงผล d/m/Y สำหรับ timeline
     *
     * Parameters สำคัญ:
     * - string $ymd: วันที่ Y-m-d หรือรูปแบบที่ parse ได้
     *
     * Return value:
     * - string
     */
    function maintenance_job_process_format_date_display($ymd)
    {
        $d = maintenance_job_process_parse_date($ymd);
        if ($d !== null) {
            return $d->format('d/m/Y');
        }

        return trim((string) $ymd);
    }
}

if (!function_exists('maintenance_job_process_spare_activate_is_past_expire')) {
    /**
     * Purpose:
     * ตรวจว่าวันปัจจุบันเลยวันหมดอายุแล้ว (ไม่นับวันหมดอายุเป็นวันสุดท้ายที่ใช้ได้)
     *
     * Parameters สำคัญ:
     * - string $expire_ymd: วันหมดอายุ Y-m-d
     * - string $today_ymd: วันอ้างอิง Y-m-d (ว่าง = วันนี้)
     *
     * Return value:
     * - bool
     */
    function maintenance_job_process_spare_activate_is_past_expire($expire_ymd, $today_ymd = '')
    {
        require_once __DIR__ . '/transac_activate_spare_helpers.php';

        $expireYmd = maintenance_transac_activate_spare_normalize_date_ymd($expire_ymd);
        if ($expireYmd === '') {
            return false;
        }
        $todayYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            $today_ymd !== '' ? $today_ymd : date('Y-m-d')
        );
        if ($todayYmd === '') {
            return false;
        }

        return $todayYmd > $expireYmd;
    }
}

if (!function_exists('maintenance_job_process_build_spare_activate_sequence_map')) {
    /**
     * Purpose:
     * สร้างแผนที่ tas_id → ลำดับครั้งที่ต่ออายุ (1-based) ตามประวัติของ tsp_id เรียงเก่า → ใหม่
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     *
     * Return value:
     * - array<int,int>
     */
    function maintenance_job_process_build_spare_activate_sequence_map($connect, $tsp_id)
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return array();
        }

        require_once __DIR__ . '/transac_activate_spare_helpers.php';
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array();
        }

        $rows = maintenance_transac_activate_spare_fetch_by_tsp_id($connect, $tsp_id, 50);
        if ($rows === array()) {
            return array();
        }

        $rows = array_reverse($rows);
        $map = array();
        $seq = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = isset($row['tas_activate_code']) ? trim((string) $row['tas_activate_code']) : '';
            if ($code === '') {
                continue;
            }
            $tasId = isset($row['tas_id']) ? (int) $row['tas_id'] : 0;
            if ($tasId < 1) {
                continue;
            }
            $seq++;
            $map[$tasId] = $seq;
        }

        return $map;
    }
}

if (!function_exists('maintenance_job_process_resolve_latest_active_spare_tas_id')) {
    /**
     * Purpose:
     * หา `tas_id` ของรายการต่ออายุที่ยังไม่หมดอายุล่าสุด (เรียงตามวันหมดอายุ แล้ว tas_id) — ใช้กำหนดว่าบรรทัดไหนแสดงรหัสเต็ม
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - string $today_ymd: วันอ้างอิง Y-m-d (ว่าง = วันนี้)
     *
     * Return value:
     * - int tas_id ล่าสุดที่ยังใช้ได้ หรือ 0 เมื่อไม่มี
     */
    function maintenance_job_process_resolve_latest_active_spare_tas_id($connect, $tsp_id, $today_ymd = '')
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return 0;
        }

        require_once __DIR__ . '/transac_activate_spare_helpers.php';
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return 0;
        }

        $todayYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            $today_ymd !== '' ? $today_ymd : date('Y-m-d')
        );
        if ($todayYmd === '') {
            return 0;
        }

        static $cache = array();
        $cacheKey = $tsp_id . '|' . $todayYmd;
        if (array_key_exists($cacheKey, $cache)) {
            return (int) $cache[$cacheKey];
        }

        $rows = maintenance_transac_activate_spare_fetch_by_tsp_id($connect, $tsp_id, 50);
        $bestTasId = 0;
        $bestExpire = '';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = isset($row['tas_activate_code']) ? trim((string) $row['tas_activate_code']) : '';
            if ($code === '') {
                continue;
            }
            $expireYmd = maintenance_transac_activate_spare_normalize_date_ymd(
                isset($row['tas_expire_date']) ? (string) $row['tas_expire_date'] : ''
            );
            if ($expireYmd === '' || maintenance_job_process_spare_activate_is_past_expire($expireYmd, $todayYmd)) {
                continue;
            }
            $tasId = isset($row['tas_id']) ? (int) $row['tas_id'] : 0;
            if ($tasId < 1) {
                continue;
            }
            if ($bestTasId < 1 || $expireYmd > $bestExpire || ($expireYmd === $bestExpire && $tasId > $bestTasId)) {
                $bestExpire = $expireYmd;
                $bestTasId = $tasId;
            }
        }

        $cache[$cacheKey] = $bestTasId;

        return $bestTasId;
    }
}

if (!function_exists('maintenance_job_process_echo_spare_activate_line')) {
    /**
     * Purpose:
     * แสดงบรรทัดรหัส/ประวัติต่ออายุเครื่องสำรอง — หมดอายุแล้วหรือไม่ใช่รหัสล่าสุดที่ยังใช้ได้ ใช้รูปแบบ «ครั้งที่ N ต่ออายุสำรองใช้ได้ถึง …»
     *
     * Parameters สำคัญ:
     * - array $line: คีย์ code, expire_display, sequence_no, is_expired, show_full_code
     *
     * Return value:
     * - void
     */
    function maintenance_job_process_echo_spare_activate_line(array $line)
    {
        $code = isset($line['code']) ? trim((string) $line['code']) : '';
        $expire = isset($line['expire_display']) ? trim((string) $line['expire_display']) : '';
        $seq = isset($line['sequence_no']) ? (int) $line['sequence_no'] : 0;
        $isExpired = !empty($line['is_expired']);
        $showFullCode = !empty($line['show_full_code']);

        if (($isExpired || !$showFullCode) && $seq > 0) {
            $lineClass = 'mn-jp-spare-activate-line';
            if ($isExpired) {
                $lineClass .= ' mn-jp-spare-activate-line--expired';
            }
            echo '<span class="' . $lineClass . '">';
            echo 'ครั้งที่ ';
            echo (int) $seq;
            echo ' ต่ออายุสำรองใช้ได้ถึง ';
            echo maintenance_status_logistic_h($expire !== '' ? $expire : '—');
            echo '</span>';

            return;
        }

        echo '<span class="mn-jp-spare-activate-line">';
        echo '<span class="mn-jp-spare-activate-code">';
        echo 'รหัสต่ออายุเครื่องสำรอง ';
        echo maintenance_status_logistic_h($code !== '' ? $code : '—');
        echo '</span>';
        echo ' (ใช้ได้ถึง ';
        echo maintenance_status_logistic_h($expire !== '' ? $expire : '—');
        echo ')';
        echo '</span>';
    }
}

if (!function_exists('maintenance_job_process_spare_activate_row_to_timeline_line')) {
    /**
     * Purpose:
     * แปลงแถว `transac_activate_spare` เป็นบรรทัด timeline แบบ spare_activate
     *
     * Parameters สำคัญ:
     * - array $activateRow: แถวจาก helper ต่ออายุ (normalize แล้ว)
     * - int $sequence_no: ลำดับครั้งที่ต่ออายุของงาน (1-based)
     * - string $today_ymd: วันอ้างอิงสำหรับตรวจหมดอายุ
     * - int $latest_active_tas_id: tas_id รายการที่ยังไม่หมดอายุล่าสุด (0 = ไม่มี)
     *
     * Return value:
     * - array{kind:string,code:string,expire_display:string,sequence_no:int,is_expired:bool,show_full_code:bool}|null
     */
    function maintenance_job_process_spare_activate_row_to_timeline_line(
        array $activateRow,
        $sequence_no = 0,
        $today_ymd = '',
        $latest_active_tas_id = 0
    ) {
        $codeRow = isset($activateRow['tas_activate_code']) ? trim((string) $activateRow['tas_activate_code']) : '';
        if ($codeRow === '') {
            return null;
        }

        require_once __DIR__ . '/transac_activate_spare_helpers.php';

        $expireRaw = isset($activateRow['tas_expire_date']) ? (string) $activateRow['tas_expire_date'] : '';
        $expireYmd = maintenance_transac_activate_spare_normalize_date_ymd($expireRaw);
        $tasId = isset($activateRow['tas_id']) ? (int) $activateRow['tas_id'] : 0;
        $isExpired = maintenance_job_process_spare_activate_is_past_expire($expireYmd, $today_ymd);
        $latestActiveTasId = (int) $latest_active_tas_id;
        $showFullCode = !$isExpired
            && $latestActiveTasId > 0
            && $tasId > 0
            && $tasId === $latestActiveTasId;

        return array(
            'kind' => 'spare_activate',
            'code' => $codeRow,
            'expire_display' => maintenance_job_process_format_date_display($expireRaw),
            'sequence_no' => (int) $sequence_no,
            'is_expired' => $isExpired,
            'show_full_code' => $showFullCode,
        );
    }
}

if (!function_exists('maintenance_job_process_fetch_spare_activate_timeline_lines')) {
    /**
     * Purpose:
     * ดึงบรรทัด timeline รหัสต่ออายุสำรองตาม tsp_id และสถานะงานขณะบันทึก (`tas_trp_status_job`)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - string $trp_product: trp_product ของงานซ่อม (ต้องเป็น bitVisitor Plus / M)
     * - string $status_mode: `exact` | `exact_jobs` | `exclude` | `exclude_jobs` (หลายสถานะ)
     * - string|array $status_job: ค่า tas_trp_status_job สำหรับกรอง (array เมื่อ mode = `exact_jobs` หรือ `exclude_jobs`)
     *
     * Return value:
     * - array<int,array{kind:string,code:string,expire_display:string}>
     */
    function maintenance_job_process_fetch_spare_activate_timeline_lines(
        $connect,
        $tsp_id,
        $trp_product,
        $status_mode,
        $status_job
    ) {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return array();
        }

        require_once __DIR__ . '/transac_activate_spare_helpers.php';
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trp_product)) {
            return array();
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array();
        }

        $mode = trim((string) $status_mode);

        if ($mode === 'exclude_jobs' || $mode === 'exact_jobs') {
            $statusList = is_array($status_job) ? $status_job : array();
            $filteredStatuses = array();
            foreach ($statusList as $statusVal) {
                $statusVal = trim((string) $statusVal);
                if ($statusVal !== '') {
                    $filteredStatuses[] = $statusVal;
                }
            }
            if ($filteredStatuses === array()) {
                return array();
            }
            if ($mode === 'exclude_jobs') {
                $rows = maintenance_transac_activate_spare_fetch_by_tsp_excluding_status_jobs(
                    $connect,
                    $tsp_id,
                    $filteredStatuses
                );
            } else {
                $rows = maintenance_transac_activate_spare_fetch_by_tsp_and_status_jobs(
                    $connect,
                    $tsp_id,
                    $filteredStatuses
                );
            }
        } else {
            $statusJobStr = trim((string) $status_job);
            if ($statusJobStr === '') {
                return array();
            }
            if ($mode === 'exclude') {
                $rows = maintenance_transac_activate_spare_fetch_by_tsp_excluding_status_job($connect, $tsp_id, $statusJobStr);
            } else {
                $rows = maintenance_transac_activate_spare_fetch_by_tsp_and_status_job($connect, $tsp_id, $statusJobStr);
            }
            if ($statusJobStr === MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE && $rows !== array()) {
                $manualRows = array();
                foreach ($rows as $tasRow) {
                    if (is_array($tasRow) && !maintenance_transac_activate_spare_is_system_backfill_row($tasRow)) {
                        $manualRows[] = $tasRow;
                    }
                }
                $rows = $manualRows;
            }
        }

        return maintenance_job_process_build_spare_activate_lines_from_rows($connect, $tsp_id, $rows);
    }
}

if (!function_exists('maintenance_job_process_build_spare_activate_lines_from_rows')) {
    /**
     * Purpose:
     * แปลงแถว `transac_activate_spare` เป็นบรรทัด timeline spare_activate
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - array $rows: แถว normalize แล้ว
     *
     * Return value:
     * - array<int,array>
     */
    function maintenance_job_process_build_spare_activate_lines_from_rows($connect, $tsp_id, array $rows)
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return array();
        }

        $todayYmd = date('Y-m-d');
        $seqMap = maintenance_job_process_build_spare_activate_sequence_map($connect, $tsp_id);
        $latestActiveTasId = maintenance_job_process_resolve_latest_active_spare_tas_id($connect, $tsp_id, $todayYmd);

        $lines = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tasId = isset($row['tas_id']) ? (int) $row['tas_id'] : 0;
            $seqNo = $tasId > 0 && isset($seqMap[$tasId]) ? (int) $seqMap[$tasId] : 0;
            $line = maintenance_job_process_spare_activate_row_to_timeline_line(
                $row,
                $seqNo,
                $todayYmd,
                $latestActiveTasId
            );
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}

if (!function_exists('maintenance_job_process_echo_step_title')) {
    /**
     * Purpose:
     * แสดงหัวข้อขั้น timeline — รองรับส่วน `(จำนวนวันที่รอ X วัน)` แยกสีเมื่อ `emphasize_wait`
     *
     * Parameters สำคัญ:
     * - array $step: แถวขั้นจาก `maintenance_job_process_build_timeline()` (คีย์ `title`, `wait_days`, `emphasize_wait`)
     *
     * Return value:
     * - void (echo HTML)
     */
    function maintenance_job_process_echo_step_title(array $step)
    {
        $title = isset($step['title']) ? (string) $step['title'] : '';
        echo maintenance_status_logistic_h($title);
        if (isset($step['wait_days']) && $step['wait_days'] !== null && $step['wait_days'] !== '') {
            $wd = (int) $step['wait_days'];
            $cls = 'mn-jp-step-wait';
            if (!empty($step['emphasize_wait'])) {
                $cls .= ' mn-jp-step-wait--hot';
            }
            echo ' <span class="' . $cls . '">(จำนวนวันที่รอ ';
            echo (int) $wd;
            echo ' วัน)</span>';
        }
    }
}

if (!function_exists('maintenance_job_process_echo_step_spare_activate_add')) {
    /**
     * Purpose:
     * แสดงปุ่ม + ต่ออายุเครื่องสำรองในหัวขั้น timeline เมื่อ `spare_activate_ui.can_add`
     *
     * Parameters สำคัญ:
     * - array $step: แถวขั้นจาก build_timeline
     *
     * Return value:
     * - void
     */
    function maintenance_job_process_echo_step_spare_activate_add(array $step)
    {
        $ui = isset($step['spare_activate_ui']) && is_array($step['spare_activate_ui']) ? $step['spare_activate_ui'] : null;
        if ($ui === null) {
            return;
        }

        if (!empty($ui['quota_exhausted']) || empty($ui['can_add'])) {
            return;
        }

        $trpId = isset($ui['trp_id']) ? (int) $ui['trp_id'] : 0;
        $tspId = isset($ui['tsp_id']) ? (int) $ui['tsp_id'] : 0;
        $count = isset($ui['count']) ? (int) $ui['count'] : 0;
        $max = isset($ui['max']) ? (int) $ui['max'] : 0;
        $title = 'เพิ่มรหัสต่ออายุเครื่องสำรอง';
        if ($max > 0) {
            $title .= ' (' . $count . '/' . $max . ')';
        }

        echo '<span class="mn-jp-activate-add-wrap">';
        echo '<button type="button" class="mn-jp-activate-add" id="mn-jp-activate-add"';
        echo ' data-trp-id="' . (int) $trpId . '"';
        echo ' data-tsp-id="' . (int) $tspId . '"';
        echo ' title="' . maintenance_status_logistic_h($title) . '"';
        echo ' aria-label="' . maintenance_status_logistic_h($title) . '">';
        echo '<i class="fa fa-plus" aria-hidden="true"></i>';
        echo '</button>';
        echo '<span class="mn-jp-activate-add-label">ขอต่ออายุสำรอง</span>';
        echo '</span>';
    }
}

if (!function_exists('maintenance_job_process_row_duration_text')) {
    /**
     * Purpose:
     * คืนข้อความระยะเวลาดำเนินการของสถานะปัจจุบัน (วัน/เดือน/ปี) — ใช้วันที่อ้างอิงเดียวกับคอลัมน์รอใน `index.php` ผ่าน `cal_wait_date()`
     *
     * Parameters สำคัญ:
     * - array $row: แถว join `transac` + `transac_repair` จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - string ข้อความล้วน (ไม่มี HTML) หรือค่าว่างถ้าไม่มีวันที่อ้างอิง
     */
    function maintenance_job_process_row_duration_text(array $row)
    {
        if (!function_exists('cal_wait_date')) {
            return '';
        }

        $job = isset($row['trp_status_job']) ? (string) $row['trp_status_job'] : '';
        $anchor = '';

        if ($job === 'รอส่งสำรอง' || $job === 'รอเครื่องซ่อม') {
            $anchor = isset($row['ts_date']) ? (string) $row['ts_date'] : '';
        } elseif ($job === 'รอประเมิน') {
            $anchor = isset($row['trp_receive_date']) ? (string) $row['trp_receive_date'] : '';
        } elseif ($job === 'รอเสนอราคา') {
            $anchor = isset($row['trp_rate_date']) ? (string) $row['trp_rate_date'] : '';
        } elseif ($job === 'รออนุมัติซ่อม') {
            $anchor = isset($row['trp_qv_date']) ? (string) $row['trp_qv_date'] : '';
        } elseif ($job === 'รอซ่อม') {
            $anchor = isset($row['trp_firm_qv_date']) ? (string) $row['trp_firm_qv_date'] : '';
        } elseif ($job === 'รอ QC') {
            $anchor = isset($row['trp_success_date']) ? (string) $row['trp_success_date'] : '';
        } elseif ($job === 'รอส่งคืนเครื่องซ่อม') {
            $anchor = isset($row['trp_qc_date']) ? (string) $row['trp_qc_date'] : '';
        } elseif ($job === 'เสร็จสิ้น' || $job === 'รอเครื่องสำรอง') {
            $anchor = isset($row['trp_send_date']) ? (string) $row['trp_send_date'] : '';
        }

        if (trim($anchor) === '') {
            return '';
        }

        return trim(strip_tags(cal_wait_date($anchor)));
    }
}

if (!function_exists('maintenance_job_process_customer_rejected_repair')) {
    /**
     * Purpose:
     * ตรวจว่างานถูกบันทึกว่าลูกค้าไม่อนุมัติซ่อม (`trp_status_firmma`) — ขั้นซ่อม/QC จะถูกข้ามใน timeline
     *
     * Parameters สำคัญ:
     * - string $trp_status_firmma: ค่าจาก `transac_repair.trp_status_firmma`
     *
     * Return value:
     * - bool true เมื่อเป็น «ไม่อนุมัติซ่อม»
     */
    function maintenance_job_process_customer_rejected_repair($trp_status_firmma)
    {
        return trim((string) $trp_status_firmma) === 'ไม่อนุมัติซ่อม';
    }
}

if (!function_exists('maintenance_job_process_fetch_bundle')) {
    /**
     * Purpose:
     * ดึงแถวซ่อม join ใบงาน พร้อมข้อมูลส่งสำรองล่าสุดและวันรับสำรองคืน (ถ้ามี) ด้วย prepared statement
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection (compat odbc)
     * - int $trp_id: รหัส `transac_repair.trp_id`
     *
     * Return value:
     * - array คีย์ `row` (assoc หรือ null), `has_spare_rows` (bool), `spare_send` (assoc|null), `spare_return_max` (string|null)
     */
    function maintenance_job_process_fetch_bundle($connect, $trp_id)
    {
        $id = (int) $trp_id;
        $out = array(
            'row' => null,
            'has_spare_rows' => false,
            'spare_send' => null,
            'spare_return_max' => null,
        );
        if ($id < 1) {
            return $out;
        }

        $sql = 'SELECT tr.trp_id, tr.trp_ts_id, tr.trp_status_job, tr.trp_status_firmma, tr.trp_product, tr.trp_sn, '
            . 'tr.trp_receive_date, tr.trp_rate_date, tr.trp_qv_date, tr.trp_firm_qv_date, '
            . 'tr.trp_success_date, tr.trp_qc_date, tr.trp_send_date, tr.trp_logistics, tr.trp_tracking, tr.trp_sendby, '
            . 'ts.ts_mtn_number, ts.ts_date, ts.ts_enduser_com, ts.ts_security_com, '
            . 'ts.ts_report_name, ts.ts_report_tel, ts.ts_report_line, ts.ts_report_email, '
            . '(SELECT COUNT(*) FROM transac_spare spc WHERE spc.tsp_trp_id = tr.trp_id) AS spare_row_count '
            . 'FROM transac_repair tr INNER JOIN transac ts ON ts.ts_id = tr.trp_ts_id '
            . 'WHERE tr.trp_id = ? LIMIT 1';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return $out;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = maintenance_job_process_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return $out;
        }
        $out['row'] = $row;
        $cnt = isset($row['spare_row_count']) ? (int) $row['spare_row_count'] : 0;
        $out['has_spare_rows'] = $cnt > 0;

        if ($out['has_spare_rows']) {
            $sqlSp = 'SELECT tsp_id, tsp_datesend, tsp_sn, tsp_logistics, tsp_tracking FROM transac_spare '
                . 'WHERE tsp_trp_id = ? AND tsp_datesend IS NOT NULL AND TRIM(tsp_datesend) <> \'\' '
                . 'ORDER BY tsp_id DESC LIMIT 1';
            $st2 = mysqli_prepare($connect, $sqlSp);
            if ($st2 !== false) {
                mysqli_stmt_bind_param($st2, 'i', $id);
                mysqli_stmt_execute($st2);
                $sp = maintenance_job_process_stmt_fetch_assoc($st2);
                mysqli_stmt_close($st2);
                if (is_array($sp)) {
                    $out['spare_send'] = $sp;
                }
            }

            $sqlRet = 'SELECT MAX(tsp_datereturn) AS mx FROM transac_spare WHERE tsp_trp_id = ? '
                . 'AND tsp_datereturn IS NOT NULL AND TRIM(tsp_datereturn) <> \'\'';
            $st3 = mysqli_prepare($connect, $sqlRet);
            if ($st3 !== false) {
                mysqli_stmt_bind_param($st3, 'i', $id);
                mysqli_stmt_execute($st3);
                $r3 = maintenance_job_process_stmt_fetch_assoc($st3);
                mysqli_stmt_close($st3);
                if (is_array($r3) && isset($r3['mx'])) {
                    $out['spare_return_max'] = trim((string) $r3['mx']) !== '' ? trim((string) $r3['mx']) : null;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('maintenance_job_process_resolve_customer_spare_activate_display')) {
    /**
     * Purpose:
     * สรุปรหัสต่ออายุล่าสุดสำหรับหน้า maintenancetracking/status จาก bundle + timeline ที่สร้างแล้ว
     * (ไม่ query DB ซ้ำ — ใช้บรรทัด spare_activate ที่ timeline ประกอบไว้แล้ว)
     *
     * Parameters สำคัญ:
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle`
     * - array $timeline: จาก `maintenance_job_process_build_timeline`
     * - string $today_ymd: วันอ้างอิง Y-m-d (ว่าง = วันนี้)
     *
     * Return value:
     * - array{show_row:bool,code:string,expire_display:string,is_expired:bool,contact_staff:bool}
     */
    function maintenance_job_process_resolve_customer_spare_activate_display(array $bundle, array $timeline, $today_ymd = '')
    {
        $empty = array(
            'show_row' => false,
            'code' => '',
            'expire_display' => '',
            'is_expired' => true,
            'contact_staff' => true,
        );

        require_once __DIR__ . '/transac_activate_spare_helpers.php';

        $meta = isset($timeline['meta']) && is_array($timeline['meta']) ? $timeline['meta'] : array();
        $trpProduct = trim((string) (isset($meta['trp_product']) ? $meta['trp_product'] : ''));
        if ($trpProduct === '') {
            $jobRow = isset($bundle['row']) && is_array($bundle['row']) ? $bundle['row'] : null;
            if ($jobRow !== null) {
                $trpProduct = trim((string) (isset($jobRow['trp_product']) ? $jobRow['trp_product'] : ''));
            }
        }
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return $empty;
        }

        if (empty($bundle['has_spare_rows'])) {
            return array_merge($empty, array('show_row' => true));
        }

        $steps = isset($timeline['steps']) && is_array($timeline['steps']) ? $timeline['steps'] : array();
        $latestLine = null;
        $latestActiveLine = null;

        foreach ($steps as $step) {
            if (!isset($step['lines']) || !is_array($step['lines'])) {
                continue;
            }
            foreach ($step['lines'] as $line) {
                if (!is_array($line) || (isset($line['kind']) ? (string) $line['kind'] : '') !== 'spare_activate') {
                    continue;
                }
                $latestLine = $line;
                if (!empty($line['show_full_code'])) {
                    $latestActiveLine = $line;
                }
            }
        }

        if ($latestLine === null) {
            return array_merge($empty, array('show_row' => true));
        }

        $displayLine = $latestActiveLine !== null ? $latestActiveLine : $latestLine;
        $expireDisplay = trim((string) (isset($displayLine['expire_display']) ? $displayLine['expire_display'] : ''));
        $isExpired = !empty($displayLine['is_expired']);

        if ($isExpired) {
            return array(
                'show_row' => true,
                'code' => '',
                'expire_display' => $expireDisplay,
                'is_expired' => true,
                'contact_staff' => true,
            );
        }

        $code = trim((string) (isset($displayLine['code']) ? $displayLine['code'] : ''));

        return array(
            'show_row' => true,
            'code' => $code,
            'expire_display' => $expireDisplay,
            'is_expired' => false,
            'contact_staff' => ($code === ''),
        );
    }
}

if (!function_exists('maintenance_job_process_build_timeline')) {
    /**
     * Purpose:
     * ประกอบขั้นตอน timeline, สถานะ done/current/upcoming, ตัวเลขวันรอ (ตามกฎธุรกิจ), และ flag เน้นเมื่อรอ > 10 วัน
     *
     * Parameters สำคัญ:
     * - array $bundle: ผลจาก `maintenance_job_process_fetch_bundle()`
     * - string $today_ymd: วันที่อ้างอิงปัจจุบัน `Y-m-d` (มักจาก `date('Y-m-d')` โซนกรุงเทพ)
     * - mysqli|null $connect: connection (ถ้ามี — ใช้ดึงรหัสต่ออายุสำรองใต้ขั้นรับเครื่องซ่อม)
     *
     * Return value:
     * - array คีย์ `meta` (ข้อมูลหัวหน้า), `steps` (รายการขั้นตอนลำดับ), `has_spare_rows`, `job_done`
     */
    function maintenance_job_process_build_timeline(array $bundle, $today_ymd, $connect = null)
    {
        $today = maintenance_job_process_parse_date($today_ymd);
        if ($today === null) {
            $today = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
            $today->setTime(0, 0, 0);
        }

        $row = isset($bundle['row']) && is_array($bundle['row']) ? $bundle['row'] : null;
        $hasSpare = !empty($bundle['has_spare_rows']);
        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;

        $meta = array(
            'ts_mtn_number' => '',
            'trp_product' => '',
            'trp_sn' => '',
            'ts_enduser_com' => '',
            'ts_security_com' => '',
            'ts_report_name' => '',
            'ts_report_tel' => '',
            'ts_report_line' => '',
            'trp_status_job' => '',
        );
        if ($row !== null) {
            $meta['ts_mtn_number'] = isset($row['ts_mtn_number']) ? (string) $row['ts_mtn_number'] : '';
            $meta['trp_product'] = isset($row['trp_product']) ? (string) $row['trp_product'] : '';
            $meta['trp_sn'] = isset($row['trp_sn']) ? (string) $row['trp_sn'] : '';
            $meta['ts_enduser_com'] = isset($row['ts_enduser_com']) ? (string) $row['ts_enduser_com'] : '';
            $meta['ts_security_com'] = isset($row['ts_security_com']) ? (string) $row['ts_security_com'] : '';
            $meta['ts_report_name'] = isset($row['ts_report_name']) ? (string) $row['ts_report_name'] : '';
            $meta['ts_report_tel'] = isset($row['ts_report_tel']) ? (string) $row['ts_report_tel'] : '';
            $meta['ts_report_line'] = isset($row['ts_report_line']) ? (string) $row['ts_report_line'] : '';
            $meta['trp_status_job'] = isset($row['trp_status_job']) ? (string) $row['trp_status_job'] : '';
        }

        $jobDone = $meta['trp_status_job'] === 'เสร็จสิ้น';

        $trpId = $row !== null && isset($row['trp_id']) ? (int) $row['trp_id'] : 0;

        $tsDate = $row !== null ? maintenance_job_process_parse_date(isset($row['ts_date']) ? $row['ts_date'] : '') : null;
        $spareSendDate = null;
        if ($spSend !== null && isset($spSend['tsp_datesend'])) {
            $spareSendDate = maintenance_job_process_parse_date($spSend['tsp_datesend']);
        }
        $recv = $row !== null ? maintenance_job_process_parse_date(isset($row['trp_receive_date']) ? $row['trp_receive_date'] : '') : null;
        $rate = $row !== null ? maintenance_job_process_parse_date(isset($row['trp_rate_date']) ? $row['trp_rate_date'] : '') : null;
        $qv = $row !== null ? maintenance_job_process_parse_date(isset($row['trp_qv_date']) ? $row['trp_qv_date'] : '') : null;
        $firm = $row !== null ? maintenance_job_process_parse_date(isset($row['trp_firm_qv_date']) ? $row['trp_firm_qv_date'] : '') : null;
        $rejectRepair = $row !== null
            && maintenance_job_process_customer_rejected_repair(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : '');
        $qc = $row !== null ? maintenance_job_process_parse_date(isset($row['trp_qc_date']) ? $row['trp_qc_date'] : '') : null;
        $sendRepair = $row !== null ? maintenance_job_process_parse_date(isset($row['trp_send_date']) ? $row['trp_send_date'] : '') : null;
        $retMax = maintenance_job_process_parse_date(isset($bundle['spare_return_max']) ? (string) $bundle['spare_return_max'] : '');

        $steps = array();

        // 1 ส่งสำรอง — แสดงสถานะเดียวตามความเป็นจริง (ไม่มีหัวข้อย่อย 1.1 / 1.2)
        if ($hasSpare) {
            $lines1 = array();
            if ($spareSendDate !== null) {
                $title1 = 'ส่งสำรองแล้ว';
                if ($spSend !== null) {
                    $spSn = trim((string) (isset($spSend['tsp_sn']) ? $spSend['tsp_sn'] : ''));
                    if ($spSn !== '') {
                        $title1 .= ' S:N ' . $spSn;
                    }
                    $lg = isset($spSend['tsp_logistics']) ? trim((string) $spSend['tsp_logistics']) : '';
                    $tk = isset($spSend['tsp_tracking']) ? trim((string) $spSend['tsp_tracking']) : '';
                    if ($lg !== '' || $tk !== '') {
                        $lines1[] = maintenance_job_process_line_logistics_tracking($lg, $tk, 'spare_' . $trpId);
                    }
                }
                $title1 .= ' (' . $spareSendDate->format('d/m/Y') . ')';
                $step1Done = true;
            } else {
                $title1 = 'รอส่งสำรอง';
                $step1Done = false;
            }
            $steps[] = array(
                'key' => '1',
                'title' => $title1,
                'lines' => $lines1,
                'done' => $step1Done,
            );
        }

        // 2 เครื่องซ่อม — แสดงสถานะเดียวตามความเป็นจริง (ไม่มีหัวข้อย่อย 2.1 / 2.2)
        $lines2 = array();
        $waitDays2 = null;
        $emphWait2 = false;
        $spareActivateUiStep2 = array(
            'can_add' => false,
            'count' => 0,
            'max' => 0,
        );
        if ($recv === null) {
            $title2 = 'รอเครื่องซ่อม';
            if ($hasSpare && $spareSendDate !== null) {
                $w2 = maintenance_job_process_days_diff($spareSendDate, $today);
                if ($w2 !== null && $w2 < 0) {
                    $w2 = 0;
                }
                if ($w2 !== null) {
                    $waitDays2 = $w2;
                    $emphWait2 = $w2 > 7;
                }
            }
            $step2Done = false;
            if ($connect instanceof mysqli && $trpId > 0 && $hasSpare) {
                require_once __DIR__ . '/transac_activate_spare_helpers.php';
                $trpProductStep2 = isset($meta['trp_product']) ? trim((string) $meta['trp_product']) : '';
                if (maintenance_transac_activate_spare_product_eligible_for_auto($trpProductStep2)) {
                    $tspIdStep2Wait = 0;
                    if ($spSend !== null && isset($spSend['tsp_id'])) {
                        $tspIdStep2Wait = (int) $spSend['tsp_id'];
                    }
                    if ($tspIdStep2Wait > 0) {
                        $waitHardwareLines = maintenance_job_process_fetch_spare_activate_timeline_lines(
                            $connect,
                            $tspIdStep2Wait,
                            $trpProductStep2,
                            'exact',
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE
                        );
                        foreach ($waitHardwareLines as $waitHardwareLine) {
                            $lines2[] = $waitHardwareLine;
                        }
                        $spareActivateUiStep2 = maintenance_transac_activate_spare_wait_hardware_ui_context(
                            $connect,
                            $trpId,
                            $bundle
                        );
                        if (!empty($spareActivateUiStep2['quota_exhausted'])) {
                            $quotaMsg2 = isset($spareActivateUiStep2['quota_message'])
                                && trim((string) $spareActivateUiStep2['quota_message']) !== ''
                                ? trim((string) $spareActivateUiStep2['quota_message'])
                                : 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนดแล้ว';
                            $lines2[] = array(
                                'kind' => 'spare_activate_quota',
                                'message' => $quotaMsg2,
                            );
                        }
                    }
                }
            }
        } else {
            $title2 = 'รับเครื่องซ่อมแล้ว (' . $recv->format('d/m/Y') . ')';
            $step2Done = true;
            if ($connect instanceof mysqli && $spSend !== null && isset($spSend['tsp_id'])) {
                $tspIdStep2 = (int) $spSend['tsp_id'];
                if ($tspIdStep2 > 0) {
                    require_once __DIR__ . '/transac_activate_spare_helpers.php';
                    $earlyActivateLines = maintenance_job_process_fetch_spare_activate_timeline_lines(
                        $connect,
                        $tspIdStep2,
                        isset($meta['trp_product']) ? $meta['trp_product'] : '',
                        'exclude_jobs',
                        array(
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE,
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE,
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE_LEGACY,
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR,
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_SPARE,
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE,
                        )
                    );
                    foreach ($earlyActivateLines as $earlyLine) {
                        $lines2[] = $earlyLine;
                    }
                }
            }
        }
        $steps[] = array(
            'key' => '2',
            'title' => $title2,
            'lines' => $lines2,
            'wait_days' => $waitDays2,
            'emphasize_wait' => $emphWait2,
            'done' => $step2Done,
            'spare_activate_ui' => $spareActivateUiStep2,
        );

        // 3 ประเมิน — หัวข้อหลักอย่างเดียว
        if ($rate !== null) {
            $title3 = 'ประเมินเสร็จ (' . $rate->format('d/m/Y') . ')';
            $step3Done = true;
        } else {
            $title3 = 'รอประเมินเครื่องซ่อม';
            $step3Done = false;
        }
        $steps[] = array(
            'key' => '3',
            'title' => $title3,
            'lines' => array(),
            'done' => $step3Done,
        );

        // 4 เสนอราคา
        $lines4 = array();
        $spareActivateUiStep4 = array(
            'can_add' => false,
            'count' => 0,
            'max' => 0,
        );
        if ($qv !== null) {
            $title4 = 'เสนอราคาแล้ว (' . $qv->format('d/m/Y') . ')';
            $step4Done = true;
        } else {
            $title4 = 'รอเสนอราคา';
            $step4Done = false;
        }
        if ($qv !== null && $connect instanceof mysqli && $trpId > 0 && !$rejectRepair) {
            require_once __DIR__ . '/transac_activate_spare_helpers.php';
            $trpProductStep4 = isset($meta['trp_product']) ? trim((string) $meta['trp_product']) : '';
            if (maintenance_transac_activate_spare_product_eligible_for_auto($trpProductStep4)) {
                $tspIdStep4 = 0;
                if ($spSend !== null && isset($spSend['tsp_id'])) {
                    $tspIdStep4 = (int) $spSend['tsp_id'];
                }
                if ($tspIdStep4 > 0) {
                    $offerActivateLines = maintenance_job_process_fetch_spare_activate_timeline_lines(
                        $connect,
                        $tspIdStep4,
                        $trpProductStep4,
                        'exact_jobs',
                        array(
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE,
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE_LEGACY,
                        )
                    );
                    foreach ($offerActivateLines as $offerLine) {
                        $lines4[] = $offerLine;
                    }
                    $statusJobStep4 = $row !== null
                        ? trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''))
                        : '';
                    // ช่วงรออนุมัติซ่อม — ปุ่มอยู่ขั้น 5 เท่านั้น (ไม่ซ้ำกับขั้นเสนอราคาแล้ว)
                    if ($firm === null && $statusJobStep4 !== MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE) {
                        $spareActivateUiStep4 = maintenance_transac_activate_spare_offer_price_ui_context(
                            $connect,
                            $trpId,
                            $bundle
                        );
                        if (!empty($spareActivateUiStep4['quota_exhausted'])) {
                            $quotaMsg4 = isset($spareActivateUiStep4['quota_message'])
                                && trim((string) $spareActivateUiStep4['quota_message']) !== ''
                                ? trim((string) $spareActivateUiStep4['quota_message'])
                                : 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนดแล้ว';
                            $lines4[] = array(
                                'kind' => 'spare_activate_quota',
                                'message' => $quotaMsg4,
                            );
                        }
                    }
                }
            }
        }
        $steps[] = array(
            'key' => '4',
            'title' => $title4,
            'lines' => $lines4,
            'done' => $step4Done,
            'spare_activate_ui' => $spareActivateUiStep4,
        );

        // 5 อนุมัติ
        $waitDays5 = null;
        $emphWait5 = false;
        $lines5 = array();
        $spareActivateUi = array(
            'can_add' => false,
            'count' => 0,
            'max' => 0,
        );
        if ($firm !== null) {
            if ($rejectRepair) {
                $title5 = 'ลูกค้าไม่ซ่อม — ไม่อนุมัติ (' . $firm->format('d/m/Y') . ')';
            } else {
                $title5 = 'อนุมัติซ่อมแล้ว (' . $firm->format('d/m/Y') . ')';
            }
            $step5Done = true;
        } else {
            $title5 = 'รออนุมัติซ่อม';
            $step5Done = false;
            if ($qv !== null) {
                $w5 = maintenance_job_process_days_diff($qv, $today);
                if ($w5 !== null && $w5 < 0) {
                    $w5 = 0;
                }
                if ($w5 !== null) {
                    $waitDays5 = $w5;
                    $emphWait5 = $w5 > 7;
                }
            }
        }
        if ($connect instanceof mysqli && $trpId > 0 && !$rejectRepair) {
            require_once __DIR__ . '/transac_activate_spare_helpers.php';
            $trpProductStep5 = isset($meta['trp_product']) ? trim((string) $meta['trp_product']) : '';
            if (maintenance_transac_activate_spare_product_eligible_for_auto($trpProductStep5)) {
                $tspIdStep5 = 0;
                if ($spSend !== null && isset($spSend['tsp_id'])) {
                    $tspIdStep5 = (int) $spSend['tsp_id'];
                }
                if ($tspIdStep5 > 0) {
                    $approveLines = maintenance_job_process_fetch_spare_activate_timeline_lines(
                        $connect,
                        $tspIdStep5,
                        $trpProductStep5,
                        'exact',
                        MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE
                    );
                    foreach ($approveLines as $approveLine) {
                        $lines5[] = $approveLine;
                    }
                    if (!$step5Done) {
                        $spareActivateUi = maintenance_transac_activate_spare_inapprove_ui_context($connect, $trpId, $bundle);
                        if (!empty($spareActivateUi['quota_exhausted'])) {
                            $quotaMsg = isset($spareActivateUi['quota_message'])
                                && trim((string) $spareActivateUi['quota_message']) !== ''
                                ? trim((string) $spareActivateUi['quota_message'])
                                : 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนดแล้ว';
                            $lines5[] = array(
                                'kind' => 'spare_activate_quota',
                                'message' => $quotaMsg,
                            );
                        }
                    }
                }
            }
        }
        $steps[] = array(
            'key' => '5',
            'title' => $title5,
            'lines' => $lines5,
            'wait_days' => $waitDays5,
            'emphasize_wait' => $emphWait5,
            'done' => $step5Done,
            'spare_activate_ui' => $spareActivateUi,
        );

        // 6 ซ่อม + QC — ข้ามเมื่อลูกค้าไม่อนุมัติซ่อม (สอดคล้อง `update_wait_ma` / `update_wait_firmQV`)
        $lines6 = array();
        $spareActivateUiStep6 = array(
            'can_add' => false,
            'count' => 0,
            'max' => 0,
        );
        if ($rejectRepair) {
            $title6 = 'ข้ามขั้นซ่อมและ QC (ลูกค้าไม่ซ่อม)';
            $step6Done = true;
        } elseif ($qc !== null) {
            $title6 = 'ซ่อมและ QC เสร็จ (' . $qc->format('d/m/Y') . ')';
            $step6Done = true;
        } else {
            $title6 = 'รอซ่อม และ QC';
            $step6Done = false;
        }
        if (!$rejectRepair && $firm !== null && $connect instanceof mysqli && $trpId > 0) {
            require_once __DIR__ . '/transac_activate_spare_helpers.php';
            $trpProductStep6 = isset($meta['trp_product']) ? trim((string) $meta['trp_product']) : '';
            if (maintenance_transac_activate_spare_product_eligible_for_auto($trpProductStep6)) {
                $tspIdStep6 = 0;
                if ($spSend !== null && isset($spSend['tsp_id'])) {
                    $tspIdStep6 = (int) $spSend['tsp_id'];
                }
                if ($tspIdStep6 > 0) {
                    $waitRepairLines = maintenance_job_process_fetch_spare_activate_timeline_lines(
                        $connect,
                        $tspIdStep6,
                        $trpProductStep6,
                        'exact',
                        MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR
                    );
                    foreach ($waitRepairLines as $waitRepairLine) {
                        $lines6[] = $waitRepairLine;
                    }
                    if (!$step6Done) {
                        $spareActivateUiStep6 = maintenance_transac_activate_spare_wait_repair_ui_context(
                            $connect,
                            $trpId,
                            $bundle
                        );
                        if (!empty($spareActivateUiStep6['quota_exhausted'])) {
                            $quotaMsg6 = isset($spareActivateUiStep6['quota_message'])
                                && trim((string) $spareActivateUiStep6['quota_message']) !== ''
                                ? trim((string) $spareActivateUiStep6['quota_message'])
                                : 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนดแล้ว';
                            $lines6[] = array(
                                'kind' => 'spare_activate_quota',
                                'message' => $quotaMsg6,
                            );
                        }
                    }
                }
            }
        }
        $steps[] = array(
            'key' => '6',
            'title' => $title6,
            'lines' => $lines6,
            'done' => $step6Done,
            'skipped' => $rejectRepair,
            'spare_activate_ui' => $spareActivateUiStep6,
        );

        // 7 ส่งคืนเครื่องซ่อม
        $lines7 = array();
        if ($sendRepair !== null) {
            $title7 = 'ส่งคืนเครื่องซ่อม (' . $sendRepair->format('d/m/Y') . ')';
            $lg7 = $row !== null ? trim((string) (isset($row['trp_logistics']) ? $row['trp_logistics'] : '')) : '';
            $tk7 = $row !== null ? trim((string) (isset($row['trp_tracking']) ? $row['trp_tracking'] : '')) : '';
            if ($lg7 !== '' || $tk7 !== '') {
                $lines7[] = maintenance_job_process_line_logistics_tracking($lg7, $tk7, 'repair_' . $trpId);
            }
            $step7Done = true;
        } else {
            $title7 = 'รอส่งคืนเครื่องซ่อม';
            $step7Done = false;
        }
        $steps[] = array(
            'key' => '7',
            'title' => $title7,
            'lines' => $lines7,
            'done' => $step7Done,
        );

        // 8 รอเครื่องสำรอง (เฉพาะมี spare) — สถานะเดียวตามความเป็นจริง
        if ($hasSpare) {
            $lines8 = array();
            $waitDays8 = null;
            $emphWait8 = false;
            $spareActivateUiStep8 = array(
                'can_add' => false,
                'count' => 0,
                'max' => 0,
            );
            if ($retMax !== null) {
                $title8 = 'รับเครื่องสำรองแล้ว (' . $retMax->format('d/m/Y') . ')';
                $step8Done = true;
            } else {
                $title8 = 'รอเครื่องสำรอง';
                $step8Done = false;
                if ($sendRepair !== null) {
                    $gate = clone $sendRepair;
                    $gate->modify('+2 days');
                    $cmp = maintenance_job_process_days_diff($gate, $today);
                    if ($cmp !== null && $cmp >= 0) {
                        $w8 = maintenance_job_process_days_diff($gate, $today);
                        if ($w8 !== null && $w8 < 0) {
                            $w8 = 0;
                        }
                        if ($w8 !== null) {
                            $waitDays8 = $w8;
                            $emphWait8 = $w8 > 7;
                        }
                    }
                }
            }
            if ($connect instanceof mysqli && $trpId > 0) {
                require_once __DIR__ . '/transac_activate_spare_helpers.php';
                $trpProductStep8 = isset($meta['trp_product']) ? trim((string) $meta['trp_product']) : '';
                if (maintenance_transac_activate_spare_product_eligible_for_auto($trpProductStep8)) {
                    $tspIdStep8 = 0;
                    if ($spSend !== null && isset($spSend['tsp_id'])) {
                        $tspIdStep8 = (int) $spSend['tsp_id'];
                    }
                    if ($tspIdStep8 > 0) {
                        $waitSpareLines = maintenance_job_process_fetch_spare_activate_timeline_lines(
                            $connect,
                            $tspIdStep8,
                            $trpProductStep8,
                            'exact',
                            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_SPARE
                        );
                        foreach ($waitSpareLines as $waitSpareLine) {
                            $lines8[] = $waitSpareLine;
                        }
                    }
                    if ($retMax === null) {
                        $spareActivateUiStep8 = maintenance_transac_activate_spare_wait_spare_ui_context(
                            $connect,
                            $trpId,
                            $bundle
                        );
                        if (!empty($spareActivateUiStep8['quota_exhausted'])) {
                            $quotaMsg8 = isset($spareActivateUiStep8['quota_message'])
                                && trim((string) $spareActivateUiStep8['quota_message']) !== ''
                                ? trim((string) $spareActivateUiStep8['quota_message'])
                                : 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนดแล้ว';
                            $lines8[] = array(
                                'kind' => 'spare_activate_quota',
                                'message' => $quotaMsg8,
                            );
                        }
                    }
                }
            }
            $steps[] = array(
                'key' => '8',
                'title' => $title8,
                'lines' => $lines8,
                'wait_days' => $waitDays8,
                'emphasize_wait' => $emphWait8,
                'done' => $step8Done,
                'spare_activate_ui' => $spareActivateUiStep8,
            );
        }

        // 9 เสร็จสิ้น
        $steps[] = array(
            'key' => '9',
            'title' => 'เสร็จสิ้น',
            'lines' => array(),
            'done' => $jobDone,
        );

        // กำหนดสถานะขั้นหลัก done/current/upcoming
        maintenance_job_process_apply_step_states($steps);

        return array(
            'meta' => $meta,
            'steps' => $steps,
            'has_spare_rows' => $hasSpare,
            'job_done' => $jobDone,
        );
    }
}

if (!function_exists('maintenance_job_process_apply_step_states')) {
    /**
     * Purpose:
     * เติมคีย์ `state` ในแต่ละขั้น (`done` | `current` | `upcoming`) โดยเดินตามลำดับจนพบขั้นแรกที่ยังไม่ done
     *
     * Parameters สำคัญ:
     * - array &$steps: อ้างอิงแบบลึกจาก `maintenance_job_process_build_timeline()`
     *
     * Return value:
     * - void
     */
    function maintenance_job_process_apply_step_states(array &$steps)
    {
        $foundCurrent = false;
        foreach ($steps as &$st) {
            $allDone = true;
            $children = isset($st['children']) && is_array($st['children']) ? $st['children'] : array();
            if (count($children) > 0) {
                foreach ($children as &$ch) {
                    if (empty($ch['done'])) {
                        $allDone = false;
                    }
                }
                unset($ch);
            } elseif (array_key_exists('done', $st)) {
                $allDone = !empty($st['done']);
            } else {
                $allDone = false;
            }

            if ($allDone) {
                $st['state'] = 'done';
                continue;
            }
            if (!$foundCurrent) {
                $st['state'] = 'current';
                $foundCurrent = true;
                if (isset($st['children']) && is_array($st['children'])) {
                    $fc = false;
                    foreach ($st['children'] as &$ch2) {
                        if (!empty($ch2['done'])) {
                            $ch2['state'] = 'done';
                        } elseif (!$fc) {
                            $ch2['state'] = 'current';
                            $fc = true;
                        } else {
                            $ch2['state'] = 'upcoming';
                        }
                    }
                    unset($ch2);
                }
            } else {
                $st['state'] = 'upcoming';
                if (isset($st['children']) && is_array($st['children'])) {
                    foreach ($st['children'] as &$ch3) {
                        $ch3['state'] = 'upcoming';
                    }
                    unset($ch3);
                }
            }
        }
        unset($st);
    }
}
