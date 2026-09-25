<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * อ่าน/เขียนค่า config จากตาราง `transac_setting` (tst_option, tst_value, tst_description)
 *
 * Flow:
 * - อ่านค่าเดียว: `maintenance_transac_setting_get_value($connect, 'period_first_spare')`
 * - อ่านเป็นจำนวนเต็ม: `maintenance_transac_setting_get_int($connect, 'priod_wait_qc', 20)`
 * - โหลดทั้งชุด: `maintenance_transac_setting_fetch_value_map($connect)` → option => value (string)
 *
 * Example:
 * $days = maintenance_transac_setting_get_int($connect, 'period_wait_check', 14);
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

if (!defined('MAINTENANCE_SETTING_OPTION_PERIOD_FIRST_SPARE')) {
    define('MAINTENANCE_SETTING_OPTION_PERIOD_FIRST_SPARE', 'period_first_spare');
}
if (!defined('MAINTENANCE_SETTING_OPTION_PERIOD_AFTER_RECEIVE_REPAIR')) {
    define('MAINTENANCE_SETTING_OPTION_PERIOD_AFTER_RECEIVE_REPAIR', 'period_after_receive_repair');
}
if (!defined('MAINTENANCE_SETTING_OPTION_PERIOD_WAIT_CHECK')) {
    define('MAINTENANCE_SETTING_OPTION_PERIOD_WAIT_CHECK', 'period_wait_check');
}
if (!defined('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_APPROVE')) {
    define('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_APPROVE', 'priod_wait_approve');
}
if (!defined('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_QC')) {
    define('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_QC', 'priod_wait_qc');
}
if (!defined('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SENDREPAIR')) {
    define('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SENDREPAIR', 'priod_wait_sendrepair');
}
if (!defined('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SPARE')) {
    define('MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SPARE', 'priod_wait_spare');
}
if (!defined('MAINTENANCE_SETTING_OPTION_MAX_ACTIVATE_SPARE_INAPPROVE')) {
    define('MAINTENANCE_SETTING_OPTION_MAX_ACTIVATE_SPARE_INAPPROVE', 'max_activate_spare_inapprove');
}

// ─────────────────────────────────────────────────────────────────
// helpers — fallback / cache
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_transac_setting_legacy_fallback_value_map')) {
    /**
     * Purpose:
     * ค่า default เมื่อยังไม่มีตาราง `transac_setting` (เทียบ `database/transac_setting.sql`)
     *
     * Return value:
     * - array<string,string> tst_option => tst_value
     */
    function maintenance_transac_setting_legacy_fallback_value_map()
    {
        return array(
            MAINTENANCE_SETTING_OPTION_PERIOD_FIRST_SPARE => '21',
            MAINTENANCE_SETTING_OPTION_PERIOD_AFTER_RECEIVE_REPAIR => '14',
            MAINTENANCE_SETTING_OPTION_PERIOD_WAIT_CHECK => '14',
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_APPROVE => '0',
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_QC => '20',
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SENDREPAIR => '20',
            MAINTENANCE_SETTING_OPTION_PRIOD_WAIT_SPARE => '3',
            MAINTENANCE_SETTING_OPTION_MAX_ACTIVATE_SPARE_INAPPROVE => '2',
        );
    }
}

if (!function_exists('maintenance_transac_setting_clear_request_cache')) {
    /**
     * Purpose:
     * ล้าง cache ใน request (ใช้หลัง UPDATE setting ในหน้าเดียวกัน)
     *
     * Return value:
     * - void
     */
    function maintenance_transac_setting_clear_request_cache()
    {
        $GLOBALS['maintenance_transac_setting_value_map_cache'] = null;
        $GLOBALS['maintenance_transac_setting_value_map_cache_connect'] = null;
    }
}

