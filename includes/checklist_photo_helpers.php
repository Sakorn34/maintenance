<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * รูปประกอบการประเมิน (แนบรายข้อตรวจ หรือรูปทั่วไปของใบงาน) — เก็บไฟล์ที่ย่อแล้วจากเบราว์เซอร์
 * ไฟล์อยู่ใน storage/photos (ชื่อสุ่ม นามสกุล .img + .htaccess ปิดการเข้าถึงตรง) เปิดดูผ่าน checklist_photo เท่านั้น
 * cp_drive_id เผื่อย้ายไปเก็บบน Google Drive ภายหลัง
 *
 * config (ไม่บังคับ): config/photo_storage.local.php → array('dir' => path, 'max_mb' => 400)
 */

if (!function_exists('mn_photo_config')) {
    function mn_photo_config()
    {
        static $cfg = null;
        if ($cfg !== null) { return $cfg; }
        $cfg = array('dir' => dirname(__DIR__) . '/storage/photos', 'max_mb' => 400);
        $file = dirname(__DIR__) . '/config/photo_storage.local.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                if (!empty($loaded['dir'])) { $cfg['dir'] = rtrim((string) $loaded['dir'], '/\\'); }
                if (!empty($loaded['max_mb'])) { $cfg['max_mb'] = max(10, (int) $loaded['max_mb']); }
            }
        }
        return $cfg;
    }
}

/** โฟลเดอร์เก็บรูป — สร้างพร้อม .htaccess ปิดการเข้าถึงจากเว็บเมื่อยังไม่มี */
if (!function_exists('mn_photo_dir')) {
    function mn_photo_dir()
    {
        $cfg = mn_photo_config();
        $dir = $cfg['dir'];
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# รูปประกอบการประเมิน — เปิดดูผ่าน checklist_photo เท่านั้น\n"
                . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }
}

