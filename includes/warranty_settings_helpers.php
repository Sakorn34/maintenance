<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper ระบบตั้งค่าระยะเวลาประกันรายรุ่น และคำนวณสถานะประกันอัตโนมัติจากวันที่ซื้อ + ประวัติซ่อม
 *
 * องค์ประกอบหลัก:
 * - `maintenance_warranty_fetch_all()` ดึงรายการรุ่นทั้งหมดพร้อม config
 * - `maintenance_warranty_fetch_months_for_product()` ดึง pw_months สำหรับรุ่นหนึ่ง
 * - `maintenance_warranty_upsert()` บันทึก/แก้ไข config
 * - `maintenance_warranty_calc_product_insurance()` คำนวณสถานะจากวันที่ซื้อ
 * - `maintenance_warranty_find_repair_warranty()` ตรวจประกันซ่อม 3 เดือนจากประวัติ
 * - `maintenance_warranty_suggest_insurance()` รวมทั้งสอง → คืนค่าที่แนะนำ
 */

require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

if (!function_exists('maintenance_warranty_ensure_table')) {
    function maintenance_warranty_ensure_table($connect)
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `product_warranty` ('
            . '`pw_id` INT(11) NOT NULL AUTO_INCREMENT,'
            . '`pw_product` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,'
            . '`pw_months` INT(11) NOT NULL DEFAULT 12,'
            . '`pw_updated_at` VARCHAR(20) DEFAULT NULL,'
            . 'PRIMARY KEY (`pw_id`),'
            . 'UNIQUE KEY `uq_pw_product` (`pw_product`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        mysqli_query($connect, $sql);
        // แก้ตารางที่ถูกสร้างด้วย collation ผิด (MySQL 8 default คือ 0900_ai_ci)
        mysqli_query($connect,
            'ALTER TABLE `product_warranty`'
            . ' MODIFY `pw_product` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'
        );
    }
}

if (!function_exists('maintenance_warranty_fetch_all')) {
    /**
     * Purpose:
     * ดึงรุ่นสินค้าทั้งหมดจาก `product_name` LEFT JOIN `product_warranty`
     *
     * Return value:
     * - array ของ associative array คีย์ pn_name, pw_months (null ถ้าไม่ได้ตั้งค่า), pw_updated_at
     */
    function maintenance_warranty_fetch_all($connect)
    {
        maintenance_warranty_ensure_table($connect);
        $sql = 'SELECT pn.pn_name, pw.pw_months, pw.pw_updated_at'
            . ' FROM product_name pn'
            . ' LEFT JOIN product_warranty pw ON pw.pw_product = pn.pn_name'
            . ' ORDER BY pn.pn_name ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = array();
        if ($res !== false) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = $r;
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('maintenance_warranty_fetch_months_for_product')) {
    /**
     * Purpose:
     * ดึง `pw_months` สำหรับรุ่นหนึ่ง คืน null ถ้าไม่มีข้อมูล
     *
     * Parameters สำคัญ:
     * - string $product: ชื่อรุ่น (ตรงกับ product_name.pn_name)
     *
     * Return value:
     * - int|null
     */
    function maintenance_warranty_fetch_months_for_product($connect, $product)
    {
        maintenance_warranty_ensure_table($connect);
        $sql = 'SELECT pw_months FROM product_warranty WHERE pw_product = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        $p = (string) $product;
        mysqli_stmt_bind_param($stmt, 's', $p);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = ($res !== false) ? mysqli_fetch_assoc($res) : null;
        if ($res !== false) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        return ($row !== null && isset($row['pw_months'])) ? (int) $row['pw_months'] : null;
    }
}

if (!function_exists('maintenance_warranty_upsert')) {
    /**
     * Purpose:
     * บันทึกหรือแก้ไข config ประกันสำหรับรุ่นหนึ่ง (INSERT ... ON DUPLICATE KEY UPDATE)
     *
     * Parameters สำคัญ:
     * - string $product: ชื่อรุ่น
     * - int $months: จำนวนเดือนประกัน (1–600)
     *
     * Return value:
     * - bool true เมื่อสำเร็จ
     */
    function maintenance_warranty_upsert($connect, $product, $months)
    {
        maintenance_warranty_ensure_table($connect);
        $p = trim((string) $product);
        $m = (int) $months;
        if ($p === '' || $m < 1 || $m > 600) {
            return false;
        }
        $now = date('Y-m-d');
        $sql = 'INSERT INTO product_warranty (pw_product, pw_months, pw_updated_at)'
            . ' VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE pw_months = VALUES(pw_months), pw_updated_at = VALUES(pw_updated_at)';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'sis', $p, $m, $now);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }
}

