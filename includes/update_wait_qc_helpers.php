<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกผล QC จากพารามิเตอร์ GET (เทียบ `old_system/update_wait_qc.php`) — INSERT ลงตาราง `logqc_*` + UPDATE `transac_repair` แบบ transaction + prepared statement
 *
 * Flow:
 * `update_wait_qc.php` เรียก `maintenance_update_wait_qc_from_get()` แล้ว redirect ไป `status_wait_qc`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_update_wait_qc_gstr')) {
    /**
     * Purpose:
     * อ่าน string จาก array GET/POST อย่างปลอดภัย
     *
     * Parameters สำคัญ:
     * - array $src: แหล่งข้อมูล
     * - string $key: ชื่อ key
     *
     * Return value:
     * - string
     */
    function maintenance_update_wait_qc_gstr(array $src, $key)
    {
        return isset($src[$key]) ? (string) $src[$key] : '';
    }
}

if (!function_exists('maintenance_update_wait_qc_allowed_tables')) {
    /**
     * Purpose:
     * รายชื่อตาราง log QC ที่อนุญาต (ป้องกัน SQL injection ผ่านชื่อตาราง) — ใช้ชื่อตัวพิมพ์เล็กตรงกับ MySQL บน Linux (`logqc_bitvisitor` ฯลฯ)
     *
     * Return value:
     * - array<int, string>
     */
    function maintenance_update_wait_qc_allowed_tables()
    {
        return array(
            'logqc_bitvisitor',
            'logqc_bitscan',
            'logqc_printer',
            'logqc_smartcard',
            'logqc_magnetic',
            'logqc_scanner',
            'logqc_scanner_wireless',
            'logqc_bitsupply',
            'logqc_cardcam',
            'logqc_bitstamp',
            'logqc_a4camera',
            'logqc_keyboard',
            'logqc_mobile',
            'logqc_router',
            'logqc_facecam',
            'logqc_other',
        );
    }
}

