<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รวบรวมสถิติการซ่อมสำหรับหน้า `repair_dashboard` — อ่านจาก `transac` + `transac_repair` (+ `transac_spare` สำหรับแนวโน้มอะไหล่สำรอง)
 * ใช้ช่วงวันที่ `transac.ts_date` เป็นขอบเขตหลักของงานที่วิเคราะห์ (สอดคล้องวันที่เปิดใบงาน) และ prepared statement ทุกคำสั่งที่มีพารามิเตอร์
 *
 * องค์ประกอบหลัก:
 * - `maintenance_repair_dashboard_parse_range` — อ่าน `from` / `to` จาก GET (ไม่ระบุ = ปีปฏิทินปัจจุบัน 1 ม.ค.–31 ธ.ค.)
 * - `maintenance_repair_dashboard_fetch_all` — รวมผลหลายชุดสำหรับกราฟ + KPI (รายเดือนเติมเดือนว่างเป็น 0)
 *
 * Flow:
 * parse range → `maintenance_repair_dashboard_fetch_all` → เติม `monthly_jobs` ทุกเดือนในช่วง → encode JSON ใน `repair_dashboard.php`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_repair_dashboard_parse_price_th')) {
    /**
     * Purpose:
     * แปลงสตริงราคาแบบมีคอมมา (จากฟอร์ม QV) เป็นตัวเลข float สำหรับ SUM
     *
     * Parameters สำคัญ:
     * - string $raw: ค่า `trp_qv_price` จาก DB
     *
     * Return value:
     * - float
     */
    function maintenance_repair_dashboard_parse_price_th($raw)
    {
        $s = str_replace(array(',', ' ', "\xc2\xa0"), '', trim((string) $raw));
        if ($s === '' || $s === '-' || strcasecmp($s, 'null') === 0) {
            return 0.0;
        }

        return (float) $s;
    }
}

if (!function_exists('maintenance_repair_dashboard_fill_monthly_jobs')) {
    /**
     * Purpose:
     * เติมทุกเดือนตั้งแต่เดือนของ `from` ถึงเดือนของ `to` ให้มี count (ไม่มีใน SQL = 0) — กรณีเลือกทั้งปีหรือหนึ่งเดือนกราฟยังอ่านง่าย
     *
     * Parameters สำคัญ:
     * - string $from: วันเริ่ม `Y-m-d`
     * - string $to: วันสิ้นสุด `Y-m-d`
     * - array $from_sql: แถวจาก SQL คีย์ `month` (Y-m), `count`
     *
     * Return value:
     * - array<int, array{month: string, count: int}>
     */
    function maintenance_repair_dashboard_fill_monthly_jobs($from, $to, array $from_sql)
    {
        $map = array();
        foreach ($from_sql as $row) {
            if (!isset($row['month'])) {
                continue;
            }
            $map[(string) $row['month']] = isset($row['count']) ? (int) $row['count'] : 0;
        }

        $y = (int) substr($from, 0, 4);
        $m = (int) substr($from, 5, 2);
        $yEnd = (int) substr($to, 0, 4);
        $mEnd = (int) substr($to, 5, 2);
        if ($y < 1 || $m < 1 || $m > 12) {
            return array();
        }

        $out = array();
        while ($y < $yEnd || ($y === $yEnd && $m <= $mEnd)) {
            $key = sprintf('%04d-%02d', $y, $m);
            $out[] = array(
                'month' => $key,
                'count' => isset($map[$key]) ? $map[$key] : 0,
            );
            $m += 1;
            if ($m > 12) {
                $m = 1;
                $y += 1;
            }
        }

        return $out;
    }
}

