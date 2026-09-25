<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper ประกอบ URL ติดตามลูกค้า (absolute) และข้อความสั้นส่ง LINE/คัดลอกจากหน้า `job_process.php` และหน้า `wait_sendtracking.php` (payload ข้อความ + LINE + mailto)
 *
 * Example:
 * $url = maintenance_job_process_customer_tracking_absolute_url($trp_id);
 * $text = maintenance_job_process_customer_share_plaintext($meta, $url);
 */

// ─────────────────────────────────────────────────────────────────
// imports
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/maintenance_tracking_link.php';

if (!function_exists('maintenance_job_process_customer_tracking_absolute_url')) {
    /**
     * Purpose:
     * แปลง URL จาก `maintenance_tracking_status_url` เป็น absolute จาก host ของ request (ถ้ายังเป็น path)
     *
     * Parameters สำคัญ:
     * - int $trp_id: รหัส transac_repair.trp_id
     *
     * Return value:
     * - string URL เต็มหรือ path ว่างเมื่อไม่สร้างลิงก์ได้
     */
    function maintenance_job_process_customer_tracking_absolute_url($trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) {
            return '';
        }

        $url = maintenance_tracking_status_url_for_repair($id);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $host = '';
        if (!empty($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $host = trim((string) $_SERVER['HTTP_HOST']);
        }

        $path = trim(str_replace('\\', '/', (string) $url));
        if ($host === '') {
            return $path !== '' ? $path : '';
        }

        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME']) && is_string($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'http' ? 'http' : 'https';
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return $scheme . '://' . $host . $path;
    }
}

