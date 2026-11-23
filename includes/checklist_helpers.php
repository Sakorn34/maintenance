<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper ระบบ Checklist ประเมินอาการรายรุ่น — สร้างตาราง auto, ดึงข้อตรวจ, บันทึกผล, แนะนำรายการ
 *
 * ตาราง: cl_symptom, cl_item, cl_job_header, cl_job_item
 * ทุก ensure_tables เรียกครั้งแรกในทุก request ที่ใช้ checklist เพื่อ auto-migrate
 */

if (!function_exists('maintenance_checklist_format_age')) {
    function maintenance_checklist_format_age($days)
    {
        if ($days < 30)  { return (int) $days . ' วัน'; }
        if ($days < 365) { return (int) ($days / 30) . ' เดือน'; }
        $years  = (int) ($days / 365.25);
        $months = (int) (($days - $years * 365.25) / 30);
        return $years . ' ปี' . ($months > 0 ? ' ' . $months . ' เดือน' : '');
    }
}

if (!function_exists('maintenance_checklist_auto_result')) {
    /**
     * คำนวณผลอัตโนมัติสำหรับ auto_source items
     * Return: array{ result: 'pass'|'fail'|'na', label: string, detail: string }
     */
    function maintenance_checklist_auto_result($connect, $trp_id, $auto_source)
    {
        $t = (int) $trp_id;

        if ($auto_source === 'buy_date') {
            $r = mysqli_query($connect, "SELECT trp_buy_date FROM transac_repair WHERE trp_id=$t LIMIT 1");
            if (!$r) { return array('result' => 'na', 'label' => 'ไม่พบข้อมูล', 'detail' => ''); }
            $row = mysqli_fetch_assoc($r);
            $buy_date = $row ? trim((string) $row['trp_buy_date']) : '';
            if ($buy_date === '') { return array('result' => 'na', 'label' => 'ไม่ทราบวันที่ซื้อ', 'detail' => ''); }
            $ts = strtotime($buy_date);
            if (!$ts) { return array('result' => 'na', 'label' => 'รูปแบบวันที่ไม่ถูกต้อง', 'detail' => $buy_date); }
            $age_days  = (time() - $ts) / 86400;
            $age_years = $age_days / 365.25;
            $age_str   = maintenance_checklist_format_age($age_days);
            $date_disp = date('d/m/Y', $ts);
            if ($age_years < 2) {
                return array('result' => 'pass', 'label' => 'น้อยกว่า 2 ปี', 'detail' => 'อายุ ' . $age_str . ' (ซื้อ ' . $date_disp . ')');
            }
            return array('result' => 'fail', 'label' => 'เกิน 2 ปี', 'detail' => 'อายุ ' . $age_str . ' (ซื้อ ' . $date_disp . ')');
        }

        if ($auto_source === 'last_ma') {
            $r = mysqli_query($connect, "SELECT trp_sn, trp_buy_date FROM transac_repair WHERE trp_id=$t LIMIT 1");
            if (!$r) { return array('result' => 'na', 'label' => 'ไม่พบข้อมูล', 'detail' => ''); }
            $row      = mysqli_fetch_assoc($r);
            $sn       = $row ? trim((string) $row['trp_sn']) : '';
            $buy_date = $row ? trim((string) $row['trp_buy_date']) : '';

            // ถ้าเครื่องอายุน้อยกว่า 2 ปี → N/A
            if ($buy_date !== '') {
                $ts_buy = strtotime($buy_date);
                if ($ts_buy && ((time() - $ts_buy) / (365.25 * 86400)) < 2) {
                    return array('result' => 'na', 'label' => 'N/A — เครื่องอายุน้อยกว่า 2 ปี', 'detail' => '');
                }
            }

            if ($sn === '') { return array('result' => 'fail', 'label' => 'ไม่พบ Serial Number', 'detail' => ''); }

            $sn_safe = mysqli_real_escape_string($connect, $sn);
            $ma      = 'MA เปลียนอะไหล่พื้นฐาน';
            $ma_safe = mysqli_real_escape_string($connect, $ma);
            $cols    = array();
            for ($i = 1; $i <= 10; $i++) { $cols[] = "trp_repair_no$i='$ma_safe'"; }
            $where_ma = implode(' OR ', $cols);
            $sql_ma = "SELECT trp_receive_date, trp_success_date FROM transac_repair"
                . " WHERE trp_sn='$sn_safe' AND trp_id!=$t AND ($where_ma)"
                . " ORDER BY trp_id DESC LIMIT 1";
            $r_ma = mysqli_query($connect, $sql_ma);
            if (!$r_ma) { return array('result' => 'fail', 'label' => 'ไม่พบประวัติ MA', 'detail' => ''); }
            $ma_row = mysqli_fetch_assoc($r_ma);
            if (!$ma_row) { return array('result' => 'fail', 'label' => 'ไม่พบประวัติ MA อะไหล่พื้นฐาน', 'detail' => ''); }

            $ma_date_str = ($ma_row['trp_success_date'] !== '' && $ma_row['trp_success_date'] !== null)
                ? $ma_row['trp_success_date'] : $ma_row['trp_receive_date'];
            $ts_ma = strtotime($ma_date_str);
            if (!$ts_ma) { return array('result' => 'fail', 'label' => 'วันที่ MA ไม่ถูกต้อง', 'detail' => $ma_date_str); }

            $age_days  = (time() - $ts_ma) / 86400;
            $age_years = $age_days / 365.25;
            $age_str   = maintenance_checklist_format_age($age_days);
            $date_disp = date('d/m/Y', $ts_ma);
            if ($age_years < 2) {
                return array('result' => 'pass', 'label' => 'ไม่เกิน 2 ปี', 'detail' => 'MA ล่าสุด ' . $date_disp . ' (' . $age_str . ' ที่แล้ว)');
            }
            return array('result' => 'fail', 'label' => 'เกิน 2 ปี', 'detail' => 'MA ล่าสุด ' . $date_disp . ' (' . $age_str . ' ที่แล้ว)');
        }

        return array('result' => 'na', 'label' => 'ไม่รู้จัก auto_source', 'detail' => '');
    }
}