if (!function_exists('mn_photo_ensure_table')) {
    function mn_photo_ensure_table($connect)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $col = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_photo` ('
            . '`cp_id` INT NOT NULL AUTO_INCREMENT,'
            . '`cp_trp_id` INT NOT NULL,'
            . '`cp_item_id` INT NOT NULL DEFAULT 0,'
            . '`cp_file` VARCHAR(64) ' . $col . ' NOT NULL,'
            . '`cp_thumb` VARCHAR(64) ' . $col . ' NOT NULL,'
            . '`cp_mime` VARCHAR(20) ' . $col . ' NOT NULL DEFAULT \'image/jpeg\','
            . '`cp_w` SMALLINT NOT NULL DEFAULT 0,'
            . '`cp_h` SMALLINT NOT NULL DEFAULT 0,'
            . '`cp_size` INT NOT NULL DEFAULT 0,'
            . '`cp_thumb_size` INT NOT NULL DEFAULT 0,'
            . '`cp_drive_id` VARCHAR(100) ' . $col . ' NULL DEFAULT NULL,'
            . '`cp_by` VARCHAR(100) ' . $col . ' NOT NULL DEFAULT \'\','
            . '`cp_at` DATETIME NULL DEFAULT NULL,'
            . 'PRIMARY KEY (`cp_id`),'
            . 'INDEX `idx_cp_trp` (`cp_trp_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        // id โฟลเดอร์บน Google Drive ที่สร้างแล้ว (ไม่ต้องค้นซ้ำทุกครั้ง)
        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_drive_folder` ('
            . '`cdf_key` CHAR(32) ' . $col . ' NOT NULL,'
            . '`cdf_id` VARCHAR(100) ' . $col . ' NOT NULL,'
            . 'PRIMARY KEY (`cdf_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

if (!function_exists('mn_photo_list')) {
    function mn_photo_list($connect, $trp_id)
    {
        mn_photo_ensure_table($connect);
        $stmt = mysqli_prepare($connect, 'SELECT cp_id, cp_item_id, cp_w, cp_h, cp_by, cp_at, (cp_drive_id IS NOT NULL) AS on_drive FROM cl_photo WHERE cp_trp_id = ? ORDER BY cp_id');
        if (!$stmt) { return array(); }
        $t = (int) $trp_id;
        mysqli_stmt_bind_param($stmt, 'i', $t);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[] = array('id' => (int) $r['cp_id'], 'ci' => (int) $r['cp_item_id'], 'w' => (int) $r['cp_w'], 'h' => (int) $r['cp_h'],
                'by' => (string) $r['cp_by'], 'at' => (string) $r['cp_at'], 'drive' => (int) $r['on_drive'] === 1);
        }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

if (!function_exists('mn_photo_row')) {
    function mn_photo_row($connect, $id)
    {
        $stmt = mysqli_prepare($connect, 'SELECT cp_id, cp_trp_id, cp_item_id, cp_file, cp_thumb, cp_mime, cp_size, cp_drive_id, cp_at FROM cl_photo WHERE cp_id = ?');
        if (!$stmt) { return null; }
        $i = (int) $id;
        mysqli_stmt_bind_param($stmt, 'i', $i);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }
}

/** ตรวจไฟล์ที่อัปโหลดว่าเป็นรูปจริง → array(mime, w, h) หรือ null */
if (!function_exists('mn_photo_check_image')) {
    function mn_photo_check_image($path, $max_bytes, $max_side)
    {
        if (!is_file($path)) { return null; }
        $size = filesize($path);
        if ($size <= 0 || $size > $max_bytes) { return null; }
        $info = @getimagesize($path);
        if (!$info) { return null; }
        $types = array(IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png');
        if (defined('IMAGETYPE_WEBP')) { $types[IMAGETYPE_WEBP] = 'image/webp'; }
        if (!isset($types[$info[2]])) { return null; }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > $max_side || $info[1] > $max_side) { return null; }
        return array($types[$info[2]], (int) $info[0], (int) $info[1], (int) $size);
    }
}

if (!function_exists('mn_photo_save')) {
    function mn_photo_save($connect, $trp_id, $item_id, array $photo, array $thumb, $by)
    {
        mn_photo_ensure_table($connect);
        $t = (int) $trp_id;
        $exists = mysqli_prepare($connect, 'SELECT trp_id FROM transac_repair WHERE trp_id = ?');
        if (!$exists) { return array('ok' => false, 'error' => 'ตรวจใบงานไม่สำเร็จ'); }
        mysqli_stmt_bind_param($exists, 'i', $t);
        mysqli_stmt_execute($exists);
        $found = mysqli_stmt_get_result($exists);
        $has = $found && mysqli_fetch_row($found);
        mysqli_stmt_close($exists);
        if (!$has) { return array('ok' => false, 'error' => 'ไม่พบใบงาน'); }

        if (count(mn_photo_list($connect, $t)) >= 40) { return array('ok' => false, 'error' => 'ใบงานนี้มีรูปครบ 40 รูปแล้ว'); }

        $tmp = isset($photo['tmp_name']) ? (string) $photo['tmp_name'] : '';
        $tmp_t = isset($thumb['tmp_name']) ? (string) $thumb['tmp_name'] : '';
        if ($tmp === '' || !is_uploaded_file($tmp) || $tmp_t === '' || !is_uploaded_file($tmp_t)) {
            return array('ok' => false, 'error' => 'ไม่ได้รับไฟล์รูป');
        }
        $img = mn_photo_check_image($tmp, 3 * 1024 * 1024, 2600);
        $img_t = mn_photo_check_image($tmp_t, 300 * 1024, 800);
        if (!$img || !$img_t) { return array('ok' => false, 'error' => 'ไฟล์ไม่ใช่รูป หรือใหญ่เกินกำหนด'); }

        $cfg = mn_photo_config();
        $used = mysqli_query($connect, 'SELECT COALESCE(SUM(cp_size + cp_thumb_size), 0) FROM cl_photo');
        $used_bytes = $used ? (int) mysqli_fetch_row($used)[0] : 0;
        if ($used_bytes + $img[3] + $img_t[3] > $cfg['max_mb'] * 1024 * 1024) {
            return array('ok' => false, 'error' => 'พื้นที่เก็บรูปเต็ม (' . $cfg['max_mb'] . ' MB) แจ้งผู้ดูแลระบบให้สำรองและล้างรูปเก่า');
        }

        $dir = mn_photo_dir();
        if (!is_dir($dir) || !is_writable($dir)) { return array('ok' => false, 'error' => 'โฟลเดอร์เก็บรูปเขียนไม่ได้'); }
        $name = bin2hex(random_bytes(16)) . '.img';
        $name_t = bin2hex(random_bytes(16)) . '.img';
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) { return array('ok' => false, 'error' => 'บันทึกรูปไม่สำเร็จ'); }
        if (!move_uploaded_file($tmp_t, $dir . '/' . $name_t)) {
            @unlink($dir . '/' . $name);
            return array('ok' => false, 'error' => 'บันทึกรูปไม่สำเร็จ');
        }

        $ci = max(0, (int) $item_id);
        $mime = $img[0];
        $w = $img[1];
        $h = $img[2];
        $size = $img[3];
        $size_t = $img_t[3];
        $who = mb_substr(trim((string) $by), 0, 100, 'UTF-8');
        $now = date('Y-m-d H:i:s');
        $stmt = mysqli_prepare($connect,
            'INSERT INTO cl_photo (cp_trp_id, cp_item_id, cp_file, cp_thumb, cp_mime, cp_w, cp_h, cp_size, cp_thumb_size, cp_by, cp_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ok = false;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iisssiiiiss', $t, $ci, $name, $name_t, $mime, $w, $h, $size, $size_t, $who, $now);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        if (!$ok) {
            @unlink($dir . '/' . $name);
            @unlink($dir . '/' . $name_t);
            return array('ok' => false, 'error' => 'บันทึกข้อมูลรูปไม่สำเร็จ');
        }
        return array('ok' => true, 'photo' => array('id' => (int) mysqli_insert_id($connect), 'ci' => $ci, 'w' => $w, 'h' => $h, 'by' => $who, 'at' => $now));
    }
}

if (!function_exists('mn_photo_delete')) {
    function mn_photo_delete($connect, $id, $trp_id)
    {
        mn_photo_ensure_table($connect);
        $row = mn_photo_row($connect, $id);
        if (!$row || (int) $row['cp_trp_id'] !== (int) $trp_id) { return array('ok' => false, 'error' => 'ไม่พบรูปนี้'); }
        $stmt = mysqli_prepare($connect, 'DELETE FROM cl_photo WHERE cp_id = ?');
        $i = (int) $id;
        mysqli_stmt_bind_param($stmt, 'i', $i);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if ($ok) {
            $dir = mn_photo_dir();
            foreach (array($row['cp_file'], $row['cp_thumb']) as $f) {
                if (preg_match('/^[a-f0-9]{32}\.img$/', (string) $f)) { @unlink($dir . '/' . $f); }
            }
            // รูปที่ขึ้น Drive แล้ว: ย้ายลงถังขยะของ Drive (กู้คืนได้ 30 วัน)
            if (!empty($row['cp_drive_id']) && mn_photo_drive_ready()) {
                $err = mn_gd_trash((string) $row['cp_drive_id']);
                if ($err !== '') { mn_gd_log('trash ' . $row['cp_drive_id'] . ': ' . $err); }
            }
        }
        return array('ok' => (bool) $ok, 'error' => $ok ? '' : 'ลบรูปไม่สำเร็จ');
    }
}

/** ไฟล์รูปขนาดดู: บน server หรือดึงจาก Drive → string (ว่าง = ไม่พบ) */
if (!function_exists('mn_photo_full_bytes')) {
    function mn_photo_full_bytes(array $row)
    {
        $file = (string) $row['cp_file'];
        $path = mn_photo_dir() . '/' . $file;
        if (preg_match('/^[a-f0-9]{32}\.img$/', $file) && is_file($path)) { return (string) file_get_contents($path); }
        if (!empty($row['cp_drive_id']) && mn_photo_drive_ready()) {
            list($bytes, $err) = mn_gd_download((string) $row['cp_drive_id']);
            if ($err === '' && $bytes !== '') { return $bytes; }
            mn_gd_log('download ' . $row['cp_drive_id'] . ': ' . $err);
        }
        return '';
    }
}

/** header ดาวน์โหลดชื่อไฟล์ภาษาไทย */
if (!function_exists('mn_photo_disposition')) {
    function mn_photo_disposition($name)
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $name);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string) $name));
    }
}

