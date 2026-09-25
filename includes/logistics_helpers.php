<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รายการขนส่งและ URL ตรวจ tracking จากตาราง `logistics_carrier` (แทน hardcode ใน `logistisc()` / JS)
 *
 * Flow:
 * - dropdown: `maintenance_logistics_fetch_active_names($connect)`
 * - validate: `maintenance_logistics_is_active_name($connect, $name)`
 * - หน้าเว็บ + copy tracking: `maintenance_logistics_fetch_tracking_url_map($connect)` → `MN_LOGISTICS_TRACKING`
 *
 * Example:
 * $names = maintenance_logistics_fetch_active_names($connect);
 * maintenance_logistics_echo_js_config($connect);
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_logistics_legacy_fallback_names')) {
    /**
     * Purpose:
     * รายชื่อขนส่งสำรองเมื่อยังไม่มีตาราง `logistics_carrier` (เทียบ legacy `logistisc()`)
     *
     * Return value:
     * - string[]
     */
    function maintenance_logistics_legacy_fallback_names()
    {
        return array(
            'TNT',
            'SCG',
            'Kerry',
            'Post Office',
            'J And T',
            'Best Express',
            'Customer',
            'Emp',
            'Other',
        );
    }
}

if (!function_exists('maintenance_logistics_legacy_fallback_tracking_url_map')) {
    /**
     * Purpose:
     * map ชื่อขนส่ง → URL สำรอง (เมื่อยังไม่มีตาราง)
     *
     * Return value:
     * - array<string,string> name => url
     */
    function maintenance_logistics_legacy_fallback_tracking_url_map()
    {
        return array(
            'TNT' => 'https://www.tnt.com/express/th_th/site/shipping-tools/tracking.html',
            'SCG' => 'https://www.scgexpress.co.th/tracking',
            'Kerry' => 'https://th.kerryexpress.com/th/track/',
            'Post Office' => 'https://track.thailandpost.co.th/',
            'J And T' => 'https://www.jtexpress.co.th/index/query/gzquery.html',
            'Best Express' => 'https://www.best-inc.co.th/track',
        );
    }
}

if (!function_exists('maintenance_logistics_helpers_path')) {
    /**
     * Purpose:
     * path ไป `includes/logistics_helpers.php` จากราก maintenance
     *
     * Return value:
     * - string
     */
    function maintenance_logistics_helpers_path()
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'logistics_helpers.php';
    }
}

if (!function_exists('maintenance_logistics_helpers_available')) {
    /**
     * Purpose:
     * ตรวจว่าไฟล์ helper ขนส่งมีบนเซิร์ฟเวอร์ (ก่อน require — fatal จับด้วย try/catch ไม่ได้)
     *
     * Return value:
     * - bool
     */
    function maintenance_logistics_helpers_available()
    {
        return is_readable(maintenance_logistics_helpers_path());
    }
}

