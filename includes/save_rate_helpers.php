<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกผลประเมินซ่อมจากพารามิเตอร์ GET (เทียบ `old_system/save_rate.php`) ด้วย mysqli prepared statement + อัปเดต `trp_fw` จากฟอร์ม
 *
 * Flow:
 * `maintenance_save_rate_apply_from_get` อ่านค่า → ประกอบ UPDATE → `mysqli_stmt_bind_param` → execute → คืนผลสำเร็จ/ข้อความ error
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_save_rate_stmt_bind')) {
    /**
     * Purpose:
     * bind พารามิเตอร์แบบ dynamic ให้ `mysqli_stmt` (หลีกเลี่ยงนับผิดจำนวน type ยาว ๆ)
     *
     * Parameters สำคัญ:
     * - mysqli_stmt $stmt: statement ที่ prepare แล้ว
     * - string $types: สตริงประเภท mysqli (เช่น `ssssi`)
     * - array $values: ค่าตามลำดับ placeholder
     *
     * Return value:
     * - bool ผลจาก `mysqli_stmt_bind_param`
     */
    function maintenance_save_rate_stmt_bind($stmt, $types, array $values)
    {
        $bind = array();
        $bind[] = &$types;
        foreach ($values as $k => $v) {
            $bind[] = &$values[$k];
        }

        return call_user_func_array(array($stmt, 'bind_param'), $bind);
    }
}

