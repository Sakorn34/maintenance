<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เก็บ "ผู้บันทึก + เวลา" ของขั้นที่ตาราง transac_repair ไม่มีช่องชื่อผู้ทำ — ใบเสนอราคา (qv), การอนุมัติ (firm), Comment (comment)
 * ตาราง cl_job_stamp (หนึ่งแถวต่อรายการซ่อมต่อขั้น เก็บครั้งล่าสุด) — สร้างเองเมื่อใช้ครั้งแรก
 * ใช้แสดงในหน้า detail_job (ป้ายวันที่/ผู้บันทึกข้างหัวกรอบ) งานที่บันทึกก่อนมีตารางนี้จะยังไม่มีชื่อ
 */

if (!function_exists('mn_job_stamp_ensure')) {
    function mn_job_stamp_ensure($connect)
    {
        static $done = false;
        if ($done || !($connect instanceof mysqli)) { return; }
        $done = true;
        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_job_stamp` ('
            . '`js_trp_id` INT NOT NULL,'
            . '`js_step` VARCHAR(20) NOT NULL,'
            . '`js_by` VARCHAR(100) NOT NULL DEFAULT \'\','
            . '`js_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`js_trp_id`, `js_step`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

/** ชื่อพนักงานที่ล็อกอินอยู่ (ชื่อเดียวกับช่อง "ผู้ส่ง/ผู้รับ" ในหน้าอื่น) */
if (!function_exists('mn_job_stamp_who')) {
    function mn_job_stamp_who($connect)
    {
        require_once __DIR__ . '/bootstrap_logistic_recorder.php';
        $name = maintenance_logistic_recorder_resolve_staff_name($connect instanceof mysqli ? $connect : null);
        return mb_substr(trim((string) $name), 0, 100, 'UTF-8');
    }
}

/** บันทึกผู้ทำ + เวลา ของขั้น $step ให้รายการซ่อมทุกตัวใน $trp_ids */
if (!function_exists('mn_job_stamp_set')) {
    function mn_job_stamp_set($connect, array $trp_ids, $step)
    {
        if (!($connect instanceof mysqli)) { return; }
        $step = substr(preg_replace('/[^a-z_]/', '', (string) $step), 0, 20);
        if ($step === '') { return; }
        mn_job_stamp_ensure($connect);
        $who = mn_job_stamp_who($connect);
        $now = date('Y-m-d H:i:s');
        $stmt = mysqli_prepare($connect,
            'INSERT INTO cl_job_stamp (js_trp_id, js_step, js_by, js_at) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE js_by = VALUES(js_by), js_at = VALUES(js_at)');
        if (!$stmt) { return; }
        foreach (array_unique(array_map('intval', $trp_ids)) as $id) {
            if ($id < 1) { continue; }
            mysqli_stmt_bind_param($stmt, 'isss', $id, $step, $who, $now);
            mysqli_stmt_execute($stmt);
        }
        mysqli_stmt_close($stmt);
    }
}

/** ผู้บันทึกของรายการซ่อม → array(step => array('by' => '', 'at' => 'Y-m-d H:i:s')) */
if (!function_exists('mn_job_stamp_fetch')) {
    function mn_job_stamp_fetch($connect, $trp_id)
    {
        $out = array();
        $id = (int) $trp_id;
        if ($id < 1 || !($connect instanceof mysqli)) { return $out; }
        mn_job_stamp_ensure($connect);
        $stmt = mysqli_prepare($connect, 'SELECT js_step, js_by, js_at FROM cl_job_stamp WHERE js_trp_id = ?');
        if (!$stmt) { return $out; }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[(string) $r['js_step']] = array('by' => (string) $r['js_by'], 'at' => (string) $r['js_at']);
        }
        mysqli_stmt_close($stmt);
        return $out;
    }
}
