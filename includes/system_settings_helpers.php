<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ค่าตั้งระบบที่ปรับได้จากหน้า settings (ตาราง cl_setting: key → value) — ไม่ตั้ง = ใช้ค่าเริ่มต้นใน mn_sys_setting_defs()
 * เรียก: mn_sys_setting($connect, 'near_2y_months')
 */

if (!function_exists('mn_sys_setting_defs')) {
    /** นิยามค่าตั้ง: label, help, min, max, default, unit, group */
    function mn_sys_setting_defs()
    {
        return array(
            'near_2y_months' => array('group' => 'ประเมิน', 'label' => 'แจ้ง "ใกล้ครบ 2 ปี" เมื่ออายุตั้งแต่',
                'help' => 'ใช้กับอายุเครื่องและ MA เปลี่ยนอะไหล่พื้นฐานล่าสุด ช่วงนี้แสดงสีส้มและแนะนำพิจารณา MA (ครบ 2 ปีขึ้นไป ใส่ MA ให้อัตโนมัติเหมือนเดิม)',
                'min' => 12, 'max' => 23, 'default' => 22, 'unit' => 'เดือน', 'format' => 'months'),
            'default_warranty_months' => array('group' => 'ประกัน', 'label' => 'ประกันเครื่องใหม่ (รุ่นที่ยังไม่ได้ตั้ง)',
                'help' => 'ใช้กับรุ่นที่ยังไม่ได้ตั้งในหน้าระยะเวลาประกันรายรุ่น ระบบเลือกค่าในช่องประกันให้ได้เสมอ (ตั้งรายรุ่นไว้แล้วใช้ค่ารายรุ่นก่อน)',
                'min' => 1, 'max' => 120, 'default' => 12, 'unit' => 'เดือน'),
            'repair_warranty_days' => array('group' => 'ประกัน', 'label' => 'ประกันงานซ่อม',
                'help' => 'นับจากวันซ่อมเสร็จของงานที่อนุมัติซ่อม ครอบคลุมทุกอะไหล่ทั้งเครื่อง',
                'min' => 7, 'max' => 730, 'default' => 90, 'unit' => 'วัน'),
            'warranty_packages' => array('group' => 'ประกัน', 'label' => 'แพ็กเกจซื้อประกันเพิ่ม',
                'help' => 'จำนวนเดือน คั่นด้วยจุลภาค เช่น 6,12,24 | ในใบเสนอราคาใช้ชื่อรายการ "ซื้อประกันเพิ่ม 12 เดือน" เมื่ออนุมัติและซ่อมเสร็จ ระบบสร้างประกันให้เอง',
                'min' => 1, 'max' => 120, 'default' => '6,12,24', 'unit' => 'เดือน', 'type' => 'list'),
            'qr_hours' => array('group' => 'รูปประกอบ', 'label' => 'QR อัปรูปจากมือถือ ใช้ได้นาน',
                'help' => 'นับจากตอนสร้าง QR ลิงก์ปิดเองเมื่อบันทึกการประเมิน กดปิดลิงก์ หรือเสนอราคาแล้ว',
                'min' => 1, 'max' => 24, 'default' => 3, 'unit' => 'ชั่วโมง'),
            'photo_max_per_job' => array('group' => 'รูปประกอบ', 'label' => 'จำนวนรูปสูงสุดต่อใบงาน',
                'help' => 'รวมรูปจากคอมและมือถือ',
                'min' => 10, 'max' => 200, 'default' => 40, 'unit' => 'รูป'),
        );
    }
}

