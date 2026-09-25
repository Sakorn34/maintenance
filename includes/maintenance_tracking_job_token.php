<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เข้ารหัส/ถอดรหัส token พารามิเตอร์ `job` และสร้าง URL ติดตามงานซ่อม
 * อยู่ที่ราก maintenance เพื่อให้ `index.php` สร้างลิงก์ได้แม้โฟลเดอร์ maintenancetracking ถูก cut deploy แยก
 *
 * Example:
 * maintenance_tracking_status_url(123) → `.../maintenancetracking/status?job=<token>`
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

if (!defined('MAINTENANCE_TRACKING_TOKEN_VERSION')) {
    define('MAINTENANCE_TRACKING_TOKEN_VERSION', 1);
}

// ─────────────────────────────────────────────────────────────────
// helpers — config / secret
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_tracking_token_secret_resolve')) {
    /**
     * Purpose:
     * อ่าน token_secret จาก env / env.local / config.php ของ maintenancetracking (ไม่ throw)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ค่าว่างถ้าไม่พบ
     */
    function maintenance_tracking_token_secret_resolve()
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $fromEnv = getenv('MAINTENANCE_TRACKING_TOKEN_SECRET');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $resolved = $fromEnv;

            return $resolved;
        }

        if (function_exists('maintenance_new_env_local_config')) {
            $local = maintenance_new_env_local_config();
            if (!empty($local['tracking_token_secret']) && is_string($local['tracking_token_secret'])) {
                $resolved = $local['tracking_token_secret'];

                return $resolved;
            }
        }

        $base = dirname(__DIR__);
        $configPaths = array(
            $base . DIRECTORY_SEPARATOR . 'maintenancetracking' . DIRECTORY_SEPARATOR . 'config.php',
            $base . DIRECTORY_SEPARATOR . 'maintenancetracking' . DIRECTORY_SEPARATOR . 'config.example.php',
        );
        foreach ($configPaths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $loaded = require $path;
            if (is_array($loaded) && !empty($loaded['token_secret']) && is_string($loaded['token_secret'])) {
                $resolved = $loaded['token_secret'];

                return $resolved;
            }
        }

        if (function_exists('maintenance_tracking_config')) {
            try {
                $cfg = maintenance_tracking_config();
                if (!empty($cfg['token_secret']) && is_string($cfg['token_secret'])) {
                    $resolved = $cfg['token_secret'];

                    return $resolved;
                }
            } catch (Exception $e) {
                // ข้าม — ใช้แหล่งอื่นหรือ dev fallback
            }
        }

        $resolved = '';

        return $resolved;
    }
}

if (!function_exists('maintenance_tracking_public_base_url_resolve')) {
    /**
     * Purpose:
     * อ่าน public_base_url สำหรับลิงก์ลูกค้า (ไม่ throw)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string
     */
    function maintenance_tracking_public_base_url_resolve()
    {
        if (function_exists('maintenance_new_tracking_public_base_url')) {
            $fromMaint = maintenance_new_tracking_public_base_url();
            if (is_string($fromMaint) && $fromMaint !== '') {
                return rtrim($fromMaint, '/');
            }
        }

        $base = dirname(__DIR__);
        $configPath = $base . DIRECTORY_SEPARATOR . 'maintenancetracking' . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded) && !empty($loaded['public_base_url'])) {
                return rtrim((string) $loaded['public_base_url'], '/');
            }
        }

        if (function_exists('maintenance_tracking_config')) {
            try {
                $cfg = maintenance_tracking_config();
                if (!empty($cfg['public_base_url'])) {
                    return rtrim((string) $cfg['public_base_url'], '/');
                }
            } catch (Exception $e) {
                // ข้าม
            }
        }

        return '/maintenancetracking';
    }
}

if (!function_exists('maintenance_tracking_token_key')) {
    /**
     * Purpose:
     * สร้างคีย์ 32 ไบต์จาก token_secret
     *
     * Return value:
     * - string raw 32 bytes
     */
    function maintenance_tracking_token_key()
    {
        $secret = maintenance_tracking_token_secret_resolve();
        if ($secret === '' || $secret === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_KEY') {
            return hash('sha256', 'maintenancetracking-dev-insecure', true);
        }

        return hash('sha256', $secret, true);
    }
}

