<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper ระบบ Checklist ประเมินอาการรายรุ่น — สร้างตาราง auto, ดึงข้อตรวจ, บันทึกผล, แนะนำรายการ
 *
 * ตาราง: cl_symptom, cl_item, cl_job_header, cl_job_item
 * ทุก ensure_tables เรียกครั้งแรกในทุก request ที่ใช้ checklist เพื่อ auto-migrate
 */

if (!function_exists('maintenance_checklist_product_supported')) {
    function maintenance_checklist_product_supported($product)
    {
        $supported = array('bitVisitor Plus');
        return in_array(trim((string) $product), $supported, true);
    }
}

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

if (!function_exists('maintenance_checklist_ensure_tables')) {
    function maintenance_checklist_ensure_tables($connect)
    {
        $col = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_symptom` ('
            . '`cs_id` INT NOT NULL AUTO_INCREMENT,'
            . '`cs_name` VARCHAR(100) ' . $col . ' NOT NULL,'
            . '`cs_order` INT NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`cs_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_item` ('
            . '`ci_id` INT NOT NULL AUTO_INCREMENT,'
            . '`ci_product` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_symptom_id` INT NOT NULL DEFAULT 0,'
            . '`ci_step` INT NOT NULL DEFAULT 0,'
            . '`ci_question` VARCHAR(300) ' . $col . ' NOT NULL,'
            . '`ci_rate_on_fail` VARCHAR(200) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_hint` VARCHAR(300) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_unit` VARCHAR(20) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`ci_val_min` DECIMAL(7,2) NULL DEFAULT NULL,'
            . '`ci_val_max` DECIMAL(7,2) NULL DEFAULT NULL,'
            . '`ci_opts` TEXT ' . $col . ' NULL DEFAULT NULL,'
            . '`ci_auto_source` VARCHAR(50) ' . $col . ' NULL DEFAULT NULL,'
            . '`ci_enabled` TINYINT(1) NOT NULL DEFAULT 1,'
            . 'PRIMARY KEY (`ci_id`),'
            . 'INDEX `idx_product_symptom` (`ci_product`, `ci_symptom_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
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
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // migrate: widen cji_result to VARCHAR(100) if still VARCHAR(10)
        $r_res = mysqli_query($connect,
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.columns"
            . " WHERE table_schema=DATABASE() AND table_name='cl_job_item' AND column_name='cji_result'"
        );
        if ($r_res) {
            $row_res = mysqli_fetch_assoc($r_res);
            if ($row_res && (int) $row_res['len'] < 100) {
                mysqli_query($connect,
                    "ALTER TABLE cl_job_item MODIFY COLUMN cji_result VARCHAR(100)"
                    . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''"
                );
            }
        }

        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_job_item` ('
            . '`cji_id` INT NOT NULL AUTO_INCREMENT,'
            . '`cji_trp_id` INT NOT NULL,'
            . '`cji_item_id` INT NOT NULL,'
            . '`cji_result` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cji_note` VARCHAR(200) ' . $col . ' NOT NULL DEFAULT \'\','
            . 'PRIMARY KEY (`cji_id`),'
            . 'UNIQUE KEY `uq_cji` (`cji_trp_id`, `cji_item_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // migrate: add ci_unit column to cl_item if missing
        $r_unit = mysqli_query($connect,
            "SELECT COUNT(*) AS n FROM information_schema.columns"
            . " WHERE table_schema=DATABASE() AND table_name='cl_item' AND column_name='ci_unit'"
        );
        if ($r_unit && (int) mysqli_fetch_assoc($r_unit)['n'] === 0) {
            mysqli_query($connect,
                "ALTER TABLE cl_item ADD COLUMN ci_unit VARCHAR(20)"
                . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER ci_hint"
            );
        }

        // migrate: add ci_val_min / ci_val_max columns
        $r_vmin = mysqli_query($connect,
            "SELECT COUNT(*) AS n FROM information_schema.columns"
            . " WHERE table_schema=DATABASE() AND table_name='cl_item' AND column_name='ci_val_min'"
        );
        if ($r_vmin && (int) mysqli_fetch_assoc($r_vmin)['n'] === 0) {
            mysqli_query($connect,
                "ALTER TABLE cl_item"
                . " ADD COLUMN ci_val_min DECIMAL(7,2) NULL DEFAULT NULL AFTER ci_unit,"
                . " ADD COLUMN ci_val_max DECIMAL(7,2) NULL DEFAULT NULL AFTER ci_val_min"
            );
        }

        // migrate: add ci_opts column
        $r_opts = mysqli_query($connect,
            "SELECT COUNT(*) AS n FROM information_schema.columns"
            . " WHERE table_schema=DATABASE() AND table_name='cl_item' AND column_name='ci_opts'"
        );
        if ($r_opts && (int) mysqli_fetch_assoc($r_opts)['n'] === 0) {
            mysqli_query($connect,
                "ALTER TABLE cl_item ADD COLUMN ci_opts TEXT"
                . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER ci_val_max"
            );
        }

        // migrate: add ci_auto_source column
        $r_auto = mysqli_query($connect,
            "SELECT COUNT(*) AS n FROM information_schema.columns"
            . " WHERE table_schema=DATABASE() AND table_name='cl_item' AND column_name='ci_auto_source'"
        );
        if ($r_auto && (int) mysqli_fetch_assoc($r_auto)['n'] === 0) {
            mysqli_query($connect,
                "ALTER TABLE cl_item ADD COLUMN ci_auto_source VARCHAR(50)"
                . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER ci_opts"
            );
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

        // seed symptoms ถ้ายังว่าง
        $r = mysqli_query($connect, 'SELECT COUNT(*) AS n FROM cl_symptom');
        $cnt = $r ? (int) mysqli_fetch_assoc($r)['n'] : 1;
        if ($cnt === 0) {
            mysqli_query($connect,
                'INSERT INTO cl_symptom (cs_id, cs_name, cs_order) VALUES'
                . " (1,'เปิดเครื่องไม่ติด',1),"
                . " (2,'จอ / ทัชสกรีน',2),"
                . " (3,'อ่านบัตรไม่ได้',3),"
                . " (4,'กล้องมีปัญหา',4),"
                . " (5,'ค้าง / รีสตาร์ทเอง',5),"
                . " (6,'MA (บำรุงรักษา)',6),"
                . " (7,'อัพเกรด / ติดตั้ง',7),"
                . " (8,'อื่นๆ',8)"
            );
        }

        // seed cl_item สำหรับ bitVisitor Plus ถ้ายังว่าง
        $r2 = mysqli_query($connect, "SELECT COUNT(*) AS n FROM cl_item WHERE ci_product='bitVisitor Plus'");
        $cnt2 = $r2 ? (int) mysqli_fetch_assoc($r2)['n'] : 1;
        if ($cnt2 === 0) {
            mysqli_query($connect,
                "INSERT INTO cl_item (ci_id,ci_product,ci_symptom_id,ci_step,ci_question,ci_rate_on_fail,ci_hint) VALUES"
                . " (1,'bitVisitor Plus',1,1,'Adapter 12V 5A ทำงานปกติ','Adapter 12V 5A',''),"
                . " (2,'bitVisitor Plus',1,2,'Jack Power 12VDC ไม่หลวม ไม่เสีย','Jack Power 12VDC',''),"
                . " (3,'bitVisitor Plus',1,3,'Switch On-Off ทำงานปกติ','Switch On-Off',''),"
                . " (4,'bitVisitor Plus',1,4,'IC Main Switch ทำงาน','IC Main Switch',''),"
                . " (5,'bitVisitor Plus',1,5,'IC Step down 5VDC ทำงาน','IC Step down 5VDC',''),"
                . " (6,'bitVisitor Plus',1,6,'Main Board (PI) บูตปกติ','Main Board (PI)',''),"
                . " (7,'bitVisitor Plus',2,1,'สาย HDMI ไม่หลวม ไม่เสีย','สาย HDMI',''),"
                . " (8,'bitVisitor Plus',2,2,'HDMI ข้องอ 270 ทำงาน','HDMI ข้องอ 270',''),"
                . " (9,'bitVisitor Plus',2,3,'หน้าจอ 7 นิ้ว แสดงผลปกติ','หน้าจอ 7 นิ้ว',''),"
                . " (10,'bitVisitor Plus',2,4,'IC BackLight จอ LCD 7\" ทำงาน','IC BackLight จอ LCD 7\"',''),"
                . " (11,'bitVisitor Plus',2,5,'แผ่น Acrylic ไม่แตก ไม่รอย','แผ่น Acrylic รุ่น Plus',''),"
                . " (12,'bitVisitor Plus',3,1,'Smart Card reader อ่านบัตรได้','Smart Card (ซื้อใหม่)',''),"
                . " (13,'bitVisitor Plus',3,2,'Port USB ทำงานปกติ','Port USB',''),"
                . " (14,'bitVisitor Plus',3,3,'Scanner 2D อ่าน QR Code ได้','Scanner 2D (ซื้อใหม่)',''),"
                . " (15,'bitVisitor Plus',4,1,'ทำความสะอาดเลนส์กล้อง','ทำความสะอาดเลนส์กล้อง',''),"
                . " (16,'bitVisitor Plus',4,2,'ปรับ Focus กล้องได้คมชัด','ปรับ Focus กล้อง',''),"
                . " (17,'bitVisitor Plus',4,3,'กล้อง FeelTek 720P ทำงาน','กล้อง FeelTek 720P-Black (TH)',''),"
                . " (18,'bitVisitor Plus',4,4,'Chip HUB FE2.1 ทำงานปกติ','Chip HUB FE2.1',''),"
                . " (19,'bitVisitor Plus',5,1,'Micro SD ไม่เสียหาย อ่านได้ปกติ','Micro SD',''),"
                . " (20,'bitVisitor Plus',5,2,'Write image ใหม่ (เมื่อ SD หรือ OS เสีย)','Write image ใหม่','ทำเมื่อ Micro SD เสียหายหรือ OS พัง'),"
                . " (21,'bitVisitor Plus',5,3,'Crystal 12MHz ทำงาน','Crystal 12MHz',''),"
                . " (22,'bitVisitor Plus',5,4,'IC Real Time Clock ทำงาน','IC Real Time Clock',''),"
                . " (23,'bitVisitor Plus',5,5,'Main Board (PI) ทำงานเสถียร','Main Board (PI)',''),"
                . " (24,'bitVisitor Plus',6,1,'MA ตรวจเช็คและทำความสะอาด','MA ตรวจเช็ค ทำความสะอาด',''),"
                . " (25,'bitVisitor Plus',6,2,'MA เปลี่ยนอะไหล่พื้นฐาน','MA เปลียนอะไหล่พื้นฐาน','ถ่าน RTC, Choke filter, Connector ฯลฯ'),"
                . " (26,'bitVisitor Plus',6,3,'Battery Backup RTC 3.2V มีประจุ','Battery Backup RTC 3.2V',''),"
                . " (27,'bitVisitor Plus',7,1,'Write image ใหม่ตามเวอร์ชันที่กำหนด','Write image ใหม่',''),"
                . " (28,'bitVisitor Plus',7,2,'แก้ไข File Config ตามข้อกำหนด','แก้ไข File Config',''),"
                . " (29,'bitVisitor Plus',7,3,'เปลี่ยน Micro SD ใหม่ (ถ้าของเก่าเสีย)','Micro SD','')"
            );
        }

        // seed/migrate การตรวจพื้นฐาน 8 ข้อ พร้อม ci_opts + ci_auto_source
        // trigger: นับ item ที่มี ci_opts หรือ ci_auto_source set — ถ้า < 8 ให้ re-seed
        $r3 = mysqli_query($connect,
            "SELECT COUNT(*) AS n FROM cl_item"
            . " WHERE ci_product='bitVisitor Plus' AND ci_symptom_id=0"
            . " AND (ci_opts IS NOT NULL OR ci_auto_source IS NOT NULL)"
        );
        $cnt3 = $r3 ? (int) mysqli_fetch_assoc($r3)['n'] : 8;
        if ($cnt3 < 8) {
            mysqli_query($connect,
                "DELETE cji FROM cl_job_item cji"
                . " INNER JOIN cl_item ci ON cji.cji_item_id=ci.ci_id"
                . " WHERE ci.ci_product='bitVisitor Plus' AND ci.ci_symptom_id=0"
            );
            mysqli_query($connect, "DELETE FROM cl_job_header WHERE ch_symptom_id=0");
            mysqli_query($connect, "DELETE FROM cl_item WHERE ci_product='bitVisitor Plus' AND ci_symptom_id=0");
            mysqli_query($connect,
                "INSERT INTO cl_item"
                . " (ci_product,ci_symptom_id,ci_step,ci_question,ci_rate_on_fail,ci_hint,ci_unit,ci_val_min,ci_val_max,ci_opts,ci_auto_source)"
                . " VALUES"
                . " ('bitVisitor Plus',0,1,'TouchScreen ตอบสนองครบทุกจุดไหม','หน้าจอ 7 นิ้ว','ทดสอบ 9 จุดตามกริด','',NULL,NULL,"
                .   "'ใช้งานได้ปกติ|กดไม่ติดทั้งจอ|กดไม่ติดบางจุด:note',NULL),"
                . " ('bitVisitor Plus',0,2,'จอแสดงผลปกติ ไม่มีเส้น/รอย/จุดดำไหม','หน้าจอ 7 นิ้ว','ดูที่หน้าจอขณะแสดงผล','',NULL,NULL,"
                .   "'ปกติ|มีเส้น:note|จอมืด/backlight เสีย|จอแตก',NULL),"
                . " ('bitVisitor Plus',0,3,'ค่าความสว่างจอ (LUX) อยู่ในช่วง 180–420 ไหม','หน้าจอ 7 นิ้ว','วัดด้วย LUX meter จ่อกลางหน้าจอ','LUX',180,420,"
                .   "'ไม่สามารถวัดได้',NULL),"
                . " ('bitVisitor Plus',0,4,'HUB ใช้งานได้ครบทุกช่องไหม','แผง HUB','ทดสอบ flash drive, ID card, keyboard','',NULL,NULL,"
                .   "'ใช้ได้ทุกช่อง|เสียบางช่อง:note|เสียทั้งหมด',NULL),"
                . " ('bitVisitor Plus',0,5,'อายุเครื่องน้อยกว่า 2 ปีไหม','','ดูวันที่ซื้อ / สติ๊กเกอร์ตัวเครื่อง','',NULL,NULL,"
                .   "NULL,'buy_date'),"
                . " ('bitVisitor Plus',0,6,'ประวัติ MA อะไหล่พื้นฐานล่าสุด ยังไม่เกิน 2 ปีไหม','MA เปลียนอะไหล่พื้นฐาน','ดูประวัติ MA','',NULL,NULL,"
                .   "NULL,'last_ma'),"
                . " ('bitVisitor Plus',0,7,'กล้องเปิดติดไหม','กล้อง Logitech Brio100-Graphite','เปิด app กล้อง','',NULL,NULL,"
                .   "'เปิดติดปกติ|เปิดติดแต่ช้า/หน่วง|เปิดไม่ติด',NULL),"
                . " ('bitVisitor Plus',0,8,'ภาพกล้องคมชัดไหม','ทำความสะอาดเลนส์กล้อง','ถ่ายภาพทดสอบ','',NULL,NULL,"
                .   "'ภาพคมชัดปกติ|ภาพมัว/ฝ้า|ภาพผิดสี/ผิดปกติ',NULL)"
            );
        }
    }
}

if (!function_exists('maintenance_checklist_fetch_symptoms')) {
    function maintenance_checklist_fetch_symptoms($connect)
    {
        $r = mysqli_query($connect, 'SELECT cs_id, cs_name FROM cl_symptom ORDER BY cs_order ASC, cs_id ASC');
        $out = array();
        if ($r) {
            while ($row = mysqli_fetch_assoc($r)) { $out[] = $row; }
        }
        return $out;
    }
}

if (!function_exists('maintenance_checklist_fetch_items')) {
    function maintenance_checklist_fetch_items($connect, $product, $symptom_id)
    {
        $sql = 'SELECT ci_id, ci_step, ci_question, ci_rate_on_fail, ci_hint,'
            . ' ci_unit, ci_val_min, ci_val_max, ci_opts, ci_auto_source'
            . ' FROM cl_item'
            . ' WHERE ci_product = ? AND ci_symptom_id = ? AND ci_enabled = 1'
            . ' ORDER BY ci_step ASC';
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return array(); }
        $p = (string) $product;
        $s = (int) $symptom_id;
        mysqli_stmt_bind_param($stmt, 'si', $p, $s);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) { $out[] = $row; }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
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
            $result  = substr(trim((string) ($data['result'] ?? '')), 0, 100);
            $note    = substr(trim((string) ($data['note']   ?? '')), 0, 200);

            if ($result === '') { continue; }

            $item_id_v = $item_id;
            mysqli_stmt_bind_param($stmt_i, 'iiss', $t, $item_id_v, $result, $note);
            mysqli_stmt_execute($stmt_i);

            // ตรวจสอบว่า result นี้ควร suggest rate ไหม
            $r2 = mysqli_query($connect,
                "SELECT ci_rate_on_fail, ci_opts, ci_val_min FROM cl_item WHERE ci_id=$item_id LIMIT 1"
            );
            if ($r2) {
                $row2 = mysqli_fetch_assoc($r2);
                mysqli_free_result($r2);
                if ($row2 && $row2['ci_rate_on_fail'] !== '') {
                    $has_opts      = ($row2['ci_opts'] !== null && $row2['ci_opts'] !== '');
                    $has_threshold = ($row2['ci_val_min'] !== null);
                    $is_bad = false;

                    if ($has_opts && !$has_threshold) {
                        // custom buttons: ตัวเลือกแรก = ดี, ที่เหลือ = แนะนำ rate
                        $opts_parts = explode('|', $row2['ci_opts']);
                        $first_opt  = str_replace(':note', '', trim($opts_parts[0]));
                        $is_bad = ($result !== $first_opt && $result !== 'pass' && $result !== 'na');
                    } else {
                        // traditional หรือ auto-calc (LUX)
                        $is_bad = ($result === 'fail');
                    }

                    if ($is_bad) {
                        $suggested[] = $row2['ci_rate_on_fail'];
                    }
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