/**
 * ส่งไฟล์รูปให้เบราว์เซอร์ (เรียกหลังตรวจสิทธิ์แล้ว — ล็อกอิน หรือรหัส QR ของใบงานนั้น)
 * $download = true → ให้เบราว์เซอร์บันทึกไฟล์ ตั้งชื่อตามที่มา (เหมือนชื่อบน Drive)
 */
if (!function_exists('mn_photo_stream')) {
    function mn_photo_stream($connect, $id, $thumb, $download = false)
    {
        mn_photo_ensure_table($connect);
        $row = mn_photo_row($connect, $id);
        if ($row && $download) {
            $bytes = mn_photo_full_bytes($row);
            if ($bytes === '') { http_response_code(404); exit; }
            header('Content-Type: ' . (string) $row['cp_mime']);
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            mn_photo_disposition(mn_photo_drive_name(mn_photo_job_info($connect, $row), $row));
            echo $bytes;
            exit;
        }
        $file = $row ? (string) ($thumb ? $row['cp_thumb'] : $row['cp_file']) : '';
        $path = mn_photo_dir() . '/' . $file;
        $local = $row && preg_match('/^[a-f0-9]{32}\.img$/', $file) && is_file($path);
        // รูปขนาดดูที่ย้ายขึ้น Drive แล้ว → ดึงจาก Drive มาให้ (เบราว์เซอร์เก็บแคชต่อ)
        if (!$local && $row && !$thumb) {
            $bytes = mn_photo_full_bytes($row);
            if ($bytes !== '') {
                header('Content-Type: ' . (string) $row['cp_mime']);
                header('Content-Length: ' . strlen($bytes));
                header('Cache-Control: private, max-age=31536000, immutable');
                header('X-Content-Type-Options: nosniff');
                echo $bytes;
                exit;
            }
        }
        if (!$local) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . ($thumb ? 'image/jpeg' : (string) $row['cp_mime']));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}

