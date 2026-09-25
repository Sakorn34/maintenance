<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * คิวรายการที่บันทึก tracking จากหน้า `status_logistic` ลงตาราง `sendtracking_history` — หน้า `wait_sendtracking` ใช้ดึงลิงก์ส่งลูกค้า
 * ลบแถวที่ไม่มีการอัปเดตเกิน 90 วันอัตโนมัติ (อิง `sth_updated_at`)
 *
 * องค์ประกอบหลัก:
 * - `maintenance_sendtracking_history_purge_rows_older_than_days()` — DELETE แถวเก่า
 * - `maintenance_sendtracking_history_upsert_row()` — INSERT / ON DUPLICATE KEY UPDATE ตาม `(sth_kind, sth_target_id)`
 * - `maintenance_sendtracking_history_fetch_recent_rows()` — รายการล่าสุดสำหรับหน้า `wait_sendtracking`
 * - `maintenance_sendtracking_history_delete_by_id()` — ลบแถวคิวหลัง staff คัดลอกข้อความส่งลูกค้าแล้ว
 * - `maintenance_sendtracking_history_status_logistic_save_success_message()` — ข้อความ modal หลังบันทึก tracking จาก `status_logistic` สำเร็จ
 *
 * Flow:
 * `update_logistic.php` / `update_logistic_repair.php` / `update_edit_spare.php` หลัง UPDATE/INSERT สำเร็จ → เรียก purge แล้ว upsert
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_sendtracking_history_purge_rows_older_than_days')) {
    /**
     * Purpose:
     * ลบแถว `sendtracking_history` ที่ `sth_updated_at` เก่ากว่า N วัน — เรียกทุกครั้งหลังบันทึก tracking เพื่อไม่ให้ตารางโตไม่จำกัด
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $days: จำนวนวัน (default 90, clamp 1–365)
     *
     * Return value:
     * - int จำนวนแถวที่ DELETE หรือ 0 เมื่อ error / ไม่มีตาราง
     */
    function maintenance_sendtracking_history_purge_rows_older_than_days($connect, $days = 90)
    {
        if (!($connect instanceof mysqli)) {
            return 0;
        }
        $d = max(1, min(365, (int) $days));
        $sql = 'DELETE FROM sendtracking_history WHERE sth_updated_at < DATE_SUB(NOW(), INTERVAL ' . $d . ' DAY)';
        if (!mysqli_query($connect, $sql)) {
            return 0;
        }

        return (int) mysqli_affected_rows($connect);
    }
}

if (!function_exists('maintenance_sendtracking_history_upsert_row')) {
    /**
     * Purpose:
     * บันทึกหรืออัปเดตคิวส่ง tracking — `sth_kind` + `sth_target_id` ไม่ซ้ำ (spare ใช้ `tsp_id`, repair ใช้ `trp_id`)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $kind: `'spare'` หรือ `'repair'`
     * - int $target_id: `tsp_id` (spare) หรือ `trp_id` (repair)
     * - int $ts_id: `ts_id` ใบงานหลัก
     * - int $trp_id: `trp_id` สำหรับลิงก์ความคืบหน้า (spare ใส่ `tsp_trp_id`; repair เท่ากับ target)
     * - string $tracking: เลข tracking
     * - string $logistics: ขนส่ง
     * - string $send_date: วันที่ส่ง (รูปแบบจากฟอร์ม)
     * - string $sendby: ผู้บันทึก
     *
     * Return value:
     * - bool true เมื่อ execute สำเร็จ
     */
    function maintenance_sendtracking_history_upsert_row(
        $connect,
        $kind,
        $target_id,
        $ts_id,
        $trp_id,
        $tracking,
        $logistics,
        $send_date,
        $sendby
    ) {
        if (!($connect instanceof mysqli)) {
            return false;
        }
        $kind = strtolower(trim((string) $kind));
        if ($kind !== 'spare' && $kind !== 'repair') {
            return false;
        }
        $target_id = (int) $target_id;
        if ($target_id < 1) {
            return false;
        }
        $ts_id = max(0, (int) $ts_id);
        $trp_id = max(0, (int) $trp_id);

        maintenance_sendtracking_history_purge_rows_older_than_days($connect, 90);

        $sql = 'INSERT INTO sendtracking_history ('
            . 'sth_kind, sth_target_id, sth_ts_id, sth_trp_id, sth_tracking, sth_logistics, sth_send_date, sth_sendby, sth_created_at, sth_updated_at'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE'
            . ' sth_ts_id = VALUES(sth_ts_id),'
            . ' sth_trp_id = VALUES(sth_trp_id),'
            . ' sth_tracking = VALUES(sth_tracking),'
            . ' sth_logistics = VALUES(sth_logistics),'
            . ' sth_send_date = VALUES(sth_send_date),'
            . ' sth_sendby = VALUES(sth_sendby),'
            . ' sth_updated_at = NOW()';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return false;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'siiissss',
            $kind,
            $target_id,
            $ts_id,
            $trp_id,
            $tracking,
            $logistics,
            $send_date,
            $sendby
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }
}