if (!function_exists('maintenance_warranty_calc_product_insurance')) {
    /**
     * Purpose:
     * คำนวณสถานะประกันผลิตภัณฑ์จากวันที่ซื้อ + จำนวนเดือนประกัน
     *
     * Parameters สำคัญ:
     * - string $buy_date_str: วันที่ซื้อ (Y-m-d)
     * - int|null $pw_months: จำนวนเดือนประกัน null = ไม่มี config
     *
     * Return value:
     * - string|null 'อยู่ในประกัน' | 'หมดประกัน' | null ถ้าไม่มีข้อมูลพอ
     */
    function maintenance_warranty_calc_product_insurance($buy_date_str, $pw_months)
    {
        if ($pw_months === null || $pw_months < 1) {
            return null;
        }
        $buyDate = trim((string) $buy_date_str);
        if ($buyDate === '' || $buyDate === '-') {
            return null;
        }
        $tz = new DateTimeZone('Asia/Bangkok');
        $buy = DateTime::createFromFormat('Y-m-d', $buyDate, $tz);
        if ($buy === false) {
            return null;
        }
        $buy->setTime(0, 0, 0);
        $expiry = clone $buy;
        $expiry->modify('+' . $pw_months . ' months');
        $today = new DateTime('now', $tz);
        $today->setTime(0, 0, 0);

        return ($today <= $expiry) ? 'อยู่ในประกัน' : 'หมดประกัน';
    }
}

if (!function_exists('maintenance_warranty_find_repair_warranty')) {
    /**
     * Purpose:
     * ตรวจว่าเครื่อง (SN เดียวกัน) มีการซ่อมที่มี trp_success_date ภายใน 90 วันก่อนวันนี้
     * เฉพาะแถวที่ trp_status_firmma = 'อนุมัติซ่อม' และมี trp_success_date
     * ข้ามแถว trp_id เดิม (ใบงานปัจจุบัน)
     *
     * Parameters สำคัญ:
     * - string $sn: S/N เครื่อง
     * - int $exclude_trp_id: trp_id ที่ไม่ต้องนับ (0 = ไม่ข้าม)
     *
     * Return value:
     * - array|null array คีย์ 'success_date' (string Y-m-d), 'days_ago' (int), 'days_left' (int) หรือ null
     */
    function maintenance_warranty_find_repair_warranty($connect, $sn, $exclude_trp_id = 0)
    {
        $snClean = trim((string) $sn);
        if ($snClean === '' || $snClean === '-') {
            return null;
        }
        $tz = new DateTimeZone('Asia/Bangkok');
        $today = new DateTime('now', $tz);
        $today->setTime(0, 0, 0);
        $cutoff = clone $today;
        $cutoff->modify('-90 days');
        $cutoffStr = $cutoff->format('Y-m-d');

        $exId = (int) $exclude_trp_id;
        if ($exId > 0) {
            $sql = 'SELECT trp_success_date FROM transac_repair'
                . " WHERE trp_sn = ? AND TRIM(trp_status_firmma) = 'อนุมัติซ่อม'"
                . " AND trp_success_date >= ? AND trp_success_date != ''"
                . ' AND trp_id != ?'
                . ' ORDER BY trp_success_date DESC LIMIT 1';
            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return null;
            }
            mysqli_stmt_bind_param($stmt, 'ssi', $snClean, $cutoffStr, $exId);
        } else {
            $sql = 'SELECT trp_success_date FROM transac_repair'
                . " WHERE trp_sn = ? AND TRIM(trp_status_firmma) = 'อนุมัติซ่อม'"
                . " AND trp_success_date >= ? AND trp_success_date != ''"
                . ' ORDER BY trp_success_date DESC LIMIT 1';
            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return null;
            }
            mysqli_stmt_bind_param($stmt, 'ss', $snClean, $cutoffStr);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = ($res !== false) ? mysqli_fetch_assoc($res) : null;
        if ($res !== false) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);

        if ($row === null || empty($row['trp_success_date'])) {
            return null;
        }
        $successDate = $row['trp_success_date'];
        $sd = DateTime::createFromFormat('Y-m-d', $successDate, $tz);
        if ($sd === false) {
            return null;
        }
        $sd->setTime(0, 0, 0);
        $expiry = clone $sd;
        $expiry->modify('+90 days');
        if ($today > $expiry) {
            return null;
        }
        $daysAgo = (int) $today->diff($sd)->days;
        $daysLeft = (int) $today->diff($expiry)->days;

        return array(
            'success_date' => $successDate,
            'days_ago'     => $daysAgo,
            'days_left'    => $daysLeft,
        );
    }
}