/** ดาวน์โหลดรูปทั้งใบงานเป็นไฟล์ zip (ชื่อไฟล์ในนั้นตามที่มา) */
if (!function_exists('mn_photo_zip')) {
    function mn_photo_zip($connect, $trp_id)
    {
        mn_photo_ensure_table($connect);
        $list = mn_photo_list($connect, $trp_id);
        if (!$list || !class_exists('ZipArchive')) { http_response_code(404); echo $list ? 'server ไม่รองรับ zip' : 'ใบงานนี้ยังไม่มีรูป'; exit; }
        @set_time_limit(180);
        $tmp = tempnam(sys_get_temp_dir(), 'mnzip');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit; }
        $job = null;
        $count = 0;
        foreach ($list as $p) {
            $row = mn_photo_row($connect, $p['id']);
            if (!$row) { continue; }
            $bytes = mn_photo_full_bytes($row);
            if ($bytes === '') { continue; }
            $info = mn_photo_job_info($connect, $row);
            if ($job === null) { $job = $info; }
            $zip->addFromString(mn_photo_drive_name($info, $row), $bytes);
            $count++;
        }
        $zip->close();
        if (!$count) { @unlink($tmp); http_response_code(404); echo 'ไม่พบไฟล์รูป'; exit; }
        $name = trim(preg_replace('#[\\\\/:*?"<>|]+#u', ' ', 'รูป ' . trim($job['mtn'] . ' ' . $job['product'] . ' ' . $job['sn']))) . '.zip';
        maintenance_db_odbc_close($connect);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store');
        mn_photo_disposition($name);
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}

