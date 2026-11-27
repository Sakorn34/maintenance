<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper ร่วมสำหรับกรอก QV/ราคาเสนอซ่อม — ดึงแถว `transac_repair` JOIN `transac` ตาม `trp_id` และบันทึกผ่าน prepared statement (เทียบ `old_system/update_offer_price.php`)
 *
 * Flow:
 * `maintenance_offer_price_qv_fetch_bundle` ใช้ในหน้า `edit_offer_price`; `maintenance_offer_price_qv_apply_from_get` ใช้ใน `update_offer_price` (รวมบันทึกจาก `detail_job`) — บันทึกแล้วซิงก์ QV/ราคาไปรายการซ่อมอื่นในเลขที่งานเดียวกัน (`trp_ts_id`)
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_offer_price_qv_fetch_bundle')) {
    /**
     * Purpose:
     * ดึงแถวซ่อม + ใบงานสำหรับหน้ากรอกเสนอราคา (prepared)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_id: รหัส `transac_repair.trp_id`
     *
     * Return value:
     * - array<string, mixed>|null
     */
    function maintenance_offer_price_qv_fetch_bundle($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) {
            return null;
        }

        $cols = 'tr.trp_id, tr.trp_ts_id, tr.trp_rate_date, tr.trp_product, tr.trp_sn, tr.trp_repair_age, tr.trp_insurance, '
            . 'tr.trp_repair_technician, tr.trp_qv_number, tr.trp_qv_date, tr.trp_qv_send, tr.trp_qv_price, '
            . 'tr.trp_repair_no1, tr.trp_repair_no2, tr.trp_repair_no3, tr.trp_repair_no4, tr.trp_repair_no5, '
            . 'tr.trp_repair_no6, tr.trp_repair_no7, tr.trp_repair_no8, tr.trp_repair_no9, tr.trp_repair_no10, '
            . 'tr.trp_clean, tr.trp_repeat_solder, tr.trp_spray, tr.trp_fw, tr.trp_repair_remarks, tr.trp_status_job, '
            . 'ts.ts_id, ts.ts_mtn_number, ts.ts_enduser_com, ts.ts_security_com, ts.ts_report_name, ts.ts_report_tel, '
            . 'ts.ts_report_line, ts.ts_address';

        $sql = 'SELECT ' . $cols . ' FROM transac_repair tr INNER JOIN transac ts ON ts.ts_id = tr.trp_ts_id WHERE tr.trp_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('maintenance_offer_price_qv_sync_siblings_same_job')) {
    /**
     * Purpose:
     * คัดลอกค่า QV/ราคาและเลื่อนสถานะไปรายการซ่อมอื่นในใบงานเดียวกัน (`trp_ts_id`) ที่ยัง `รอเสนอราคา`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $trp_ts_id: `transac_repair.trp_ts_id`
     * - int $exclude_trp_id: `trp_id` ที่บันทึกจากฟอร์ม (ไม่ update ซ้ำ)
     * - string $num: `trp_qv_number`
     * - string $date: `trp_qv_date`
     * - string $send: `trp_qv_send`
     * - string $price: `trp_qv_price`
     *
     * Return value:
     * - int จำนวนแถวที่อัปเดต (ไม่รวมแถวหลัก)
     */
    function maintenance_offer_price_qv_sync_siblings_same_job(
        $connect,
        $trp_ts_id,
        $exclude_trp_id,
        $num,
        $date,
        $send,
        $price
    ) {
        $trp_ts_id = (int) $trp_ts_id;
        $exclude_trp_id = (int) $exclude_trp_id;
        if ($trp_ts_id < 1 || $exclude_trp_id < 1 || !($connect instanceof mysqli)) {
            return 0;
        }

        require_once __DIR__ . '/status_offer_price_helpers.php';
        $status_from = maintenance_status_offer_price_status_literal();
        $status_to = 'รออนุมัติซ่อม';

        $sql = 'UPDATE transac_repair SET trp_qv_number = ?, trp_qv_date = ?, trp_qv_send = ?, trp_qv_price = ?, trp_status_job = ? '
            . 'WHERE trp_ts_id = ? AND trp_id <> ? AND trp_status_job = ?';

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return 0;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'sssssiis',
            $num,
            $date,
            $send,
            $price,
            $status_to,
            $trp_ts_id,
            $exclude_trp_id,
            $status_from
        );
        $ok = mysqli_stmt_execute($stmt);
        $affected = $ok ? mysqli_stmt_affected_rows($stmt) : 0;
        mysqli_stmt_close($stmt);

        return $affected > 0 ? (int) $affected : 0;
    }
}

