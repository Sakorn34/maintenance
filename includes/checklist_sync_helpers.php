<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ซิงก์การตั้งค่า checklist ระหว่าง server (ชุดทดสอบ test-tom) กับเครื่อง localhost
 * - server: `checklist_export` ส่งออกตารางการตั้งค่าเป็น JSON (ยืนยันด้วย token ใน header)
 * - localhost: ปุ่ม「ดึงค่าจาก server」ในหน้า checklist_settings → `mn_cl_sync_pull()` ดึงแล้วแทนที่ตารางในเครื่อง (สำรองของเดิมก่อน)
 *
 * config: `config/checklist_sync.local.php` (gitignored — แม่แบบ `config/checklist_sync.example.php`)
 */

if (!function_exists('mn_cl_sync_config')) {
    function mn_cl_sync_config()
    {
        $cfg = array('url' => '', 'token' => '', 'cainfo' => '');
        $file = dirname(__DIR__) . '/config/checklist_sync.local.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                foreach ($cfg as $k => $v) {
                    if (isset($loaded[$k])) { $cfg[$k] = trim((string) $loaded[$k]); }
                }
            }
        }
        return $cfg;
    }
}

/** ตารางการตั้งค่า (ไม่รวมผลตรวจของใบงาน cl_job_header / cl_job_item) */
if (!function_exists('mn_cl_sync_tables')) {
    function mn_cl_sync_tables()
    {
        return array('cl_product', 'cl_symptom', 'cl_group', 'cl_item', 'cl_rate_bundle', 'cl_rate_reason');
    }
}

if (!function_exists('mn_cl_sync_columns')) {
    function mn_cl_sync_columns($connect, $table)
    {
        $cols = array();
        $res = mysqli_query($connect, 'SHOW COLUMNS FROM `' . $table . '`');
        while ($res && ($r = mysqli_fetch_assoc($res))) { $cols[] = (string) $r['Field']; }
        return $cols;
    }
}

if (!function_exists('mn_cl_sync_export')) {
    function mn_cl_sync_export($connect)
    {
        $out = array('format' => 'mn-checklist-sync', 'version' => 1, 'exported_at' => date('Y-m-d H:i:s'), 'tables' => array());
        foreach (mn_cl_sync_tables() as $t) {
            $cols = mn_cl_sync_columns($connect, $t);
            $rows = array();
            if ($cols) {
                $res = mysqli_query($connect, 'SELECT `' . implode('`, `', $cols) . '` FROM `' . $t . '` ORDER BY `' . $cols[0] . '`');
                while ($res && ($r = mysqli_fetch_row($res))) { $rows[] = $r; }
            }
            $out['tables'][$t] = array('columns' => $cols, 'rows' => $rows);
        }
        return $out;
    }
}

if (!function_exists('mn_cl_sync_fetch_remote')) {
    function mn_cl_sync_fetch_remote(array $cfg)
    {
        if (!function_exists('curl_init')) { return array(null, 'PHP ไม่มี curl'); }
        $ch = curl_init($cfg['url']);
        $opts = array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => array('X-Checklist-Token: ' . $cfg['token'], 'Accept: application/json'),
        );
        if ($cfg['cainfo'] !== '') { $opts[CURLOPT_CAINFO] = $cfg['cainfo']; }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) { return array(null, 'เชื่อมต่อ server ไม่ได้: ' . $err); }
        if ($code === 403) { return array(null, 'server ปฏิเสธ token — ตรวจว่า token ใน config/checklist_sync.local.php ตรงกันทั้งสองฝั่ง'); }
        if ($code === 404) { return array(null, 'ไม่พบ checklist_export บน server — อัปโหลดไฟล์ชุดใหม่ขึ้น server ก่อน'); }
        if ($code !== 200) { return array(null, 'server ตอบกลับ HTTP ' . $code); }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['format']) || $data['format'] !== 'mn-checklist-sync' || !isset($data['tables']) || !is_array($data['tables'])) {
            return array(null, 'ข้อมูลจาก server ไม่ถูกต้อง');
        }
        foreach (array('cl_product', 'cl_symptom', 'cl_group', 'cl_item') as $t) {
            if (!isset($data['tables'][$t]['columns'], $data['tables'][$t]['rows']) || !is_array($data['tables'][$t]['rows'])) {
                return array(null, 'ข้อมูลจาก server ไม่มีตาราง ' . $t);
            }
        }
        return array($data, '');
    }
}

