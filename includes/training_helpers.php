<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า "เทรนการใช้งาน" — สถานะรายหัวข้อ (cl_training_mark, tm_person = 0 = ทั้งทีม), ข้อเสนอแนะจากผู้ใช้งาน (cl_training_feedback),
 * รูปที่เปลี่ยนเอง (storage/training/<topic>-<step>.jpg|png) ทับรูปตั้งต้นใน _img/training/
 * เนื้อหาคู่มืออยู่ใน includes/training_content.php
 */

if (!function_exists('mn_tr_content')) {
    function mn_tr_content()
    {
        static $c = null;
        if ($c === null) { $c = require __DIR__ . '/training_content.php'; }
        return $c;
    }
}

if (!function_exists('mn_tr_ensure')) {
    function mn_tr_ensure($connect)
    {
        static $done = false;
        if ($done || !($connect instanceof mysqli)) { return; }
        $done = true;
        @mysqli_query($connect, 'CREATE TABLE IF NOT EXISTS cl_training_mark ('
            . ' tm_person INT NOT NULL, tm_topic VARCHAR(10) NOT NULL, tm_status VARCHAR(10) NOT NULL,'
            . ' tm_at DATETIME NOT NULL, tm_by VARCHAR(100) NULL, PRIMARY KEY (tm_person, tm_topic)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        @mysqli_query($connect, 'CREATE TABLE IF NOT EXISTS cl_training_feedback ('
            . ' tf_id INT AUTO_INCREMENT PRIMARY KEY, tf_topic VARCHAR(10) NOT NULL, tf_text TEXT NOT NULL,'
            . ' tf_by VARCHAR(100) NULL, tf_at DATETIME NOT NULL, tf_done TINYINT NOT NULL DEFAULT 0, tf_done_by VARCHAR(100) NULL, tf_done_at DATETIME NULL,'
            . ' KEY k_topic (tf_topic)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}

/** ความคืบหน้าทั้งหมด → array(person_id => array(topic_id => array('s','at','by'))) */
if (!function_exists('mn_tr_marks')) {
    function mn_tr_marks($connect)
    {
        mn_tr_ensure($connect);
        $out = array();
        $res = @mysqli_query($connect, 'SELECT tm_person, tm_topic, tm_status, tm_at, tm_by FROM cl_training_mark WHERE tm_person = 0');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[(int) $r['tm_person']][(string) $r['tm_topic']] = array('s' => (string) $r['tm_status'], 'at' => (string) $r['tm_at'], 'by' => (string) $r['tm_by']);
        }
        return $out;
    }
}

/** หัวข้อของฝ่าย (เรียงตามบท) */
if (!function_exists('mn_tr_topics_of')) {
    function mn_tr_topics_of($dept)
    {
        $c = mn_tr_content();
        $chs = array();
        foreach ($c['chapters'] as $ch) { if ($dept === '' || $ch['dept'] === $dept) { $chs[$ch['id']] = true; } }
        $out = array();
        foreach ($c['topics'] as $t) { if (isset($chs[$t['ch']])) { $out[] = $t; } }
        return $out;
    }
}

if (!function_exists('mn_tr_topic')) {
    function mn_tr_topic($id)
    {
        foreach (mn_tr_content()['topics'] as $t) { if ($t['id'] === $id) { return $t; } }
        return null;
    }
}

if (!function_exists('mn_tr_chapter')) {
    function mn_tr_chapter($id)
    {
        foreach (mn_tr_content()['chapters'] as $ch) { if ($ch['id'] === $id) { return $ch; } }
        return null;
    }
}

/** ภาพรวม (ใช้ป้ายในหน้าตั้งค่า) → array(หัวข้อที่เทรนแล้ว, หัวข้อทั้งหมด, ข้อเสนอแนะที่ยังไม่แก้) */
if (!function_exists('mn_tr_overall')) {
    function mn_tr_overall($connect)
    {
        $marks = mn_tr_marks($connect);
        $all = mn_tr_content()['topics'];
        $done = 0;
        foreach ($all as $t) { if (isset($marks[0][$t['id']]) && $marks[0][$t['id']]['s'] === 'done') { $done++; } }
        $open = 0;
        $res = @mysqli_query($connect, 'SELECT COUNT(*) FROM cl_training_feedback WHERE tf_done = 0');
        if ($res && ($r = mysqli_fetch_row($res))) { $open = (int) $r[0]; }
        return array($done, count($all), $open);
    }
}

// ---------- ข้อเสนอแนะจากผู้ใช้งาน ----------

/** ข้อเสนอแนะ → array of rows (ล่าสุดก่อน); $topic = '' คือทุกหัวข้อ */
if (!function_exists('mn_tr_feedback')) {
    function mn_tr_feedback($connect, $topic = '', $open_only = false)
    {
        mn_tr_ensure($connect);
        $out = array();
        $sql = 'SELECT tf_id, tf_topic, tf_text, tf_by, tf_at, tf_done, tf_done_by, tf_done_at FROM cl_training_feedback';
        $w = array();
        if ($topic !== '') { $w[] = 'tf_topic = ?'; }
        if ($open_only) { $w[] = 'tf_done = 0'; }
        if ($w) { $sql .= ' WHERE ' . implode(' AND ', $w); }
        $sql .= ' ORDER BY tf_done, tf_id DESC LIMIT 300';
        $st = mysqli_prepare($connect, $sql);
        if (!$st) { return $out; }
        if ($topic !== '') { mysqli_stmt_bind_param($st, 's', $topic); }
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($res && ($r = mysqli_fetch_assoc($res))) { $out[] = $r; }
        mysqli_stmt_close($st);
        return $out;
    }
}

/** จำนวนข้อเสนอแนะที่ยังไม่แก้ ต่อหัวข้อ → array(topic => n) */
if (!function_exists('mn_tr_feedback_counts')) {
    function mn_tr_feedback_counts($connect)
    {
        mn_tr_ensure($connect);
        $out = array();
        $res = @mysqli_query($connect, 'SELECT tf_topic, COUNT(*) n FROM cl_training_feedback WHERE tf_done = 0 GROUP BY tf_topic');
        while ($res && ($r = mysqli_fetch_assoc($res))) { $out[(string) $r['tf_topic']] = (int) $r['n']; }
        return $out;
    }
}

if (!function_exists('mn_tr_feedback_add')) {
    function mn_tr_feedback_add($connect, $topic, $text, $by)
    {
        mn_tr_ensure($connect);
        $text = trim(str_replace("", '', (string) $text));
        $text = mb_substr($text, 0, 1000, 'UTF-8');
        if ($text === '') { return 'กรุณาพิมพ์ข้อเสนอแนะ'; }
        if ($topic !== 'general' && !mn_tr_topic($topic)) { return 'ไม่พบหัวข้อ'; }
        $by = mb_substr((string) $by, 0, 100, 'UTF-8');
        $st = mysqli_prepare($connect, 'INSERT INTO cl_training_feedback (tf_topic, tf_text, tf_by, tf_at) VALUES (?, ?, ?, NOW())');
        mysqli_stmt_bind_param($st, 'sss', $topic, $text, $by);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        return '';
    }
}

/** $act: done (แก้แล้ว) | open (เปิดใหม่) | delete */
if (!function_exists('mn_tr_feedback_set')) {
    function mn_tr_feedback_set($connect, $id, $act, $by)
    {
        mn_tr_ensure($connect);
        $id = (int) $id;
        $by = mb_substr((string) $by, 0, 100, 'UTF-8');
        if ($act === 'delete') {
            $st = mysqli_prepare($connect, 'DELETE FROM cl_training_feedback WHERE tf_id = ? LIMIT 1');
            mysqli_stmt_bind_param($st, 'i', $id);
        } elseif ($act === 'done') {
            $st = mysqli_prepare($connect, 'UPDATE cl_training_feedback SET tf_done = 1, tf_done_by = ?, tf_done_at = NOW() WHERE tf_id = ? LIMIT 1');
            mysqli_stmt_bind_param($st, 'si', $by, $id);
        } else {
            $st = mysqli_prepare($connect, 'UPDATE cl_training_feedback SET tf_done = 0, tf_done_by = NULL, tf_done_at = NULL WHERE tf_id = ? LIMIT 1');
            mysqli_stmt_bind_param($st, 'i', $id);
        }
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
}

// ---------- รูป ----------

if (!function_exists('mn_tr_custom_dir')) {
    function mn_tr_custom_dir()
    {
        return dirname(__DIR__) . '/storage/training';
    }
}

/** key รูป = <topic>-<step> (step นับจาก 1) */
if (!function_exists('mn_tr_img_key')) {
    function mn_tr_img_key($topic_id, $step_no)
    {
        return preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $topic_id)) . '-' . (int) $step_no;
    }
}

if (!function_exists('mn_tr_custom_file')) {
    function mn_tr_custom_file($key)
    {
        $key = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $key));
        if ($key === '') { return ''; }
        foreach (array('jpg', 'png') as $ext) {
            $f = mn_tr_custom_dir() . '/' . $key . '.' . $ext;
            if (is_file($f)) { return $f; }
        }
        return '';
    }
}