if (!function_exists('maintenance_checklist_column_exists')) {
    function maintenance_checklist_column_exists($connect, $table, $column)
    {
        $stmt = mysqli_prepare($connect,
            'SELECT COUNT(*) AS n FROM information_schema.columns'
            . ' WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'
        );
        if (!$stmt) { return true; }
        mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ? (int) $row['n'] > 0 : true;
    }
}

if (!function_exists('maintenance_checklist_group_rules')) {
    function maintenance_checklist_group_rules()
    {
        return array(
            array('/อายุเครื่อง|ประวัติ MA|^MA /u', 'ข้อมูลเครื่อง / MA'),
            array('/TouchScreen|จอแสดงผล|LUX|หน้าจอ|BackLight/iu', 'หน้าจอ'),
            array('/HDMI/u', 'สาย HDMI'),
            array('/Acrylic/u', 'ตัวเครื่อง / Acrylic'),
            array('/HUB|Port USB/u', 'HUB / USB'),
            array('/กล้อง|Focus/u', 'กล้อง'),
            array('/Adapter|Jack Power|Switch|Step down/u', 'ภาคจ่ายไฟ'),
            array('/Main Board|Crystal|Real Time|Battery/u', 'บอร์ด Main Board (PI)'),
            array('/Micro SD|Write image|Config/u', 'Micro SD / ระบบ'),
            array('/Smart Card|Scanner/u', 'เครื่องอ่านบัตร / สแกน'),
        );
    }
}

/**
 * จัดกลุ่มชิ้นส่วนตั้งต้นจากคำในคำถาม (ใช้ตอน migrate ครั้งแรกเท่านั้น)
 */