if (!function_exists('maintenance_repair_dashboard_parse_range')) {
    /**
     * Purpose:
     * อ่านช่วงวันที่จาก query string — ถ้าไม่ส่งทั้งคู่ใช้ **ปีปฏิทินปัจจุบัน** (1 ม.ค. – 31 ธ.ค.); ถ้าส่งไม่ครบใช้ต้นปีของปีที่ `to` เป็นค่า `from` เริ่มต้น
     *
     * Parameters สำคัญ:
     * - array $get: มักเป็น `$_GET` คีย์ `from`, `to` (รูปแบบ `Y-m-d`)
     *
     * Return value:
     * - array คีย์ `from` (string), `to` (string)
     */
    function maintenance_repair_dashboard_parse_range(array $get)
    {
        $fromProvided = isset($get['from']) && trim((string) $get['from']) !== '';
        $toProvided = isset($get['to']) && trim((string) $get['to']) !== '';

        if (!$fromProvided && !$toProvided) {
            $y = (int) date('Y');

            return array(
                'from' => sprintf('%04d-01-01', $y),
                'to' => sprintf('%04d-12-31', $y),
            );
        }

        $to = isset($get['to']) ? trim((string) $get['to']) : '';
        $from = isset($get['from']) ? trim((string) $get['from']) : '';
        if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $y = (int) substr($to, 0, 4);
            $from = sprintf('%04d-01-01', $y);
        }
        if (strcmp($from, $to) > 0) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        return array('from' => $from, 'to' => $to);
    }
}