if (!function_exists('maintenance_logistics_safe_fetch_tracking_url_map')) {
    /**
     * Purpose:
     * ดึง map URL ตรวจ tracking จาก DB — ถ้า helper/ตารางไม่พร้อมคืน fallback โดยไม่ fatal
     *
     * Parameters สำคัญ:
     * - mysqli|null $connect: connection (ว่างได้ → fallback)
     *
     * Return value:
     * - array<string,string>
     */
    function maintenance_logistics_safe_fetch_tracking_url_map($connect = null)
    {
        if (!function_exists('maintenance_logistics_fetch_tracking_url_map')) {
            if (!maintenance_logistics_helpers_available()) {
                return array();
            }
            require_once maintenance_logistics_helpers_path();
        }
        if (!function_exists('maintenance_logistics_legacy_fallback_tracking_url_map')) {
            return array();
        }
        if (!($connect instanceof mysqli)) {
            return maintenance_logistics_legacy_fallback_tracking_url_map();
        }
        try {
            return maintenance_logistics_fetch_tracking_url_map($connect);
        } catch (Throwable $e) {
            return maintenance_logistics_legacy_fallback_tracking_url_map();
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_logistics_table_available')) {
    /**
     * Purpose:
     * ตรวจว่ามีตาราง `logistics_carrier` และ query ได้
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - bool
     */
    function maintenance_logistics_table_available($connect)
    {
        if (!($connect instanceof mysqli)) {
            return false;
        }
        $res = @mysqli_query($connect, 'SELECT 1 FROM logistics_carrier LIMIT 1');
        if ($res === false) {
            return false;
        }
        mysqli_free_result($res);

        return true;
    }
}

if (!function_exists('maintenance_logistics_fetch_rows')) {
    /**
     * Purpose:
     * ดึงแถวขนส่งทั้งหมดที่ active เรียงตาม `lc_sort_order`, `lc_name`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - bool $active_only: true = เฉพาะ lc_active = 1
     *
     * Return value:
     * - array<int,array{lc_id:int,lc_name:string,lc_tracking_url:string,lc_sort_order:int,lc_active:int}>
     */
    function maintenance_logistics_fetch_rows($connect, $active_only = true)
    {
        if (!maintenance_logistics_table_available($connect)) {
            return array();
        }
        $sql = 'SELECT lc_id, lc_name, lc_tracking_url, lc_sort_order, lc_active FROM logistics_carrier';
        if ($active_only) {
            $sql .= ' WHERE lc_active = 1';
        }
        $sql .= ' ORDER BY lc_sort_order ASC, lc_name ASC';
        $exe = maintenance_db_odbc_exec($connect, $sql);
        if ($exe === false) {
            return array();
        }
        $rows = array();
        while ($row = maintenance_db_odbc_fetch_array($exe)) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('maintenance_logistics_fetch_active_names')) {
    /**
     * Purpose:
     * รายชื่อขนส่งสำหรับ dropdown (UTF-8)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - string[]
     */
    function maintenance_logistics_fetch_active_names($connect)
    {
        $rows = maintenance_logistics_fetch_rows($connect, true);
        if ($rows === array()) {
            return maintenance_logistics_legacy_fallback_names();
        }
        $out = array();
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        foreach ($rows as $row) {
            $name = trim(maintenance_flow_db_text_to_utf8(isset($row['lc_name']) ? $row['lc_name'] : ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }
        if ($out === array()) {
            return maintenance_logistics_legacy_fallback_names();
        }

        return $out;
    }
}

if (!function_exists('maintenance_logistics_fetch_tracking_url_map')) {
    /**
     * Purpose:
     * map ชื่อขนส่ง → URL ตรวจ tracking สำหรับ `MN_LOGISTICS_TRACKING` ใน JS
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array<string,string> เฉพาะรายการที่มี URL
     */
    function maintenance_logistics_fetch_tracking_url_map($connect)
    {
        $rows = maintenance_logistics_fetch_rows($connect, true);
        if ($rows === array()) {
            return maintenance_logistics_legacy_fallback_tracking_url_map();
        }
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        $map = array();
        foreach ($rows as $row) {
            $name = trim(maintenance_flow_db_text_to_utf8(isset($row['lc_name']) ? $row['lc_name'] : ''));
            $url = trim(maintenance_flow_db_text_to_utf8(isset($row['lc_tracking_url']) ? $row['lc_tracking_url'] : ''));
            if ($name !== '' && $url !== '') {
                $map[$name] = $url;
            }
        }
        if ($map === array()) {
            return maintenance_logistics_legacy_fallback_tracking_url_map();
        }

        return $map;
    }
}

if (!function_exists('maintenance_logistics_is_active_name')) {
    /**
     * Purpose:
     * ตรวจว่าชื่อขนส่งอยู่ในรายการ active
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $name: ชื่อขนส่ง (UTF-8)
     *
     * Return value:
     * - bool
     */
    function maintenance_logistics_is_active_name($connect, $name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }

        return in_array($name, maintenance_logistics_fetch_active_names($connect), true);
    }
}

if (!function_exists('maintenance_logistics_echo_js_config')) {
    /**
     * Purpose:
     * ส่งออก `window.MN_LOGISTICS_TRACKING` ให้ `mn-logistics-tracking.js` เปิด URL จาก DB
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - void
     */
    function maintenance_logistics_echo_js_config($connect = null, $mapOverride = null)
    {
        if ($mapOverride !== null && is_array($mapOverride)) {
            $map = $mapOverride;
        } elseif ($connect instanceof mysqli) {
            $map = maintenance_logistics_fetch_tracking_url_map($connect);
        } else {
            $map = maintenance_logistics_legacy_fallback_tracking_url_map();
        }
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
        if (defined('JSON_HEX_AMP')) {
            $flags |= JSON_HEX_AMP;
        }
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }
        echo '<script>window.MN_LOGISTICS_TRACKING=';
        echo json_encode($map, $flags);
        echo ';</script>' . "\n";
    }
}
