<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รับ GET จากหน้า detail_job แล้วอัปเดต `transac_repair.trp_other_comment` (เทียบ `old_system/update_other_comment.php`)
 *
 * Flow:
 * validate GET → UPDATE → redirect กลับ `detail_job?ts_id=`
 *
 * Parameters สำคัญจาก GET:
 * - trp_id: int
 * - ts_id: int ใช้ redirect
 * - trp_other_comment: string (ควร encode ใน URL จากฝั่ง client)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/mysql_odbc_compat.php';

$trp_id = isset($_GET['trp_id']) ? (int) $_GET['trp_id'] : 0;
$ts_id = isset($_GET['ts_id']) ? (int) $_GET['ts_id'] : 0;
$comment = isset($_GET['trp_other_comment']) ? (string) $_GET['trp_other_comment'] : '';

if ($trp_id <= 0 || $ts_id <= 0) {
    maintenance_db_odbc_close($connect);
    header('Location: detail_job?ts_id=' . max(0, $ts_id));
    exit;
}

$stmt = mysqli_prepare($connect, 'UPDATE transac_repair SET trp_other_comment = ? WHERE trp_id = ? LIMIT 1');
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'si', $comment, $trp_id);
    if (mysqli_stmt_execute($stmt)) {
        // ผู้บันทึก Comment
        require_once __DIR__ . '/includes/job_stamp_helpers.php';
        mn_job_stamp_set($connect, array($trp_id), 'comment');
    }
    mysqli_stmt_close($stmt);
}
maintenance_db_odbc_close($connect);

header('Location: detail_job?ts_id=' . $ts_id);
exit;
