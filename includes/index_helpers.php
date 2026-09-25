<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า `index.php` — แปลงพารามิเตอร์ค้นหา (GET) เป็นเงื่อนไข SQL แบบปลอดภัย, สรุปข้อความตัวกรอง, และ HTML สถานะ/ระยะเวลาแถวผลลัพธ์
 * Refactor logic การค้นหาจาก `old_system/index.php` ให้รวมเงื่อนไขเป็น AND เดียว (ไม่ duplicate branch) และใช้ `LIMIT` แทน `TOP` สำหรับ MySQL
 *
 * Flow:
 * `maintenance_index_parse_search_params()` → `maintenance_index_build_list_sql()` → loop แสดงผลพร้อม `maintenance_index_row_status_html()`
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

if (!defined('MAINTENANCE_INDEX_LIMIT_DEFAULT')) {
    /** จำนวนแถวสูงสุดเมื่อไม่มีตัวกรอง (เทียบ Top 50 เดิม) */
    define('MAINTENANCE_INDEX_LIMIT_DEFAULT', 50);
}
if (!defined('MAINTENANCE_INDEX_LIMIT_SEARCH')) {
    /** จำนวนแถวสูงสุดเมื่อมีตัวกรอง (กันผลลัพธ์ใหญ่เกิน) */
    define('MAINTENANCE_INDEX_LIMIT_SEARCH', 200);
}

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_index_parse_search_params')) {
    /**
     * Purpose:
     * อ่านและ normalize พารามิเตอร์ค้นหาจาก `$_GET` (รองรับชื่อเดิมจากฟอร์ม legacy)
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET`
     *
     * Return value:
     * - array คีย์ s_number, s_company, s_product, s_status, s_report, s_product_sn, s_spare_sn
     */
    function maintenance_index_parse_search_params(array $get)
    {
        return array(
            's_number' => isset($get['s_number']) ? trim((string) $get['s_number']) : '',
            's_company' => isset($get['s_company']) ? trim((string) $get['s_company']) : '',
            's_product' => isset($get['s_product']) ? trim((string) $get['s_product']) : '',
            's_status' => isset($get['s_status']) ? trim((string) $get['s_status']) : '',
            's_report' => isset($get['s_report']) ? trim((string) $get['s_report']) : '',
            's_product_sn' => isset($get['s_product_sn']) ? trim((string) $get['s_product_sn']) : '',
            's_spare_sn' => isset($get['s_spare_sn']) ? trim((string) $get['s_spare_sn']) : '',
        );
    }
}

if (!function_exists('maintenance_index_like')) {
    /**
     * Purpose:
     * สร้างเงื่อนไข `LIKE` หลัง escape สำหรับ mysqli
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $column: ชื่อคอลัมน์เต็ม (เช่น transac.ts_mtn_number)
     * - string $raw: คำค้น
     *
     * Return value:
     * - string เงื่อนไข SQL หรือสตริงว่างถ้า `$raw` ว่าง
     */
    function maintenance_index_like($connect, $column, $raw)
    {
        if ($raw === '') {
            return '';
        }
        $e = mysqli_real_escape_string($connect, $raw);
        return $column . " LIKE '%" . $e . "%'";
    }
}