if (!function_exists('maintenance_tracking_base64url_encode')) {
    /**
     * Purpose:
     * Base64 URL-safe (ไม่มี padding)
     *
     * Parameters สำคัญ:
     * - string $raw: ข้อมูลไบนารี
     *
     * Return value:
     * - string
     */
    function maintenance_tracking_base64url_encode($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

if (!function_exists('maintenance_tracking_base64url_decode')) {
    /**
     * Purpose:
     * ถอด base64 URL-safe
     *
     * Parameters สำคัญ:
     * - string $text: สตริงที่ encode แล้ว
     *
     * Return value:
     * - string|false
     */
    function maintenance_tracking_base64url_decode($text)
    {
        $s = strtr((string) $text, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($s, true);
    }
}

if (!function_exists('maintenance_tracking_job_encode')) {
    /**
     * Purpose:
     * เข้ารหัส `trp_id` เป็นค่า query `job` สำหรับ URL ภายนอก
     *
     * Parameters สำคัญ:
     * - int $trp_id: รหัส transac_repair.trp_id
     *
     * Return value:
     * - string token หรือค่าว่างถ้า trp_id ไม่ถูกต้อง
     */
    function maintenance_tracking_job_encode($trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            return '';
        }

        $payload = json_encode(array(
            'v' => MAINTENANCE_TRACKING_TOKEN_VERSION,
            'trp_id' => $id,
        ));
        if ($payload === false) {
            return '';
        }

        $key = maintenance_tracking_token_key();
        if (function_exists('random_bytes')) {
            $iv = random_bytes(16);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $iv = openssl_random_pseudo_bytes(16);
        } else {
            return '';
        }
        if ($iv === false || strlen($iv) !== 16) {
            return '';
        }

        $cipher = openssl_encrypt($payload, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }

        $mac = hash_hmac('sha256', $iv . $cipher, $key, true);

        return maintenance_tracking_base64url_encode($iv . $mac . $cipher);
    }
}

if (!function_exists('maintenance_tracking_job_decode')) {
    /**
     * Purpose:
     * ถอดรหัสค่า `job` จาก URL เป็น trp_id
     *
     * Parameters สำคัญ:
     * - string $token: ค่าจาก query string
     *
     * Return value:
     * - int trp_id หรือ 0 ถ้า token ไม่ถูกต้อง
     */
    function maintenance_tracking_job_decode($token)
    {
        $token = trim((string) $token);
        if ($token === '' || !function_exists('openssl_decrypt')) {
            return 0;
        }

        $raw = maintenance_tracking_base64url_decode($token);
        if ($raw === false || strlen($raw) < 16 + 32 + 1) {
            return 0;
        }

        $key = maintenance_tracking_token_key();
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);

        $macCheck = hash_hmac('sha256', $iv . $cipher, $key, true);
        if (!function_exists('hash_equals') || !hash_equals($mac, $macCheck)) {
            return 0;
        }

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false || $plain === '') {
            return 0;
        }

        $data = json_decode($plain, true);
        if (!is_array($data) || !isset($data['trp_id'])) {
            return 0;
        }
        if (isset($data['v']) && (int) $data['v'] !== MAINTENANCE_TRACKING_TOKEN_VERSION) {
            return 0;
        }

        $id = (int) $data['trp_id'];

        return $id > 0 ? $id : 0;
    }
}

if (!function_exists('maintenance_tracking_public_base_url')) {
    /**
     * Purpose:
     * URL ฐานของแอป maintenancetracking (ไม่มี / ท้าย)
     *
     * Return value:
     * - string
     */
    function maintenance_tracking_public_base_url()
    {
        return maintenance_tracking_public_base_url_resolve();
    }
}

if (!function_exists('maintenance_tracking_status_url')) {
    /**
     * Purpose:
     * สร้าง URL หน้าติดตามสถานะ `.../status?job=<token>`
     *
     * Parameters สำคัญ:
     * - int $trp_id: รหัสงานซ่อม
     *
     * Return value:
     * - string URL หรือค่าว่างถ้า encode ไม่ได้
     */
    function maintenance_tracking_status_url($trp_id)
    {
        $token = maintenance_tracking_job_encode($trp_id);
        if ($token === '') {
            return '';
        }

        $base = maintenance_tracking_public_base_url_resolve();
        if ($base === '') {
            $base = '/maintenancetracking';
        }

        return rtrim($base, '/') . '/status?job=' . rawurlencode($token);
    }
}