if (!function_exists('maintenance_sendtracking_history_fetch_recent_rows')) {
    /**
     * Purpose:
     * ดึงรายการคิวล่าสุดจาก `sendtracking_history` หลัง purge แถวที่ไม่อัปเดตเกิน 90 วัน
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $limit: จำนวนแถวสูงสุด (clamp 1–500, default 200)
     *
     * Return value:
     * - array<int, array<string, mixed>> เมื่อสำเร็จ (อาจว่าง)
     * - false เมื่อ query ล้มเหลว (เช่น ยังไม่มีตารางหรือสิทธิ์ไม่พอ)
     */
    function maintenance_sendtracking_history_fetch_recent_rows($connect, $limit = 200)
    {
        if (!($connect instanceof mysqli)) {
            return false;
        }
        maintenance_sendtracking_history_purge_rows_older_than_days($connect, 90);
        $lim = max(1, min(500, (int) $limit));
        $sql = 'SELECT sth_id, sth_kind, sth_target_id, sth_ts_id, sth_trp_id, sth_tracking, sth_logistics, sth_send_date, sth_sendby, sth_created_at, sth_updated_at '
            . 'FROM sendtracking_history ORDER BY sth_updated_at DESC LIMIT ' . $lim;
        $res = mysqli_query($connect, $sql);
        if ($res === false) {
            return false;
        }
        $out = array();
        while ($row = mysqli_fetch_assoc($res)) {
            $out[] = $row;
        }
        mysqli_free_result($res);

        return $out;
    }
}

if (!function_exists('maintenance_sendtracking_history_delete_by_id')) {
    /**
     * Purpose:
     * ลบแถวคิว `sendtracking_history` หนึ่งรายการ — เรียกหลังคัดลอกข้อความส่งลูกค้าสำเร็จจากหน้า `wait_sendtracking` เพื่อไม่แสดงซ้ำ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $sth_id: `sendtracking_history.sth_id`
     *
     * Return value:
     * - bool true เมื่อ DELETE อย่างน้อย 1 แถว
     */
    function maintenance_sendtracking_history_delete_by_id($connect, $sth_id)
    {
        if (!($connect instanceof mysqli)) {
            return false;
        }
        $sth_id = (int) $sth_id;
        if ($sth_id < 1) {
            return false;
        }
        $stmt = mysqli_prepare($connect, 'DELETE FROM sendtracking_history WHERE sth_id = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $sth_id);
        $ok = mysqli_stmt_execute($stmt);
        $n = $ok ? (int) mysqli_stmt_affected_rows($stmt) : 0;
        mysqli_stmt_close($stmt);

        return $ok && $n > 0;
    }
}

if (!function_exists('maintenance_sendtracking_history_status_logistic_save_success_message')) {
    /**
     * Purpose:
     * ข้อความหลายบรรทัดสำหรับ `mnModalAlert` หลังบันทึกเลข tracking จากหน้า `status_logistic` สำเร็จ — แนะนำให้ไปหน้าแรกที่ลิงก์ลัด «รอส่งเลขพัสดุลูกค้า» เพื่อคัดลอกข้อความส่งลูกค้า
     *
     * Parameters สำคัญ:
     * - ไม่มี
     *
     * Return value:
     * - string ข้อความ UTF-8 (มี `\n\n` คั่นย่อหน้า — `mn-modal.css` ใช้ `white-space: pre-wrap`)
     */
    function maintenance_sendtracking_history_status_logistic_save_success_message()
    {
        return "บันทึกสำเร็จ\n\nท่านสามารถเข้าที่แท็บ Home --> รอส่งเลขพัสดุลูกค้า เพื่อคัดลอกข้อความพร้อมลิงก์ tracking ส่งให้ลูกค้า";
    }
}