if (!function_exists('maintenance_transac_setting_table_available')) {
    /**
     * Purpose:
     * ตรวจว่ามีตาราง `transac_setting` และ query ได้
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - bool
     */
    function maintenance_transac_setting_table_available($connect)
    {
        if (!($connect instanceof mysqli)) {
            return false;
        }
        $res = @mysqli_query($connect, 'SELECT 1 FROM transac_setting LIMIT 1');
        if ($res === false) {
            return false;
        }
        mysqli_free_result($res);

        return true;
    }
}

if (!function_exists('maintenance_transac_setting_fetch_rows')) {
    /**
     * Purpose:
     * ดึงแถว setting ทั้งหมดเรียงตาม `tst_option`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array<int,array{tst_id:int,tst_option:string,tst_value:string,tst_description:string}>
     */
    function maintenance_transac_setting_fetch_rows($connect)
    {
        if (!maintenance_transac_setting_table_available($connect)) {
            return array();
        }
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        $sql = 'SELECT tst_id, tst_option, tst_value, tst_description FROM transac_setting ORDER BY tst_option ASC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $rows = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = array(
                'tst_id' => (int) (isset($row['tst_id']) ? $row['tst_id'] : 0),
                'tst_option' => trim(maintenance_flow_db_text_to_utf8(isset($row['tst_option']) ? $row['tst_option'] : '')),
                'tst_value' => trim(maintenance_flow_db_text_to_utf8(isset($row['tst_value']) ? $row['tst_value'] : '')),
                'tst_description' => trim(maintenance_flow_db_text_to_utf8(isset($row['tst_description']) ? $row['tst_description'] : '')),
            );
        }

        return $rows;
    }
}

if (!function_exists('maintenance_transac_setting_fetch_value_map')) {
    /**
     * Purpose:
     * map `tst_option` → `tst_value` (string) จาก DB — cache ต่อ connection ใน request เดียว
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array<string,string>
     */
    function maintenance_transac_setting_fetch_value_map($connect)
    {
        if (!($connect instanceof mysqli)) {
            return maintenance_transac_setting_legacy_fallback_value_map();
        }
        $cacheKey = spl_object_hash($connect);
        if (
            isset($GLOBALS['maintenance_transac_setting_value_map_cache'])
            && is_array($GLOBALS['maintenance_transac_setting_value_map_cache'])
            && isset($GLOBALS['maintenance_transac_setting_value_map_cache_connect'])
            && $GLOBALS['maintenance_transac_setting_value_map_cache_connect'] === $cacheKey
        ) {
            return $GLOBALS['maintenance_transac_setting_value_map_cache'];
        }

        $rows = maintenance_transac_setting_fetch_rows($connect);
        if ($rows === array()) {
            $map = maintenance_transac_setting_legacy_fallback_value_map();
        } else {
            $map = array();
            foreach ($rows as $row) {
                $opt = isset($row['tst_option']) ? (string) $row['tst_option'] : '';
                if ($opt !== '') {
                    $map[$opt] = isset($row['tst_value']) ? (string) $row['tst_value'] : '';
                }
            }
            if ($map === array()) {
                $map = maintenance_transac_setting_legacy_fallback_value_map();
            }
        }

        $GLOBALS['maintenance_transac_setting_value_map_cache'] = $map;
        $GLOBALS['maintenance_transac_setting_value_map_cache_connect'] = $cacheKey;

        return $map;
    }
}

if (!function_exists('maintenance_transac_setting_safe_fetch_value_map')) {
    /**
     * Purpose:
     * โหลด map setting — ไม่ throw เมื่อ query ล้มเหลว
     *
     * Parameters สำคัญ:
     * - mysqli|null $connect: connection
     *
     * Return value:
     * - array<string,string>
     */
    function maintenance_transac_setting_safe_fetch_value_map($connect = null)
    {
        if (!($connect instanceof mysqli)) {
            return maintenance_transac_setting_legacy_fallback_value_map();
        }
        try {
            return maintenance_transac_setting_fetch_value_map($connect);
        } catch (Throwable $e) {
            return maintenance_transac_setting_legacy_fallback_value_map();
        }
    }
}

