<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกและอ่านประวัติต่ออายุเครื่องสำรองจากตาราง `transac_activate_spare`
 *
 * Flow:
 * - บันทึก: `maintenance_transac_activate_spare_insert($connect, $row)`
 * - ดึงตาม tsp: `maintenance_transac_activate_spare_fetch_by_tsp_id($connect, $tsp_id)`
 * - นับตามสถานะงาน: `maintenance_transac_activate_spare_count_by_tsp_and_status($connect, $tsp_id, $status)`
 * - สร้างรหัส: `maintenance_transac_activate_spare_generate_unique_code($connect)`
 * - คำขอต่ออายุจาก `job_process.php` (ปุ่ม + modal): รอเครื่องซ่อม / รออนุมัติซ่อม / เสนอราคาแล้ว / รอซ่อม / รอเครื่องสำรอง
 * - Backfill งานเก่าเมื่อเปิด `job_process`: ถ้ายังไม่มีแถวใน `transac_activate_spare` เลย — รับเครื่องซ่อมแล้ว: เริ่ม = วันรับ + 1, หมดอายุ = เริ่ม + priod_wait_qc (system); ขั้นรอเครื่องซ่อม/ extension อื่นใช้ปุ่มคำขอ
 * - ฟังก์ชัน `record_auto_*` ที่เรียกจากหน้าอื่น — **ไม่สร้างรหัส** (คืน 0); backfill ทำเฉพาะ `ensure_on_job_process_view`
 *
 * Example:
 * $tas_id = maintenance_transac_activate_spare_insert($connect, array(
 *     'tas_tsp_id' => 904,
 *     'tas_trp_status_job' => 'ติดตามใบเสนอราคา',
 *     'tas_activate_date' => '2026-05-16',
 *     'tas_expire_date' => '2026-06-06',
 *     'tas_request_name' => 'คุณสมชาย',
 *     'tas_request_contact' => '0812345678',
 *     'tas_request_reason' => 'รอเปิด PO',
 *     'tas_staff_user' => 'Palm',
 * ));
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

if (!defined('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE')) {
    define('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE', 'รออนุมัติซ่อม');
}

if (!defined('MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE')) {
    define('MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE', 'เสนอราคาแล้ว');
}

if (!defined('MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE_LEGACY')) {
    define('MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE_LEGACY', 'รอเสนอราคา');
}

if (!defined('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR')) {
    define('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR', 'รอซ่อม');
}

if (!defined('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_SPARE')) {
    define('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_SPARE', 'รอเครื่องสำรอง');
}

if (!defined('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE')) {
    define('MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE', 'รอเครื่องซ่อม');
}

if (!defined('MAINTENANCE_ACTIVATE_SPARE_MAX_DAYS_WAIT_HARDWARE')) {
    define('MAINTENANCE_ACTIVATE_SPARE_MAX_DAYS_WAIT_HARDWARE', 7);
}

if (!defined('MAINTENANCE_ACTIVATE_SPARE_STATUS_AFTER_RECEIVE_REPAIR')) {
    define('MAINTENANCE_ACTIVATE_SPARE_STATUS_AFTER_RECEIVE_REPAIR', 'รับเครื่องซ่อมแล้ว');
}

// ─────────────────────────────────────────────────────────────────
// helpers — QR code / auto activate
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_transac_activate_spare_product_eligible_for_auto')) {
    /**
     * Purpose:
     * ตรวจว่ารุ่นเครื่องสำรองเข้าเงื่อนไขต่ออายุอัตโนมัติเมื่อบันทึกวันที่ส่ง (bitVisitor Plus / M)
     *
     * Parameters สำคัญ:
     * - string $tsp_product: `transac_spare.tsp_product`
     *
     * Return value:
     * - bool
     */
    function maintenance_transac_activate_spare_product_eligible_for_auto($tsp_product)
    {
        $p = trim((string) $tsp_product);

        return $p === 'bitVisitor Plus' || $p === 'bitVisitor M';
    }
}

if (!function_exists('maintenance_transac_activate_spare_normalize_date_ymd')) {
    /**
     * Purpose:
     * แปลงวันที่เป็นรูปแบบ Y-m-d สำหรับคอลัมน์ date
     *
     * Parameters สำคัญ:
     * - string $date: วันที่ (มักเป็น Y-m-d)
     *
     * Return value:
     * - string Y-m-d หรือค่าว่างเมื่อไม่ถูกต้อง
     */
    function maintenance_transac_activate_spare_normalize_date_ymd($date)
    {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $parts = explode('-', $date);
            if (count($parts) === 3 && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                return $date;
            }
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }

        return date('Y-m-d', $ts);
    }
}

if (!function_exists('maintenance_transac_activate_spare_add_days_ymd')) {
    /**
     * Purpose:
     * บวกจำนวนวันลงวันที่ Y-m-d
     *
     * Parameters สำคัญ:
     * - string $date_ymd: วันที่เริ่ม
     * - int $days: จำนวนวัน (>= 0)
     *
     * Return value:
     * - string Y-m-d หรือค่าว่างเมื่อ parse ไม่ได้
     */
    function maintenance_transac_activate_spare_add_days_ymd($date_ymd, $days)
    {
        $date_ymd = maintenance_transac_activate_spare_normalize_date_ymd($date_ymd);
        if ($date_ymd === '') {
            return '';
        }
        $days = max(0, (int) $days);
        try {
            $dt = new DateTime($date_ymd);
            if ($days > 0) {
                $dt->modify('+' . $days . ' days');
            }

            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            return '';
        }
    }
}

if (!function_exists('maintenance_transac_activate_spare_compute_code_from_expire_date')) {
    /**
     * Purpose:
     * คำนวณรหัสต่ออายุจากวันหมดอายุ (สูตรเดิม QR: 212224 + (dateCode*100 - dateCode))
     *
     * Parameters สำคัญ:
     * - string $expire_date_ymd: วันหมดอายุ Y-m-d
     *
     * Return value:
     * - array{code:string,code_id:int,code_display:string} ค่าว่างเมื่อคำนวณไม่ได้
     */
    function maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd)
    {
        $expire_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd($expire_date_ymd);
        if ($expire_date_ymd === '') {
            return array('code' => '', 'code_id' => 0, 'code_display' => '');
        }

        $ymd = str_replace('-', '', $expire_date_ymd);
        if (strlen($ymd) < 8) {
            return array('code' => '', 'code_id' => 0, 'code_display' => '');
        }

        $dateCodeNum = '1' . substr($ymd, 2, 6) . '1';
        $dateCodeInt = (int) $dateCodeNum;
        $codeId = 212224 + (($dateCodeInt * 100) - $dateCodeInt);
        $code = (string) $codeId;

        $arr = str_split($code);
        $codeDisplay = $code;
        if (count($arr) >= 10) {
            $codeDisplay = $arr[0] . $arr[1] . $arr[2] . ' '
                . $arr[3] . $arr[4] . $arr[5] . ' '
                . $arr[6] . $arr[7] . $arr[8] . ' '
                . $arr[9];
        }

        return array(
            'code' => $code,
            'code_id' => $codeId,
            'code_display' => $codeDisplay,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_record_auto_insert_row')) {
    /**
     * Purpose:
     * สร้างแถว `transac_activate_spare` จากวันที่ส่งสำรอง (ไม่ตรวจรุ่น — ผู้เรียกต้องกรองเงื่อนไขรุ่นแล้ว)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - int $trp_id: transac_repair.trp_id
     * - string $activate_date_ymd: วันที่ส่งสำรอง (Y-m-d)
     *
     * Return value:
     * - int tas_id ที่ insert สำเร็จ หรือ 0 เมื่อข้าม/ล้มเหลว
     */
    function maintenance_transac_activate_spare_record_auto_insert_row($connect, $tsp_id, $trp_id, $activate_date_ymd)
    {
        $tsp_id = (int) $tsp_id;
        $trp_id = (int) $trp_id;
        $activate_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd($activate_date_ymd);
        if ($tsp_id < 1 || $trp_id < 1 || $activate_date_ymd === '') {
            return 0;
        }
        if (!($connect instanceof mysqli) || !maintenance_transac_activate_spare_table_available($connect)) {
            return 0;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        require_once __DIR__ . '/transac_setting_helpers.php';

        $sql = 'SELECT trp.trp_status_job FROM transac_spare tsp'
            . ' INNER JOIN transac_repair trp ON trp.trp_id = tsp.tsp_trp_id'
            . ' WHERE tsp.tsp_id = ? AND tsp.tsp_trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'ii', $tsp_id, $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return 0;
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return 0;
        }

        $status_job = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PERIOD_FIRST_SPARE,
            21
        );
        $expire_date_ymd = maintenance_transac_activate_spare_add_days_ymd($activate_date_ymd, $periodDays);
        if ($expire_date_ymd === '') {
            return 0;
        }

        $codePack = maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd);
        $code = isset($codePack['code']) ? (string) $codePack['code'] : '';
        if ($code === '') {
            return 0;
        }

        return maintenance_transac_activate_spare_insert($connect, array(
            'tas_tsp_id' => $tsp_id,
            'tas_trp_status_job' => $status_job,
            'tas_activate_date' => $activate_date_ymd,
            'tas_expire_date' => $expire_date_ymd,
            'tas_activate_code' => $code,
            'tas_request_name' => 'system',
            'tas_request_contact' => '',
            'tas_request_reason' => 'auto activate',
            'tas_staff_user' => 'system',
        ));
    }
}

if (!function_exists('maintenance_transac_activate_spare_record_auto_on_spare_send')) {
    /**
     * Purpose:
     * บันทึก `transac_activate_spare` อัตโนมัติหลังอัปเดตวันที่ส่งสำรอง — เฉพาะรุ่นสำรอง bitVisitor Plus / M
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - int $trp_id: transac_repair.trp_id
     * - string $activate_date_ymd: วันที่ส่งสำรอง (Y-m-d)
     *
     * Return value:
     * - int tas_id ที่ insert สำเร็จ หรือ 0 เมื่อข้าม/ล้มเหลว (ไม่ throw)
     */
    function maintenance_transac_activate_spare_record_auto_on_spare_send($connect, $tsp_id, $trp_id, $activate_date_ymd)
    {
        return 0;
    }
}

if (!function_exists('maintenance_transac_activate_spare_has_auto_activate_for_status')) {
    /**
     * Purpose:
     * ตรวจว่ามีแถวต่ออายุอัตโนมัติ (`tas_request_reason = auto activate`) สำหรับ tsp + สถานะงานแล้วหรือไม่
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - string $status_job: ค่า tas_trp_status_job
     *
     * Return value:
     * - bool
     */
    function maintenance_transac_activate_spare_has_auto_activate_for_status($connect, $tsp_id, $status_job)
    {
        $tsp_id = (int) $tsp_id;
        $status_job = trim((string) $status_job);
        if ($tsp_id < 1 || $status_job === '' || !($connect instanceof mysqli)) {
            return false;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return false;
        }

        $reason = 'auto activate';
        $sql = 'SELECT 1 FROM transac_activate_spare'
            . ' WHERE tas_tsp_id = ? AND tas_trp_status_job = ? AND tas_request_reason = ?'
            . ' LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'iss', $tsp_id, $status_job, $reason);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return false;
        }
        $res = mysqli_stmt_get_result($stmt);
        $has = $res && mysqli_fetch_assoc($res);
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return (bool) $has;
    }
}

if (!function_exists('maintenance_transac_activate_spare_record_auto_on_offer_price')) {
    /**
     * Purpose:
     * บันทึกต่ออายุอัตโนมัติเมื่อมี `trp_qv_date` แล้ว (เสนอราคาแล้ว) — ฐานวันเริ่ม = วันหมดอายุครั้งก่อน + 1 วัน, หมดอายุ = เริ่ม + period_wait_check
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     *
     * Return value:
     * - int tas_id ที่ insert สำเร็จ หรือ 0 เมื่อข้าม/ล้มเหลว
     */
    function maintenance_transac_activate_spare_record_auto_on_offer_price($connect, $trp_id)
    {
        return 0;
    }
}

if (!function_exists('maintenance_transac_activate_spare_record_auto_on_wait_repair')) {
    /**
     * Purpose:
     * บันทึกต่ออายุอัตโนมัติเมื่องานอยู่สถานะ `รอซ่อม` — ฐานวันเริ่ม = วันหมดอายุครั้งก่อน + 1 วัน, หมดอายุ = เริ่ม + priod_wait_qc
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     *
     * Return value:
     * - int tas_id ที่ insert สำเร็จ หรือ 0 เมื่อข้าม/ล้มเหลว
     */
    function maintenance_transac_activate_spare_record_auto_on_wait_repair($connect, $trp_id)
    {
        return 0;
    }
}

if (!function_exists('maintenance_transac_activate_spare_exists_for_repair')) {
    /**
     * Purpose:
     * ตรวจว่ามีแถว `transac_activate_spare` สำหรับงานซ่อมนี้แล้วหรือไม่ (ผ่าน transac_spare)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     *
     * Return value:
     * - bool
     */
    function maintenance_transac_activate_spare_exists_for_repair($connect, $trp_id)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli) || !maintenance_transac_activate_spare_table_available($connect)) {
            return false;
        }

        $sql = 'SELECT 1 FROM transac_activate_spare tas'
            . ' INNER JOIN transac_spare tsp ON tsp.tsp_id = tas.tas_tsp_id'
            . ' WHERE tsp.tsp_trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return false;
        }
        $res = mysqli_stmt_get_result($stmt);
        $has = $res && mysqli_fetch_assoc($res);
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return (bool) $has;
    }
}