if (!function_exists('maintenance_warranty_suggest_insurance')) {
    /**
     * Purpose:
     * รวมการตรวจประกันซ่อม (3 เดือน) และประกันผลิตภัณฑ์ → คืนค่าที่แนะนำพร้อมข้อความอธิบาย
     * ประกันซ่อมมีลำดับสูงกว่า (override ประกันผลิตภัณฑ์)
     *
     * Parameters สำคัญ:
     * - string $product: ชื่อรุ่น (trp_product)
     * - string $buy_date: วันที่ซื้อ (Y-m-d หรือว่าง)
     * - string $sn: S/N เครื่อง
     * - int $trp_id: trp_id ปัจจุบัน (ข้ามตัวเอง)
     *
     * Return value:
     * - array คีย์ 'value' (string หรือ null), 'hint' (string), 'repair_warranty' (array|null), 'product_months' (int|null)
     */
    function maintenance_warranty_suggest_insurance($connect, $product, $buy_date, $sn, $trp_id = 0)
    {
        $pw_months = maintenance_warranty_fetch_months_for_product($connect, (string) $product);
        $repairWty = maintenance_warranty_find_repair_warranty($connect, (string) $sn, (int) $trp_id);

        if ($repairWty !== null) {
            $hint = 'อยู่ในประกันซ่อม (ซ่อมล่าสุด ' . $repairWty['days_ago'] . ' วันก่อน เหลือ ' . $repairWty['days_left'] . ' วัน)';

            return array(
                'value'          => 'อยู่ในประกันซ่อม',
                'hint'           => $hint,
                'repair_warranty' => $repairWty,
                'product_months' => $pw_months,
            );
        }

        $productIns = maintenance_warranty_calc_product_insurance((string) $buy_date, $pw_months);
        if ($productIns !== null) {
            $monthsLabel = $pw_months !== null ? ' (ประกัน ' . maintenance_warranty_months_label($pw_months) . ')' : '';
            $hint = $productIns . $monthsLabel;

            return array(
                'value'          => $productIns,
                'hint'           => $hint,
                'repair_warranty' => null,
                'product_months' => $pw_months,
            );
        }

        if ($pw_months === null) {
            $hint = 'รุ่นนี้ยังไม่ได้ตั้งค่าระยะเวลาประกัน — เลือกด้วยตนเอง';
        } else {
            $hint = 'ไม่มีวันที่ซื้อ — เลือกด้วยตนเอง';
        }

        return array(
            'value'          => null,
            'hint'           => $hint,
            'repair_warranty' => null,
            'product_months' => $pw_months,
        );
    }
}

if (!function_exists('maintenance_warranty_months_label')) {
    /**
     * Purpose:
     * แปลงจำนวนเดือนเป็นข้อความ เช่น 12 → "1 ปี", 18 → "1 ปี 6 เดือน", 6 → "6 เดือน"
     *
     * Return value:
     * - string
     */
    function maintenance_warranty_months_label($months)
    {
        $m = (int) $months;
        if ($m <= 0) {
            return '0 เดือน';
        }
        $years = (int) floor($m / 12);
        $rem = $m % 12;
        if ($years > 0 && $rem > 0) {
            return $years . ' ปี ' . $rem . ' เดือน';
        }
        if ($years > 0) {
            return $years . ' ปี';
        }

        return $rem . ' เดือน';
    }
}
