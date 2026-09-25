<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper สำหรับหน้า `status_rate.php` — รายการงานสถานะ «รอประเมิน» พร้อม filter บริษัท / สินค้า / ประเภทประกัน
 *
 * Flow:
 * parse `$_GET` → ประกอบ SQL JOIN `transac` + `transac_repair` (escape ผ่าน mysqli) → ดึงแถวด้วย `maintenance_wait_hardware_fetch_join_rows`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_status_rate_status_literal')) {
    /**
     * Purpose:
     * คืนข้อความ `trp_status_job` ที่หน้านี้แสดง (ต้องตรงกับคอลัมน์ในฐานข้อมูล)
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string เช่น `รอประเมิน`
     */
    function maintenance_status_rate_status_literal()
    {
        return 'รอประเมิน';
    }
}

if (!function_exists('maintenance_status_rate_map_insurance_param_to_db')) {
    /**
     * Purpose:
     * แปลงค่า query `trp_insurance` แบบสั้น (insurance / exp_insurance / ma_insurance) เป็นข้อความใน DB
     *
     * Parameters สำคัญ:
     * - string $param: ค่าจาก `$_GET['trp_insurance']`
     *
     * Return value:
     * - string ข้อความประกันสำหรับ `WHERE` หรือสตริงว่างถ้าไม่ map
     */
    function maintenance_status_rate_map_insurance_param_to_db($param)
    {
        $p = trim((string) $param);
        if ($p === 'insurance') {
            return 'อยู่ในประกัน';
        }
        if ($p === 'exp_insurance') {
            return 'หมดประกัน';
        }
        if ($p === 'ma_insurance') {
            return 'อยู่ในประกันซ่อม';
        }

        return '';
    }
}

if (!function_exists('maintenance_status_rate_parse_get_filters')) {
    /**
     * Purpose:
     * อ่าน filter จาก query string (บริษัท / สินค้า / ประเภทประกันแบบสั้น)
     *
     * Parameters สำคัญ:
     * - array $get: มักส่ง `$_GET`
     *
     * Return value:
     * - array คีย์ `company`, `product`, `insurance_param` (string ค่าเดิมจาก GET), `insurance_db` (string สำหรับ SQL เมื่อ filter ประกัน)
     */
    function maintenance_status_rate_parse_get_filters(array $get)
    {
        $company = isset($get['company']) ? trim((string) $get['company']) : '';
        $product = isset($get['product']) ? trim((string) $get['product']) : '';
        $insuranceParam = isset($get['trp_insurance']) ? trim((string) $get['trp_insurance']) : '';
        if ($insuranceParam === 'All') {
            $insuranceParam = '';
        }
        $insuranceDb = maintenance_status_rate_map_insurance_param_to_db($insuranceParam);

        return array(
            'company' => $company,
            'product' => $product,
            'insurance_param' => $insuranceParam,
            'insurance_db' => $insuranceDb,
        );
    }
}

if (!function_exists('maintenance_status_rate_build_list_sql')) {
    /**
     * Purpose:
     * ประกอบ SELECT คอลัมน์ที่จำเป็น (ไม่ใช้ `SELECT *`) สำหรับรายการรอประเมิน ตาม filter เดิมของระบบเก่า
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $company: filter บริษัท (ว่าง = ไม่ใช้)
     * - string $product: filter สินค้า
     * - string $insuranceParam: ค่า GET ก่อน map (ว่าง = ไม่กรองประกันในคู่ product+insurance)
     * - string $insuranceDb: ข้อความประกันใน DB เมื่อกรอง
     *
     * Return value:
     * - string คำสั่ง SQL
     */
    function maintenance_status_rate_build_list_sql($connect, $company, $product, $insuranceParam, $insuranceDb)
    {
        $status = maintenance_status_rate_status_literal();
        $st = mysqli_real_escape_string($connect, $status);

        $cols = 'transac.ts_id, transac.ts_mtn_number, transac.ts_date, transac.ts_enduser_com, transac.ts_security_com, '
            . 'transac.ts_report_name, transac.ts_report_tel, transac.ts_user_recive, '
            . 'transac_repair.trp_id, transac_repair.trp_ts_id, transac_repair.trp_receive_date, transac_repair.trp_product, '
            . 'transac_repair.trp_sn, transac_repair.trp_insurance, transac_repair.trp_repair_inform, transac_repair.trp_backup, '
            . 'transac_repair.trp_status_job';

        $sql = 'SELECT ' . $cols . ' FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id '
            . "WHERE transac_repair.trp_status_job = '" . $st . "' ";

        if ($company !== '') {
            $c = mysqli_real_escape_string($connect, $company);
            $sql .= "AND (transac.ts_enduser_com = '" . $c . "' OR transac.ts_security_com = '" . $c . "') ";
        } elseif ($product !== '' && $insuranceParam !== '' && $insuranceDb !== '') {
            $p = mysqli_real_escape_string($connect, $product);
            $ins = mysqli_real_escape_string($connect, $insuranceDb);
            $sql .= "AND transac_repair.trp_product = '" . $p . "' AND transac_repair.trp_insurance = '" . $ins . "' ";
        } elseif ($product !== '') {
            $p = mysqli_real_escape_string($connect, $product);
            $sql .= "AND transac_repair.trp_product = '" . $p . "' ";
        } elseif ($insuranceParam !== '' && $insuranceDb !== '') {
            $ins = mysqli_real_escape_string($connect, $insuranceDb);
            $sql .= "AND transac_repair.trp_insurance = '" . $ins . "' ";
        }

        return $sql;
    }
}

if (!function_exists('maintenance_status_rate_insurance_cell_class')) {
    /**
     * Purpose:
     * เลือก class สีประกัน (เขียวเมื่ออยู่ในประกัน / ประกันซ่อม — แดงในกรณีอื่น) ให้สอดคล้อง logic เดิม
     *
     * Parameters สำคัญ:
     * - string $trp_insurance: ค่าจาก `transac_repair.trp_insurance`
     *
     * Return value:
     * - string class CSS (`mn-status-rate-ins--in` หรือ `mn-status-rate-ins--out`)
     */
    function maintenance_status_rate_insurance_cell_class($trp_insurance)
    {
        $v = trim((string) $trp_insurance);
        if ($v === 'อยู่ในประกัน' || $v === 'อยู่ในประกันซ่อม') {
            return 'mn-status-rate-ins--in';
        }

        return 'mn-status-rate-ins--out';
    }
}