if (!function_exists('maintenance_checklist_seed_groups_by_keyword')) {
    function maintenance_checklist_seed_groups_by_keyword($connect, $product)
    {
        $order = array('หน้าจอ', 'สาย HDMI', 'ตัวเครื่อง / Acrylic', 'HUB / USB', 'กล้อง', 'ภาคจ่ายไฟ',
            'บอร์ด Main Board (PI)', 'Micro SD / ระบบ', 'เครื่องอ่านบัตร / สแกน', 'ข้อมูลเครื่อง / MA');
        $ids = array();
        $ins = mysqli_prepare($connect, 'INSERT INTO cl_group (cg_product, cg_name, cg_order) VALUES (?, ?, ?)');
        if (!$ins) { return; }
        foreach ($order as $i => $name) {
            $n = $i + 1;
            mysqli_stmt_bind_param($ins, 'ssi', $product, $name, $n);
            mysqli_stmt_execute($ins);
            $ids[$name] = (int) mysqli_insert_id($connect);
        }
        mysqli_stmt_close($ins);

        $sel = mysqli_prepare($connect, 'SELECT ci_id, ci_question FROM cl_item WHERE ci_product = ?');
        $upd = mysqli_prepare($connect, 'UPDATE cl_item SET ci_group_id = ? WHERE ci_id = ?');
        if (!$sel || !$upd) { return; }
        mysqli_stmt_bind_param($sel, 's', $product);
        mysqli_stmt_execute($sel);
        $res = mysqli_stmt_get_result($sel);
        $rules = maintenance_checklist_group_rules();
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            foreach ($rules as $rule) {
                if (preg_match($rule[0], (string) $row['ci_question']) && isset($ids[$rule[1]])) {
                    $gid = $ids[$rule[1]];
                    $cid = (int) $row['ci_id'];
                    mysqli_stmt_bind_param($upd, 'ii', $gid, $cid);
                    mysqli_stmt_execute($upd);
                    break;
                }
            }
        }
        mysqli_stmt_close($sel);
        mysqli_stmt_close($upd);

        $del = mysqli_prepare($connect,
            'DELETE g FROM cl_group g LEFT JOIN cl_item i ON i.ci_group_id = g.cg_id'
            . ' WHERE g.cg_product = ? AND i.ci_id IS NULL'
        );
        if ($del) {
            mysqli_stmt_bind_param($del, 's', $product);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);
        }
    }
}