/**
 * รูปของหลายใบงานในคำสั่งเดียว → array( trp_id => array( array(id, ci, label), ... ) )
 * label = ชื่อข้อตรวจที่แนบ (ว่าง = รูปประกอบเพิ่มเติม)
 */
if (!function_exists('mn_photo_by_trps')) {
    function mn_photo_by_trps($connect, array $trp_ids)
    {
        $ids = array();
        foreach ($trp_ids as $t) { if ((int) $t > 0) { $ids[(int) $t] = true; } }
        if (!$ids) { return array(); }
        mn_photo_ensure_table($connect);
        $ids = array_keys($ids);
        $sql = 'SELECT p.cp_id, p.cp_trp_id, p.cp_item_id, (p.cp_drive_id IS NOT NULL) AS on_drive, COALESCE(i.ci_question, \'\') AS q'
            . ' FROM cl_photo p LEFT JOIN cl_item i ON i.ci_id = p.cp_item_id'
            . ' WHERE p.cp_trp_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY p.cp_trp_id, p.cp_id';
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return array(); }
        $bind = array($stmt, str_repeat('i', count($ids)));
        foreach ($ids as $k => $v) { $bind[] = &$ids[$k]; }
        call_user_func_array('mysqli_stmt_bind_param', $bind);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[(int) $r['cp_trp_id']][] = array('id' => (int) $r['cp_id'], 'ci' => (int) $r['cp_item_id'], 'label' => (string) $r['q'], 'drive' => (int) $r['on_drive'] === 1);
        }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

/** เครื่องหมายติ๊กถูกมุมรูป = รูปขึ้น Google Drive แล้ว (สไตล์ .mn-photo-drive ใน css/mn-photo-lightbox.css) */
if (!function_exists('mn_photo_drive_badge')) {
    function mn_photo_drive_badge()
    {
        return '<span class="mn-photo-drive" title="ขึ้น Google Drive แล้ว" aria-label="ขึ้น Google Drive แล้ว"><i class="fa fa-check" aria-hidden="true"></i></span>';
    }
}