if (!function_exists('maintenance_offer_price_qv_apply_from_get')) {
    /**
     * Purpose:
     * อัปเดตฟิลด์ QV/ราคาและตั้ง `trp_status_job = รออนุมัติซ่อม` ตาม GET — รายการซ่อมอื่นในเลขที่งานเดียวกันที่ยัง `รอเสนอราคา` จะได้ค่าเดียวกันอัตโนมัติ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $get: มักเป็น `$_GET` คีย์ `id_trp`, `trp_qv_number`, `trp_qv_date`, `trp_qv_send`, `trp_qv_price`
     *
     * Return value:
     * - array{ok: bool, message: string, synced_siblings?: int}
     */
    function maintenance_offer_price_qv_apply_from_get($connect, array $get)
    {
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $id = maintenance_flow_get_int($get, 'id_trp', 0);
        if ($id < 1) {
            return array('ok' => false, 'message' => 'ข้อมูลไม่ครบ (id_trp)');
        }

        $num = isset($get['trp_qv_number']) ? trim((string) $get['trp_qv_number']) : '';
        $date = isset($get['trp_qv_date']) ? trim((string) $get['trp_qv_date']) : '';
        $send = isset($get['trp_qv_send']) ? trim((string) $get['trp_qv_send']) : '';
        $price = isset($get['trp_qv_price']) ? trim((string) $get['trp_qv_price']) : '';

        if ($send === '') {
            return array('ok' => false, 'message' => 'กรุณาระบุรูปแบบการส่ง');
        }
        if ($price === '') {
            return array('ok' => false, 'message' => 'กรุณาระบุค่าซ่อม');
        }
        if ($num === '') {
            return array('ok' => false, 'message' => 'กรุณาระบุหมายเลขใบเสนอราคา');
        }
        if ($date === '') {
            return array('ok' => false, 'message' => 'กรุณาระบุวันที่เสนอราคา');
        }

        $trp_ts_id = 0;
        $stmt_ts = mysqli_prepare($connect, 'SELECT trp_ts_id FROM transac_repair WHERE trp_id = ? LIMIT 1');
        if ($stmt_ts !== false) {
            mysqli_stmt_bind_param($stmt_ts, 'i', $id);
            if (mysqli_stmt_execute($stmt_ts)) {
                $res_ts = mysqli_stmt_get_result($stmt_ts);
                $row_ts = $res_ts ? mysqli_fetch_assoc($res_ts) : null;
                if (is_array($row_ts) && isset($row_ts['trp_ts_id'])) {
                    $trp_ts_id = (int) $row_ts['trp_ts_id'];
                }
                if ($res_ts) {
                    mysqli_free_result($res_ts);
                }
            }
            mysqli_stmt_close($stmt_ts);
        }
        if ($trp_ts_id < 1) {
            return array('ok' => false, 'message' => 'ไม่พบข้อมูลงานซ่อม (trp_ts_id)');
        }

        $sql = 'UPDATE transac_repair SET trp_qv_number = ?, trp_qv_date = ?, trp_qv_send = ?, trp_qv_price = ?, '
            . "trp_status_job = 'รออนุมัติซ่อม' WHERE trp_id = ?";

        mysqli_begin_transaction($connect);

        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            mysqli_rollback($connect);
            return array('ok' => false, 'message' => 'prepare ล้มเหลว: ' . mysqli_error($connect));
        }

        mysqli_stmt_bind_param($stmt, 'ssssi', $num, $date, $send, $price, $id);
        $ok = mysqli_stmt_execute($stmt);
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            mysqli_rollback($connect);
            return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ: ' . $err);
        }

        $synced = maintenance_offer_price_qv_sync_siblings_same_job(
            $connect,
            $trp_ts_id,
            $id,
            $num,
            $date,
            $send,
            $price
        );

        if (!mysqli_commit($connect)) {
            mysqli_rollback($connect);
            return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ: commit ล้มเหลว');
        }

        // ผู้บันทึกใบเสนอราคา (รวมรายการซ่อมในงานเดียวกันที่ได้ใบเสนอราคาเดียวกัน)
        require_once __DIR__ . '/job_stamp_helpers.php';
        $stamp_ids = array($id);
        $stmt_ids = mysqli_prepare($connect, 'SELECT trp_id FROM transac_repair WHERE trp_ts_id = ? AND trp_qv_number = ? AND trp_qv_date = ?');
        if ($stmt_ids !== false) {
            mysqli_stmt_bind_param($stmt_ids, 'iss', $trp_ts_id, $num, $date);
            mysqli_stmt_execute($stmt_ids);
            $res_ids = mysqli_stmt_get_result($stmt_ids);
            while ($res_ids && ($row_id = mysqli_fetch_assoc($res_ids))) {
                $stamp_ids[] = (int) $row_id['trp_id'];
            }
            mysqli_stmt_close($stmt_ids);
        }
        mn_job_stamp_set($connect, $stamp_ids, 'qv');

        $message = 'บันทึกสำเร็จ';
        if ($synced > 0) {
            $message .= ' (อัปเดตรายการซ่อมในงานเดียวกัน ' . $synced . ' รายการ)';
        }

        return array(
            'ok' => true,
            'message' => $message,
            'synced_siblings' => $synced,
        );
    }
}