if (!function_exists('maintenance_update_wait_qc_from_get')) {
    /**
     * Purpose:
     * รันคำสั่ง INSERT + UPDATE ตามพารามิเตอร์จาก URL (สอดคล้องฟอร์ม `qc_checklist`)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $g: มักเป็น `$_GET`
     *
     * Return value:
     * - bool true เมื่อ commit สำเร็จ
     */
    function maintenance_update_wait_qc_from_get($connect, array $g)
    {
        require_once __DIR__ . '/maintenance_flow_sql_helpers.php';
        $trp_id = maintenance_flow_get_int($g, 'trp_id', 0);
        if ($trp_id < 1) {
            return false;
        }
        $table = strtolower(trim(maintenance_update_wait_qc_gstr($g, 'table')));
        if (!in_array($table, maintenance_update_wait_qc_allowed_tables(), true)) {
            return false;
        }
        $trp_qc_date = maintenance_update_wait_qc_gstr($g, 'trp_qc_date');
        $trp_qc_user = maintenance_update_wait_qc_gstr($g, 'trp_qc_user');
        $trp_qc_comment = maintenance_update_wait_qc_gstr($g, 'trp_qc_comment');
        $status_after = 'รอส่งคืนเครื่องซ่อม';

        mysqli_begin_transaction($connect);

        $okInsert = false;
        switch ($table) {
            case 'logqc_bitvisitor':
                $sql = 'INSERT INTO logqc_bitvisitor (trp_id, qc_vfirmware, qc_vcamerafocus, qc_vledcard, qc_vusbvolt, qc_vexportusb, qc_vexportlan, qc_vledlan, qc_vtestprint, qc_vsoundlevel, qc_vsleepmode, qc_vtouch, qc_vcpuuse, qc_vramuse, qc_vnoopenclose, qc_vtimestart, qc_vinout, qc_comment) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $f0 = maintenance_update_wait_qc_gstr($g, 'qc_vfirmware');
                $f1 = maintenance_update_wait_qc_gstr($g, 'qc_vcamerafocus');
                $f2 = maintenance_update_wait_qc_gstr($g, 'qc_vledcard');
                $f3 = maintenance_update_wait_qc_gstr($g, 'qc_vusbvolt');
                $f4 = maintenance_update_wait_qc_gstr($g, 'qc_vexportusb');
                $f5 = maintenance_update_wait_qc_gstr($g, 'qc_vexportlan');
                $f6 = maintenance_update_wait_qc_gstr($g, 'qc_vledlan');
                $f7 = maintenance_update_wait_qc_gstr($g, 'qc_vtestprint');
                $f8 = maintenance_update_wait_qc_gstr($g, 'qc_vsoundlevel');
                $f9 = maintenance_update_wait_qc_gstr($g, 'qc_vsleepmode');
                $f10 = maintenance_update_wait_qc_gstr($g, 'qc_vtouch');
                $f11 = maintenance_update_wait_qc_gstr($g, 'qc_vcpuuse');
                $f12 = maintenance_update_wait_qc_gstr($g, 'qc_vramuse');
                $f13 = maintenance_update_wait_qc_gstr($g, 'qc_vnoopenclose');
                $f14 = maintenance_update_wait_qc_gstr($g, 'qc_vtimestart');
                $f15 = maintenance_update_wait_qc_gstr($g, 'qc_vinout');
                mysqli_stmt_bind_param(
                    $stmt,
                    'isssssssssssssssss',
                    $trp_id,
                    $f0,
                    $f1,
                    $f2,
                    $f3,
                    $f4,
                    $f5,
                    $f6,
                    $f7,
                    $f8,
                    $f9,
                    $f10,
                    $f11,
                    $f12,
                    $f13,
                    $f14,
                    $f15,
                    $trp_qc_comment
                );
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_bitscan':
                $sql = 'INSERT INTO logqc_bitscan (trp_id, qc_sscan, qc_sphasescan, qc_ssound_shake_light, qc_sloaddata, qc_comment) VALUES (?,?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_sscan');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_sphasescan');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_ssound_shake_light');
                $d = maintenance_update_wait_qc_gstr($g, 'qc_sloaddata');
                mysqli_stmt_bind_param($stmt, 'isssss', $trp_id, $a, $b, $c, $d, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_printer':
                $sql = 'INSERT INTO logqc_printer (trp_id, qc_plogo, qc_psharpness, qc_psoundprint, qc_pdatacable, qc_psticker_lock, qc_comment) VALUES (?,?,?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_plogo');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_psharpness');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_psoundprint');
                $d = maintenance_update_wait_qc_gstr($g, 'qc_pdatacable');
                $e = maintenance_update_wait_qc_gstr($g, 'qc_psticker_lock');
                mysqli_stmt_bind_param($stmt, 'issssss', $trp_id, $a, $b, $c, $d, $e, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_smartcard':
                $sql = 'INSERT INTO logqc_smartcard (trp_id, qc_scled, qc_scslotcard, qc_screadcard, qc_comment) VALUES (?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_scled');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_scslotcard');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_screadcard');
                mysqli_stmt_bind_param($stmt, 'issss', $trp_id, $a, $b, $c, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_magnetic':
                $sql = 'INSERT INTO logqc_magnetic (trp_id, qc_mtled, qc_mttrackerror, qc_mtreadcard, qc_comment) VALUES (?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_mtled');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_mttrackerror');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_mtreadcard');
                mysqli_stmt_bind_param($stmt, 'issss', $trp_id, $a, $b, $c, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_scanner':
                $sql = 'INSERT INTO logqc_scanner (trp_id, qc_snledred, qc_snledblue, qc_snrj45, qc_snreadqr, qc_comment) VALUES (?,?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_snledred');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_snledblue');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_snrj45');
                $d = maintenance_update_wait_qc_gstr($g, 'qc_snreadqr');
                mysqli_stmt_bind_param($stmt, 'isssss', $trp_id, $a, $b, $c, $d, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_scanner_wireless':
                $sql = 'INSERT INTO logqc_scanner_wireless (trp_id, qc_swcable, qc_swportusb, qc_swresetbutton, qc_swresetpair, qc_swhandheldledrgb, qc_swreseiveledrgb, qc_swreadqr, qc_comment) VALUES (?,?,?,?,?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_swcable');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_swportusb');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_swresetbutton');
                $d = maintenance_update_wait_qc_gstr($g, 'qc_swresetpair');
                $e = maintenance_update_wait_qc_gstr($g, 'qc_swhandheldledrgb');
                $f = maintenance_update_wait_qc_gstr($g, 'qc_swreseiveledrgb');
                $h = maintenance_update_wait_qc_gstr($g, 'qc_swreadqr');
                mysqli_stmt_bind_param($stmt, 'issssssss', $trp_id, $a, $b, $c, $d, $e, $f, $h, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_bitsupply':
                $sql = 'INSERT INTO logqc_bitsupply (trp_id, qc_bssoundalert, qc_bsledacdc, qc_bspower, qc_bspowerprint, qc_comment) VALUES (?,?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_bssoundalert');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_bsledacdc');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_bspower');
                $d = maintenance_update_wait_qc_gstr($g, 'qc_bspowerprint');
                mysqli_stmt_bind_param($stmt, 'isssss', $trp_id, $a, $b, $c, $d, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_cardcam':
                $sql = 'INSERT INTO logqc_cardcam (trp_id, qc_ccled, qc_ccfocus, qc_ccacrylic, qc_comment) VALUES (?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_ccled');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_ccfocus');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_ccacrylic');
                mysqli_stmt_bind_param($stmt, 'issss', $trp_id, $a, $b, $c, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_bitstamp':
                $sql = 'INSERT INTO logqc_bitstamp (trp_id, qc_bstled, qc_bstreadrfid, qc_bstopenclose, qc_bstsettingmode, qc_bstusb, qc_comment) VALUES (?,?,?,?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_bstled');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_bstreadrfid');
                $c = maintenance_update_wait_qc_gstr($g, 'qc_bstopenclose');
                $d = maintenance_update_wait_qc_gstr($g, 'qc_bstsettingmode');
                $e = maintenance_update_wait_qc_gstr($g, 'qc_bstusb');
                mysqli_stmt_bind_param($stmt, 'issssss', $trp_id, $a, $b, $c, $d, $e, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_a4camera':
                $sql = 'INSERT INTO logqc_a4camera (trp_id, qc_a4focus, qc_a4position, qc_comment) VALUES (?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_a4focus');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_a4position');
                mysqli_stmt_bind_param($stmt, 'isss', $trp_id, $a, $b, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_keyboard':
                $sql = 'INSERT INTO logqc_keyboard (trp_id, qc_kbbuttonvalue, qc_kbbuttonok, qc_comment) VALUES (?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_kbbuttonvalue');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_kbbuttonok');
                mysqli_stmt_bind_param($stmt, 'isss', $trp_id, $a, $b, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_mobile':
                $sql = 'INSERT INTO logqc_mobile (trp_id, qc_mbtranferimg, qc_mbclearcache, qc_comment) VALUES (?,?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_mbtranferimg');
                $b = maintenance_update_wait_qc_gstr($g, 'qc_mbclearcache');
                mysqli_stmt_bind_param($stmt, 'isss', $trp_id, $a, $b, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_router':
                $sql = 'INSERT INTO logqc_router (trp_id, qc_rtsetok, qc_comment) VALUES (?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_rtsetok');
                mysqli_stmt_bind_param($stmt, 'iss', $trp_id, $a, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_facecam':
                $sql = 'INSERT INTO logqc_facecam (trp_id, qc_fcfocus, qc_comment) VALUES (?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_fcfocus');
                mysqli_stmt_bind_param($stmt, 'iss', $trp_id, $a, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            case 'logqc_other':
                $sql = 'INSERT INTO logqc_other (trp_id, qc_checkok, qc_comment) VALUES (?,?,?)';
                $stmt = mysqli_prepare($connect, $sql);
                if ($stmt === false) {
                    mysqli_rollback($connect);
                    return false;
                }
                $a = maintenance_update_wait_qc_gstr($g, 'qc_checkok');
                mysqli_stmt_bind_param($stmt, 'iss', $trp_id, $a, $trp_qc_comment);
                $okInsert = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                break;
            default:
                mysqli_rollback($connect);
                return false;
        }

        if (!$okInsert) {
            mysqli_rollback($connect);
            return false;
        }

        $sqlUp = 'UPDATE transac_repair SET trp_qc_date = ?, trp_qc_user = ?, trp_qc_comment = ?, trp_status_job = ? WHERE trp_id = ?';
        $stmtUp = mysqli_prepare($connect, $sqlUp);
        if ($stmtUp === false) {
            mysqli_rollback($connect);
            return false;
        }
        mysqli_stmt_bind_param($stmtUp, 'ssssi', $trp_qc_date, $trp_qc_user, $trp_qc_comment, $status_after, $trp_id);
        $okUp = mysqli_stmt_execute($stmtUp);
        mysqli_stmt_close($stmtUp);
        if (!$okUp) {
            mysqli_rollback($connect);
            return false;
        }

        mysqli_commit($connect);

        return true;
    }
}