/** แถบรูปเล็ก กดแล้วเปิด mnPhotoLightbox (js/mn-photo-lightbox.js) */
if (!function_exists('mn_photo_strip_html')) {
    function mn_photo_strip_html(array $photos, $max = 8, $zip_trp_id = 0)
    {
        if (!$photos) { return ''; }
        $items = array();
        foreach ($photos as $p) {
            $items[] = array('url' => 'checklist_photo?id=' . (int) $p['id'] . '&s=f', 'download' => 'checklist_photo?id=' . (int) $p['id'] . '&s=f&dl=1',
                'caption' => $p['label'] !== '' ? $p['label'] : 'รูปประกอบเพิ่มเติม');
        }
        $html = '<div class="mn-photo-strip" data-mn-photos="' . htmlspecialchars(json_encode($items, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '">';
        foreach ($photos as $i => $p) {
            if ($i >= $max) { break; }
            $cap = htmlspecialchars($items[$i]['caption'], ENT_QUOTES, 'UTF-8');
            $html .= '<button type="button" class="mn-photo-strip__img" data-mn-photo="' . $i . '" title="' . $cap . '" aria-label="ดูรูป ' . $cap . '">'
                . '<img src="checklist_photo?id=' . (int) $p['id'] . '&amp;s=t" alt="" loading="lazy" />'
                . (!empty($p['drive']) ? mn_photo_drive_badge() : '') . '</button>';
        }
        if (count($photos) > $max) { $html .= '<span class="mn-photo-strip__more">+' . (count($photos) - $max) . ' รูป</span>'; }
        if ((int) $zip_trp_id > 0) {
            $html .= '<a class="mn-photo-strip__zip" href="checklist_photo?action=zip&amp;trp_id=' . (int) $zip_trp_id . '" title="ดาวน์โหลดรูปทั้งหมดของใบงานนี้ (zip)">'
                . '<i class="fa fa-download" aria-hidden="true"></i> ทั้งหมด</a>';
        }
        return $html . '</div>';
    }
}

// ---------- Google Drive (ถ้าตั้งค่าไว้) ----------

if (!function_exists('mn_photo_drive_ready')) {
    function mn_photo_drive_ready()
    {
        if (!function_exists('mn_gd_enabled')) { require_once __DIR__ . '/google_drive_helpers.php'; }
        return mn_gd_enabled();
    }
}

/** หัวข้อของรูป: "กลุ่ม - ข้อตรวจ" (ข้อที่มีชื่อกลุ่มอยู่แล้วไม่ซ้ำ) | ว่าง = รูปประกอบเพิ่มเติม */
if (!function_exists('mn_photo_topic')) {
    function mn_photo_topic($question, $group)
    {
        $q = trim((string) $question);
        $g = trim((string) $group);
        if ($q === '') { return 'รูปประกอบเพิ่มเติม'; }
        if ($g !== '' && mb_strpos($q, $g, 0, 'UTF-8') === false) { return $g . ' - ' . $q; }
        return $q;
    }
}

/** ข้อมูลใบงาน + หัวข้อของรูป (ใช้ตั้งชื่อไฟล์บน Drive) → array(mtn, product, sn, topic) */
if (!function_exists('mn_photo_job_info')) {
    function mn_photo_job_info($connect, array $row)
    {
        $job = array('mtn' => '', 'product' => '', 'sn' => '', 'q' => '', 'g' => '');
        $stmt = mysqli_prepare($connect,
            'SELECT COALESCE(t.ts_mtn_number, \'\') AS mtn, COALESCE(r.trp_product, \'\') AS product, COALESCE(r.trp_sn, \'\') AS sn,'
            . ' COALESCE(i.ci_question, \'\') AS q, COALESCE(g.cg_name, \'\') AS g'
            . ' FROM transac_repair r LEFT JOIN transac t ON t.ts_id = r.trp_ts_id'
            . ' LEFT JOIN cl_item i ON i.ci_id = ? LEFT JOIN cl_group g ON g.cg_id = i.ci_group_id'
            . ' WHERE r.trp_id = ?'
        );
        if ($stmt) {
            $ci = (int) $row['cp_item_id'];
            $t = (int) $row['cp_trp_id'];
            mysqli_stmt_bind_param($stmt, 'ii', $ci, $t);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $found = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if ($found) { $job = $found; }
        }
        $job['topic'] = mn_photo_topic($job['q'], $job['g']);
        return $job;
    }
}

/**
 * ชื่อไฟล์บน Drive บอกที่มาครบ: <เลขงาน>_<รุ่น>_<SN>_<หัวข้อ>_<วันที่ เวลาที่ถ่าย>_<id>.jpg
 * เช่น 69-0602_bitVisitor Plus_BP21081017_ตัวเครื่อง - สภาพภายนอก_2026-11-24 0831_57.jpg
 */
if (!function_exists('mn_photo_drive_name')) {
    function mn_photo_drive_name(array $job, array $row)
    {
        $clean = function ($s, $max) {
            $s = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('#[\\\\/:*?"<>|_]+#u', ' ', (string) $s)));
            return mb_substr($s, 0, $max, 'UTF-8');
        };
        $at = strtotime((string) $row['cp_at']);
        $parts = array($clean($job['mtn'], 30), $clean($job['product'], 40), $clean($job['sn'], 40), $clean($job['topic'], 80),
            $at ? date('Y-m-d Hi', $at) : '', (string) (int) $row['cp_id']);
        $parts = array_values(array_filter($parts, function ($p) { return $p !== ''; }));
        $ext = $row['cp_mime'] === 'image/png' ? 'png' : ($row['cp_mime'] === 'image/webp' ? 'webp' : 'jpg');
        return implode('_', $parts) . '.' . $ext;
    }
}