if (!function_exists('mn_sys_settings_ensure')) {
    function mn_sys_settings_ensure($connect)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $col = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_setting` ('
            . '`cs_key` VARCHAR(50) ' . $col . ' NOT NULL,'
            . '`cs_value` VARCHAR(255) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cs_by` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cs_at` DATETIME NULL DEFAULT NULL,'
            . 'PRIMARY KEY (`cs_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

/** ค่าที่บันทึกไว้ทั้งหมด → array(key => array(value, by, at)) */
if (!function_exists('mn_sys_settings_saved')) {
    function mn_sys_settings_saved($connect)
    {
        mn_sys_settings_ensure($connect);
        $out = array();
        $res = mysqli_query($connect, 'SELECT cs_key, cs_value, cs_by, cs_at FROM cl_setting');
        while ($res && ($r = mysqli_fetch_assoc($res))) { $out[$r['cs_key']] = array($r['cs_value'], $r['cs_by'], $r['cs_at']); }
        return $out;
    }
}

/** ค่าตั้ง 1 ค่า (ตัวเลขในช่วงที่กำหนด) — อ่านจากฐานครั้งเดียวต่อ request */
if (!function_exists('mn_sys_setting')) {
    function mn_sys_setting($connect, $key)
    {
        static $cache = null;
        $defs = mn_sys_setting_defs();
        if (!isset($defs[$key])) { return null; }
        if ($cache === null) {
            $cache = array();
            if ($connect instanceof mysqli) {
                foreach (mn_sys_settings_saved($connect) as $k => $v) { $cache[$k] = $v[0]; }
            }
        }
        $d = $defs[$key];
        if (isset($d['type']) && $d['type'] === 'list') {
            $list = mn_sys_parse_list(isset($cache[$key]) && $cache[$key] !== '' ? $cache[$key] : $d['default'], $d['min'], $d['max']);
            return $list ? $list : mn_sys_parse_list($d['default'], $d['min'], $d['max']);
        }
        if (!isset($cache[$key]) || $cache[$key] === '' || !is_numeric($cache[$key])) { return $d['default']; }
        return max($d['min'], min($d['max'], (int) $cache[$key]));
    }
}

/** บันทึกค่าตั้งจากฟอร์ม → array(ok, error) */
if (!function_exists('mn_sys_settings_save')) {
    function mn_sys_settings_save($connect, array $values, $by)
    {
        mn_sys_settings_ensure($connect);
        $defs = mn_sys_setting_defs();
        $stmt = mysqli_prepare($connect,
            'INSERT INTO cl_setting (cs_key, cs_value, cs_by, cs_at) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE cs_value = VALUES(cs_value), cs_by = VALUES(cs_by), cs_at = VALUES(cs_at)');
        if (!$stmt) { return array(false, 'บันทึกไม่สำเร็จ'); }
        $who = mb_substr(trim((string) $by), 0, 100, 'UTF-8');
        $now = date('Y-m-d H:i:s');
        foreach ($defs as $key => $d) {
            if (isset($d['type']) && $d['type'] === 'list') {
                if (!isset($values[$key])) { continue; }
                $list = mn_sys_parse_list($values[$key], $d['min'], $d['max']);
                if (!$list) {
                    mysqli_stmt_close($stmt);
                    return array(false, $d['label'] . ' ใส่ตัวเลข ' . $d['min'] . '–' . $d['max'] . ' คั่นด้วยจุลภาค');
                }
                $val = implode(',', $list);
                $k = $key;
                mysqli_stmt_bind_param($stmt, 'ssss', $k, $val, $who, $now);
                mysqli_stmt_execute($stmt);
                continue;
            }
            if (!isset($values[$key]) || !is_numeric($values[$key])) { continue; }
            $v = (int) $values[$key];
            if ($v < $d['min'] || $v > $d['max']) {
                mysqli_stmt_close($stmt);
                return array(false, $d['label'] . ' ต้องอยู่ระหว่าง ' . $d['min'] . '–' . $d['max'] . ' ' . $d['unit']);
            }
            $val = (string) $v;
            $k = $key;
            mysqli_stmt_bind_param($stmt, 'ssss', $k, $val, $who, $now);
            mysqli_stmt_execute($stmt);
        }
        mysqli_stmt_close($stmt);
        return array(true, '');
    }
}

/** "6, 12,24" → array(6, 12, 24) (กรองค่านอกช่วง ตัดซ้ำ เรียงน้อยไปมาก) */
if (!function_exists('mn_sys_parse_list')) {
    function mn_sys_parse_list($raw, $min, $max)
    {
        $out = array();
        foreach (preg_split('/[\s,]+/', (string) $raw) as $p) {
            if ($p === '' || !ctype_digit($p)) { continue; }
            $v = (int) $p;
            if ($v >= $min && $v <= $max) { $out[$v] = $v; }
        }
        ksort($out);
        return array_values($out);
    }
}

/** แสดงจำนวนเดือนแบบ "1 ปี 10 เดือน" */
if (!function_exists('mn_sys_months_text')) {
    function mn_sys_months_text($m)
    {
        $m = (int) $m;
        $y = (int) floor($m / 12);
        $r = $m % 12;
        return trim(($y ? $y . ' ปี ' : '') . ($r ? $r . ' เดือน' : ''));
    }
}