/** URL รูปของขั้น → array(url, custom?) หรือ array('', false) */
if (!function_exists('mn_tr_img_url')) {
    function mn_tr_img_url($topic_id, $step_no, $img)
    {
        $key = mn_tr_img_key($topic_id, $step_no);
        $cf = mn_tr_custom_file($key);
        if ($cf !== '') { return array('training_img?k=' . rawurlencode($key) . '&v=' . filemtime($cf), true); }
        $img = preg_replace('/[^a-z0-9_]/', '', (string) $img);
        if ($img === '') { return array('', false); }
        $f = dirname(__DIR__) . '/_img/training/' . $img . '.jpg';
        if (!is_file($f)) { return array('', false); }
        return array('_img/training/' . $img . '.jpg?v=' . filemtime($f), false);
    }
}

// ---------- บันทึก ----------

/** ตั้งสถานะหัวข้อให้หลายคน: $status done | review | '' (ล้าง) */
if (!function_exists('mn_tr_set_mark')) {
    function mn_tr_set_mark($connect, array $person_ids, $topic_id, $status, $by)
    {
        mn_tr_ensure($connect);
        if (!mn_tr_topic($topic_id)) { return; }
        $by = mb_substr((string) $by, 0, 100, 'UTF-8');
        foreach ($person_ids as $pid) {
            $pid = (int) $pid;
            if ($pid < 0) { continue; }
            if ($status === '') {
                $st = mysqli_prepare($connect, 'DELETE FROM cl_training_mark WHERE tm_person = ? AND tm_topic = ?');
                mysqli_stmt_bind_param($st, 'is', $pid, $topic_id);
            } else {
                $st = mysqli_prepare($connect, 'INSERT INTO cl_training_mark (tm_person, tm_topic, tm_status, tm_at, tm_by) VALUES (?, ?, ?, NOW(), ?)'
                    . ' ON DUPLICATE KEY UPDATE tm_status = VALUES(tm_status), tm_at = VALUES(tm_at), tm_by = VALUES(tm_by)');
                mysqli_stmt_bind_param($st, 'isss', $pid, $topic_id, $status, $by);
            }
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }
}

/** เก็บรูปที่อัปโหลดแทนรูปตั้งต้น (jpg/png ไม่เกิน 4 MB) → '' หรือข้อความผิดพลาด */
if (!function_exists('mn_tr_save_upload')) {
    function mn_tr_save_upload($key, array $file)
    {
        $key = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $key));
        if ($key === '' || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) { return 'ไม่พบไฟล์ที่อัปโหลด'; }
        if ((int) $file['size'] > 4 * 1024 * 1024) { return 'ไฟล์ใหญ่เกิน 4 MB'; }
        $info = @getimagesize($file['tmp_name']);
        $ext = $info && $info[2] === IMAGETYPE_PNG ? 'png' : ($info && $info[2] === IMAGETYPE_JPEG ? 'jpg' : '');
        if ($ext === '') { return 'รองรับเฉพาะรูป JPG หรือ PNG'; }
        $dir = mn_tr_custom_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { return 'สร้างโฟลเดอร์เก็บรูปไม่ได้'; }
        foreach (array('jpg', 'png') as $e) { if (is_file($dir . '/' . $key . '.' . $e)) { @unlink($dir . '/' . $key . '.' . $e); } }
        if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $key . '.' . $ext)) { return 'บันทึกรูปไม่สำเร็จ'; }
        return '';
    }
}

if (!function_exists('mn_tr_reset_upload')) {
    function mn_tr_reset_upload($key)
    {
        $f = mn_tr_custom_file($key);
        if ($f !== '') { @unlink($f); }
    }
}
