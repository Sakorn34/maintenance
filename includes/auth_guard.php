<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เปิด output buffer + session แล้วบังคับให้มี `$_SESSION['profile']` ก่อนเข้าหน้า/ endpoint (เฉพาะ production)
 * โหมด development ไม่ตรวจสิทธิ์ — return ทันทีโดยไม่เปิด session
 * ถ้าไม่มี session (production): หน้า HTML redirect ไป URL จาก `maintenance_new_auth_login_url()` (default หน้า BIT login); คำขอ AJAX ตอบ HTTP 401 JSON
 *
 * องค์ประกอบหลัก:
 * - `maintenance_new_auth_bootstrap()` เรียกครั้งเดียวต่อ request
 * - `maintenance_new_is_ajax_request()` ตรวจจาก X-Requested-With
 * - `maintenance_new_require_logged_in(array $options)` exit ถ้ายังไม่ login
 *
 * Flow:
 * สคริปต์ใดใน maintenance ให้ `require_once __DIR__ . '/includes/require_auth.php';` บรรทัดต้นๆ ก่อน header/output
 */

require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/session_profile.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_is_ajax_request')) {
    /**
     * Purpose:
     * ตรวจว่าเป็น XMLHttpRequest หรือไม่ (ใช้ตัดสินใจตอบ 401 แทน redirect)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - bool
     */
    function maintenance_new_is_ajax_request()
    {
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return false;
        }
        return strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('maintenance_new_auth_bootstrap')) {
    /**
     * Purpose:
     * เริ่ม ob_start (ระดับแรก) และ session_start ตามลำดับเดิมของระบบ login
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - void
     */
    function maintenance_new_auth_bootstrap()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (ob_get_level() === 0) {
            ob_start();
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('maintenance_new_require_logged_in')) {
    /**
     * Purpose:
     * บังคับให้มี profile ใน session ไม่เช่นนั้น destroy session แล้ว redirect หรือส่ง 401
     *
     * Parameters สำคัญ:
     * - array $options: คีย์ `for_ajax` (bool|null) ถ้า null จะ auto จาก maintenance_new_is_ajax_request()
     *
     * Return value:
     * - void (หรือ exit)
     */
    function maintenance_new_require_logged_in(array $options = array())
    {
        if (!maintenance_new_app_env_is_production()) {
            return;
        }

        maintenance_new_auth_bootstrap();
        $forAjax = array_key_exists('for_ajax', $options) ? $options['for_ajax'] : null;
        if ($forAjax === null) {
            $forAjax = maintenance_new_is_ajax_request();
        }

        if (isset($_SESSION['profile']) && $_SESSION['profile'] !== null && $_SESSION['profile'] !== '') {
            return;
        }

        $_SESSION = array();
        session_unset();
        session_destroy();

        if ($forAjax) {
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(array('error' => 'unauthorized', 'message' => 'ต้องเข้าสู่ระบบก่อน'));
            exit;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $url = maintenance_new_auth_login_url();
        if (!headers_sent()) {
            header('Location: ' . $url);
        }
        exit;
    }
}
