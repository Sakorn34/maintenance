<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึก activity ลงตาราง `activity_log` — ฟังก์ชันกลางสำหรับหน้า/API ใน maintenance (prepared INSERT)
 * อ่านผู้กระทำจาก session (`maintenance_new_profile_login_name`) ถ้ามี; ถ้า INSERT ล้มเหลวจะเขียน `error_log` แล้วคืน false ไม่ throw
 *
 * ข้อกำหนดฐานข้อมูล:
 * - รัน `database/activity_log.sql` บนฐานข้อมูลก่อนใช้งาน
 *
 * Flow:
 * หลัง `require_auth` + bootstrap ได้ `$connect` → `maintenance_activity_log_write($connect, 'action_key', array('payload'=>..., 'entity_type'=>...))`
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/session_profile.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_activity_log_ensure_session')) {
    /**
     * Purpose:
     * เปิด session ถ้ายังไม่เปิด เพื่ออ่าน `actor_login` (กรณีเรียก log หลัง auth แล้วมักไม่ต้องทำอะไร)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - void
     */
    function maintenance_activity_log_ensure_session()
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }
}

if (!function_exists('maintenance_activity_log_client_ip')) {
    /**
     * Purpose:
     * คืน IP ลูกข่ายแบบง่าย (REMOTE_ADDR หรือค่าว่าง)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string
     */
    function maintenance_activity_log_client_ip()
    {
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return substr((string) $_SERVER['REMOTE_ADDR'], 0, 45);
        }

        return '';
    }
}

if (!function_exists('maintenance_activity_log_request_uri')) {
    /**
     * Purpose:
     * คืน URI คำขอปัจจุบัน (ตัดความยาวให้พอดีคอลัมน์)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string
     */
    function maintenance_activity_log_request_uri()
    {
        $u = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (strlen($u) > 1024) {
            return substr($u, 0, 1021) . '...';
        }

        return $u;
    }
}

if (!function_exists('maintenance_activity_log_user_agent')) {
    /**
     * Purpose:
     * คืน User-Agent ที่ตัดความยาว
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string
     */
    function maintenance_activity_log_user_agent()
    {
        $a = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        return strlen($a) > 512 ? substr($a, 0, 509) . '...' : $a;
    }
}

if (!function_exists('maintenance_activity_log_write')) {
    /**
     * Purpose:
     * INSERT แถวหนึ่งใน `activity_log` สำหรับ audit / analytics การใช้งาน
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection ที่ยังไม่ปิด
     * - string $action: รหัสเหตุการณ์ (ไม่ว่าง, สั้นกว่า 160 ตัวอักษร)
     * - array $context: คีย์เช่น `entity_type` (string), `entity_id` (string|int), `payload` (array|string จะเก็บเป็น JSON/text)
     *
     * Return value:
     * - bool สำเร็จ true, ล้มเหลว false
     */
    function maintenance_activity_log_write($connect, $action, array $context = array())
    {
        if (!($connect instanceof mysqli)) {
            return false;
        }
        $action = trim((string) $action);
        if ($action === '' || strlen($action) > 160) {
            return false;
        }

        maintenance_activity_log_ensure_session();
        $actor = maintenance_new_profile_login_name();
        $actor_s = $actor !== null ? substr((string) $actor, 0, 128) : '';

        $entity_type = '';
        if (isset($context['entity_type'])) {
            $entity_type = substr(trim((string) $context['entity_type']), 0, 80);
        }
        $entity_id = '';
        if (isset($context['entity_id'])) {
            $entity_id = substr(trim((string) $context['entity_id']), 0, 120);
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(substr((string) $_SERVER['REQUEST_METHOD'], 0, 10)) : '';
        $uri = maintenance_activity_log_request_uri();
        $ip = maintenance_activity_log_client_ip();
        $ua = maintenance_activity_log_user_agent();

        $payload_str = '';
        if (array_key_exists('payload', $context)) {
            $p = $context['payload'];
            if (is_array($p)) {
                $payload_str = (string) json_encode($p, JSON_UNESCAPED_UNICODE);
            } else {
                $payload_str = (string) $p;
            }
            if (strlen($payload_str) > 16777210) {
                $payload_str = substr($payload_str, 0, 16777100) . '...[truncated]';
            }
        }

        $sql = 'INSERT INTO activity_log (actor_login, action, entity_type, entity_id, request_method, request_uri, ip_address, user_agent, payload) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            error_log('activity_log: prepare failed — ' . mysqli_error($connect));

            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssssssss',
            $actor_s,
            $action,
            $entity_type,
            $entity_id,
            $method,
            $uri,
            $ip,
            $ua,
            $payload_str
        );
        $ok = mysqli_stmt_execute($stmt);
        if (!$ok) {
            error_log('activity_log: execute failed — ' . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);

        return $ok;
    }
}