if (!function_exists('maintenance_index_build_list_sql')) {
    /**
     * Purpose:
     * ประกอบ SQL รายการ transac + transac_repair ตามตัวกรอง (AND รวมกัน) และกำหนด LIMIT
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $p: ผลจาก `maintenance_index_parse_search_params()`
     *
     * Return value:
     * - array คีย์ `sql` (string), `use_default_limit` (bool), `summary_lines` (array of string ภาษาไทยสำหรับแสดงใต้ตาราง)
     */
    function maintenance_index_build_list_sql($connect, array $p)
    {
        $conds = array();
        $summary = array();

        if ($p['s_number'] !== '') {
            $c = maintenance_index_like($connect, 'transac.ts_mtn_number', $p['s_number']);
            if ($c !== '') {
                $conds[] = $c;
                $summary[] = 'เลขที่ซ่อมมี "' . $p['s_number'] . '"';
            }
        }
        if ($p['s_company'] !== '') {
            $e = mysqli_real_escape_string($connect, $p['s_company']);
            $conds[] = "(transac.ts_enduser_com LIKE '%" . $e . "%' OR transac.ts_security_com LIKE '%" . $e . "%')";
            $summary[] = 'บริษัทมี "' . $p['s_company'] . '"';
        }
        if ($p['s_report'] !== '') {
            $c = maintenance_index_like($connect, 'transac.ts_report_name', $p['s_report']);
            if ($c !== '') {
                $conds[] = $c;
                $summary[] = 'ผู้แจ้งมี "' . $p['s_report'] . '"';
            }
        }
        if ($p['s_status'] !== '') {
            $e = mysqli_real_escape_string($connect, $p['s_status']);
            $conds[] = "transac_repair.trp_status_job = '" . $e . "'";
            $summary[] = 'สถานะ "' . $p['s_status'] . '"';
        }

        if ($p['s_product'] !== '') {
            $c = maintenance_index_like($connect, 'transac_repair.trp_product', $p['s_product']);
            if ($c !== '') {
                $conds[] = $c;
                $summary[] = 'รุ่น (เครื่องซ่อม) "' . $p['s_product'] . '"';
            }
        }
        if ($p['s_product_sn'] !== '') {
            $c = maintenance_index_like($connect, 'transac_repair.trp_sn', $p['s_product_sn']);
            if ($c !== '') {
                $conds[] = $c;
                $summary[] = 'S/N ซ่อมมี "' . $p['s_product_sn'] . '"';
            }
        }
        if ($p['s_spare_sn'] !== '') {
            $e = mysqli_real_escape_string($connect, $p['s_spare_sn']);
            $conds[] = 'EXISTS (SELECT 1 FROM transac_spare sp_idx WHERE sp_idx.tsp_trp_id = transac_repair.trp_id AND sp_idx.tsp_sn LIKE \'%' . $e . '%\')';
            $summary[] = 'S/N สำรองมี "' . $p['s_spare_sn'] . '"';
        }

        $where = '';
        if (count($conds) > 0) {
            $where = ' WHERE ' . implode(' AND ', $conds);
        }

        $use_default = ($where === '');
        $limit = $use_default ? (int) MAINTENANCE_INDEX_LIMIT_DEFAULT : (int) MAINTENANCE_INDEX_LIMIT_SEARCH;

        $sql = 'SELECT * FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id'
            . $where
            . ' ORDER BY transac_repair.trp_id DESC LIMIT ' . $limit;

        if ($use_default) {
            $summary[] = 'แสดงล่าสุด ' . (string) $limit . ' แถว (ไม่มีตัวกรอง)';
        } else {
            $summary[] = 'จำกัดไม่เกิน ' . (string) $limit . ' แถว';
        }

        return array(
            'sql' => $sql,
            'use_default_limit' => $use_default,
            'summary_lines' => $summary,
        );
    }
}

if (!function_exists('maintenance_index_row_status_html')) {
    /**
     * Purpose:
     * สร้าง HTML สีสถานะงานซ่อมและข้อความระยะเวลา (อ้าง logic สีจาก `old_system/index.php`)
     *
     * Parameters สำคัญ:
     * - array $row: แถว join transac + transac_repair
     *
     * Return value:
     * - array คีย์ `status` (string HTML), `wait` (string HTML)
     */
    function maintenance_index_row_status_html(array $row)
    {
        $job = isset($row['trp_status_job']) ? (string) $row['trp_status_job'] : '';
        $status = '';
        $wait = '';

        if ($job === 'รอส่งสำรอง') {
            $status = "<span style='color:#FF9900'>รอส่งสำรอง</span><br />";
            $wait = cal_wait_date(isset($row['ts_date']) ? $row['ts_date'] : '');
        } elseif ($job === 'รอเครื่องซ่อม') {
            $status = "<span style='color:#FF9900'>รอเครื่องซ่อม</span><br />";
            $wait = cal_wait_date(isset($row['ts_date']) ? $row['ts_date'] : '');
        } elseif ($job === 'รอประเมิน') {
            $status = "<span style='color:#000000'>รอประเมิน</span><br />";
            $wait = cal_wait_date(isset($row['trp_receive_date']) ? $row['trp_receive_date'] : '');
        } elseif ($job === 'รอเสนอราคา') {
            $status = "<span style='color:#339933'>รอเสนอราคา</span><br />";
            $wait = cal_wait_date(isset($row['trp_rate_date']) ? $row['trp_rate_date'] : '');
        } elseif ($job === 'รออนุมัติซ่อม') {
            $status = "<span style='color:#339933'>รออนุมัติซ่อม</span><br />";
            $wait = cal_wait_date(isset($row['trp_qv_date']) ? $row['trp_qv_date'] : '');
        } elseif ($job === 'รอซ่อม') {
            $status = "<span style='color:#000000'>รอซ่อม</span><br />";
            $wait = cal_wait_date(isset($row['trp_firm_qv_date']) ? $row['trp_firm_qv_date'] : '');
        } elseif ($job === 'รอ QC') {
            $status = "<span style='color:#FF9900'>รอ QC</span><br />";
            $wait = cal_wait_date(isset($row['trp_success_date']) ? $row['trp_success_date'] : '');
        } elseif ($job === 'รอส่งคืนเครื่องซ่อม') {
            $status = "<span style='color:#C00000'>รอส่งคืนเครื่องซ่อม</span><br />";
            $wait = cal_wait_date(isset($row['trp_qc_date']) ? $row['trp_qc_date'] : '');
        } elseif ($job === 'เสร็จสิ้น') {
            $status = "<span style='color:#000000'>เสร็จสิ้น</span><br />";
            $w = cal_wait_date(isset($row['trp_send_date']) ? $row['trp_send_date'] : '');
            $wait = "<span style='color:#000000'>" . $w . '</span>';
        } elseif ($job === 'รอเครื่องสำรอง') {
            $status = "<span style='color:#FF0000'> รอสำรอง</span><br />";
            $wait = cal_wait_date(isset($row['trp_send_date']) ? $row['trp_send_date'] : '');
        } else {
            $status = "<span style='color:#FF0000'>" . htmlspecialchars($job, ENT_QUOTES, 'UTF-8') . "</span><br />";
            $wait = '';
        }

        return array('status' => $status, 'wait' => $wait);
    }
}