if (!function_exists('maintenance_checklist_ensure_tables')) {
    function maintenance_checklist_ensure_tables($connect)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;

        $col = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        $tbl = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_symptom` ('
            . '`cs_id` INT NOT NULL AUTO_INCREMENT,'
            . '`cs_product` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cs_name` VARCHAR(100) ' . $col . ' NOT NULL,'
            . '`cs_order` INT NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`cs_id`),'
            . 'INDEX `idx_cs_product` (`cs_product`)'
            . $tbl
        );

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_group` ('
            . '`cg_id` INT NOT NULL AUTO_INCREMENT,'
            . '`cg_product` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cg_name` VARCHAR(100) ' . $col . ' NOT NULL,'
            . '`cg_order` INT NOT NULL DEFAULT 0,'
            . '`cg_note` VARCHAR(300) ' . $col . ' NOT NULL DEFAULT \'\','
            . 'PRIMARY KEY (`cg_id`),'
            . 'INDEX `idx_cg_product` (`cg_product`)'
            . $tbl
        );

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_product` ('
            . '`cp_product` VARCHAR(100) ' . $col . ' NOT NULL,'
            . '`cp_share_from` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cp_updated_at` VARCHAR(20) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cp_updated_by` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . 'PRIMARY KEY (`cp_product`)'
            . $tbl
        );

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_item` ('
            . '`ci_id` INT NOT NULL AUTO_INCREMENT,'
            . '`ci_product` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_symptom_id` INT NOT NULL DEFAULT 0,'
            . '`ci_group_id` INT NOT NULL DEFAULT 0,'
            . '`ci_step` INT NOT NULL DEFAULT 0,'
            . '`ci_question` VARCHAR(300) ' . $col . ' NOT NULL,'
            . '`ci_rate_on_fail` VARCHAR(200) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_hint` VARCHAR(300) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_unit` VARCHAR(20) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_val_min` DECIMAL(7,2) NULL DEFAULT NULL,'
            . '`ci_val_max` DECIMAL(7,2) NULL DEFAULT NULL,'
            . '`ci_opts` TEXT ' . $col . ' NULL DEFAULT NULL,'
            . '`ci_opt_rates` TEXT ' . $col . ' NULL DEFAULT NULL,'
            . '`ci_opt_colors` VARCHAR(200) ' . $col . ' NULL DEFAULT NULL,'
            . '`ci_multi` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`ci_auto_source` VARCHAR(50) ' . $col . ' NULL DEFAULT NULL,'
            . '`ci_enabled` TINYINT(1) NOT NULL DEFAULT 1,'
            . 'PRIMARY KEY (`ci_id`),'
            . 'INDEX `idx_product_symptom` (`ci_product`, `ci_symptom_id`)'
            . $tbl
        );

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_job_header` ('
            . '`ch_id` INT NOT NULL AUTO_INCREMENT,'
            . '`ch_trp_id` INT NOT NULL,'
            . '`ch_symptom_id` INT NOT NULL DEFAULT 0,'
            . '`ch_completed` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`ch_saved_at` VARCHAR(20) ' . $col . ' DEFAULT NULL,'
            . '`ch_saved_by` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . 'PRIMARY KEY (`ch_id`),'
            . 'UNIQUE KEY `uq_ch_trp` (`ch_trp_id`, `ch_symptom_id`)'
            . $tbl
        );

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_job_item` ('
            . '`cji_id` INT NOT NULL AUTO_INCREMENT,'
            . '`cji_trp_id` INT NOT NULL,'
            . '`cji_item_id` INT NOT NULL,'
            . '`cji_result` VARCHAR(700) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cji_note` VARCHAR(200) ' . $col . ' NOT NULL DEFAULT \'\','
            . 'PRIMARY KEY (`cji_id`),'
            . 'UNIQUE KEY `uq_cji` (`cji_trp_id`, `cji_item_id`)'
            . $tbl
        );

        // migrate: widen cji_result to VARCHAR(100)
        $r_res = mysqli_query($connect,
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.columns"
            . " WHERE table_schema=DATABASE() AND table_name='cl_job_item' AND column_name='cji_result'"
        );
        $row_res = $r_res ? mysqli_fetch_assoc($r_res) : null;
        if ($row_res && (int) $row_res['len'] < 700) {
            mysqli_query($connect,
                "ALTER TABLE cl_job_item MODIFY COLUMN cji_result VARCHAR(700) " . $col . " NOT NULL DEFAULT ''"
            );
        }

        // migrate: columns ที่เพิ่มทีหลังใน cl_item
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_unit')) {
            mysqli_query($connect, "ALTER TABLE cl_item ADD COLUMN ci_unit VARCHAR(20) " . $col . " NOT NULL DEFAULT '' AFTER ci_hint");
        }
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_val_min')) {
            mysqli_query($connect,
                'ALTER TABLE cl_item ADD COLUMN ci_val_min DECIMAL(7,2) NULL DEFAULT NULL AFTER ci_unit,'
                . ' ADD COLUMN ci_val_max DECIMAL(7,2) NULL DEFAULT NULL AFTER ci_val_min'
            );
        }
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_opts')) {
            mysqli_query($connect, 'ALTER TABLE cl_item ADD COLUMN ci_opts TEXT ' . $col . ' NULL DEFAULT NULL AFTER ci_val_max');
        }
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_auto_source')) {
            mysqli_query($connect, 'ALTER TABLE cl_item ADD COLUMN ci_auto_source VARCHAR(50) ' . $col . ' NULL DEFAULT NULL AFTER ci_opts');
        }

        // migrate: รายการซ่อมแยกตามตัวเลือก — ค่าเดิมทุกตัวเลือก (ยกเว้นตัวแรก) = ci_rate_on_fail
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_opt_rates')) {
            mysqli_query($connect, 'ALTER TABLE cl_item ADD COLUMN ci_opt_rates TEXT ' . $col . ' NULL DEFAULT NULL AFTER ci_opts');
            $r_or = mysqli_query($connect,
                "SELECT ci_id, ci_opts, ci_rate_on_fail FROM cl_item WHERE ci_opts IS NOT NULL AND ci_opts <> '' AND ci_unit = ''"
            );
            $upd = mysqli_prepare($connect, 'UPDATE cl_item SET ci_opt_rates = ? WHERE ci_id = ?');
            while ($r_or && $upd && ($row = mysqli_fetch_assoc($r_or))) {
                $n = count(explode('|', (string) $row['ci_opts']));
                $rates = array();
                for ($i = 0; $i < $n; $i++) { $rates[] = $i === 0 ? '' : (string) $row['ci_rate_on_fail']; }
                $json = json_encode($rates, JSON_UNESCAPED_UNICODE);
                $cid = (int) $row['ci_id'];
                mysqli_stmt_bind_param($upd, 'si', $json, $cid);
                mysqli_stmt_execute($upd);
            }
            if ($upd) { mysqli_stmt_close($upd); }
        }

        // migrate: สีของแต่ละตัวเลือก + ตัวเลือกแบบเลือกได้หลายค่า
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_opt_colors')) {
            mysqli_query($connect, 'ALTER TABLE cl_item ADD COLUMN ci_opt_colors VARCHAR(200) ' . $col . ' NULL DEFAULT NULL AFTER ci_opt_rates');
        }
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_multi')) {
            mysqli_query($connect, 'ALTER TABLE cl_item ADD COLUMN ci_multi TINYINT(1) NOT NULL DEFAULT 0 AFTER ci_opt_colors');
        }

        // migrate: อาการแยกตามรุ่น — อาการเดิมทั้งหมดใช้กับ bitVisitor Plus
        if (!maintenance_checklist_column_exists($connect, 'cl_symptom', 'cs_product')) {
            mysqli_query($connect,
                "ALTER TABLE cl_symptom ADD COLUMN cs_product VARCHAR(100) " . $col . " NOT NULL DEFAULT '' AFTER cs_id,"
                . " ADD INDEX idx_cs_product (cs_product)"
            );
            mysqli_query($connect, "UPDATE cl_symptom SET cs_product = 'bitVisitor Plus' WHERE cs_product = ''");
        }

        // migrate: กลุ่มชิ้นส่วน — จัดกลุ่มตั้งต้นให้รุ่นที่มีข้อตรวจอยู่แล้ว
        if (!maintenance_checklist_column_exists($connect, 'cl_item', 'ci_group_id')) {
            mysqli_query($connect, 'ALTER TABLE cl_item ADD COLUMN ci_group_id INT NOT NULL DEFAULT 0 AFTER ci_symptom_id');
            $r_p = mysqli_query($connect, 'SELECT DISTINCT ci_product FROM cl_item');
            $prods = array();
            while ($r_p && ($row = mysqli_fetch_assoc($r_p))) { $prods[] = (string) $row['ci_product']; }
            foreach ($prods as $p) { maintenance_checklist_seed_groups_by_keyword($connect, $p); }
        }

        // migrate: widen unique key to (ch_trp_id, ch_symptom_id)
        $r_key = mysqli_query($connect,
            "SELECT COUNT(*) AS n FROM information_schema.statistics"
            . " WHERE table_schema=DATABASE() AND table_name='cl_job_header'"
            . " AND index_name='uq_ch_trp' AND column_name='ch_symptom_id'"
        );
        $key_has_symptom = $r_key ? (int) mysqli_fetch_assoc($r_key)['n'] : 1;
        if ($key_has_symptom === 0) {
            mysqli_query($connect, 'ALTER TABLE cl_job_header DROP INDEX uq_ch_trp');
            mysqli_query($connect, 'ALTER TABLE cl_job_header ADD UNIQUE KEY uq_ch_trp (ch_trp_id, ch_symptom_id)');
        }
    }
}