if (!function_exists('maintenance_transac_setting_fetch_by_option')) {
    /**
     * Purpose:
     * ดึงแถว setting ตาม `tst_option` (prepared)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $option: ชื่อ option
     *
     * Return value:
     * - array{tst_id:int,tst_option:string,tst_value:string,tst_description:string}|null
     */
    function maintenance_transac_setting_fetch_by_option($connect, $option)
    {
        $option = trim((string) $option);
        if ($option === '' || !($connect instanceof mysqli)) {
            return null;
        }
        if (!maintenance_transac_setting_table_available($connect)) {
            return null;
        }
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        $sql = 'SELECT tst_id, tst_option, tst_value, tst_description FROM transac_setting WHERE tst_option = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 's', $option);
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

        return array(
            'tst_id' => (int) (isset($row['tst_id']) ? $row['tst_id'] : 0),
            'tst_option' => trim(maintenance_flow_db_text_to_utf8(isset($row['tst_option']) ? $row['tst_option'] : '')),
            'tst_value' => trim(maintenance_flow_db_text_to_utf8(isset($row['tst_value']) ? $row['tst_value'] : '')),
            'tst_description' => trim(maintenance_flow_db_text_to_utf8(isset($row['tst_description']) ? $row['tst_description'] : '')),
        );
    }
}

if (!function_exists('maintenance_transac_setting_get_value')) {
    /**
     * Purpose:
     * อ่าน `tst_value` ตามชื่อ option — คืน default เมื่อไม่พบหรือยังไม่มีตาราง
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $option: ชื่อ option (เช่น `period_first_spare`)
     * - string $default: ค่าเมื่อไม่พบ
     *
     * Return value:
     * - string
     */
    function maintenance_transac_setting_get_value($connect, $option, $default = '')
    {
        $option = trim((string) $option);
        if ($option === '') {
            return (string) $default;
        }
        $map = maintenance_transac_setting_fetch_value_map($connect);
        if (isset($map[$option])) {
            return (string) $map[$option];
        }
        $fallback = maintenance_transac_setting_legacy_fallback_value_map();
        if (isset($fallback[$option])) {
            return (string) $fallback[$option];
        }

        return (string) $default;
    }
}

if (!function_exists('maintenance_transac_setting_get_int')) {
    /**
     * Purpose:
     * อ่าน `tst_value` แล้วแปลงเป็นจำนวนเต็ม (ใช้กับจำนวนวัน/ครั้ง)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $option: ชื่อ option
     * - int $default: ค่าเมื่อไม่พบหรือ parse ไม่ได้
     *
     * Return value:
     * - int
     */
    function maintenance_transac_setting_get_int($connect, $option, $default = 0)
    {
        $raw = maintenance_transac_setting_get_value($connect, $option, (string) $default);
        if ($raw === '' || !is_numeric($raw)) {
            return (int) $default;
        }

        return (int) $raw;
    }
}

if (!function_exists('maintenance_transac_setting_update_value')) {
    /**
     * Purpose:
     * อัปเดต `tst_value` ของ option ที่มีอยู่แล้ว (ไม่ insert option ใหม่)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $option: ชื่อ option
     * - string $value: ค่าใหม่ (เก็บเป็น string)
     *
     * Return value:
     * - bool true เมื่ออัปเดตสำเร็จอย่างน้อย 1 แถว
     */
    function maintenance_transac_setting_update_value($connect, $option, $value)
    {
        $option = trim((string) $option);
        $value = trim((string) $value);
        if ($option === '' || !($connect instanceof mysqli)) {
            return false;
        }
        if (!maintenance_transac_setting_table_available($connect)) {
            return false;
        }
        $sql = 'UPDATE transac_setting SET tst_value = ? WHERE tst_option = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $value, $option);
        $ok = mysqli_stmt_execute($stmt);
        $affected = $ok ? mysqli_stmt_affected_rows($stmt) : 0;
        mysqli_stmt_close($stmt);
        if ($ok && $affected > 0) {
            maintenance_transac_setting_clear_request_cache();

            return true;
        }

        return false;
    }
}