if (!function_exists('maintenance_index_detail_job_link_html')) {
    /**
     * Purpose:
     * ลิงก์ «Info» ไป `detail_job` แบบเดียวกับหน้า `status_wait_ma` — ใช้ในตารางและใต้ช่องค้นหาบริษัท
     *
     * Parameters สำคัญ:
     * - int $ts_id: `transac.ts_id`
     * - string $extra_class: class เพิ่มบน `<a>` (เช่น `mn-index-detail-link--filter`)
     *
     * Return value:
     * - string HTML หรือค่าว่างเมื่อ `$ts_id` ไม่ถูกต้อง
     */
    function maintenance_index_detail_job_link_html($ts_id, $extra_class = '')
    {
        $id = (int) $ts_id;
        if ($id < 1) {
            return '';
        }
        $class = 'mn-index-detail-link';
        $extra = trim((string) $extra_class);
        if ($extra !== '') {
            $class .= ' ' . $extra;
        }
        $href = 'detail_job?ts_id=' . $id;

        return '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" href="'
            . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" title="เปิดรายละเอียดงาน">'
            . '<span class="mn-index-detail-link__inner"><i class="fa fa-info-circle" aria-hidden="true"></i> Info</span></a>';
    }
}

if (!function_exists('maintenance_index_company_cell_html')) {
    /**
     * Purpose:
     * ประกอบ HTML คอลัมน์บริษัท (End user / รปภ.) + ลิงก์รายละเอียดใต้ชื่อ — ใช้ `strReplace()` legacy ถ้ามี
     *
     * Parameters สำคัญ:
     * - array $row: แถว join
     * - int $ts_id: ts_id สำหรับลิงก์
     *
     * Return value:
     * - string HTML
     */
    function maintenance_index_company_cell_html(array $row, $ts_id)
    {
        require_once __DIR__ . '/status_logistic_helpers.php';
        $eu = maintenance_status_logistic_normalize_company_label(
            isset($row['ts_enduser_com']) ? $row['ts_enduser_com'] : '',
            array('str_replace' => true, 'strip_prefix' => false)
        );
        $sec = maintenance_status_logistic_normalize_company_label(
            isset($row['ts_security_com']) ? $row['ts_security_com'] : '',
            array('str_replace' => true, 'strip_prefix' => false)
        );
        $detail = maintenance_status_logistic_company_names_html($eu, $sec);

        $names = '';
        if ($detail !== '') {
            $href = 'detail_job?ts_id=' . (int) $ts_id;
            $names = '<a class="mn-index-company-names-link" target="_blank" rel="noopener" href="'
                . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $detail . '</a>';
        } else {
            $names = '<span class="mn-index-empty-cell">—</span>';
        }

        return '<div class="mn-index-company-cell">' . $names . maintenance_index_detail_job_link_html($ts_id) . '</div>';
    }
}