if (!function_exists('maintenance_save_rate_apply_from_get')) {
    /**
     * Purpose:
     * อัปเดต `transac_repair` ตามพารามิเตอร์ GET ของหน้า save_rate (logic สถานะ/ประกันเทียบระบบเก่า)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $get: มักเป็น `$_GET`
     *
     * Return value:
     * - array{ok: bool, message: string}
     */
    function maintenance_save_rate_apply_from_get($connect, array $get)
    {
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

        $id = maintenance_flow_get_int($get, 'trp_id', 0);
        if ($id < 1) {
            return array('ok' => false, 'message' => 'ข้อมูลไม่ครบ (trp_id)');
        }

        require_once __DIR__ . '/detail_job_edit_helpers.php';
        // เสนอราคาแล้ว: บันทึกได้เฉพาะงานรออนุมัติซ่อมที่ปลดล็อกด้วยรหัสตั้งค่าแล้ว (คงใบเสนอราคาและสถานะเดิม)
        $qv_edit = false;
        if (maintenance_detail_job_repair_rate_edit_locked($connect, $id)) {
            $qv_edit = !empty($get['qv_edit'])
                && maintenance_detail_job_repair_rate_qv_edit_eligible($connect, $id)
                && maintenance_detail_job_repair_rate_qv_edit_granted($id);
            if (!$qv_edit) {
                return array('ok' => false, 'message' => 'ไม่สามารถแก้ไขงานประเมินซ่อมได้ เนื่องจากมีการเสนอราคาซ่อมแล้ว');
            }
        }

        $tech = isset($get['trp_repair_technician']) ? (string) $get['trp_repair_technician'] : '';
        $rate_date = isset($get['trp_rate_date']) ? (string) $get['trp_rate_date'] : '';
        $clean = isset($get['trp_clean']) ? (string) $get['trp_clean'] : '';
        $solder = isset($get['trp_repeat_solder']) ? (string) $get['trp_repeat_solder'] : '';
        $spray = isset($get['trp_spray']) ? (string) $get['trp_spray'] : '';
        $fw = isset($get['trp_fw']) ? (string) $get['trp_fw'] : '';
        $remarks = isset($get['trp_repair_remarks']) ? (string) $get['trp_repair_remarks'] : '';
        $user_rate = isset($get['trp_user_rate']) ? (string) $get['trp_user_rate'] : '';
        $insurance = isset($get['trp_insurance']) ? (string) $get['trp_insurance'] : '';
        $prev_status = isset($get['trp_status_job']) ? (string) $get['trp_status_job'] : '';

        $n = array();
        for ($i = 1; $i <= 10; $i++) {
            $k = 'trp_repair_no' . $i;
            $n[$i] = isset($get[$k]) ? (string) $get[$k] : '';
        }

        $warranty = ($insurance === 'อยู่ในประกัน' || $insurance === 'อยู่ในประกันซ่อม');
        $status_job = ($warranty || $prev_status === 'รอซ่อม') ? 'รอซ่อม' : 'รอเสนอราคา';
        if ($qv_edit) {
            // ไม่แตะใบเสนอราคา (ไม่เข้าทางในประกันที่เขียนทับ QV) สถานะคงเป็นรออนุมัติซ่อม
            $warranty   = false;
            $status_job = 'รออนุมัติซ่อม';
        }

        if ($warranty) {
            $sql = 'UPDATE transac_repair SET '
                . 'trp_repair_technician = ?, trp_rate_date = ?, trp_clean = ?, trp_repeat_solder = ?, trp_spray = ?, trp_fw = ?, '
                . 'trp_repair_no1 = ?, trp_repair_no2 = ?, trp_repair_no3 = ?, trp_repair_no4 = ?, trp_repair_no5 = ?, '
                . 'trp_repair_no6 = ?, trp_repair_no7 = ?, trp_repair_no8 = ?, trp_repair_no9 = ?, trp_repair_no10 = ?, '
                . 'trp_repair_remarks = ?, trp_user_rate = ?, '
                . "trp_qv_number = '-', trp_qv_date = ?, trp_qv_send = '-', trp_qv_price = '0', trp_status_firmma = 'อนุมัติซ่อม', trp_firm_qv_date = ?, "
                . 'trp_status_job = ? '
                . 'WHERE trp_id = ?';

            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return array('ok' => false, 'message' => 'prepare ล้มเหลว: ' . mysqli_error($connect));
            }

            $vals = array(
                $tech,
                $rate_date,
                $clean,
                $solder,
                $spray,
                $fw,
                $n[1],
                $n[2],
                $n[3],
                $n[4],
                $n[5],
                $n[6],
                $n[7],
                $n[8],
                $n[9],
                $n[10],
                $remarks,
                $user_rate,
                $rate_date,
                $rate_date,
                $status_job,
                $id,
            );
            $types = str_repeat('s', 21) . 'i';
            if (!maintenance_save_rate_stmt_bind($stmt, $types, $vals)) {
                mysqli_stmt_close($stmt);

                return array('ok' => false, 'message' => 'bind_param ล้มเหลว');
            }
        } else {
            $sql = 'UPDATE transac_repair SET '
                . 'trp_repair_technician = ?, trp_rate_date = ?, trp_clean = ?, trp_repeat_solder = ?, trp_spray = ?, trp_fw = ?, '
                . 'trp_repair_no1 = ?, trp_repair_no2 = ?, trp_repair_no3 = ?, trp_repair_no4 = ?, trp_repair_no5 = ?, '
                . 'trp_repair_no6 = ?, trp_repair_no7 = ?, trp_repair_no8 = ?, trp_repair_no9 = ?, trp_repair_no10 = ?, '
                . 'trp_repair_remarks = ?, trp_user_rate = ?, trp_status_job = ? '
                . 'WHERE trp_id = ?';

            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return array('ok' => false, 'message' => 'prepare ล้มเหลว: ' . mysqli_error($connect));
            }

            $vals = array(
                $tech,
                $rate_date,
                $clean,
                $solder,
                $spray,
                $fw,
                $n[1],
                $n[2],
                $n[3],
                $n[4],
                $n[5],
                $n[6],
                $n[7],
                $n[8],
                $n[9],
                $n[10],
                $remarks,
                $user_rate,
                $status_job,
                $id,
            );
            $types = str_repeat('s', 19) . 'i';
            if (!maintenance_save_rate_stmt_bind($stmt, $types, $vals)) {
                mysqli_stmt_close($stmt);

                return array('ok' => false, 'message' => 'bind_param ล้มเหลว');
            }
        }

        $ok = mysqli_stmt_execute($stmt);
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        if ($ok) {
            if ($warranty) {
                // ในประกัน: ระบบตั้งใบเสนอราคา "-" และอนุมัติให้เองตอนบันทึกประเมิน → ผู้บันทึกคือผู้บันทึกประเมิน
                require_once __DIR__ . '/job_stamp_helpers.php';
                mn_job_stamp_set($connect, array($id), 'qv');
                mn_job_stamp_set($connect, array($id), 'firm');
            }
            return array('ok' => true, 'message' => $qv_edit ? 'บันทึกผลประเมินแล้ว ใบเสนอราคาและสถานะคงเดิม' : 'บันทึกสำเร็จ', 'redirect' => $qv_edit ? 'status_wait_firmQV' : 'status_rate');
        }

        return array('ok' => false, 'message' => 'บันทึกไม่สำเร็จ: ' . $err);
    }
}