/** ย้ายรูปไปข้ออื่นของใบงานเดียวกัน (ci 0 = รูปประกอบเพิ่มเติม) — รูปที่ขึ้น Drive แล้วเปลี่ยนชื่อไฟล์ตาม */
if (!function_exists('mn_photo_move')) {
    function mn_photo_move($connect, $id, $trp_id, $ci)
    {
        mn_photo_ensure_table($connect);
        $row = mn_photo_row($connect, $id);
        if (!$row || (int) $row['cp_trp_id'] !== (int) $trp_id) { return array('ok' => false, 'error' => 'ไม่พบรูปนี้'); }
        $to = max(0, (int) $ci);
        if ($to > 0) {
            $chk = mysqli_prepare($connect, 'SELECT ci_id FROM cl_item WHERE ci_id = ?');
            mysqli_stmt_bind_param($chk, 'i', $to);
            mysqli_stmt_execute($chk);
            $r = mysqli_stmt_get_result($chk);
            $has = $r && mysqli_fetch_row($r);
            mysqli_stmt_close($chk);
            if (!$has) { return array('ok' => false, 'error' => 'ไม่พบข้อตรวจที่จะย้ายไป'); }
        }
        $stmt = mysqli_prepare($connect, 'UPDATE cl_photo SET cp_item_id = ? WHERE cp_id = ?');
        $pid = (int) $row['cp_id'];
        mysqli_stmt_bind_param($stmt, 'ii', $to, $pid);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) { return array('ok' => false, 'error' => 'ย้ายรูปไม่สำเร็จ'); }
        if (!empty($row['cp_drive_id']) && mn_photo_drive_ready()) {
            $err = mn_photo_rename_drive($connect, $pid);
            if ($err !== '') { mn_gd_log('rename ' . $pid . ': ' . $err); }
        }
        return array('ok' => true, 'error' => '', 'ci' => $to);
    }
}

/** ตั้งชื่อไฟล์บน Drive ใหม่ตามรูปแบบปัจจุบัน (หลังย้ายข้อ หรือปรับชื่อไฟล์เก่า) → error */
if (!function_exists('mn_photo_rename_drive')) {
    function mn_photo_rename_drive($connect, $id)
    {
        if (!mn_photo_drive_ready()) { return 'ยังไม่ได้ตั้งค่า Google Drive'; }
        $row = mn_photo_row($connect, $id);
        if (!$row || empty($row['cp_drive_id'])) { return ''; }
        return mn_gd_rename((string) $row['cp_drive_id'], mn_photo_drive_name(mn_photo_job_info($connect, $row), $row));
    }
}

/**
 * ตอบผลอัปโหลดให้เบราว์เซอร์ก่อน แล้วค่อยส่งรูปขึ้น Drive (คนถ่ายไม่ต้องรอ)
 * server ที่ไม่มี fastcgi_finish_request จะส่งขึ้น Drive ก่อนตอบแทน
 */
if (!function_exists('mn_photo_finish_upload')) {
    function mn_photo_finish_upload($connect, array $out)
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        if (!empty($out['ok']) && mn_photo_drive_ready()) {
            if (function_exists('fastcgi_finish_request')) {
                header('Content-type: application/json; charset=utf-8');
                header('Content-Length: ' . strlen($json));
                echo $json;
                fastcgi_finish_request();
                ignore_user_abort(true);
                mn_photo_push_pending($connect, 5);
                maintenance_db_odbc_close($connect);
                exit;
            }
            mn_photo_push_pending($connect, 3);
        }
        maintenance_db_odbc_close($connect);
        header('Content-type: application/json; charset=utf-8');
        echo $json;
        exit;
    }
}