/**
 * ดึงการตั้งค่าจาก server มาแทนที่ในเครื่อง — สำรองของเดิมเป็น JSON ใน storage/checklist_sync/ ก่อน
 */
if (!function_exists('mn_cl_sync_pull')) {
    function mn_cl_sync_pull($connect)
    {
        $cfg = mn_cl_sync_config();
        if ($cfg['url'] === '' || $cfg['token'] === '') {
            return array('ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า url / token ใน config/checklist_sync.local.php');
        }
        list($data, $err) = mn_cl_sync_fetch_remote($cfg);
        if (!$data) { return array('ok' => false, 'error' => $err); }

        $dir = dirname(__DIR__) . '/storage/checklist_sync';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { return array('ok' => false, 'error' => 'สร้างโฟลเดอร์สำรองข้อมูลไม่ได้'); }
        $backup = 'backup-' . date('Ymd-His') . '.json';
        if (file_put_contents($dir . '/' . $backup, json_encode(mn_cl_sync_export($connect), JSON_UNESCAPED_UNICODE)) === false) {
            return array('ok' => false, 'error' => 'สำรองข้อมูลเดิมไม่สำเร็จ จึงยังไม่แทนที่');
        }

        $counts = array();
        mysqli_begin_transaction($connect);
        foreach (mn_cl_sync_tables() as $t) {
            // ตารางที่ server ยังไม่มี (ไฟล์บน server รุ่นเก่ากว่า) — ข้าม ไม่ลบของในเครื่อง
            if (empty($data['tables'][$t]['columns']) || !isset($data['tables'][$t]['rows']) || !is_array($data['tables'][$t]['rows'])) { continue; }
            $local = mn_cl_sync_columns($connect, $t);
            $remote = $data['tables'][$t]['columns'];
            $use = array();
            foreach ($remote as $i => $c) { if (in_array($c, $local, true)) { $use[$i] = $c; } }
            if (!$use || !mysqli_query($connect, 'DELETE FROM `' . $t . '`')) {
                mysqli_rollback($connect);
                return array('ok' => false, 'error' => 'แทนที่ตาราง ' . $t . ' ไม่สำเร็จ (ข้อมูลเดิมยังอยู่)');
            }
            $stmt = mysqli_prepare($connect,
                'INSERT INTO `' . $t . '` (`' . implode('`, `', $use) . '`) VALUES (' . implode(', ', array_fill(0, count($use), '?')) . ')'
            );
            if (!$stmt) {
                mysqli_rollback($connect);
                return array('ok' => false, 'error' => 'เตรียมคำสั่งตาราง ' . $t . ' ไม่สำเร็จ (ข้อมูลเดิมยังอยู่)');
            }
            $n = 0;
            foreach ($data['tables'][$t]['rows'] as $row) {
                $vals = array();
                foreach ($use as $i => $c) { $vals[] = isset($row[$i]) ? (string) $row[$i] : null; }
                $bind = array($stmt, str_repeat('s', count($vals)));
                foreach ($vals as $k => $v) { $bind[] = &$vals[$k]; }
                call_user_func_array('mysqli_stmt_bind_param', $bind);
                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    mysqli_rollback($connect);
                    return array('ok' => false, 'error' => 'นำเข้าตาราง ' . $t . ' ไม่สำเร็จ (ข้อมูลเดิมยังอยู่)');
                }
                $n++;
            }
            mysqli_stmt_close($stmt);
            $counts[$t] = $n;
        }
        mysqli_commit($connect);
        return array('ok' => true, 'counts' => $counts, 'exported_at' => (string) $data['exported_at'], 'backup' => 'storage/checklist_sync/' . $backup);
    }
}