/**
 * รุ่นที่ใช้ข้อตรวจจริง (ถ้าตั้ง "ใช้ร่วมกับรุ่น" ไว้ จะได้รุ่นต้นทาง)
 */
if (!function_exists('maintenance_checklist_effective_product')) {
    function maintenance_checklist_effective_product($connect, $product)
    {
        $p = trim((string) $product);
        $stmt = mysqli_prepare($connect, 'SELECT cp_share_from FROM cl_product WHERE cp_product = ?');
        if (!$stmt) { return $p; }
        mysqli_stmt_bind_param($stmt, 's', $p);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        $from = $row ? trim((string) $row['cp_share_from']) : '';
        return ($from !== '' && $from !== $p) ? $from : $p;
    }
}

if (!function_exists('maintenance_checklist_product_supported')) {
    function maintenance_checklist_product_supported($connect, $product)
    {
        $eff = maintenance_checklist_effective_product($connect, $product);
        if ($eff === '') { return false; }
        $stmt = mysqli_prepare($connect, 'SELECT COUNT(*) AS n FROM cl_item WHERE ci_product = ? AND ci_enabled = 1');
        if (!$stmt) { return false; }
        mysqli_stmt_bind_param($stmt, 's', $eff);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row && (int) $row['n'] > 0;
    }
}