if (!function_exists('maintenance_job_process_customer_share_plaintext')) {
    /**
     * Purpose:
     * ข้อความพร้อม emoji ใช้ส่งลูกค้า LINE — ผสมชื่อผู้แจ้ง หน่วยงาน รปภ. (ถ้ามี) รุ่น S/N สถานะ และลิงก์เปิดได้ทันที
     *
     * Parameters สำคัญ:
     * - array $meta: คีย์จาก `maintenance_job_process_build_timeline()` เช่น ts_report_name, ts_enduser_com, ts_security_com, trp_product, trp_sn, trp_status_job
     * - string $absoluteTrackingUrl: URL เปิดหน้า status ภายนอก (absolute)
     *
     * Return value:
     * - string ข้อความบรรทัดเดียว/หลายบรรทัด ไม่มี HTML
     */
    function maintenance_job_process_customer_share_plaintext(array $meta, $absoluteTrackingUrl)
    {
        $reportName = trim((string) (isset($meta['ts_report_name']) ? $meta['ts_report_name'] : ''));
        $enduser = trim((string) (isset($meta['ts_enduser_com']) ? $meta['ts_enduser_com'] : ''));
        $security = trim((string) (isset($meta['ts_security_com']) ? $meta['ts_security_com'] : ''));
        $prod = trim((string) (isset($meta['trp_product']) ? $meta['trp_product'] : ''));
        $sn = trim((string) (isset($meta['trp_sn']) ? $meta['trp_sn'] : ''));
        $status = trim((string) (isset($meta['trp_status_job']) ? $meta['trp_status_job'] : ''));
        $link = trim((string) $absoluteTrackingUrl);

        $lines = array();
        $lines[] = '🔧 อัปเดตความคืบหน้างานซ่อม';
        $lines[] = '';
        $lines[] = '👤 ชื่อผู้แจ้ง: ' . ($reportName !== '' ? $reportName : '—');
        $lines[] = '🏢 หน่วยงาน: ' . ($enduser !== '' ? $enduser : '—');
        if ($security !== '') {
            $lines[] = '🛡️ ' . $security;
        }
        $lines[] = '';
        $deviceLine = '📦 เครื่อง: ' . ($prod !== '' ? $prod : '—');
        if ($sn !== '') {
            $deviceLine .= ' [ S/N ' . $sn . ' ]';
        }
        $lines[] = $deviceLine;
        $lines[] = '📌 สถานะปัจจุบัน: ' . ($status !== '' ? $status : '—');
        $lines[] = '';
        if ($link !== '') {
            $lines[] = '🔗 เปิดดูความคืบหน้า (ไม่ต้องล็อกอิน):';
            $lines[] = $link;
        } else {
            $lines[] = '🔗 ลิงก์ติดตาม: (ยังไม่พร้อม — ติดต่อเจ้าหน้าที่)';
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('maintenance_job_process_customer_share_meta_from_bundle')) {
    /**
     * Purpose:
     * สร้าง `$meta` สำหรับข้อความแชร์ลูกค้าจาก bundle โดยไม่เรียก `build_timeline` (ลดความเสี่ยง error และเร็วกว่า)
     *
     * Parameters สำคัญ:
     * - array $bundle: จาก `maintenance_job_process_fetch_bundle`
     *
     * Return value:
     * - array คีย์เดียวกับ `timeline['meta']` ที่ใช้ใน `maintenance_job_process_customer_share_plaintext`
     */
    function maintenance_job_process_customer_share_meta_from_bundle(array $bundle)
    {
        $row = isset($bundle['row']) && is_array($bundle['row']) ? $bundle['row'] : null;
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
        if ($row === null) {
            return $meta;
        }
        foreach (array_keys($meta) as $key) {
            if (isset($row[$key])) {
                $meta[$key] = (string) $row[$key];
            }
        }

        return $meta;
    }
}

if (!function_exists('maintenance_job_process_customer_share_payload_for_trp')) {
    /**
     * Purpose:
     * ประกอบข้อความแชร์ลูกค้าแบบเดียวกับ textarea ในหน้า `job_process.php` (เรียก `maintenance_job_process_fetch_bundle` + meta จาก bundle + `maintenance_job_process_customer_share_plaintext`) พร้อม URL สำหรับเปิด LINE และ mailto จากข้อความเดิม
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: `transac_repair.trp_id`
     *
     * Return value:
     * - array คีย์ `plain`, `line_url`, `mailto`, `report_line`, `report_email`, `org_enduser`, `product_detail` — ค่าว่างเมื่อไม่พบงานหรือไม่สร้างข้อความได้ (บางคีย์อาจมีค่าแม้ `plain` ว่าง)
     */
    function maintenance_job_process_customer_share_payload_for_trp($connect, $trp_id)
    {
        require_once __DIR__ . '/job_process_timeline_helpers.php';
        $empty = array(
            'plain' => '',
            'line_url' => '',
            'mailto' => '',
            'report_line' => '',
            'report_email' => '',
            'org_enduser' => '',
            'product_detail' => '',
        );
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) {
            return $empty;
        }
        try {
            $bundle = maintenance_job_process_fetch_bundle($connect, $id);
            if (!isset($bundle['row']) || !is_array($bundle['row'])) {
                return $empty;
            }
            $br = $bundle['row'];
            $out = $empty;
            $out['report_line'] = trim((string) (isset($br['ts_report_line']) ? $br['ts_report_line'] : ''));
            $out['report_email'] = trim((string) (isset($br['ts_report_email']) ? $br['ts_report_email'] : ''));
            $out['org_enduser'] = trim((string) (isset($br['ts_enduser_com']) ? $br['ts_enduser_com'] : ''));
            $prod = trim((string) (isset($br['trp_product']) ? $br['trp_product'] : ''));
            $sn = trim((string) (isset($br['trp_sn']) ? $br['trp_sn'] : ''));
            $pd = $prod !== '' ? $prod : '—';
            if ($sn !== '') {
                $pd .= ' [ S/N ' . $sn . ' ]';
            }
            $out['product_detail'] = $pd;
            $meta = maintenance_job_process_customer_share_meta_from_bundle($bundle);
            $abs = maintenance_job_process_customer_tracking_absolute_url($id);
            $plain = maintenance_job_process_customer_share_plaintext($meta, $abs);
            $out['plain'] = $plain;
            if ($plain !== '') {
                $out['line_url'] = 'https://line.me/R/msg/text/?' . rawurlencode($plain);
                $out['mailto'] = 'mailto:?subject=' . rawurlencode('อัปเดตความคืบหน้างานซ่อม') . '&body=' . rawurlencode($plain);
            }

            return $out;
        } catch (Throwable $e) {
            return $empty;
        }
    }
}
