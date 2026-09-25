<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึก S/N เครื่องสำรองจาก `edit_spare` (detail_job) — ไม่บังคับวันที่ส่ง/ขนส่ง/tracking
 * โหมดเปลี่ยน: UPDATE `tsp_sn` บนแถวเดิม + สลับ `spare.sp_status`; โหมดเพิ่ม: INSERT แถวสำรองใหม่
 * ตั้ง `trp_status_job = รอส่งสำรอง` เฉพาะเมื่อยัง `รอเครื่องซ่อม` และยังไม่เคยส่งสำรอง — กรณีขอสำรองทีหลังไม่แก้สถานะ
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';

$ts_id = maintenance_flow_get_int($_GET, 'ts_id', 0);
$trp_id = maintenance_flow_get_int($_GET, 'trp_id', 0);
$tsp_product = isset($_GET['tsp_product']) ? trim((string) $_GET['tsp_product']) : '';
$tsp_sn = isset($_GET['tsp_sn']) ? trim((string) $_GET['tsp_sn']) : '';
$tsp_sn_old = isset($_GET['tsp_sn_old']) ? trim((string) $_GET['tsp_sn_old']) : '';
$tsp_remarks_mode = isset($_GET['tsp_remarks']) ? trim((string) $_GET['tsp_remarks']) : '';

if ($ts_id < 1 || $trp_id < 1 || $tsp_sn === '') {
    header('Location: detail_job?ts_id=' . (int) $ts_id);
    exit;
}

$blank = 'blank';
$use = 'use';

mysqli_begin_transaction($connect);
try {
    if ($tsp_remarks_mode === 'change' && $tsp_sn_old !== '' && $tsp_sn_old !== $tsp_sn) {
        $sql_u = 'UPDATE transac_spare SET tsp_sn = ? WHERE tsp_ts_id = ? AND tsp_trp_id = ? AND tsp_sn = ? LIMIT 1';
        $st_u = mysqli_prepare($connect, $sql_u);
        if ($st_u === false) {
            throw new RuntimeException(mysqli_error($connect));
        }
        mysqli_stmt_bind_param($st_u, 'siis', $tsp_sn, $ts_id, $trp_id, $tsp_sn_old);
        mysqli_stmt_execute($st_u);
        $affected = mysqli_stmt_affected_rows($st_u);
        mysqli_stmt_close($st_u);
        if ($affected < 1) {
            $sql_u2 = 'UPDATE transac_spare SET tsp_sn = ? WHERE tsp_ts_id = ? AND tsp_trp_id = ? ORDER BY tsp_id DESC LIMIT 1';
            $st_u2 = mysqli_prepare($connect, $sql_u2);
            if ($st_u2 === false) {
                throw new RuntimeException(mysqli_error($connect));
            }
            mysqli_stmt_bind_param($st_u2, 'sii', $tsp_sn, $ts_id, $trp_id);
            mysqli_stmt_execute($st_u2);
            $affected = mysqli_stmt_affected_rows($st_u2);
            mysqli_stmt_close($st_u2);
            if ($affected < 1) {
                throw new RuntimeException('ไม่พบแถวเครื่องสำรองเดิมสำหรับเปลี่ยน S/N');
            }
        }

        $st_blank = mysqli_prepare($connect, 'UPDATE spare SET sp_status = ? WHERE sp_sn = ? LIMIT 1');
        if ($st_blank === false) {
            throw new RuntimeException(mysqli_error($connect));
        }
        mysqli_stmt_bind_param($st_blank, 'ss', $blank, $tsp_sn_old);
        mysqli_stmt_execute($st_blank);
        mysqli_stmt_close($st_blank);
    } else {
        $sql_ins = 'INSERT INTO transac_spare (tsp_ts_id, tsp_trp_id, tsp_product, tsp_sn) VALUES (?,?,?,?)';
        $st_i = mysqli_prepare($connect, $sql_ins);
        if ($st_i === false) {
            throw new RuntimeException(mysqli_error($connect));
        }
        mysqli_stmt_bind_param($st_i, 'iiss', $ts_id, $trp_id, $tsp_product, $tsp_sn);
        mysqli_stmt_execute($st_i);
        mysqli_stmt_close($st_i);
    }

    $st_use = mysqli_prepare($connect, 'UPDATE spare SET sp_status = ? WHERE sp_sn = ? LIMIT 1');
    if ($st_use === false) {
        throw new RuntimeException(mysqli_error($connect));
    }
    mysqli_stmt_bind_param($st_use, 'ss', $use, $tsp_sn);
    mysqli_stmt_execute($st_use);
    mysqli_stmt_close($st_use);

    maintenance_status_logistic_apply_repair_status_on_edit_spare($connect, $trp_id, $ts_id);

    mysqli_commit($connect);
} catch (Throwable $e) {
    mysqli_rollback($connect);
    maintenance_db_odbc_close($connect);
    exit('บันทึกไม่สำเร็จ: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
maintenance_db_odbc_close($connect);

header('Location: detail_job?ts_id=' . (int) $ts_id . '&mn_flash=spare_sn_saved');
exit;