if (!function_exists('maintenance_checklist_fetch_symptoms')) {
    function maintenance_checklist_fetch_symptoms($connect, $product)
    {
        $eff = maintenance_checklist_effective_product($connect, $product);
        $stmt = mysqli_prepare($connect,
            'SELECT cs_id, cs_name FROM cl_symptom WHERE cs_product = ? ORDER BY cs_order ASC, cs_id ASC'
        );
        if (!$stmt) { return array(); }
        mysqli_stmt_bind_param($stmt, 's', $eff);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        while ($res && ($row = mysqli_fetch_assoc($res))) { $out[] = $row; }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

/**
 * ข้อตรวจที่เปิดใช้ของรุ่น (ส่งรุ่นที่ resolve แล้ว) เรียงตามกลุ่มชิ้นส่วน แล้วตามลำดับข้อ
 */
if (!function_exists('maintenance_checklist_fetch_items')) {
    function maintenance_checklist_fetch_items($connect, $product, $symptom_id)
    {
        $sql = 'SELECT i.ci_id, i.ci_step, i.ci_question, i.ci_rate_on_fail, i.ci_hint,'
            . ' i.ci_unit, i.ci_val_min, i.ci_val_max, i.ci_opts, i.ci_opt_rates, i.ci_opt_colors, i.ci_multi, i.ci_auto_source, i.ci_group_id,'
            . ' COALESCE(g.cg_name, \'\') AS group_name, COALESCE(g.cg_order, 9999) AS group_order'
            . ' FROM cl_item i'
            . ' LEFT JOIN cl_group g ON g.cg_id = i.ci_group_id'
            . ' WHERE i.ci_product = ? AND i.ci_symptom_id = ? AND i.ci_enabled = 1'
            . ' ORDER BY group_order ASC, i.ci_step ASC, i.ci_id ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return array(); }
        $p = (string) $product;
        $s = (int) $symptom_id;
        mysqli_stmt_bind_param($stmt, 'si', $p, $s);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        while ($res && ($row = mysqli_fetch_assoc($res))) { $out[] = $row; }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

/**
 * รายการซ่อมที่ควรแนะนำจากผลตรวจ 1 ข้อ — ข้อแบบเลือกได้หลายค่าเก็บผลเป็น "ตัวเลือกA|ตัวเลือกB"
 */
if (!function_exists('maintenance_checklist_rates_for_result')) {
    function maintenance_checklist_rates_for_result(array $item, $result)
    {
        $result = (string) $result;
        if ($result === '' || $result === 'pass' || $result === 'na') { return array(); }
        $opts = (isset($item['ci_opts']) && $item['ci_opts'] !== null && $item['ci_opts'] !== '')
            ? array_map(function ($o) { return str_replace(':note', '', trim($o)); }, explode('|', (string) $item['ci_opts']))
            : array();
        $has_threshold = isset($item['ci_val_min']) && $item['ci_val_min'] !== null;
        if (!$opts || $has_threshold) {
            $r = $result === 'fail' ? trim((string) $item['ci_rate_on_fail']) : '';
            return $r !== '' ? array($r) : array();
        }
        $picked = !empty($item['ci_multi']) ? explode('|', $result) : array($result);
        $rates = json_decode((string) (isset($item['ci_opt_rates']) ? $item['ci_opt_rates'] : ''), true);
        $out = array();
        foreach ($picked as $label) {
            $idx = array_search($label, $opts, true);
            if ($idx === false || $idx === 0) { continue; }
            $r = is_array($rates) ? (isset($rates[$idx]) ? trim((string) $rates[$idx]) : '') : trim((string) $item['ci_rate_on_fail']);
            if ($r !== '') { $out[] = $r; }
        }
        return $out;
    }
}

if (!function_exists('maintenance_checklist_fetch_all_job_headers')) {
    function maintenance_checklist_fetch_all_job_headers($connect, $trp_id)
    {
        $sql = 'SELECT ch_symptom_id, ch_completed, ch_saved_at, ch_saved_by'
            . ' FROM cl_job_header WHERE ch_trp_id = ? ORDER BY ch_symptom_id ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return array(); }
        $t = (int) $trp_id;
        mysqli_stmt_bind_param($stmt, 'i', $t);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) { $out[(int) $row['ch_symptom_id']] = $row; }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

if (!function_exists('maintenance_checklist_fetch_job_results')) {
    function maintenance_checklist_fetch_job_results($connect, $trp_id)
    {
        $sql = 'SELECT cji_item_id, cji_result, cji_note FROM cl_job_item WHERE cji_trp_id = ?';
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return array(); }
        $t = (int) $trp_id;
        mysqli_stmt_bind_param($stmt, 'i', $t);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $out[(int) $row['cji_item_id']] = array(
                    'result' => $row['cji_result'],
                    'note'   => $row['cji_note'],
                );
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

if (!function_exists('maintenance_checklist_save')) {
    /**
     * บันทึก header + item results แบบ upsert
     * $results = array( ci_id => array('result'=>string, 'note'=>string), ... )
     * Return: array{ ok: bool, suggested_rates: string[] }
     */
    function maintenance_checklist_save($connect, $trp_id, $symptom_id, array $results, $saved_by)
    {
        $t   = (int) $trp_id;
        $s   = (int) $symptom_id;
        $by  = substr(trim((string) $saved_by), 0, 100);
        $now = date('Y-m-d');

        $sql_h = 'INSERT INTO cl_job_header (ch_trp_id, ch_symptom_id, ch_completed, ch_saved_at, ch_saved_by)'
            . ' VALUES (?, ?, 1, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' ch_completed=1, ch_saved_at=VALUES(ch_saved_at), ch_saved_by=VALUES(ch_saved_by)';
        $stmt_h = mysqli_prepare($connect, $sql_h);
        if (!$stmt_h) { return array('ok' => false, 'suggested_rates' => array()); }
        mysqli_stmt_bind_param($stmt_h, 'iiss', $t, $s, $now, $by);
        mysqli_stmt_execute($stmt_h);
        mysqli_stmt_close($stmt_h);

        $sql_i = 'INSERT INTO cl_job_item (cji_trp_id, cji_item_id, cji_result, cji_note)'
            . ' VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE cji_result=VALUES(cji_result), cji_note=VALUES(cji_note)';
        $stmt_i = mysqli_prepare($connect, $sql_i);
        if (!$stmt_i) { return array('ok' => false, 'suggested_rates' => array()); }

        $suggested = array();
        foreach ($results as $ci_id => $data) {
            $item_id = (int) $ci_id;
            $result  = mb_substr(trim((string) ($data['result'] ?? '')), 0, 700, 'UTF-8');
            $note    = mb_substr(trim((string) ($data['note']   ?? '')), 0, 200, 'UTF-8');

            if ($result === '') { continue; }

            $item_id_v = $item_id;
            mysqli_stmt_bind_param($stmt_i, 'iiss', $t, $item_id_v, $result, $note);
            mysqli_stmt_execute($stmt_i);

            $q = mysqli_prepare($connect,
                'SELECT ci_rate_on_fail, ci_opts, ci_opt_rates, ci_multi, ci_val_min FROM cl_item WHERE ci_id = ?'
            );
            if ($q) {
                mysqli_stmt_bind_param($q, 'i', $item_id_v);
                mysqli_stmt_execute($q);
                $rq = mysqli_stmt_get_result($q);
                $row2 = $rq ? mysqli_fetch_assoc($rq) : null;
                mysqli_stmt_close($q);
                if ($row2) {
                    foreach (maintenance_checklist_rates_for_result($row2, $result) as $rate) { $suggested[] = $rate; }
                }
            }
        }
        mysqli_stmt_close($stmt_i);

        $seen   = array();
        $unique = array();
        foreach ($suggested as $v) {
            if (!isset($seen[$v])) { $seen[$v] = true; $unique[] = $v; }
        }

        return array('ok' => true, 'suggested_rates' => $unique);
    }
}