if (!function_exists('maintenance_transac_activate_spare_resolve_period_days')) {
    /**
     * Purpose:
     * อ่านจำนวนวันต่ออายุจาก `transac_setting` — ถ้าไม่พบหรือค่า < 1 ใช้ default ตาม option (fallback 14)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $setting_option: ชื่อ option ใน transac_setting
     *
     * Return value:
     * - int จำนวนวัน (อย่างน้อย 1)
     */
    function maintenance_transac_activate_spare_resolve_period_days($connect, $setting_option)
    {
        require_once __DIR__ . '/transac_setting_helpers.php';

        $setting_option = trim((string) $setting_option);
        $defaultByOption = array(
            MAINTENANCE_SETTING_OPTION_PERIOD_FIRST_SPARE => 21,
            MAINTENANCE_SETTING_OPTION_PERIOD_AFTER_RECEIVE_REPAIR => 14,
            MAINTENANCE_SETTING_OPTION_PERIOD_WAIT_CHECK => 14,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_APPROVE => 0,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_QC => 20,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SPARE => 3,
        );
        $defaultDays = isset($defaultByOption[$setting_option]) ? (int) $defaultByOption[$setting_option] : 14;

        $days = maintenance_transac_setting_get_int($connect, $setting_option, $defaultDays);
        if ($days < 1) {
            $days = $defaultDays > 0 ? $defaultDays : 14;
        }

        return $days;
    }
}

if (!function_exists('maintenance_transac_activate_spare_insert_system_backfill_row')) {
    /**
     * Purpose:
     * สร้างแถวต่ออายุครั้งแรกสำหรับงานเก่า (ผู้ขอ/พนักงาน = system) — วันเริ่มและหมดอายุจากวันเข้าหน้า + period
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id, int $trp_id: เครื่องสำรอง / งานซ่อม
     * - string $status_job: ค่า tas_trp_status_job
     * - string $setting_option: ชื่อ period ใน transac_setting
     * - string $activate_date_ymd: วันที่เริ่มต่ออายุ (มักเป็นวันนี้)
     *
     * Return value:
     * - int tas_id ที่ insert สำเร็จ หรือ 0
     */
    function maintenance_transac_activate_spare_insert_system_backfill_row(
        $connect,
        $tsp_id,
        $trp_id,
        $status_job,
        $setting_option,
        $activate_date_ymd
    ) {
        $tsp_id = (int) $tsp_id;
        $trp_id = (int) $trp_id;
        $status_job = trim((string) $status_job);
        $setting_option = trim((string) $setting_option);
        $activate_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd($activate_date_ymd);
        if ($tsp_id < 1 || $trp_id < 1 || $status_job === '' || $setting_option === '' || $activate_date_ymd === '') {
            return 0;
        }
        if (!($connect instanceof mysqli) || !maintenance_transac_activate_spare_table_available($connect)) {
            return 0;
        }

        $periodDays = maintenance_transac_activate_spare_resolve_period_days($connect, $setting_option);
        $expire_date_ymd = maintenance_transac_activate_spare_add_days_ymd($activate_date_ymd, $periodDays);
        if ($expire_date_ymd === '') {
            return 0;
        }

        $codePack = maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd);
        $code = isset($codePack['code']) ? (string) $codePack['code'] : '';
        if ($code === '') {
            return 0;
        }

        return maintenance_transac_activate_spare_insert($connect, array(
            'tas_tsp_id' => $tsp_id,
            'tas_trp_status_job' => $status_job,
            'tas_activate_date' => $activate_date_ymd,
            'tas_expire_date' => $expire_date_ymd,
            'tas_activate_code' => $code,
            'tas_request_name' => 'system',
            'tas_request_contact' => 'system',
            'tas_request_reason' => 'system',
            'tas_staff_user' => 'system',
        ));
    }
}

if (!function_exists('maintenance_transac_activate_spare_resolve_legacy_backfill_pack')) {
    /**
     * Purpose:
     * กำหนดแถว baseline ครั้งแรกเมื่อยังไม่มี `transac_activate_spare` เลย
     * — หลังรับเครื่องซ่อม: เริ่ม = วันรับ + 1, หมดอายุ = เริ่ม + priod_wait_qc (แสดงขั้นรับเครื่องซ่อม)
     * — ขั้นรอเครื่องซ่อม (ยังไม่รับ): ไม่ backfill — ใช้ปุ่มคำขอ
     * — ขั้น extension อื่น (เสนอราคา/อนุมัติ/รอซ่อม/รอเครื่องสำรอง): ไม่ auto extension แต่ baseline หลังรับยัง insert ได้
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - array|null คีย์ tsp_id, status_job, setting_option, activate_date_ymd (ถ้ามี) หรือ null เมื่อไม่ต้อง backfill
     */
    function maintenance_transac_activate_spare_resolve_legacy_backfill_pack($connect, $trp_id, array $bundle)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli) || !isset($bundle['row']) || !is_array($bundle['row'])) {
            return null;
        }
        if (empty($bundle['has_spare_rows'])) {
            return null;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        require_once __DIR__ . '/transac_setting_helpers.php';

        $row = $bundle['row'];
        $trp_product = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trp_product)) {
            return null;
        }

        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;
        if ($spSend === null) {
            return null;
        }
        $tsp_id = isset($spSend['tsp_id']) ? (int) $spSend['tsp_id'] : 0;
        if ($tsp_id < 1) {
            return null;
        }

        $rejectRepair = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : '')) === 'ไม่อนุมัติซ่อม';

        $recvYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['trp_receive_date']) ? $row['trp_receive_date'] : '')
        );
        $datesendYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            isset($spSend['tsp_datesend']) ? trim((string) $spSend['tsp_datesend']) : ''
        );

        if ($rejectRepair) {
            return null;
        }

        // ขั้นรอเครื่องซ่อม (ยังไม่รับเครื่อง) — ใช้ปุ่มคำขอเท่านั้น ไม่ backfill
        if ($recvYmd === '' && $datesendYmd !== '') {
            return null;
        }

        // Baseline หลังรับเครื่องซ่อม — ใช้ได้ทุกขั้นหลัง recv (รวมรอเครื่องสำรอง) เมื่อยังไม่มี tas เลย
        if ($recvYmd !== '') {
            $activateAfterRecv = maintenance_transac_activate_spare_add_days_ymd($recvYmd, 1);
            if ($activateAfterRecv === '') {
                return null;
            }

            return array(
                'tsp_id' => $tsp_id,
                'status_job' => MAINTENANCE_ACTIVATE_SPARE_STATUS_AFTER_RECEIVE_REPAIR,
                'setting_option' => MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_QC,
                'activate_date_ymd' => $activateAfterRecv,
            );
        }

        return null;
    }
}

if (!function_exists('maintenance_transac_activate_spare_bundle_at_wait_spare_stage')) {
    /**
     * Purpose:
     * ตรวจว่างานอยู่ขั้นรอเครื่องสำรองตาม timeline (ส่งคืนเครื่องซ่อมแล้ว ยังไม่รับเครื่องสำรองกลับ)
     *
     * Parameters สำคัญ:
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - bool
     */
    function maintenance_transac_activate_spare_bundle_at_wait_spare_stage(array $bundle)
    {
        if (empty($bundle['has_spare_rows']) || !isset($bundle['row']) || !is_array($bundle['row'])) {
            return false;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $row = $bundle['row'];
        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return false;
        }

        $retMax = isset($bundle['spare_return_max']) ? $bundle['spare_return_max'] : null;
        if ($retMax !== null && trim((string) $retMax) !== '') {
            return false;
        }

        $sendRepairYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['trp_send_date']) ? $row['trp_send_date'] : '')
        );

        return $sendRepairYmd !== '';
    }
}

if (!function_exists('maintenance_transac_activate_spare_is_system_backfill_row')) {
    /**
     * Purpose:
     * ตรวจว่าแถว `transac_activate_spare` เป็น backfill/system อัตโนมัติ (ไม่นับเป็นโควต้าคำขอต่ออายุ)
     *
     * Parameters สำคัญ:
     * - array $row: แถว normalize แล้วจาก transac_activate_spare
     *
     * Return value:
     * - bool
     */
    function maintenance_transac_activate_spare_is_system_backfill_row(array $row)
    {
        $reason = isset($row['tas_request_reason']) ? trim((string) $row['tas_request_reason']) : '';
        if ($reason === 'system' || $reason === 'auto activate') {
            return true;
        }
        $name = isset($row['tas_request_name']) ? trim((string) $row['tas_request_name']) : '';
        $staff = isset($row['tas_staff_user']) ? trim((string) $row['tas_staff_user']) : '';

        return $name === 'system' && $staff === 'system';
    }
}

if (!function_exists('maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status')) {
    /**
     * Purpose:
     * นับจำนวนคำขอต่ออายุด้วยมือ (ไม่รวมแถว backfill/system) ต่อ tsp_id และสถานะงาน
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - string $trp_status_job: ค่า tas_trp_status_job
     *
     * Return value:
     * - int
     */
    function maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status($connect, $tsp_id, $trp_status_job)
    {
        $tsp_id = (int) $tsp_id;
        $trp_status_job = trim((string) $trp_status_job);
        if ($tsp_id < 1 || $trp_status_job === '' || !($connect instanceof mysqli)) {
            return 0;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) AS cnt FROM transac_activate_spare'
            . ' WHERE tas_tsp_id = ? AND tas_trp_status_job = ?'
            . " AND COALESCE(TRIM(tas_request_reason), '') NOT IN ('system', 'auto activate')"
            . " AND NOT (COALESCE(TRIM(tas_request_name), '') = 'system'"
            . " AND COALESCE(TRIM(tas_staff_user), '') = 'system')";
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'is', $tsp_id, $trp_status_job);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return 0;
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return is_array($row) ? (int) (isset($row['cnt']) ? $row['cnt'] : 0) : 0;
    }
}

if (!function_exists('maintenance_transac_activate_spare_ensure_on_job_process_view')) {
    /**
     * Purpose:
     * เมื่อเปิดหน้า `job_process.php` — ถ้ายังไม่มีประวัติ `transac_activate_spare` เลย ให้ insert backfill ครั้งแรก (system)
     * กรณีรับเครื่องซ่อมแล้ว: วันเริ่ม = วันรับ + 1 วัน, หมดอายุ = เริ่ม + priod_wait_qc
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - array $bundle: ผลจาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - int tas_id ที่ insert หรือ 0 เมื่อข้าม/มีอยู่แล้ว
     */
    function maintenance_transac_activate_spare_ensure_on_job_process_view($connect, $trp_id, array $bundle)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return 0;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return 0;
        }
        if (maintenance_transac_activate_spare_exists_for_repair($connect, $trp_id)) {
            return 0;
        }

        $pack = maintenance_transac_activate_spare_resolve_legacy_backfill_pack($connect, $trp_id, $bundle);
        if (!is_array($pack)) {
            return 0;
        }

        $tsp_id = isset($pack['tsp_id']) ? (int) $pack['tsp_id'] : 0;
        $status_job = isset($pack['status_job']) ? trim((string) $pack['status_job']) : '';
        $setting_option = isset($pack['setting_option']) ? trim((string) $pack['setting_option']) : '';
        if ($tsp_id < 1 || $status_job === '' || $setting_option === '') {
            return 0;
        }

        $activateYmd = isset($pack['activate_date_ymd'])
            ? maintenance_transac_activate_spare_normalize_date_ymd((string) $pack['activate_date_ymd'])
            : '';
        if ($activateYmd === '') {
            $activateYmd = date('Y-m-d');
        }

        return maintenance_transac_activate_spare_insert_system_backfill_row(
            $connect,
            $tsp_id,
            $trp_id,
            $status_job,
            $setting_option,
            $activateYmd
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_ensure_offer_price_on_job_process_view')) {
    /**
     * Purpose:
     * (เลิกใช้แยก) — รวมใน `maintenance_transac_activate_spare_ensure_on_job_process_view` แล้ว
     *
     * Return value:
     * - int 0 เสมอ
     */
    function maintenance_transac_activate_spare_ensure_offer_price_on_job_process_view($connect, $trp_id, array $bundle)
    {
        return 0;
    }
}