if (!function_exists('maintenance_repair_dashboard_stmt_rows')) {
    /**
     * Purpose:
     * รัน SELECT prepared แล้วคืนแถว associative ทั้งหมด
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $sql: คำสั่ง SELECT พร้อม `?`
     * - string $types: ชนิด bind เช่น `ss`
     * - array $bind: ค่า bind ตามลำดับ
     *
     * Return value:
     * - array<int, array<string, mixed>>
     */
    function maintenance_repair_dashboard_stmt_rows(mysqli $connect, $sql, $types, array $bind)
    {
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array();
        }
        if ($types !== '' && $bind !== array()) {
            mysqli_stmt_bind_param($stmt, $types, ...$bind);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = array();
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = $r;
            }
        }
        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('maintenance_repair_dashboard_fetch_all')) {
    /**
     * Purpose:
     * ดึงชุดสถิติทั้งหมดสำหรับ dashboard (KPI + ข้อมูลกราฟ) ในช่วง `ts_date` ของใบงาน
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $range: ผลจาก `maintenance_repair_dashboard_parse_range()` คีย์ `from`, `to`
     *
     * Return value:
     * - array โครงสร้างคงที่สำหรับ JSON (ตัวเลขเป็นค่า scalar / array ของแถว {label,count} ฯลฯ)
     */
    function maintenance_repair_dashboard_fetch_all(mysqli $connect, array $range)
    {
        $from = (string) $range['from'];
        $to = (string) $range['to'];
        $types = 'ss';
        $bind = array($from, $to);

        $out = array(
            'range' => array('from' => $from, 'to' => $to),
            'job_count' => 0,
            'spare_trends' => array(),
            'wait_hardware' => array('avg_days' => null, 'n' => 0),
            'wait_approval' => array('avg_days' => null, 'n' => 0),
            'repair_cycle' => array('avg_days' => null, 'n' => 0),
            'rate_after_receive' => array('avg_days' => null, 'n' => 0),
            'revenue_total' => 0.0,
            'revenue_rows' => 0,
            'status_breakdown' => array(),
            'monthly_jobs' => array(),
            'top_products' => array(),
            'wip_by_status' => array(),
        );

        $sqlCount = 'SELECT COUNT(*) AS c FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id WHERE t.ts_date BETWEEN ? AND ?';
        $r0 = maintenance_repair_dashboard_stmt_rows($connect, $sqlCount, $types, $bind);
        if (isset($r0[0]['c'])) {
            $out['job_count'] = (int) $r0[0]['c'];
        }

        $sqlSpare = 'SELECT COALESCE(NULLIF(TRIM(sp.tsp_product), \'\'), \'(ไม่ระบุสินค้าสำรอง)\') AS lb, COUNT(*) AS c '
            . 'FROM transac_spare sp INNER JOIN transac_repair tr ON tr.trp_id = sp.tsp_trp_id '
            . 'INNER JOIN transac t ON t.ts_id = tr.trp_ts_id WHERE t.ts_date BETWEEN ? AND ? '
            . 'GROUP BY COALESCE(NULLIF(TRIM(sp.tsp_product), \'\'), \'(ไม่ระบุสินค้าสำรอง)\') ORDER BY c DESC LIMIT 15';
        foreach (maintenance_repair_dashboard_stmt_rows($connect, $sqlSpare, $types, $bind) as $row) {
            $out['spare_trends'][] = array(
                'label' => (string) $row['lb'],
                'count' => (int) $row['c'],
            );
        }

        $sqlHw = 'SELECT AVG(DATEDIFF(tr.trp_receive_date, t.ts_date)) AS avg_d, COUNT(*) AS n '
            . 'FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id '
            . 'WHERE t.ts_date BETWEEN ? AND ? AND tr.trp_receive_date IS NOT NULL AND TRIM(tr.trp_receive_date) <> \'\' '
            . 'AND DATEDIFF(tr.trp_receive_date, t.ts_date) >= 0 AND DATEDIFF(tr.trp_receive_date, t.ts_date) <= 900';
        $rHw = maintenance_repair_dashboard_stmt_rows($connect, $sqlHw, $types, $bind);
        if (isset($rHw[0])) {
            $out['wait_hardware']['n'] = (int) $rHw[0]['n'];
            if ($rHw[0]['avg_d'] !== null) {
                $out['wait_hardware']['avg_days'] = round((float) $rHw[0]['avg_d'], 2);
            }
        }

        $sqlAp = 'SELECT AVG(DATEDIFF(tr.trp_firm_qv_date, tr.trp_qv_date)) AS avg_d, COUNT(*) AS n '
            . 'FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id '
            . 'WHERE t.ts_date BETWEEN ? AND ? AND tr.trp_qv_date IS NOT NULL AND TRIM(tr.trp_qv_date) <> \'\' '
            . 'AND tr.trp_firm_qv_date IS NOT NULL AND TRIM(tr.trp_firm_qv_date) <> \'\' '
            . 'AND DATEDIFF(tr.trp_firm_qv_date, tr.trp_qv_date) >= 0 AND DATEDIFF(tr.trp_firm_qv_date, tr.trp_qv_date) <= 900';
        $rAp = maintenance_repair_dashboard_stmt_rows($connect, $sqlAp, $types, $bind);
        if (isset($rAp[0])) {
            $out['wait_approval']['n'] = (int) $rAp[0]['n'];
            if ($rAp[0]['avg_d'] !== null) {
                $out['wait_approval']['avg_days'] = round((float) $rAp[0]['avg_d'], 2);
            }
        }

        $sqlRep = 'SELECT AVG(DATEDIFF(tr.trp_success_date, tr.trp_firm_qv_date)) AS avg_d, COUNT(*) AS n '
            . 'FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id '
            . 'WHERE t.ts_date BETWEEN ? AND ? AND tr.trp_success_date IS NOT NULL AND TRIM(tr.trp_success_date) <> \'\' '
            . 'AND tr.trp_firm_qv_date IS NOT NULL AND TRIM(tr.trp_firm_qv_date) <> \'\' '
            . 'AND DATEDIFF(tr.trp_success_date, tr.trp_firm_qv_date) >= 0 AND DATEDIFF(tr.trp_success_date, tr.trp_firm_qv_date) <= 900';
        $rRep = maintenance_repair_dashboard_stmt_rows($connect, $sqlRep, $types, $bind);
        if (isset($rRep[0])) {
            $out['repair_cycle']['n'] = (int) $rRep[0]['n'];
            if ($rRep[0]['avg_d'] !== null) {
                $out['repair_cycle']['avg_days'] = round((float) $rRep[0]['avg_d'], 2);
            }
        }

        $sqlRate = 'SELECT AVG(DATEDIFF(tr.trp_rate_date, tr.trp_receive_date)) AS avg_d, COUNT(*) AS n '
            . 'FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id '
            . 'WHERE t.ts_date BETWEEN ? AND ? AND tr.trp_receive_date IS NOT NULL AND TRIM(tr.trp_receive_date) <> \'\' '
            . 'AND tr.trp_rate_date IS NOT NULL AND TRIM(tr.trp_rate_date) <> \'\' '
            . 'AND DATEDIFF(tr.trp_rate_date, tr.trp_receive_date) >= 0 AND DATEDIFF(tr.trp_rate_date, tr.trp_receive_date) <= 900';
        $rRate = maintenance_repair_dashboard_stmt_rows($connect, $sqlRate, $types, $bind);
        if (isset($rRate[0])) {
            $out['rate_after_receive']['n'] = (int) $rRate[0]['n'];
            if ($rRate[0]['avg_d'] !== null) {
                $out['rate_after_receive']['avg_days'] = round((float) $rRate[0]['avg_d'], 2);
            }
        }

        $sqlPrice = 'SELECT tr.trp_qv_price AS p FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id '
            . 'WHERE t.ts_date BETWEEN ? AND ? AND tr.trp_qv_price IS NOT NULL AND TRIM(tr.trp_qv_price) <> \'\' AND TRIM(tr.trp_qv_price) <> \'-\'';
        $sum = 0.0;
        $npr = 0;
        foreach (maintenance_repair_dashboard_stmt_rows($connect, $sqlPrice, $types, $bind) as $row) {
            $v = maintenance_repair_dashboard_parse_price_th(isset($row['p']) ? (string) $row['p'] : '');
            if ($v > 0) {
                $sum += $v;
                $npr++;
            }
        }
        $out['revenue_total'] = round($sum, 2);
        $out['revenue_rows'] = $npr;

        $sqlSt = 'SELECT tr.trp_status_job AS st, COUNT(*) AS c FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id '
            . 'WHERE t.ts_date BETWEEN ? AND ? GROUP BY tr.trp_status_job ORDER BY c DESC';
        foreach (maintenance_repair_dashboard_stmt_rows($connect, $sqlSt, $types, $bind) as $row) {
            $out['status_breakdown'][] = array(
                'label' => (string) $row['st'],
                'count' => (int) $row['c'],
            );
        }

        $sqlMo = 'SELECT DATE_FORMAT(t.ts_date, \'%Y-%m\') AS ym, COUNT(*) AS c FROM transac_repair tr '
            . 'INNER JOIN transac t ON t.ts_id = tr.trp_ts_id WHERE t.ts_date BETWEEN ? AND ? GROUP BY ym ORDER BY ym ASC';
        $moRaw = array();
        foreach (maintenance_repair_dashboard_stmt_rows($connect, $sqlMo, $types, $bind) as $row) {
            $moRaw[] = array(
                'month' => (string) $row['ym'],
                'count' => (int) $row['c'],
            );
        }
        $out['monthly_jobs'] = maintenance_repair_dashboard_fill_monthly_jobs($from, $to, $moRaw);

        $sqlProd = 'SELECT COALESCE(NULLIF(TRIM(tr.trp_product), \'\'), \'(ไม่ระบุรุ่น)\') AS lb, COUNT(*) AS c '
            . 'FROM transac_repair tr INNER JOIN transac t ON t.ts_id = tr.trp_ts_id WHERE t.ts_date BETWEEN ? AND ? '
            . 'GROUP BY COALESCE(NULLIF(TRIM(tr.trp_product), \'\'), \'(ไม่ระบุรุ่น)\') ORDER BY c DESC LIMIT 12';
        foreach (maintenance_repair_dashboard_stmt_rows($connect, $sqlProd, $types, $bind) as $row) {
            $out['top_products'][] = array(
                'label' => (string) $row['lb'],
                'count' => (int) $row['c'],
            );
        }

        $sqlWip = 'SELECT trp_status_job AS st, COUNT(*) AS c FROM transac_repair GROUP BY trp_status_job ORDER BY c DESC';
        foreach (maintenance_repair_dashboard_stmt_rows($connect, $sqlWip, '', array()) as $row) {
            $out['wip_by_status'][] = array(
                'label' => (string) $row['st'],
                'count' => (int) $row['c'],
            );
        }

        return $out;
    }
}
