<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึก log การทำงานของ `insert_job.php` (รับ GET จากฟอร์ม add_job) เพื่อไล่ปัญหาเมื่อ insert ล้มเหลว
 * เปิดใช้เฉพาะช่วงแก้ไข — log อยู่ใน `storage/logs/` และอาจมีข้อมูลลูกค้า/พารามิเตอร์ธุรกิจ
 *
 * การเปิดใช้:
 * - `config/env.local.php` คีย์ `add_job_insert_debug` => true
 * - หรือ environment `MAINTENANCE_ADD_JOB_INSERT_DEBUG=1` หรือ `MAINTENANCE_NEW_ADD_JOB_INSERT_DEBUG=1` (legacy)
 *
 * Flow:
 * - `insert_job.php` เรียก `maintenance_new_add_job_insert_debug_log()` จุดสำคัญของงาน
 * - ข้อมูลเขียนเป็น JSON หนึ่งบรรทัดต่อเหตุการณ์ (UTF-8)
 */

require_once __DIR__ . '/../config/app_environment.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_new_add_job_insert_debug_enabled')) {
    /**
     * Purpose:
     * ตรวจว่าควรเขียน debug log หรือไม่
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - bool true เมื่อเปิดโหมด debug
     */
    function maintenance_new_add_job_insert_debug_enabled()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $env = getenv('MAINTENANCE_ADD_JOB_INSERT_DEBUG');
        if (is_string($env) && ($env === '1' || strtolower($env) === 'true')) {
            return $cached = true;
        }
        $env = getenv('MAINTENANCE_NEW_ADD_JOB_INSERT_DEBUG');
        if (is_string($env) && ($env === '1' || strtolower($env) === 'true')) {
            return $cached = true;
        }
        $local = maintenance_new_env_local_config();
        if (!empty($local['add_job_insert_debug'])) {
            return $cached = true;
        }
        return $cached = false;
    }
}

if (!function_exists('maintenance_new_add_job_insert_debug_log_dir')) {
    /**
     * Purpose:
     * คืนโฟลเดอร์เก็บไฟล์ log (สร้างโฟลเดอร์ถ้ายังไม่มีและเขียนได้)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string|null path ที่ลงท้ายด้วย DIRECTORY_SEPARATOR หรือ null ถ้าใช้ไม่ได้
     */
    function maintenance_new_add_job_insert_debug_log_dir()
    {
        static $resolved = false;
        static $dir = null;
        if ($resolved) {
            return $dir;
        }
        $resolved = true;
        $local = maintenance_new_env_local_config();
        if (!empty($local['add_job_insert_debug_log_dir']) && is_string($local['add_job_insert_debug_log_dir'])) {
            $candidate = $local['add_job_insert_debug_log_dir'];
        } else {
            $candidate = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }
        $candidate = realpath($candidate) ?: $candidate;
        if (!is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }
        if (!is_dir($candidate) || !is_writable($candidate)) {
            return $dir = null;
        }
        return $dir = rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('maintenance_new_add_job_insert_debug_safe_get_summary')) {
    /**
     * Purpose:
     * สรุป $_GET สำหรับ log — มีความยาวและตัวอย่างข้อความ (ตัดยาวเพื่อไม่ให้ไฟล์โตเกินไป)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - array โครงสร้างคีย์ => array(len, preview)
     */
    function maintenance_new_add_job_insert_debug_safe_get_summary()
    {
        $out = array();
        if (!isset($_GET) || !is_array($_GET)) {
            return $out;
        }
        foreach ($_GET as $key => $value) {
            if (!is_string($value)) {
                $out[$key] = array('type' => gettype($value));
                continue;
            }
            $len = strlen($value);
            $preview = $len <= 600 ? $value : (substr($value, 0, 600) . '…');
            $out[$key] = array('len' => $len, 'preview' => $preview);
        }
        return $out;
    }
}

if (!function_exists('maintenance_new_add_job_insert_debug_log')) {
    /**
     * Purpose:
     * เขียนบรรทัด log (JSON) เมื่อเปิด debug — ไม่ throw ถ้าเขียนไฟล์ไม่ได้
     *
     * Parameters สำคัญ:
     * - string $stage: ชื่อจุดใน flow เช่น insert_transac
     * - array $context: ข้อมูลประกอบ (จะ encode เป็น UTF-8 JSON)
     *
     * Return value:
     * - void
     */
    function maintenance_new_add_job_insert_debug_log($stage, array $context = array())
    {
        if (!maintenance_new_add_job_insert_debug_enabled()) {
            return;
        }
        $dir = maintenance_new_add_job_insert_debug_log_dir();
        if ($dir === null) {
            return;
        }
        $line = array(
            'time' => date('c'),
            'stage' => $stage,
            'context' => $context,
        );
        $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }
        $file = $dir . 'add_job_insert_' . date('Y-m-d') . '.log';
        @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('maintenance_new_add_job_insert_debug_register_shutdown')) {
    /**
     * Purpose:
     * ลงทะเบียน shutdown handler เพื่อ log fatal error เมื่อเปิด debug (ถ้ามี)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - void
     */
    function maintenance_new_add_job_insert_debug_register_shutdown()
    {
        if (!maintenance_new_add_job_insert_debug_enabled()) {
            return;
        }
        register_shutdown_function(function () {
            $last = error_get_last();
            if (!$last || !is_array($last)) {
                return;
            }
            $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
            if (!in_array((int) $last['type'], $fatalTypes, true)) {
                return;
            }
            maintenance_new_add_job_insert_debug_log('php_fatal', array(
                'type' => isset($last['type']) ? $last['type'] : null,
                'message' => isset($last['message']) ? $last['message'] : '',
                'file' => isset($last['file']) ? $last['file'] : '',
                'line' => isset($last['line']) ? $last['line'] : 0,
            ));
        });
    }
}