if (!function_exists('maintenance_transac_activate_spare_ensure_wait_repair_on_job_process_view')) {
    /**
     * Purpose:
     * (เลิกใช้แยก) — รวมใน `maintenance_transac_activate_spare_ensure_on_job_process_view` แล้ว
     *
     * Return value:
     * - int 0 เสมอ
     */
    function maintenance_transac_activate_spare_ensure_wait_repair_on_job_process_view($connect, $trp_id, array $bundle)
    {
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────────
// helpers — table / CRUD
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_transac_activate_spare_table_available')) {
    /**
     * Purpose:
     * ตรวจว่ามีตาราง `transac_activate_spare` และ query ได้
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - bool
     */
    function maintenance_transac_activate_spare_table_available($connect)
    {
        if (!($connect instanceof mysqli)) {
            return false;
        }
        $res = @mysqli_query($connect, 'SELECT 1 FROM transac_activate_spare LIMIT 1');
        if ($res === false) {
            return false;
        }
        mysqli_free_result($res);

        return true;
    }
}

if (!function_exists('maintenance_transac_activate_spare_normalize_row')) {
    /**
     * Purpose:
     * แปลงแถวจาก DB เป็นโครงมาตรฐาน UTF-8
     *
     * Parameters สำคัญ:
     * - array $row: แถวจาก mysqli
     *
     * Return value:
     * - array<string,mixed>
     */
    function maintenance_transac_activate_spare_normalize_row(array $row)
    {
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        return array(
            'tas_id' => (int) (isset($row['tas_id']) ? $row['tas_id'] : 0),
            'tas_tsp_id' => (int) (isset($row['tas_tsp_id']) ? $row['tas_tsp_id'] : 0),
            'tas_trp_status_job' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_trp_status_job']) ? $row['tas_trp_status_job'] : '')),
            'tas_activate_date' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_activate_date']) ? $row['tas_activate_date'] : '')),
            'tas_expire_date' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_expire_date']) ? $row['tas_expire_date'] : '')),
            'tas_activate_code' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_activate_code']) ? $row['tas_activate_code'] : '')),
            'tas_request_name' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_request_name']) ? $row['tas_request_name'] : '')),
            'tas_request_contact' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_request_contact']) ? $row['tas_request_contact'] : '')),
            'tas_request_reason' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_request_reason']) ? $row['tas_request_reason'] : '')),
            'tas_staff_user' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_staff_user']) ? $row['tas_staff_user'] : '')),
            'tas_created_at' => trim(maintenance_flow_db_text_to_utf8(isset($row['tas_created_at']) ? $row['tas_created_at'] : '')),
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_validate_insert_row')) {
    /**
     * Purpose:
     * ตรวจข้อมูลก่อน INSERT
     *
     * Parameters สำคัญ:
     * - array $row: คีย์ tas_* ที่จำเป็น
     *
     * Return value:
     * - array{ok:bool,error?:string}
     */
    function maintenance_transac_activate_spare_validate_insert_row(array $row)
    {
        $tsp_id = (int) (isset($row['tas_tsp_id']) ? $row['tas_tsp_id'] : 0);
        if ($tsp_id < 1) {
            return array('ok' => false, 'error' => 'ไม่พบรหัสเครื่องสำรอง (tsp_id)');
        }

        $activate = trim((string) (isset($row['tas_activate_date']) ? $row['tas_activate_date'] : ''));
        $expire = trim((string) (isset($row['tas_expire_date']) ? $row['tas_expire_date'] : ''));
        if ($activate === '' || $expire === '') {
            return array('ok' => false, 'error' => 'กรุณาระบุวันที่ต่ออายุและวันที่หมดอายุ');
        }

        $code = preg_replace('/\D+/', '', (string) (isset($row['tas_activate_code']) ? $row['tas_activate_code'] : ''));
        $codeLen = strlen($code);
        if ($codeLen < 9 || $codeLen > 10) {
            return array('ok' => false, 'error' => 'รหัสต่ออายุต้องเป็นตัวเลข 9–10 หลัก');
        }

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_transac_activate_spare_code_exists')) {
    /**
     * Purpose:
     * ตรวจว่ารหัสต่ออายุซ้ำในระบบหรือไม่
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $code: รหัสตัวเลข
     *
     * Return value:
     * - bool true เมื่อมีแถวนี้แล้ว
     */
    function maintenance_transac_activate_spare_code_exists($connect, $code)
    {
        $code = preg_replace('/\D+/', '', (string) $code);
        if ($code === '' || !($connect instanceof mysqli)) {
            return false;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return false;
        }
        $sql = 'SELECT tas_id FROM transac_activate_spare WHERE tas_activate_code = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 's', $code);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return false;
        }
        $res = mysqli_stmt_get_result($stmt);
        $found = $res && mysqli_fetch_assoc($res);
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $found !== false && $found !== null;
    }
}

if (!function_exists('maintenance_transac_activate_spare_generate_unique_code')) {
    /**
     * Purpose:
     * สร้างรหัสต่ออายุตัวเลข 9–10 หลัก (ไม่บังคับไม่ซ้ำ — `tas_activate_code` ไม่มี UNIQUE)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $max_attempts: จำนวนครั้งสุ่มสูงสุด
     *
     * Return value:
     * - string รหัส หรือค่าว่างเมื่อสุ่มไม่สำเร็จ
     */
    function maintenance_transac_activate_spare_generate_unique_code($connect, $max_attempts = 25)
    {
        if (!($connect instanceof mysqli)) {
            return '';
        }
        $attempts = max(1, (int) $max_attempts);
        $digits = random_int(9, 10);
        $min = (int) pow(10, $digits - 1);
        $max = (int) pow(10, $digits) - 1;

        return (string) random_int($min, $max        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_bind_stmt_params')) {
    /**
     * Purpose:
     * bind พารามิเตอร์แบบ dynamic types ให้ `mysqli_stmt` (IN / NOT IN หลายค่า)
     *
     * Parameters สำคัญ:
     * - mysqli_stmt $stmt: statement
     * - string $types: ชนิด mysqli เช่น `iss`
     * - array $params: ค่าเรียงตาม types (ไม่รวมสตริง types)
     *
     * Return value:
     * - bool สำเร็จหรือไม่
     */
    function maintenance_transac_activate_spare_bind_stmt_params($stmt, $types, array $params)
    {
        $refs = array();
        $refs[] = $types;
        foreach ($params as $i => $_v) {
            $refs[] = &$params[$i];
        }

        return call_user_func_array(array($stmt, 'bind_param'), $refs);
    }
}

if (!function_exists('maintenance_transac_activate_spare_insert')) {
    /**
     * Purpose:
     * บันทึกรายการต่ออายุเครื่องสำรองหนึ่งครั้ง
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $row: คีย์ tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,
     *   tas_activate_code (ว่างได้ → สุ่มให้), tas_request_name, tas_request_contact,
     *   tas_request_reason, tas_staff_user
     *
     * Return value:
     * - int tas_id ที่ insert สำเร็จ หรือ 0 เมื่อล้มเหลว
     */
    function maintenance_transac_activate_spare_insert($connect, array $row)
    {
        if (!($connect instanceof mysqli) || !maintenance_transac_activate_spare_table_available($connect)) {
            return 0;
        }

        $valid = maintenance_transac_activate_spare_validate_insert_row($row);
        if (empty($valid['ok'])) {
            return 0;
        }

        $tsp_id = (int) $row['tas_tsp_id'];
        $status_job = trim((string) (isset($row['tas_trp_status_job']) ? $row['tas_trp_status_job'] : ''));
        $activate_date = trim((string) $row['tas_activate_date']);
        $expire_date = trim((string) $row['tas_expire_date']);
        $code = preg_replace('/\D+/', '', (string) (isset($row['tas_activate_code']) ? $row['tas_activate_code'] : ''));
        if ($code === '') {
            $code = maintenance_transac_activate_spare_generate_unique_code($connect);
        }
        if ($code === '' || strlen($code) < 9 || strlen($code) > 10) {
            return 0;
        }

        $request_name = trim((string) (isset($row['tas_request_name']) ? $row['tas_request_name'] : ''));
        $request_contact = trim((string) (isset($row['tas_request_contact']) ? $row['tas_request_contact'] : ''));
        $request_reason = trim((string) (isset($row['tas_request_reason']) ? $row['tas_request_reason'] : ''));
        $staff_user = trim((string) (isset($row['tas_staff_user']) ? $row['tas_staff_user'] : ''));

        $sql = 'INSERT INTO transac_activate_spare ('
            . 'tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date, tas_activate_code,'
            . 'tas_request_name, tas_request_contact, tas_request_reason, tas_staff_user, tas_created_at'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return 0;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'issssssss',
            $tsp_id,
            $status_job,
            $activate_date,
            $expire_date,
            $code,
            $request_name,
            $request_contact,
            $request_reason,
            $staff_user
        );

        $ok = mysqli_stmt_execute($stmt);
        $new_id = $ok ? (int) mysqli_insert_id($connect) : 0;
        mysqli_stmt_close($stmt);

        return $new_id;
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_by_id')) {
    /**
     * Purpose:
     * ดึงแถวตาม `tas_id`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tas_id: รหัสรายการ
     *
     * Return value:
     * - array|null
     */
    function maintenance_transac_activate_spare_fetch_by_id($connect, $tas_id)
    {
        $tas_id = (int) $tas_id;
        if ($tas_id < 1 || !($connect instanceof mysqli)) {
            return null;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return null;
        }

        $sql = 'SELECT tas_id, tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,'
            . ' tas_activate_code, tas_request_name, tas_request_contact, tas_request_reason,'
            . ' tas_staff_user, tas_created_at'
            . ' FROM transac_activate_spare WHERE tas_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $tas_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return null;
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return null;
        }

        return maintenance_transac_activate_spare_normalize_row($row);
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_by_code')) {
    /**
     * Purpose:
     * ดึงแถวตามรหัสต่ออายุ (สำหรับตรวจสอบจากระบบภายนอก / QR)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $code: รหัสตัวเลข 9–10 หลัก
     *
     * Return value:
     * - array|null
     */
    function maintenance_transac_activate_spare_fetch_by_code($connect, $code)
    {
        $code = preg_replace('/\D+/', '', (string) $code);
        if ($code === '' || !($connect instanceof mysqli)) {
            return null;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return null;
        }

        $sql = 'SELECT tas_id, tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,'
            . ' tas_activate_code, tas_request_name, tas_request_contact, tas_request_reason,'
            . ' tas_staff_user, tas_created_at'
            . ' FROM transac_activate_spare WHERE tas_activate_code = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 's', $code);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return null;
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return null;
        }

        return maintenance_transac_activate_spare_normalize_row($row);
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_by_tsp_id')) {
    /**
     * Purpose:
     * ดึงประวัติต่ออายุของเครื่องสำรองหนึ่งรายการ (ล่าสุดก่อน)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - int $limit: จำนวนแถวสูงสุด (0 = ไม่จำกัดใน SQL — ใช้ 100 เป็นค่าเริ่ม)
     *
     * Return value:
     * - array<int,array>
     */
    function maintenance_transac_activate_spare_fetch_by_tsp_id($connect, $tsp_id, $limit = 100)
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return array();
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array();
        }

        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 100;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $sql = 'SELECT tas_id, tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,'
            . ' tas_activate_code, tas_request_name, tas_request_contact, tas_request_reason,'
            . ' tas_staff_user, tas_created_at'
            . ' FROM transac_activate_spare WHERE tas_tsp_id = ?'
            . ' ORDER BY tas_created_at DESC, tas_id DESC'
            . ' LIMIT ' . $limit;

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 'i', $tsp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (is_array($row)) {
                    $out[] = maintenance_transac_activate_spare_normalize_row($row);
                }
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_customer_tracking_display')) {
    /**
     * Purpose:
     * สรุปรหัสต่ออายุล่าสุดสำหรับหน้า maintenancetracking/status (ลูกค้า) — เฉพาะ bitVisitor Plus / M
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle`
     * - string $today_ymd: วันอ้างอิง Y-m-d (ว่าง = วันนี้)
     *
     * Return value:
     * - array{show_row:bool,code:string,expire_display:string,is_expired:bool,contact_staff:bool}
     */
    function maintenance_transac_activate_spare_fetch_customer_tracking_display($connect, array $bundle, $today_ymd = '')
    {
        $empty = array(
            'show_row' => false,
            'code' => '',
            'expire_display' => '',
            'is_expired' => true,
            'contact_staff' => true,
        );

        $jobRow = isset($bundle['row']) && is_array($bundle['row']) ? $bundle['row'] : null;
        if ($jobRow === null) {
            return $empty;
        }

        $trpProduct = trim((string) (isset($jobRow['trp_product']) ? $jobRow['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return $empty;
        }

        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;
        if ($spSend === null || !isset($spSend['tsp_id']) || (int) $spSend['tsp_id'] < 1) {
            return array_merge($empty, array('show_row' => true));
        }

        if (!($connect instanceof mysqli) || !maintenance_transac_activate_spare_table_available($connect)) {
            return array_merge($empty, array('show_row' => true));
        }

        $todayYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            $today_ymd !== '' ? $today_ymd : date('Y-m-d')
        );

        $rows = maintenance_transac_activate_spare_fetch_by_tsp_id($connect, (int) $spSend['tsp_id'], 50);
        $latest = null;
        foreach ($rows as $tasRow) {
            if (!is_array($tasRow)) {
                continue;
            }
            $code = trim((string) (isset($tasRow['tas_activate_code']) ? $tasRow['tas_activate_code'] : ''));
            if ($code === '') {
                continue;
            }
            $latest = $tasRow;
            break;
        }

        if ($latest === null) {
            return array_merge($empty, array('show_row' => true));
        }

        $code = trim((string) (isset($latest['tas_activate_code']) ? $latest['tas_activate_code'] : ''));
        $expireYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            isset($latest['tas_expire_date']) ? (string) $latest['tas_expire_date'] : ''
        );
        $expireDisplay = maintenance_transac_activate_spare_format_date_display_th($expireYmd);
        $isExpired = ($expireYmd !== '' && $todayYmd !== '' && $todayYmd > $expireYmd);

        if ($isExpired) {
            return array(
                'show_row' => true,
                'code' => '',
                'expire_display' => $expireDisplay,
                'is_expired' => true,
                'contact_staff' => true,
            );
        }

        return array(
            'show_row' => true,
            'code' => $code,
            'expire_display' => $expireDisplay,
            'is_expired' => false,
            'contact_staff' => false,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_format_date_display_th')) {
    /**
     * Purpose:
     * แปลงวันที่ Y-m-d เป็น d/m/Y สำหรับแสดงผล
     *
     * Parameters สำคัญ:
     * - string $ymd: วันที่
     *
     * Return value:
     * - string
     */
    function maintenance_transac_activate_spare_format_date_display_th($ymd)
    {
        $ymd = maintenance_transac_activate_spare_normalize_date_ymd($ymd);
        if ($ymd === '') {
            return trim((string) $ymd);
        }
        $parts = explode('-', $ymd);
        if (count($parts) === 3) {
            return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
        }

        return $ymd;
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_latest_expire_for_repair')) {
    /**
     * Purpose:
     * ดึงวันหมดอายุล่าสุดจากประวัติต่ออายุของงานซ่อม (เรียงตาม tas_expire_date ล่าสุด)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - string $exclude_status_job: ถ้าระบุ จะไม่รวมแถวที่ tas_trp_status_job ตรงค่านี้
     *
     * Return value:
     * - string Y-m-d หรือค่าว่างเมื่อไม่มีประวัติ
     */
    function maintenance_transac_activate_spare_fetch_latest_expire_for_repair(
        $connect,
        $trp_id,
        $exclude_status_job = ''
    ) {
        $trp_id = (int) $trp_id;
        $exclude_status_job = trim((string) $exclude_status_job);
        if ($trp_id < 1 || !($connect instanceof mysqli) || !maintenance_transac_activate_spare_table_available($connect)) {
            return '';
        }

        $sql = 'SELECT tas.tas_expire_date FROM transac_activate_spare tas'
            . ' INNER JOIN transac_spare tsp ON tsp.tsp_id = tas.tas_tsp_id'
            . ' WHERE tsp.tsp_trp_id = ?';
        if ($exclude_status_job !== '') {
            $sql .= ' AND tas.tas_trp_status_job <> ?';
        }
        $sql .= ' ORDER BY tas.tas_expire_date DESC, tas.tas_id DESC LIMIT 1';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return '';
        }
        if ($exclude_status_job !== '') {
            mysqli_stmt_bind_param($stmt, 'is', $trp_id, $exclude_status_job);
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $trp_id);
        }
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return '';
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return '';
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        return maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['tas_expire_date']) ? $row['tas_expire_date'] : '')
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_base_expire_for_inapprove')) {
    /**
     * Purpose:
     * วันฐานสำหรับคำนวณต่ออายุขั้นรออนุมัติซ่อม — ครั้งแรกใช้หมดอายุจากขั้นก่อนหน้า (ไม่รวมสถานะรออนุมัติซ่อม) ครั้งถัดไปใช้หมดอายุล่าสุดรวมทุกขั้น
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - int $tsp_id: transac_spare.tsp_id
     *
     * Return value:
     * - string Y-m-d หรือค่าว่างเมื่อไม่มีประวัติ
     */
    function maintenance_transac_activate_spare_fetch_base_expire_for_inapprove($connect, $trp_id, $tsp_id = 0)
    {
        $trp_id = (int) $trp_id;
        $tsp_id = (int) $tsp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return '';
        }

        $inapproveCount = 0;
        if ($tsp_id > 0) {
            $inapproveCount = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
                $connect,
                $tsp_id,
                MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE
            );
        }

        if ($inapproveCount === 0) {
            return maintenance_transac_activate_spare_fetch_latest_expire_for_repair(
                $connect,
                $trp_id,
                MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE
            );
        }

        return maintenance_transac_activate_spare_fetch_latest_expire_for_repair($connect, $trp_id);
    }
}

if (!function_exists('maintenance_transac_activate_spare_resolve_inapprove_expire_ymd')) {
    /**
     * Purpose:
     * กำหนดวันหมดอายุสำหรับขั้นรออนุมัติซ่อม — ฐาน = วันหมดอายุล่าสุดของงาน (ไม่มีใช้วันนี้); ถ้า priod_wait_approve > 0 บวกอัตโนมัติ มิฉะนั้นใช้ค่าจากผู้ใช้
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - string $user_expire_ymd: วันหมดอายุจากฟอร์ม (ใช้เมื่อ priod_wait_approve = 0)
     * - string $today_ymd: วันที่อ้างอิงเมื่อไม่มีประวัติหมดอายุก่อนหน้า
     * - int $tsp_id: transac_spare.tsp_id (ใช้เลือกวันฐานจากขั้นก่อนหน้า vs ครั้งก่อนในขั้นรออนุมัติ)
     *
     * Return value:
     * - array{ok:bool,expire_date?:string,base_date?:string,period_days?:int,error?:string}
     */
    function maintenance_transac_activate_spare_resolve_inapprove_expire_ymd(
        $connect,
        $trp_id,
        $user_expire_ymd = '',
        $today_ymd = '',
        $tsp_id = 0
    ) {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return array('ok' => false, 'error' => 'ข้อมูลงานไม่ถูกต้อง');
        }

        require_once __DIR__ . '/transac_setting_helpers.php';

        $activate_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd(
            $today_ymd !== '' ? $today_ymd : date('Y-m-d')
        );
        if ($activate_date_ymd === '') {
            return array('ok' => false, 'error' => 'วันที่อ้างอิงไม่ถูกต้อง');
        }

        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_APPROVE,
            0
        );
        $prevExpire = maintenance_transac_activate_spare_fetch_base_expire_for_inapprove(
            $connect,
            $trp_id,
            $tsp_id
        );
        $baseYmd = $prevExpire !== '' ? $prevExpire : $activate_date_ymd;

        if ($periodDays > 0) {
            $expire_date_ymd = maintenance_transac_activate_spare_add_days_ymd($baseYmd, $periodDays);
            if ($expire_date_ymd === '') {
                return array('ok' => false, 'error' => 'คำนวณวันหมดอายุไม่สำเร็จ');
            }

            return array(
                'ok' => true,
                'expire_date' => $expire_date_ymd,
                'base_date' => $baseYmd,
                'period_days' => $periodDays,
            );
        }

        $expire_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd($user_expire_ymd);
        if ($expire_date_ymd === '') {
            return array('ok' => false, 'error' => 'กรุณาเลือกวันที่หมดอายุเครื่องสำรอง');
        }

        return array(
            'ok' => true,
            'expire_date' => $expire_date_ymd,
            'base_date' => $baseYmd,
            'period_days' => 0,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_compute_inapprove_extension')) {
    /**
     * Purpose:
     * คำนวณวันที่ต่ออายุและรหัสสำหรับขั้นรออนุมัติซ่อม
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - string $today_ymd: วันที่ทำรายการ (Y-m-d)
     * - string $user_expire_ymd: วันหมดอายุจากฟอร์ม (เมื่อ priod_wait_approve = 0)
     * - int $tsp_id: transac_spare.tsp_id
     *
     * Return value:
     * - array{activate_date:string,expire_date:string,code:string}|null
     */
    function maintenance_transac_activate_spare_compute_inapprove_extension(
        $connect,
        $trp_id,
        $today_ymd = '',
        $user_expire_ymd = '',
        $tsp_id = 0
    ) {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return null;
        }

        $activate_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd(
            $today_ymd !== '' ? $today_ymd : date('Y-m-d')
        );
        if ($activate_date_ymd === '') {
            return null;
        }

        $resolved = maintenance_transac_activate_spare_resolve_inapprove_expire_ymd(
            $connect,
            $trp_id,
            $user_expire_ymd,
            $activate_date_ymd,
            $tsp_id
        );
        if (empty($resolved['ok']) || !isset($resolved['expire_date'])) {
            return null;
        }

        $expire_date_ymd = (string) $resolved['expire_date'];
        $codePack = maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd);
        $code = isset($codePack['code']) ? trim((string) $codePack['code']) : '';
        if ($code === '') {
            return null;
        }

        return array(
            'activate_date' => $activate_date_ymd,
            'expire_date' => $expire_date_ymd,
            'code' => $code,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_compute_wait_spare_extension')) {
    /**
     * Purpose:
     * คำนวณวันที่ต่ออายุและรหัสสำหรับขั้นรอเครื่องสำรอง — วันเริ่ม = หมดอายุครั้งก่อน + 1 วัน, หมดอายุ = เริ่ม + priod_wait_spare
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - int $tsp_id: transac_spare.tsp_id (ไม่บังคับ — ใช้ตรวจ tsp กับงาน)
     *
     * Return value:
     * - array{activate_date:string,expire_date:string,code:string}|null
     */
    function maintenance_transac_activate_spare_compute_wait_spare_extension($connect, $trp_id, $tsp_id = 0)
    {
        $trp_id = (int) $trp_id;
        $tsp_id = (int) $tsp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return null;
        }

        require_once __DIR__ . '/transac_setting_helpers.php';

        $prevExpire = maintenance_transac_activate_spare_fetch_latest_expire_for_repair($connect, $trp_id);
        if ($prevExpire === '') {
            return null;
        }

        $activate_date_ymd = maintenance_transac_activate_spare_add_days_ymd($prevExpire, 1);
        if ($activate_date_ymd === '') {
            return null;
        }

        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SPARE,
            3
        );
        $expire_date_ymd = maintenance_transac_activate_spare_add_days_ymd($activate_date_ymd, $periodDays);
        if ($expire_date_ymd === '') {
            return null;
        }

        $codePack = maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd);
        $code = isset($codePack['code']) ? trim((string) $codePack['code']) : '';
        if ($code === '') {
            return null;
        }

        return array(
            'activate_date' => $activate_date_ymd,
            'expire_date' => $expire_date_ymd,
            'code' => $code,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_compute_offer_price_extension')) {
    /**
     * Purpose:
     * คำนวณวันที่ต่ออายุและรหัสสำหรับขั้นเสนอราคาแล้ว — วันเริ่ม = หมดอายุครั้งก่อน + 1 วัน, หมดอายุ = เริ่ม + period_wait_check
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     *
     * Return value:
     * - array{activate_date:string,expire_date:string,code:string}|null
     */
    function maintenance_transac_activate_spare_compute_offer_price_extension($connect, $trp_id)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return null;
        }

        require_once __DIR__ . '/transac_setting_helpers.php';

        $prevExpire = maintenance_transac_activate_spare_fetch_latest_expire_for_repair($connect, $trp_id);
        if ($prevExpire === '') {
            return null;
        }

        $activate_date_ymd = maintenance_transac_activate_spare_add_days_ymd($prevExpire, 1);
        if ($activate_date_ymd === '') {
            return null;
        }

        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PERIOD_WAIT_CHECK,
            14
        );
        $expire_date_ymd = maintenance_transac_activate_spare_add_days_ymd($activate_date_ymd, $periodDays);
        if ($expire_date_ymd === '') {
            return null;
        }

        $codePack = maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd);
        $code = isset($codePack['code']) ? trim((string) $codePack['code']) : '';
        if ($code === '') {
            return null;
        }

        return array(
            'activate_date' => $activate_date_ymd,
            'expire_date' => $expire_date_ymd,
            'code' => $code,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_compute_wait_repair_extension')) {
    /**
     * Purpose:
     * คำนวณวันที่ต่ออายุและรหัสสำหรับขั้นรอซ่อม — วันเริ่ม = หมดอายุครั้งก่อน + 1 วัน, หมดอายุ = เริ่ม + priod_wait_qc
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     *
     * Return value:
     * - array{activate_date:string,expire_date:string,code:string}|null
     */
    function maintenance_transac_activate_spare_compute_wait_repair_extension($connect, $trp_id)
    {
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli)) {
            return null;
        }

        require_once __DIR__ . '/transac_setting_helpers.php';

        $prevExpire = maintenance_transac_activate_spare_fetch_latest_expire_for_repair($connect, $trp_id);
        if ($prevExpire === '') {
            return null;
        }

        $activate_date_ymd = maintenance_transac_activate_spare_add_days_ymd($prevExpire, 1);
        if ($activate_date_ymd === '') {
            return null;
        }

        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_QC,
            20
        );
        $expire_date_ymd = maintenance_transac_activate_spare_add_days_ymd($activate_date_ymd, $periodDays);
        if ($expire_date_ymd === '') {
            return null;
        }

        $codePack = maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd);
        $code = isset($codePack['code']) ? trim((string) $codePack['code']) : '';
        if ($code === '') {
            return null;
        }

        return array(
            'activate_date' => $activate_date_ymd,
            'expire_date' => $expire_date_ymd,
            'code' => $code,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_count_offer_price_requests')) {
    /**
     * Purpose:
     * นับคำขอต่ออายุขั้นเสนอราคาแล้ว (รวม legacy `รอเสนอราคา`)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     *
     * Return value:
     * - int
     */
    function maintenance_transac_activate_spare_count_offer_price_requests($connect, $tsp_id)
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return 0;
        }

        return maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status($connect, $tsp_id, MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE)
            + maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
                $connect,
                $tsp_id,
                MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE_LEGACY
            );
    }
}