/**
 * ส่งรูป 1 รูปขึ้น Drive: โฟลเดอร์ <ราก>/<ปี-เดือน>/<เลขงาน รุ่น SN>/ ชื่อไฟล์ตาม mn_photo_drive_name()
 * สำเร็จแล้วลบไฟล์ขนาดดูบน server (เก็บรูปเล็กไว้) เว้นแต่ตั้ง keep_local
 */
if (!function_exists('mn_photo_push_drive')) {
    function mn_photo_push_drive($connect, $id)
    {
        if (!mn_photo_drive_ready()) { return 'ยังไม่ได้ตั้งค่า Google Drive'; }
        $row = mn_photo_row($connect, $id);
        if (!$row || !empty($row['cp_drive_id'])) { return ''; }
        $path = mn_photo_dir() . '/' . $row['cp_file'];
        if (!preg_match('/^[a-f0-9]{32}\.img$/', (string) $row['cp_file']) || !is_file($path)) { return 'ไม่พบไฟล์รูปบน server'; }

        $job = mn_photo_job_info($connect, $row);
        $cfg = mn_gd_config();
        $month = substr((string) $row['cp_at'], 0, 7);
        list($f_month, $err) = mn_gd_folder($connect, $cfg['folder_id'], $month !== '' ? $month : date('Y-m'));
        if ($err !== '') { return $err; }
        $job_name = trim($job['mtn'] . ' ' . $job['product'] . ' ' . $job['sn']);
        list($f_job, $err) = mn_gd_folder($connect, $f_month, $job_name !== '' ? $job_name : ('ใบงาน ' . $row['cp_trp_id']));
        if ($err !== '') { return $err; }
        list($file_id, $err) = mn_gd_upload($f_job, mn_photo_drive_name($job, $row), $path, (string) $row['cp_mime']);
        if ($err !== '') { return $err; }

        $keep = !empty($cfg['keep_local']);
        $upd = mysqli_prepare($connect, 'UPDATE cl_photo SET cp_drive_id = ?, cp_size = ? WHERE cp_id = ?');
        $size = $keep ? (int) $row['cp_size'] : 0;
        $pid = (int) $row['cp_id'];
        mysqli_stmt_bind_param($upd, 'sii', $file_id, $size, $pid);
        $ok = mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
        if ($ok && !$keep) { @unlink($path); }
        return $ok ? '' : 'บันทึก id ไฟล์ Drive ไม่สำเร็จ';
    }
}

/** ส่งรูปที่ค้างขึ้น Drive (เรียกหลังอัปโหลด หรือจากปุ่มส่งค้าง) → array(sent, failed, last_error) */
if (!function_exists('mn_photo_push_pending')) {
    function mn_photo_push_pending($connect, $limit = 5)
    {
        if (!mn_photo_drive_ready()) { return array(0, 0, ''); }
        mn_photo_ensure_table($connect);
        $ids = array();
        $res = mysqli_query($connect, 'SELECT cp_id FROM cl_photo WHERE cp_drive_id IS NULL ORDER BY cp_id LIMIT ' . max(1, (int) $limit));
        while ($res && ($r = mysqli_fetch_row($res))) { $ids[] = (int) $r[0]; }
        $sent = 0;
        $failed = 0;
        $last = '';
        foreach ($ids as $id) {
            $err = mn_photo_push_drive($connect, $id);
            if ($err === '') { $sent++; continue; }
            $failed++;
            $last = $err;
            mn_gd_log('push ' . $id . ': ' . $err);
            break; // Drive มีปัญหา — หยุดรอบนี้ ไว้ลองใหม่ครั้งหน้า
        }
        return array($sent, $failed, $last);
    }
}

if (!function_exists('mn_photo_pending_count')) {
    function mn_photo_pending_count($connect)
    {
        mn_photo_ensure_table($connect);
        $res = mysqli_query($connect, 'SELECT COUNT(*) FROM cl_photo WHERE cp_drive_id IS NULL');
        return $res ? (int) mysqli_fetch_row($res)[0] : 0;
    }
}