if (!function_exists('maintenance_transac_activate_spare_resolve_wait_hardware_expire_ymd')) {
    /**
     * Purpose:
     * ตรวจวันหมดอายุคำขอขั้นรอเครื่องซ่อม — ต้องอยู่ระหว่างวันที่ขอ ถึง วันที่ขอ + 7 วัน (ไม่เกิน)
     *
     * Parameters สำคัญ:
     * - string $activate_date_ymd: วันที่ขอ (Y-m-d)
     * - string $user_expire_ymd: วันหมดอายุจากฟอร์ม
     * - int $max_days: จำนวนวันสูงสุดนับจากวันขอ (default 7)
     *
     * Return value:
     * - array{ok:bool,expire_date?:string,error?:string}
     */
    function maintenance_transac_activate_spare_resolve_wait_hardware_expire_ymd(
        $activate_date_ymd,
        $user_expire_ymd,
        $max_days = null
    ) {
        if ($max_days === null) {
            $max_days = MAINTENANCE_ACTIVATE_SPARE_MAX_DAYS_WAIT_HARDWARE;
        }
        $max_days = (int) $max_days;
        if ($max_days < 1) {
            $max_days = MAINTENANCE_ACTIVATE_SPARE_MAX_DAYS_WAIT_HARDWARE;
        }

        $activate_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd($activate_date_ymd);
        $expire_date_ymd = maintenance_transac_activate_spare_normalize_date_ymd($user_expire_ymd);
        if ($activate_date_ymd === '') {
            return array('ok' => false, 'error' => 'วันที่ขอไม่ถูกต้อง');
        }
        if ($expire_date_ymd === '') {
            return array('ok' => false, 'error' => 'กรุณาเลือกวันที่หมดอายุเครื่องสำรอง');
        }

        $maxExpireYmd = maintenance_transac_activate_spare_add_days_ymd($activate_date_ymd, $max_days);
        if ($maxExpireYmd === '') {
            return array('ok' => false, 'error' => 'คำนวณวันหมดอายุสูงสุดไม่สำเร็จ');
        }

        if ($expire_date_ymd < $activate_date_ymd) {
            return array('ok' => false, 'error' => 'วันหมดอายุต้องไม่ก่อนวันที่ขอต่ออายุ');
        }
        if ($expire_date_ymd > $maxExpireYmd) {
            return array(
                'ok' => false,
                'error' => 'วันหมดอายุต้องไม่เกิน ' . (int) $max_days . ' วันนับจากวันที่ขอ',
            );
        }

        return array(
            'ok' => true,
            'expire_date' => $expire_date_ymd,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_by_tsp_and_status_job')) {
    /**
     * Purpose:
     * ดึงประวัติต่ออายุตาม tsp_id และสถานะงานขณะบันทึก (เรียงเก่า → ใหม่)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - string $trp_status_job: ค่า tas_trp_status_job
     *
     * Return value:
     * - array<int,array>
     */
    function maintenance_transac_activate_spare_fetch_by_tsp_and_status_job($connect, $tsp_id, $trp_status_job)
    {
        $tsp_id = (int) $tsp_id;
        $status = trim((string) $trp_status_job);
        if ($tsp_id < 1 || $status === '' || !($connect instanceof mysqli)) {
            return array();
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array();
        }

        $sql = 'SELECT tas_id, tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,'
            . ' tas_activate_code, tas_request_name, tas_request_contact, tas_request_reason,'
            . ' tas_staff_user, tas_created_at'
            . ' FROM transac_activate_spare'
            . ' WHERE tas_tsp_id = ? AND tas_trp_status_job = ?'
            . ' ORDER BY tas_created_at ASC, tas_id ASC';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 'is', $tsp_id, $status);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (is_array($row)) {
                    $out[] = maintenance_transac_activate_spare_normalize_row($row);
                }
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_by_tsp_and_status_jobs')) {
    /**
     * Purpose:
     * ดึงประวัติต่ออายุตาม tsp_id และรายการสถานะงานขณะบันทึก (เรียงเก่า → ใหม่)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - array $trp_status_jobs: รายการค่า tas_trp_status_job
     *
     * Return value:
     * - array<int,array>
     */
    function maintenance_transac_activate_spare_fetch_by_tsp_and_status_jobs($connect, $tsp_id, array $trp_status_jobs)
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return array();
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array();
        }

        $filtered = array();
        foreach ($trp_status_jobs as $status) {
            $status = trim((string) $status);
            if ($status !== '') {
                $filtered[] = $status;
            }
        }
        if ($filtered === array()) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($filtered), '?'));
        $sql = 'SELECT tas_id, tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,'
            . ' tas_activate_code, tas_request_name, tas_request_contact, tas_request_reason,'
            . ' tas_staff_user, tas_created_at'
            . ' FROM transac_activate_spare'
            . ' WHERE tas_tsp_id = ? AND tas_trp_status_job IN (' . $placeholders . ')'
            . ' ORDER BY tas_created_at ASC, tas_id ASC';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }

        $types = 'i' . str_repeat('s', count($filtered));
        $bindParams = array_merge(array($tsp_id), $filtered);
        if (!maintenance_transac_activate_spare_bind_stmt_params($stmt, $types, $bindParams)) {
            mysqli_stmt_close($stmt);

            return array();
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (is_array($row)) {
                    $out[] = maintenance_transac_activate_spare_normalize_row($row);
                }
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_by_tsp_excluding_status_job')) {
    /**
     * Purpose:
     * ดึงประวัติต่ออายุของ tsp_id ที่ไม่ใช่สถานะที่ระบุ (เช่น ยกเว้น «รออนุมัติซ่อม» สำหรับขั้นต้น timeline)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - string $exclude_status: ค่า tas_trp_status_job ที่ไม่ต้องการ
     *
     * Return value:
     * - array<int,array>
     */
    function maintenance_transac_activate_spare_fetch_by_tsp_excluding_status_job($connect, $tsp_id, $exclude_status)
    {
        $tsp_id = (int) $tsp_id;
        $exclude_status = trim((string) $exclude_status);
        if ($tsp_id < 1 || $exclude_status === '' || !($connect instanceof mysqli)) {
            return array();
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array();
        }

        $sql = 'SELECT tas_id, tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,'
            . ' tas_activate_code, tas_request_name, tas_request_contact, tas_request_reason,'
            . ' tas_staff_user, tas_created_at'
            . ' FROM transac_activate_spare'
            . ' WHERE tas_tsp_id = ? AND tas_trp_status_job <> ?'
            . ' ORDER BY tas_created_at ASC, tas_id ASC';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_bind_param($stmt, 'is', $tsp_id, $exclude_status);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (is_array($row)) {
                    $out[] = maintenance_transac_activate_spare_normalize_row($row);
                }
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_by_tsp_excluding_status_jobs')) {
    /**
     * Purpose:
     * ดึงประวัติต่ออายุของ tsp_id ที่ไม่อยู่ในรายการสถานะที่ระบุ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - array $exclude_statuses: รายการ tas_trp_status_job ที่ไม่ต้องการ
     *
     * Return value:
     * - array<int,array>
     */
    function maintenance_transac_activate_spare_fetch_by_tsp_excluding_status_jobs($connect, $tsp_id, array $exclude_statuses)
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return array();
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array();
        }

        $filtered = array();
        foreach ($exclude_statuses as $status) {
            $status = trim((string) $status);
            if ($status !== '') {
                $filtered[] = $status;
            }
        }
        if ($filtered === array()) {
            return maintenance_transac_activate_spare_fetch_by_tsp_id($connect, $tsp_id, 50);
        }

        $placeholders = implode(',', array_fill(0, count($filtered), '?'));
        $sql = 'SELECT tas_id, tas_tsp_id, tas_trp_status_job, tas_activate_date, tas_expire_date,'
            . ' tas_activate_code, tas_request_name, tas_request_contact, tas_request_reason,'
            . ' tas_staff_user, tas_created_at'
            . ' FROM transac_activate_spare'
            . ' WHERE tas_tsp_id = ? AND tas_trp_status_job NOT IN (' . $placeholders . ')'
            . ' ORDER BY tas_created_at ASC, tas_id ASC';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }

        $types = 'i' . str_repeat('s', count($filtered));
        $bindParams = array_merge(array($tsp_id), $filtered);
        if (!maintenance_transac_activate_spare_bind_stmt_params($stmt, $types, $bindParams)) {
            mysqli_stmt_close($stmt);

            return array();
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array();
        }
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (is_array($row)) {
                    $out[] = maintenance_transac_activate_spare_normalize_row($row);
                }
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_fetch_modal_history_for_tsp')) {
    /**
     * Purpose:
     * ดึงประวัติต่ออายุเครื่องสำรองสำหรับแสดงใน modal คำขอ (เรียงเก่า → ใหม่)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     *
     * Return value:
     * - array<int,array{activate_display:string,expire_display:string,code:string,status_job:string,sequence_no:int,is_expired:bool}>
     */
    function maintenance_transac_activate_spare_fetch_modal_history_for_tsp($connect, $tsp_id)
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return array();
        }

        $rows = maintenance_transac_activate_spare_fetch_by_tsp_id($connect, $tsp_id, 50);
        if ($rows === array()) {
            return array();
        }

        $rows = array_reverse($rows);
        $todayYmd = date('Y-m-d');
        $out = array();
        $seq = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = isset($row['tas_activate_code']) ? trim((string) $row['tas_activate_code']) : '';
            if ($code === '') {
                continue;
            }
            $seq++;
            $activateYmd = isset($row['tas_activate_date']) ? (string) $row['tas_activate_date'] : '';
            $expireYmd = isset($row['tas_expire_date']) ? (string) $row['tas_expire_date'] : '';
            $expireNorm = maintenance_transac_activate_spare_normalize_date_ymd($expireYmd);
            $statusJob = isset($row['tas_trp_status_job']) ? trim((string) $row['tas_trp_status_job']) : '';
            $out[] = array(
                'activate_display' => maintenance_transac_activate_spare_format_date_display_th($activateYmd),
                'expire_display' => maintenance_transac_activate_spare_format_date_display_th($expireYmd),
                'code' => $code,
                'status_job' => $statusJob,
                'sequence_no' => $seq,
                'is_expired' => $expireNorm !== '' && $todayYmd > $expireNorm,
            );
        }

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_inapprove_ui_context')) {
    /**
     * Purpose:
     * บริบท UI ปุ่ม + ใน `job_process` ขั้นรออนุมัติซ่อม — นับครั้งเทียบ max_activate_spare_inapprove
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - array คีย์ can_add, count, max, quota_exhausted, quota_message, tsp_id, trp_id, period_wait_approve,
     *   expire_auto, base_date_ymd, prev_expire_ymd, suggested_expire_ymd, preview_*, history
     */
    function maintenance_transac_activate_spare_inapprove_ui_context($connect, $trp_id, array $bundle)
    {
        $defaults = array(
            'can_add' => false,
            'count' => 0,
            'max' => 0,
            'quota_exhausted' => false,
            'quota_message' => '',
            'flow' => 'inapprove',
            'tsp_id' => 0,
            'trp_id' => (int) $trp_id,
            'period_wait_approve' => 0,
            'expire_auto' => false,
            'base_date_ymd' => '',
            'prev_expire_ymd' => '',
            'suggested_expire_ymd' => '',
            'activate_date_ymd' => '',
            'preview_activate_display' => '',
            'preview_expire_display' => '',
            'preview_code' => '',
            'history' => array(),
        );
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli) || !isset($bundle['row']) || !is_array($bundle['row'])) {
            return $defaults;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return $defaults;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        require_once __DIR__ . '/transac_setting_helpers.php';

        $row = $bundle['row'];
        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return $defaults;
        }

        $firmRaw = isset($row['trp_firm_qv_date']) ? trim((string) $row['trp_firm_qv_date']) : '';
        if ($firmRaw !== '') {
            return $defaults;
        }

        $statusJob = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        if ($statusJob !== MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE) {
            return $defaults;
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return $defaults;
        }

        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;
        $tsp_id = $spSend !== null && isset($spSend['tsp_id']) ? (int) $spSend['tsp_id'] : 0;
        if ($tsp_id < 1) {
            return $defaults;
        }

        $max = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_MAX_ACTIVATE_SPARE_INAPPROVE,
            2
        );
        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE
        );

        $out = $defaults;
        $out['tsp_id'] = $tsp_id;
        $out['trp_id'] = $trp_id;
        $out['count'] = $count;
        $out['max'] = $max;

        if ($max > 0 && $count >= $max) {
            $out['quota_exhausted'] = true;
            $out['quota_message'] = 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด ' . (int) $max . ' ครั้งแล้ว';
            $out['can_add'] = false;

            return $out;
        }

        $out['can_add'] = $count < $max;
        if (!$out['can_add']) {
            return $out;
        }

        $out['history'] = maintenance_transac_activate_spare_fetch_modal_history_for_tsp($connect, $tsp_id);

        $todayYmd = date('Y-m-d');
        $out['activate_date_ymd'] = $todayYmd;
        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_APPROVE,
            0
        );
        $out['period_wait_approve'] = $periodDays;
        $out['expire_auto'] = $periodDays > 0;

        $prevExpire = maintenance_transac_activate_spare_fetch_base_expire_for_inapprove(
            $connect,
            $trp_id,
            $tsp_id
        );
        $out['prev_expire_ymd'] = $prevExpire;
        $baseYmd = $prevExpire !== '' ? $prevExpire : $todayYmd;
        $out['base_date_ymd'] = $baseYmd;

        if ($periodDays > 0) {
            $suggestedYmd = maintenance_transac_activate_spare_add_days_ymd($baseYmd, $periodDays);
            if ($suggestedYmd === '') {
                $out['can_add'] = false;

                return $out;
            }
            $out['suggested_expire_ymd'] = $suggestedYmd;

            $pack = maintenance_transac_activate_spare_compute_inapprove_extension(
                $connect,
                $trp_id,
                $todayYmd,
                '',
                $tsp_id
            );
            if (is_array($pack)) {
                $out['preview_activate_display'] = maintenance_transac_activate_spare_format_date_display_th($pack['activate_date']);
                $out['preview_expire_display'] = maintenance_transac_activate_spare_format_date_display_th($pack['expire_date']);
                $out['preview_code'] = isset($pack['code']) ? (string) $pack['code'] : '';
            } else {
                $out['can_add'] = false;
            }
        } else {
            $out['suggested_expire_ymd'] = '';
            $out['preview_activate_display'] = maintenance_transac_activate_spare_format_date_display_th($todayYmd);
            $out['preview_expire_display'] = '—';
            $out['preview_code'] = '—';
        }

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_wait_spare_ui_context')) {
    /**
     * Purpose:
     * บริบท UI ปุ่มขอต่ออายุใน `job_process` ขั้นรอเครื่องสำรอง — ขอได้ 1 ครั้งต่อ tsp
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - array คีย์ can_add, count, max, flow, period_wait_spare, preview_*, history ฯลฯ
     */
    function maintenance_transac_activate_spare_wait_spare_ui_context($connect, $trp_id, array $bundle)
    {
        $defaults = array(
            'can_add' => false,
            'count' => 0,
            'max' => 1,
            'quota_exhausted' => false,
            'quota_message' => '',
            'flow' => 'wait_spare',
            'tsp_id' => 0,
            'trp_id' => (int) $trp_id,
            'period_wait_spare' => 0,
            'expire_auto' => true,
            'base_date_ymd' => '',
            'prev_expire_ymd' => '',
            'activate_date_ymd' => '',
            'suggested_expire_ymd' => '',
            'preview_activate_display' => '',
            'preview_expire_display' => '',
            'preview_code' => '',
            'history' => array(),
        );
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli) || !isset($bundle['row']) || !is_array($bundle['row'])) {
            return $defaults;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return $defaults;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        require_once __DIR__ . '/transac_setting_helpers.php';

        $row = $bundle['row'];
        if (!maintenance_transac_activate_spare_bundle_at_wait_spare_stage($bundle)) {
            return $defaults;
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return $defaults;
        }

        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;
        $tsp_id = $spSend !== null && isset($spSend['tsp_id']) ? (int) $spSend['tsp_id'] : 0;
        if ($tsp_id < 1) {
            return $defaults;
        }

        $max = 1;
        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_SPARE
        );

        $out = $defaults;
        $out['tsp_id'] = $tsp_id;
        $out['trp_id'] = $trp_id;
        $out['count'] = $count;
        $out['max'] = $max;
        $out['history'] = maintenance_transac_activate_spare_fetch_modal_history_for_tsp($connect, $tsp_id);

        if ($count >= $max) {
            $out['quota_exhausted'] = true;
            $out['quota_message'] = 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด ' . (int) $max . ' ครั้งแล้ว';
            $out['can_add'] = false;

            return $out;
        }

        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SPARE,
            3
        );
        $out['period_wait_spare'] = $periodDays;
        $out['expire_auto'] = true;

        $pack = maintenance_transac_activate_spare_compute_wait_spare_extension($connect, $trp_id, $tsp_id);
        if (!is_array($pack)) {
            $out['can_add'] = false;

            return $out;
        }

        $activateYmd = isset($pack['activate_date']) ? (string) $pack['activate_date'] : '';
        $expireYmd = isset($pack['expire_date']) ? (string) $pack['expire_date'] : '';
        $prevExpire = maintenance_transac_activate_spare_fetch_latest_expire_for_repair($connect, $trp_id);

        $out['prev_expire_ymd'] = $prevExpire;
        $out['activate_date_ymd'] = $activateYmd;
        $out['suggested_expire_ymd'] = $expireYmd;
        $out['base_date_ymd'] = $activateYmd;
        $out['preview_activate_display'] = maintenance_transac_activate_spare_format_date_display_th($activateYmd);
        $out['preview_expire_display'] = maintenance_transac_activate_spare_format_date_display_th($expireYmd);
        $out['preview_code'] = isset($pack['code']) ? (string) $pack['code'] : '';
        $out['can_add'] = true;

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_wait_hardware_ui_context')) {
    /**
     * Purpose:
     * บริบท UI ปุ่มขอต่ออายุใน `job_process` ขั้นรอเครื่องซ่อม — ขอได้ 1 ครั้ง หมดอายุไม่เกิน 7 วันนับจากวันขอ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - array คีย์ can_add, count, max, flow, max_extension_days, activate_date_ymd, max_expire_ymd ฯลฯ
     */
    function maintenance_transac_activate_spare_wait_hardware_ui_context($connect, $trp_id, array $bundle)
    {
        $defaults = array(
            'can_add' => false,
            'count' => 0,
            'max' => 1,
            'quota_exhausted' => false,
            'quota_message' => '',
            'flow' => 'wait_hardware',
            'tsp_id' => 0,
            'trp_id' => (int) $trp_id,
            'max_extension_days' => MAINTENANCE_ACTIVATE_SPARE_MAX_DAYS_WAIT_HARDWARE,
            'expire_auto' => false,
            'activate_date_ymd' => '',
            'max_expire_ymd' => '',
            'suggested_expire_ymd' => '',
            'preview_activate_display' => '',
            'preview_expire_display' => '',
            'preview_code' => '',
            'history' => array(),
        );
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli) || !isset($bundle['row']) || !is_array($bundle['row'])) {
            return $defaults;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return $defaults;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $row = $bundle['row'];
        $statusJob = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        if ($statusJob !== MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE) {
            return $defaults;
        }

        $recvRaw = isset($row['trp_receive_date']) ? trim((string) $row['trp_receive_date']) : '';
        if ($recvRaw !== '' && maintenance_transac_activate_spare_normalize_date_ymd($recvRaw) !== '') {
            return $defaults;
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return $defaults;
        }

        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;
        $tsp_id = $spSend !== null && isset($spSend['tsp_id']) ? (int) $spSend['tsp_id'] : 0;
        if ($tsp_id < 1) {
            return $defaults;
        }

        $max = 1;
        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE
        );

        $todayYmd = date('Y-m-d');
        $maxDays = MAINTENANCE_ACTIVATE_SPARE_MAX_DAYS_WAIT_HARDWARE;
        $maxExpireYmd = maintenance_transac_activate_spare_add_days_ymd($todayYmd, $maxDays);

        $out = $defaults;
        $out['tsp_id'] = $tsp_id;
        $out['trp_id'] = $trp_id;
        $out['count'] = $count;
        $out['max'] = $max;
        $out['activate_date_ymd'] = $todayYmd;
        $out['max_expire_ymd'] = $maxExpireYmd !== '' ? $maxExpireYmd : '';
        $out['suggested_expire_ymd'] = $maxExpireYmd !== '' ? $maxExpireYmd : '';
        $out['preview_activate_display'] = maintenance_transac_activate_spare_format_date_display_th($todayYmd);
        $out['preview_expire_display'] = $maxExpireYmd !== ''
            ? maintenance_transac_activate_spare_format_date_display_th($maxExpireYmd)
            : '—';
        $out['history'] = maintenance_transac_activate_spare_fetch_modal_history_for_tsp($connect, $tsp_id);

        if ($count >= $max) {
            $out['quota_exhausted'] = true;
            $out['quota_message'] = 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด ' . (int) $max . ' ครั้งแล้ว';
            $out['can_add'] = false;

            return $out;
        }

        if ($maxExpireYmd === '') {
            $out['can_add'] = false;

            return $out;
        }

        $out['can_add'] = true;

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_offer_price_ui_context')) {
    /**
     * Purpose:
     * บริบท UI ปุ่มขอต่ออายุใน `job_process` ขั้นเสนอราคาแล้ว — ขอได้ 1 ครั้ง
     * ไม่แสดงเมื่องานอยู่สถานะ `รออนุมัติซ่อม` (ใช้ปุ่มขั้น 5 แทน)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - array คีย์ can_add, flow, period_wait_check, preview_* ฯลฯ
     */
    function maintenance_transac_activate_spare_offer_price_ui_context($connect, $trp_id, array $bundle)
    {
        $defaults = array(
            'can_add' => false,
            'count' => 0,
            'max' => 1,
            'quota_exhausted' => false,
            'quota_message' => '',
            'flow' => 'offer_price',
            'tsp_id' => 0,
            'trp_id' => (int) $trp_id,
            'period_wait_check' => 0,
            'expire_auto' => true,
            'prev_expire_ymd' => '',
            'activate_date_ymd' => '',
            'suggested_expire_ymd' => '',
            'preview_activate_display' => '',
            'preview_expire_display' => '',
            'preview_code' => '',
            'history' => array(),
        );
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli) || !isset($bundle['row']) || !is_array($bundle['row'])) {
            return $defaults;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return $defaults;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        require_once __DIR__ . '/transac_setting_helpers.php';

        $row = $bundle['row'];
        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return $defaults;
        }

        $qvYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['trp_qv_date']) ? $row['trp_qv_date'] : '')
        );
        if ($qvYmd === '') {
            return $defaults;
        }

        $statusJob = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        if ($statusJob === MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE) {
            return $defaults;
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return $defaults;
        }

        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;
        $tsp_id = $spSend !== null && isset($spSend['tsp_id']) ? (int) $spSend['tsp_id'] : 0;
        if ($tsp_id < 1) {
            return $defaults;
        }

        $max = 1;
        $count = maintenance_transac_activate_spare_count_offer_price_requests($connect, $tsp_id);

        $out = $defaults;
        $out['tsp_id'] = $tsp_id;
        $out['trp_id'] = $trp_id;
        $out['count'] = $count;
        $out['max'] = $max;
        $out['history'] = maintenance_transac_activate_spare_fetch_modal_history_for_tsp($connect, $tsp_id);

        if ($count >= $max) {
            $out['quota_exhausted'] = true;
            $out['quota_message'] = 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด ' . (int) $max . ' ครั้งแล้ว';
            $out['can_add'] = false;

            return $out;
        }

        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PERIOD_WAIT_CHECK,
            14
        );
        $out['period_wait_check'] = $periodDays;

        $pack = maintenance_transac_activate_spare_compute_offer_price_extension($connect, $trp_id);
        if (!is_array($pack)) {
            $out['can_add'] = false;

            return $out;
        }

        $activateYmd = isset($pack['activate_date']) ? (string) $pack['activate_date'] : '';
        $expireYmd = isset($pack['expire_date']) ? (string) $pack['expire_date'] : '';
        $prevExpire = maintenance_transac_activate_spare_fetch_latest_expire_for_repair($connect, $trp_id);

        $out['prev_expire_ymd'] = $prevExpire;
        $out['activate_date_ymd'] = $activateYmd;
        $out['suggested_expire_ymd'] = $expireYmd;
        $out['preview_activate_display'] = maintenance_transac_activate_spare_format_date_display_th($activateYmd);
        $out['preview_expire_display'] = maintenance_transac_activate_spare_format_date_display_th($expireYmd);
        $out['preview_code'] = isset($pack['code']) ? (string) $pack['code'] : '';
        $out['can_add'] = true;

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_wait_repair_ui_context')) {
    /**
     * Purpose:
     * บริบท UI ปุ่มขอต่ออายุใน `job_process` ขั้นรอซ่อม (สถานะ `รอซ่อม`) — ขอได้ 1 ครั้ง
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle()`
     *
     * Return value:
     * - array คีย์ can_add, flow, priod_wait_qc, preview_* ฯลฯ
     */
    function maintenance_transac_activate_spare_wait_repair_ui_context($connect, $trp_id, array $bundle)
    {
        $defaults = array(
            'can_add' => false,
            'count' => 0,
            'max' => 1,
            'quota_exhausted' => false,
            'quota_message' => '',
            'flow' => 'wait_repair',
            'tsp_id' => 0,
            'trp_id' => (int) $trp_id,
            'period_wait_qc' => 0,
            'expire_auto' => true,
            'prev_expire_ymd' => '',
            'activate_date_ymd' => '',
            'suggested_expire_ymd' => '',
            'preview_activate_display' => '',
            'preview_expire_display' => '',
            'preview_code' => '',
            'history' => array(),
        );
        $trp_id = (int) $trp_id;
        if ($trp_id < 1 || !($connect instanceof mysqli) || !isset($bundle['row']) || !is_array($bundle['row'])) {
            return $defaults;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return $defaults;
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        require_once __DIR__ . '/transac_setting_helpers.php';

        $row = $bundle['row'];
        $statusJob = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        if ($statusJob !== MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR) {
            return $defaults;
        }

        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return $defaults;
        }

        $qcYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['trp_qc_date']) ? $row['trp_qc_date'] : '')
        );
        if ($qcYmd !== '') {
            return $defaults;
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return $defaults;
        }

        $spSend = isset($bundle['spare_send']) && is_array($bundle['spare_send']) ? $bundle['spare_send'] : null;
        $tsp_id = $spSend !== null && isset($spSend['tsp_id']) ? (int) $spSend['tsp_id'] : 0;
        if ($tsp_id < 1) {
            return $defaults;
        }

        $max = 1;
        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR
        );

        $out = $defaults;
        $out['tsp_id'] = $tsp_id;
        $out['trp_id'] = $trp_id;
        $out['count'] = $count;
        $out['max'] = $max;
        $out['history'] = maintenance_transac_activate_spare_fetch_modal_history_for_tsp($connect, $tsp_id);

        if ($count >= $max) {
            $out['quota_exhausted'] = true;
            $out['quota_message'] = 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด ' . (int) $max . ' ครั้งแล้ว';
            $out['can_add'] = false;

            return $out;
        }

        $periodDays = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_QC,
            20
        );
        $out['period_wait_qc'] = $periodDays;

        $pack = maintenance_transac_activate_spare_compute_wait_repair_extension($connect, $trp_id);
        if (!is_array($pack)) {
            $out['can_add'] = false;

            return $out;
        }

        $activateYmd = isset($pack['activate_date']) ? (string) $pack['activate_date'] : '';
        $expireYmd = isset($pack['expire_date']) ? (string) $pack['expire_date'] : '';
        $prevExpire = maintenance_transac_activate_spare_fetch_latest_expire_for_repair($connect, $trp_id);

        $out['prev_expire_ymd'] = $prevExpire;
        $out['activate_date_ymd'] = $activateYmd;
        $out['suggested_expire_ymd'] = $expireYmd;
        $out['preview_activate_display'] = maintenance_transac_activate_spare_format_date_display_th($activateYmd);
        $out['preview_expire_display'] = maintenance_transac_activate_spare_format_date_display_th($expireYmd);
        $out['preview_code'] = isset($pack['code']) ? (string) $pack['code'] : '';
        $out['can_add'] = true;

        return $out;
    }
}

if (!function_exists('maintenance_transac_activate_spare_insert_inapprove_extension')) {
    /**
     * Purpose:
     * บันทึกต่ออายุเครื่องสำรองขั้นรออนุมัติซ่อม (ตรวจจำนวนครั้งและสถานะงานก่อน insert)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - int $tsp_id: transac_spare.tsp_id
     * - string $request_name, $request_contact, $request_reason: จากฟอร์ม
     * - string $staff_user: พนักงานผู้ทำรายการ
     * - string $user_expire_ymd: วันหมดอายุจากฟอร์ม (เมื่อ priod_wait_approve = 0)
     *
     * Return value:
     * - array{ok:bool,message:string,tas_id?:int}
     */
    function maintenance_transac_activate_spare_insert_inapprove_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user,
        $user_expire_ymd = ''
    ) {
        $trp_id = (int) $trp_id;
        $tsp_id = (int) $tsp_id;
        $request_name = trim((string) $request_name);
        $request_contact = trim((string) $request_contact);
        $request_reason = trim((string) $request_reason);
        $staff_user = trim((string) $staff_user);

        if ($trp_id < 1 || $tsp_id < 1 || !($connect instanceof mysqli)) {
            return array('ok' => false, 'message' => 'ข้อมูลงานไม่ถูกต้อง');
        }
        if ($request_name === '' || $request_contact === '' || $request_reason === '') {
            return array('ok' => false, 'message' => 'กรุณากรอกชื่อผู้ร้องขอ เบอร์/LINE และเหตุผลให้ครบ');
        }
        if ($staff_user === '') {
            return array('ok' => false, 'message' => 'ไม่พบชื่อพนักงานผู้ทำรายการ — กรุณาเข้าสู่ระบบใหม่');
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array('ok' => false, 'message' => 'ยังไม่มีตาราง transac_activate_spare บนระบบ');
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        require_once __DIR__ . '/transac_setting_helpers.php';

        $sql = 'SELECT trp.trp_status_job, trp.trp_status_firmma, trp.trp_product, trp.trp_firm_qv_date, tsp.tsp_trp_id'
            . ' FROM transac_spare tsp'
            . ' INNER JOIN transac_repair trp ON trp.trp_id = tsp.tsp_trp_id'
            . ' WHERE tsp.tsp_id = ? AND tsp.tsp_trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array('ok' => false, 'message' => 'เตรียมคำสั่งตรวจสอบงานไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmt, 'ii', $tsp_id, $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array('ok' => false, 'message' => 'ตรวจสอบงานไม่สำเร็จ');
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return array('ok' => false, 'message' => 'ไม่พบข้อมูลเครื่องสำรองของงานนี้');
        }

        $firmRaw = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_firm_qv_date']) ? $row['trp_firm_qv_date'] : ''));
        if ($firmRaw !== '') {
            return array('ok' => false, 'message' => 'งานอนุมัติซ่อมแล้ว — ไม่สามารถต่ออายุในขั้นรออนุมัติได้');
        }

        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return array('ok' => false, 'message' => 'ลูกค้าไม่อนุมัติซ่อม — ไม่สามารถต่ออายุได้');
        }

        $statusJob = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        if ($statusJob !== MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE) {
            return array('ok' => false, 'message' => 'สถานะงานไม่ใช่รออนุมัติซ่อม — ไม่สามารถบันทึกต่ออายุได้');
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return array('ok' => false, 'message' => 'รุ่นเครื่องซ่อมไม่รองรับการต่ออายุเครื่องสำรอง');
        }

        $max = maintenance_transac_setting_get_int(
            $connect,
            MAINTENANCE_SETTING_OPTION_MAX_ACTIVATE_SPARE_INAPPROVE,
            2
        );
        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_APPROVE
        );
        if ($count >= $max) {
            return array(
                'ok' => false,
                'message' => 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด ' . (int) $max . ' ครั้งแล้ว',
            );
        }

        $pack = maintenance_transac_activate_spare_compute_inapprove_extension(
            $connect,
            $trp_id,
            '',
            $user_expire_ymd,
            $tsp_id
        );
        if (!is_array($pack)) {
            $periodDays = maintenance_transac_setting_get_int(
                $connect,
                MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_APPROVE,
                0
            );
            if ($periodDays <= 0) {
                return array('ok' => false, 'message' => 'กรุณาเลือกวันที่หมดอายุเครื่องสำรอง');
            }

            return array('ok' => false, 'message' => 'คำนวณวันหมดอายุหรือรหัสไม่สำเร็จ');
        }

        $tas_id = maintenance_transac_activate_spare_insert($connect, array(
            'tas_tsp_id' => $tsp_id,
            'tas_trp_status_job' => $statusJob,
            'tas_activate_date' => $pack['activate_date'],
            'tas_expire_date' => $pack['expire_date'],
            'tas_activate_code' => $pack['code'],
            'tas_request_name' => $request_name,
            'tas_request_contact' => $request_contact,
            'tas_request_reason' => $request_reason,
            'tas_staff_user' => $staff_user,
        ));
        if ($tas_id < 1) {
            return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ');
        }

        return array(
            'ok' => true,
            'message' => 'บันทึกรหัสต่ออายุเครื่องสำรองแล้ว',
            'tas_id' => $tas_id,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_insert_offer_price_extension')) {
    /**
     * Purpose:
     * บันทึกต่ออายุเครื่องสำรองขั้นเสนอราคาแล้ว (ขอได้ 1 ครั้ง — วันที่คำนวณอัตโนมัติ)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id, int $tsp_id: งานซ่อม / เครื่องสำรอง
     * - string $request_name, $request_contact, $request_reason: จากฟอร์ม modal
     * - string $staff_user: พนักงานผู้ทำรายการ
     *
     * Return value:
     * - array{ok:bool,message:string,tas_id?:int}
     */
    function maintenance_transac_activate_spare_insert_offer_price_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user
    ) {
        $trp_id = (int) $trp_id;
        $tsp_id = (int) $tsp_id;
        $request_name = trim((string) $request_name);
        $request_contact = trim((string) $request_contact);
        $request_reason = trim((string) $request_reason);
        $staff_user = trim((string) $staff_user);

        if ($trp_id < 1 || $tsp_id < 1 || !($connect instanceof mysqli)) {
            return array('ok' => false, 'message' => 'ข้อมูลงานไม่ถูกต้อง');
        }
        if ($request_name === '' || $request_contact === '' || $request_reason === '') {
            return array('ok' => false, 'message' => 'กรุณากรอกชื่อผู้ร้องขอ เบอร์/LINE และเหตุผลให้ครบ');
        }
        if ($staff_user === '') {
            return array('ok' => false, 'message' => 'ไม่พบชื่อพนักงานผู้ทำรายการ — กรุณาเข้าสู่ระบบใหม่');
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array('ok' => false, 'message' => 'ยังไม่มีตาราง transac_activate_spare บนระบบ');
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $sql = 'SELECT trp.trp_status_job, trp.trp_status_firmma, trp.trp_product, trp.trp_qv_date, tsp.tsp_trp_id'
            . ' FROM transac_spare tsp'
            . ' INNER JOIN transac_repair trp ON trp.trp_id = tsp.tsp_trp_id'
            . ' WHERE tsp.tsp_id = ? AND tsp.tsp_trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array('ok' => false, 'message' => 'เตรียมคำสั่งตรวจสอบงานไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmt, 'ii', $tsp_id, $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array('ok' => false, 'message' => 'ตรวจสอบงานไม่สำเร็จ');
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return array('ok' => false, 'message' => 'ไม่พบข้อมูลเครื่องสำรองของงานนี้');
        }

        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return array('ok' => false, 'message' => 'ลูกค้าไม่อนุมัติซ่อม — ไม่สามารถต่ออายุได้');
        }

        $qvYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['trp_qv_date']) ? $row['trp_qv_date'] : '')
        );
        if ($qvYmd === '') {
            return array('ok' => false, 'message' => 'ยังไม่มีวันที่เสนอราคา — ไม่สามารถบันทึกต่ออายุได้');
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return array('ok' => false, 'message' => 'รุ่นเครื่องซ่อมไม่รองรับการต่ออายุเครื่องสำรอง');
        }

        if (maintenance_transac_activate_spare_count_offer_price_requests($connect, $tsp_id) >= 1) {
            return array(
                'ok' => false,
                'message' => 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด 1 ครั้งแล้ว',
            );
        }

        $pack = maintenance_transac_activate_spare_compute_offer_price_extension($connect, $trp_id);
        if (!is_array($pack)) {
            return array('ok' => false, 'message' => 'คำนวณวันที่ต่ออายุหรือรหัสไม่สำเร็จ — ตรวจสอบประวัติต่ออายุก่อนหน้า');
        }

        $tas_id = maintenance_transac_activate_spare_insert($connect, array(
            'tas_tsp_id' => $tsp_id,
            'tas_trp_status_job' => MAINTENANCE_ACTIVATE_SPARE_STATUS_OFFER_PRICE,
            'tas_activate_date' => $pack['activate_date'],
            'tas_expire_date' => $pack['expire_date'],
            'tas_activate_code' => $pack['code'],
            'tas_request_name' => $request_name,
            'tas_request_contact' => $request_contact,
            'tas_request_reason' => $request_reason,
            'tas_staff_user' => $staff_user,
        ));
        if ($tas_id < 1) {
            return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ');
        }

        return array(
            'ok' => true,
            'message' => 'บันทึกรหัสต่ออายุเครื่องสำรองแล้ว',
            'tas_id' => $tas_id,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_insert_wait_repair_extension')) {
    /**
     * Purpose:
     * บันทึกต่ออายุเครื่องสำรองขั้นรอซ่อม (ขอได้ 1 ครั้ง — วันที่คำนวณอัตโนมัติ)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id, int $tsp_id: งานซ่อม / เครื่องสำรอง
     * - string $request_name, $request_contact, $request_reason: จากฟอร์ม modal
     * - string $staff_user: พนักงานผู้ทำรายการ
     *
     * Return value:
     * - array{ok:bool,message:string,tas_id?:int}
     */
    function maintenance_transac_activate_spare_insert_wait_repair_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user
    ) {
        $trp_id = (int) $trp_id;
        $tsp_id = (int) $tsp_id;
        $request_name = trim((string) $request_name);
        $request_contact = trim((string) $request_contact);
        $request_reason = trim((string) $request_reason);
        $staff_user = trim((string) $staff_user);

        if ($trp_id < 1 || $tsp_id < 1 || !($connect instanceof mysqli)) {
            return array('ok' => false, 'message' => 'ข้อมูลงานไม่ถูกต้อง');
        }
        if ($request_name === '' || $request_contact === '' || $request_reason === '') {
            return array('ok' => false, 'message' => 'กรุณากรอกชื่อผู้ร้องขอ เบอร์/LINE และเหตุผลให้ครบ');
        }
        if ($staff_user === '') {
            return array('ok' => false, 'message' => 'ไม่พบชื่อพนักงานผู้ทำรายการ — กรุณาเข้าสู่ระบบใหม่');
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array('ok' => false, 'message' => 'ยังไม่มีตาราง transac_activate_spare บนระบบ');
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $sql = 'SELECT trp.trp_status_job, trp.trp_status_firmma, trp.trp_product, trp.trp_qc_date, tsp.tsp_trp_id'
            . ' FROM transac_spare tsp'
            . ' INNER JOIN transac_repair trp ON trp.trp_id = tsp.tsp_trp_id'
            . ' WHERE tsp.tsp_id = ? AND tsp.tsp_trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array('ok' => false, 'message' => 'เตรียมคำสั่งตรวจสอบงานไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmt, 'ii', $tsp_id, $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array('ok' => false, 'message' => 'ตรวจสอบงานไม่สำเร็จ');
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return array('ok' => false, 'message' => 'ไม่พบข้อมูลเครื่องสำรองของงานนี้');
        }

        $statusJob = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        if ($statusJob !== MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR) {
            return array('ok' => false, 'message' => 'สถานะงานไม่ใช่รอซ่อม — ไม่สามารถบันทึกต่ออายุได้');
        }

        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return array('ok' => false, 'message' => 'ลูกค้าไม่อนุมัติซ่อม — ไม่สามารถต่ออายุได้');
        }

        $qcYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['trp_qc_date']) ? $row['trp_qc_date'] : '')
        );
        if ($qcYmd !== '') {
            return array('ok' => false, 'message' => 'ซ่อมและ QC เสร็จแล้ว — ไม่สามารถขอต่ออายุในขั้นนี้ได้');
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return array('ok' => false, 'message' => 'รุ่นเครื่องซ่อมไม่รองรับการต่ออายุเครื่องสำรอง');
        }

        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR
        );
        if ($count >= 1) {
            return array(
                'ok' => false,
                'message' => 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด 1 ครั้งแล้ว',
            );
        }

        $pack = maintenance_transac_activate_spare_compute_wait_repair_extension($connect, $trp_id);
        if (!is_array($pack)) {
            return array('ok' => false, 'message' => 'คำนวณวันที่ต่ออายุหรือรหัสไม่สำเร็จ — ตรวจสอบประวัติต่ออายุก่อนหน้า');
        }

        $tas_id = maintenance_transac_activate_spare_insert($connect, array(
            'tas_tsp_id' => $tsp_id,
            'tas_trp_status_job' => MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_REPAIR,
            'tas_activate_date' => $pack['activate_date'],
            'tas_expire_date' => $pack['expire_date'],
            'tas_activate_code' => $pack['code'],
            'tas_request_name' => $request_name,
            'tas_request_contact' => $request_contact,
            'tas_request_reason' => $request_reason,
            'tas_staff_user' => $staff_user,
        ));
        if ($tas_id < 1) {
            return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ');
        }

        return array(
            'ok' => true,
            'message' => 'บันทึกรหัสต่ออายุเครื่องสำรองแล้ว',
            'tas_id' => $tas_id,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_insert_wait_spare_extension')) {
    /**
     * Purpose:
     * บันทึกต่ออายุเครื่องสำรองขั้นรอเครื่องสำรอง (ขอได้ 1 ครั้ง — วันที่คำนวณอัตโนมัติ)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - int $tsp_id: transac_spare.tsp_id
     * - string $request_name, $request_contact, $request_reason: จากฟอร์ม modal
     * - string $staff_user: พนักงานผู้ทำรายการ
     *
     * Return value:
     * - array{ok:bool,message:string,tas_id?:int}
     */
    function maintenance_transac_activate_spare_insert_wait_spare_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user
    ) {
        $trp_id = (int) $trp_id;
        $tsp_id = (int) $tsp_id;
        $request_name = trim((string) $request_name);
        $request_contact = trim((string) $request_contact);
        $request_reason = trim((string) $request_reason);
        $staff_user = trim((string) $staff_user);

        if ($trp_id < 1 || $tsp_id < 1 || !($connect instanceof mysqli)) {
            return array('ok' => false, 'message' => 'ข้อมูลงานไม่ถูกต้อง');
        }
        if ($request_name === '' || $request_contact === '' || $request_reason === '') {
            return array('ok' => false, 'message' => 'กรุณากรอกชื่อผู้ร้องขอ เบอร์/LINE และเหตุผลให้ครบ');
        }
        if ($staff_user === '') {
            return array('ok' => false, 'message' => 'ไม่พบชื่อพนักงานผู้ทำรายการ — กรุณาเข้าสู่ระบบใหม่');
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array('ok' => false, 'message' => 'ยังไม่มีตาราง transac_activate_spare บนระบบ');
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $sql = 'SELECT trp.trp_status_job, trp.trp_product, trp.trp_send_date, trp.trp_status_firmma, tsp.tsp_trp_id'
            . ' FROM transac_spare tsp'
            . ' INNER JOIN transac_repair trp ON trp.trp_id = tsp.tsp_trp_id'
            . ' WHERE tsp.tsp_id = ? AND tsp.tsp_trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array('ok' => false, 'message' => 'เตรียมคำสั่งตรวจสอบงานไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmt, 'ii', $tsp_id, $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array('ok' => false, 'message' => 'ตรวจสอบงานไม่สำเร็จ');
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return array('ok' => false, 'message' => 'ไม่พบข้อมูลเครื่องสำรองของงานนี้');
        }

        $firmma = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_firmma']) ? $row['trp_status_firmma'] : ''));
        if ($firmma === 'ไม่อนุมัติซ่อม') {
            return array('ok' => false, 'message' => 'ลูกค้าไม่อนุมัติซ่อม — ไม่สามารถต่ออายุได้');
        }

        $sendRepairYmd = maintenance_transac_activate_spare_normalize_date_ymd(
            maintenance_flow_db_text_to_utf8(isset($row['trp_send_date']) ? $row['trp_send_date'] : '')
        );
        if ($sendRepairYmd === '') {
            return array('ok' => false, 'message' => 'ยังไม่ส่งคืนเครื่องซ่อม — ไม่สามารถขอต่ออายุในขั้นนี้ได้');
        }

        $sqlRet = 'SELECT MAX(tsp_datereturn) AS mx FROM transac_spare WHERE tsp_trp_id = ?'
            . " AND tsp_datereturn IS NOT NULL AND TRIM(tsp_datereturn) <> ''";
        $stmtRet = mysqli_prepare($connect, $sqlRet);
        if ($stmtRet === false) {
            return array('ok' => false, 'message' => 'ตรวจสอบวันรับเครื่องสำรองไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmtRet, 'i', $trp_id);
        if (!mysqli_stmt_execute($stmtRet)) {
            mysqli_stmt_close($stmtRet);

            return array('ok' => false, 'message' => 'ตรวจสอบวันรับเครื่องสำรองไม่สำเร็จ');
        }
        $resRet = mysqli_stmt_get_result($stmtRet);
        $rowRet = $resRet ? mysqli_fetch_assoc($resRet) : null;
        if ($resRet) {
            mysqli_free_result($resRet);
        }
        mysqli_stmt_close($stmtRet);
        $retMaxYmd = is_array($rowRet) && isset($rowRet['mx'])
            ? maintenance_transac_activate_spare_normalize_date_ymd(trim((string) $rowRet['mx']))
            : '';
        if ($retMaxYmd !== '') {
            return array('ok' => false, 'message' => 'รับเครื่องสำรองแล้ว — ไม่สามารถขอต่ออายุในขั้นนี้ได้');
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return array('ok' => false, 'message' => 'รุ่นเครื่องซ่อมไม่รองรับการต่ออายุเครื่องสำรอง');
        }

        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_SPARE
        );
        if ($count >= 1) {
            return array(
                'ok' => false,
                'message' => 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด 1 ครั้งแล้ว',
            );
        }

        $pack = maintenance_transac_activate_spare_compute_wait_spare_extension($connect, $trp_id, $tsp_id);
        if (!is_array($pack)) {
            return array('ok' => false, 'message' => 'คำนวณวันที่ต่ออายุหรือรหัสไม่สำเร็จ — ตรวจสอบประวัติต่ออายุก่อนหน้า');
        }

        $tas_id = maintenance_transac_activate_spare_insert($connect, array(
            'tas_tsp_id' => $tsp_id,
            'tas_trp_status_job' => MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_SPARE,
            'tas_activate_date' => $pack['activate_date'],
            'tas_expire_date' => $pack['expire_date'],
            'tas_activate_code' => $pack['code'],
            'tas_request_name' => $request_name,
            'tas_request_contact' => $request_contact,
            'tas_request_reason' => $request_reason,
            'tas_staff_user' => $staff_user,
        ));
        if ($tas_id < 1) {
            return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ');
        }

        return array(
            'ok' => true,
            'message' => 'บันทึกรหัสต่ออายุเครื่องสำรองแล้ว',
            'tas_id' => $tas_id,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_insert_wait_hardware_extension')) {
    /**
     * Purpose:
     * บันทึกต่ออายุเครื่องสำรองขั้นรอเครื่องซ่อม — ขอได้ 1 ครั้ง, หมดอายุไม่เกิน 7 วันนับจากวันขอ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: transac_repair.trp_id
     * - int $tsp_id: transac_spare.tsp_id
     * - string $request_name, $request_contact, $request_reason: จากฟอร์ม modal
     * - string $staff_user: พนักงานผู้ทำรายการ
     * - string $user_expire_ymd: วันหมดอายุจากฟอร์ม
     *
     * Return value:
     * - array{ok:bool,message:string,tas_id?:int}
     */
    function maintenance_transac_activate_spare_insert_wait_hardware_extension(
        $connect,
        $trp_id,
        $tsp_id,
        $request_name,
        $request_contact,
        $request_reason,
        $staff_user,
        $user_expire_ymd = ''
    ) {
        $trp_id = (int) $trp_id;
        $tsp_id = (int) $tsp_id;
        $request_name = trim((string) $request_name);
        $request_contact = trim((string) $request_contact);
        $request_reason = trim((string) $request_reason);
        $staff_user = trim((string) $staff_user);

        if ($trp_id < 1 || $tsp_id < 1 || !($connect instanceof mysqli)) {
            return array('ok' => false, 'message' => 'ข้อมูลงานไม่ถูกต้อง');
        }
        if ($request_name === '' || $request_contact === '' || $request_reason === '') {
            return array('ok' => false, 'message' => 'กรุณากรอกชื่อผู้ร้องขอ เบอร์/LINE และเหตุผลให้ครบ');
        }
        if ($staff_user === '') {
            return array('ok' => false, 'message' => 'ไม่พบชื่อพนักงานผู้ทำรายการ — กรุณาเข้าสู่ระบบใหม่');
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return array('ok' => false, 'message' => 'ยังไม่มีตาราง transac_activate_spare บนระบบ');
        }

        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $sql = 'SELECT trp.trp_status_job, trp.trp_product, trp.trp_receive_date, tsp.tsp_trp_id'
            . ' FROM transac_spare tsp'
            . ' INNER JOIN transac_repair trp ON trp.trp_id = tsp.tsp_trp_id'
            . ' WHERE tsp.tsp_id = ? AND tsp.tsp_trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array('ok' => false, 'message' => 'เตรียมคำสั่งตรวจสอบงานไม่สำเร็จ');
        }
        mysqli_stmt_bind_param($stmt, 'ii', $tsp_id, $trp_id);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return array('ok' => false, 'message' => 'ตรวจสอบงานไม่สำเร็จ');
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return array('ok' => false, 'message' => 'ไม่พบข้อมูลเครื่องสำรองของงานนี้');
        }

        $statusJob = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_status_job']) ? $row['trp_status_job'] : ''));
        if ($statusJob !== MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE) {
            return array('ok' => false, 'message' => 'สถานะงานไม่ใช่รอเครื่องซ่อม — ไม่สามารถบันทึกต่ออายุได้');
        }

        $recvRaw = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_receive_date']) ? $row['trp_receive_date'] : ''));
        if ($recvRaw !== '' && maintenance_transac_activate_spare_normalize_date_ymd($recvRaw) !== '') {
            return array('ok' => false, 'message' => 'รับเครื่องซ่อมแล้ว — ไม่สามารถขอต่ออายุในขั้นรอเครื่องซ่อมได้');
        }

        $trpProduct = trim(maintenance_flow_db_text_to_utf8(isset($row['trp_product']) ? $row['trp_product'] : ''));
        if (!maintenance_transac_activate_spare_product_eligible_for_auto($trpProduct)) {
            return array('ok' => false, 'message' => 'รุ่นเครื่องซ่อมไม่รองรับการต่ออายุเครื่องสำรอง');
        }

        $count = maintenance_transac_activate_spare_count_manual_extension_by_tsp_and_status(
            $connect,
            $tsp_id,
            MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE
        );
        if ($count >= 1) {
            return array(
                'ok' => false,
                'message' => 'คุณขอต่ออายุการใช้งานเครื่องครบตามสิทธิ์ที่กำหนด 1 ครั้งแล้ว',
            );
        }

        $activate_date_ymd = date('Y-m-d');
        $resolved = maintenance_transac_activate_spare_resolve_wait_hardware_expire_ymd(
            $activate_date_ymd,
            $user_expire_ymd
        );
        if (empty($resolved['ok']) || !isset($resolved['expire_date'])) {
            $err = isset($resolved['error']) ? trim((string) $resolved['error']) : '';

            return array(
                'ok' => false,
                'message' => $err !== '' ? $err : 'วันหมดอายุไม่ถูกต้อง',
            );
        }

        $expire_date_ymd = (string) $resolved['expire_date'];
        $codePack = maintenance_transac_activate_spare_compute_code_from_expire_date($expire_date_ymd);
        $code = isset($codePack['code']) ? trim((string) $codePack['code']) : '';
        if ($code === '') {
            return array('ok' => false, 'message' => 'คำนวณรหัสต่ออายุไม่สำเร็จ');
        }

        $tas_id = maintenance_transac_activate_spare_insert($connect, array(
            'tas_tsp_id' => $tsp_id,
            'tas_trp_status_job' => MAINTENANCE_ACTIVATE_SPARE_STATUS_WAIT_HARDWARE,
            'tas_activate_date' => $activate_date_ymd,
            'tas_expire_date' => $expire_date_ymd,
            'tas_activate_code' => $code,
            'tas_request_name' => $request_name,
            'tas_request_contact' => $request_contact,
            'tas_request_reason' => $request_reason,
            'tas_staff_user' => $staff_user,
        ));
        if ($tas_id < 1) {
            return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ');
        }

        return array(
            'ok' => true,
            'message' => 'บันทึกรหัสต่ออายุเครื่องสำรองแล้ว',
            'tas_id' => $tas_id,
        );
    }
}

if (!function_exists('maintenance_transac_activate_spare_count_by_tsp_and_status')) {
    /**
     * Purpose:
     * นับจำนวนครั้งที่ต่ออายุแล้ว ต่อ tsp_id และสถานะงานซ่อม (ใช้เทียบ max_activate_spare_inapprove)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $tsp_id: transac_spare.tsp_id
     * - string $trp_status_job: สถานะงานขณะต่ออายุ (ว่าง = นับทุกสถานะ)
     *
     * Return value:
     * - int
     */
    function maintenance_transac_activate_spare_count_by_tsp_and_status($connect, $tsp_id, $trp_status_job = '')
    {
        $tsp_id = (int) $tsp_id;
        if ($tsp_id < 1 || !($connect instanceof mysqli)) {
            return 0;
        }
        if (!maintenance_transac_activate_spare_table_available($connect)) {
            return 0;
        }

        $status = trim((string) $trp_status_job);
        if ($status === '') {
            $sql = 'SELECT COUNT(*) AS cnt FROM transac_activate_spare WHERE tas_tsp_id = ?';
            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return 0;
            }
            mysqli_stmt_bind_param($stmt, 'i', $tsp_id);
        } else {
            $sql = 'SELECT COUNT(*) AS cnt FROM transac_activate_spare WHERE tas_tsp_id = ? AND tas_trp_status_job = ?';
            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return 0;
            }
            mysqli_stmt_bind_param($stmt, 'is', $tsp_id, $status);
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return 0;
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return is_array($row) ? (int) (isset($row['cnt']) ? $row['cnt'] : 0) : 0;
    }
}
