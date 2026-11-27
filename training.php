<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า "เทรนการใช้งาน" (เข้าจากหน้าตั้งค่าระบบ) — คู่มือทีละหัวข้อของ Admin / ช่าง / DDT พร้อมภาพหน้าจอจริงที่มีหมุดชี้จุดกด
 * - สถานะรายหัวข้อของทั้งทีม (เทรนแล้ว / ต้องทวน) เก็บ cl_training_mark (tm_person = 0)
 * - ข้อเสนอแนะการปรับปรุงจากผู้ใช้งาน ต่อหัวข้อ หรือเรื่องทั่วไป (cl_training_feedback) — ทุกคนที่ล็อกอินส่งได้
 * - บันทึกสถานะ / ปิดหรือลบข้อเสนอแนะ / เปลี่ยนรูป: ต้องปลดล็อกด้วยรหัสหน้าตั้งค่า (training?unlock=1)
 * - มือถือ: ซ่อนแถบเมนูระบบและรายการหัวข้อ แสดงคู่มือเต็มจอ เปลี่ยนหัวข้อจากตัวเลือกด้านบนหรือปุ่มก่อนหน้า/ถัดไป
 *
 * GET: d = ฝ่าย (admin|tech|ddt), t = id หัวข้อ, view = fb (ข้อเสนอแนะทั้งหมด)
 * POST: act = mark | fb_add | fb_done | fb_open | fb_delete | img_upload | img_reset
 */
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_maintenance_legacy.php';
require_once __DIR__ . '/includes/settings_lock_helpers.php';
require_once __DIR__ . '/includes/job_stamp_helpers.php';
require_once __DIR__ . '/includes/training_helpers.php';

function mn_tr_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$C = mn_tr_content();
$depts = $C['depts'];
$d = isset($_REQUEST['d']) && isset($depts[$_REQUEST['d']]) ? (string) $_REQUEST['d'] : 'admin';
$t = isset($_REQUEST['t']) ? preg_replace('/[^a-z0-9\-]/', '', (string) $_REQUEST['t']) : '';
$view = isset($_REQUEST['view']) && $_REQUEST['view'] === 'fb' ? 'fb' : 'guide';

if (isset($_GET['unlock'])) {
    mn_settings_gate($connect, 'page', array('title' => 'เทรนการใช้งาน', 'text' => 'ใส่รหัสหน้าตั้งค่า เพื่อบันทึกสถานะการเทรน จัดการข้อเสนอแนะ และเปลี่ยนรูป',
        'btn' => 'ปลดล็อก', 'page' => 'settings', 'cancel_href' => 'training?d=' . rawurlencode($d), 'cancel_label' => 'กลับไปอ่านคู่มือ'));
}
$unlocked = mn_settings_unlocked($connect);

$url = function ($extra = array()) use ($d, $t, $view) {
    $q = array_merge(array('d' => $d, 't' => $t, 'view' => $view === 'fb' ? 'fb' : ''), $extra);
    return 'training?' . http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== null; }));
};

// ---------- POST ----------
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['act']) ? (string) $_POST['act'] : '';
    $msg = '';
    $err = '';
    $who = mn_job_stamp_who($connect);
    $anchor = '#guide';
    if ($act === 'fb_add') {
        // ข้อเสนอแนะ: ทุกคนที่ล็อกอินส่งได้
        $err = mn_tr_feedback_add($connect, isset($_POST['fb_topic']) ? preg_replace('/[^a-z0-9\-]/', '', (string) $_POST['fb_topic']) : '', isset($_POST['text']) ? $_POST['text'] : '', $who);
        $msg = $err === '' ? 'ส่งข้อเสนอแนะแล้ว ขอบคุณครับ' : '';
        $anchor = '#fb';
    } elseif (!$unlocked) {
        $err = 'ต้องใส่รหัสหน้าตั้งค่าก่อนบันทึก';
    } elseif ($act === 'mark') {
        $status = isset($_POST['status']) && in_array($_POST['status'], array('done', 'review', ''), true) ? (string) $_POST['status'] : 'done';
        mn_tr_set_mark($connect, array(0), $t, $status, $who);
        $msg = $status === 'done' ? 'บันทึกว่าเทรนหัวข้อนี้แล้ว' : ($status === 'review' ? 'บันทึกว่าต้องทวน' : 'ล้างสถานะแล้ว');
    } elseif ($act === 'fb_done' || $act === 'fb_open' || $act === 'fb_delete') {
        mn_tr_feedback_set($connect, isset($_POST['fid']) ? (int) $_POST['fid'] : 0, substr($act, 3), $who);
        $msg = $act === 'fb_done' ? 'ปิดข้อเสนอแนะแล้ว (แก้แล้ว)' : ($act === 'fb_open' ? 'เปิดข้อเสนอแนะอีกครั้ง' : 'ลบข้อเสนอแนะแล้ว');
        $anchor = '#fb';
    } elseif ($act === 'img_upload' || $act === 'img_reset') {
        $key = mn_tr_img_key($t, isset($_POST['step']) ? (int) $_POST['step'] : 0);
        if ($act === 'img_reset') { mn_tr_reset_upload($key); $msg = 'กลับไปใช้รูปตั้งต้นแล้ว'; }
        else { $err = mn_tr_save_upload($key, isset($_FILES['img']) ? $_FILES['img'] : array()); $msg = $err === '' ? 'เปลี่ยนรูปแล้ว' : ''; }
    }
    maintenance_db_odbc_close($connect);
    header('Location: ' . $url($err !== '' ? array('err' => $err) : array('ok' => $msg)) . $anchor);
    exit;
}

// ---------- ข้อมูลหน้า ----------
$marks = mn_tr_marks($connect);
$marks = isset($marks[0]) ? $marks[0] : array();
$fb_counts = mn_tr_feedback_counts($connect);
$fb_open_all = array_sum($fb_counts);

$topics = mn_tr_topics_of($d);
$state = array();
foreach ($topics as $tp) { $state[$tp['id']] = isset($marks[$tp['id']]) ? $marks[$tp['id']]['s'] : ''; }
$topic = $t !== '' ? mn_tr_topic($t) : null;
if (!$topic || mn_tr_chapter($topic['ch'])['dept'] !== $d) {
    $topic = null;
    foreach ($topics as $tp) { if ($state[$tp['id']] !== 'done') { $topic = $tp; break; } }
    if (!$topic && $topics) { $topic = $topics[0]; }
}
$t = $topic ? $topic['id'] : '';
$fb_list = $view === 'fb' ? mn_tr_feedback($connect, '', false) : ($t !== '' ? mn_tr_feedback($connect, $t, false) : array());
maintenance_db_odbc_close($connect);

$idx = 0;
foreach ($topics as $i => $tp) { if ($tp['id'] === $t) { $idx = $i; } }
$prev = $idx > 0 ? $topics[$idx - 1] : null;
$next = $idx < count($topics) - 1 ? $topics[$idx + 1] : null;
$n_done = 0;
foreach ($state as $s) { if ($s === 'done') { $n_done++; } }
$pct = $topics ? (int) round($n_done / count($topics) * 100) : 0;
$flash_ok = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
$flash_err = isset($_GET['err']) ? (string) $_GET['err'] : '';
$dot = array('done' => array('is-done', '✓', 'เทรนแล้ว'), 'review' => array('is-review', '!', 'ต้องทวน'), '' => array('', '', 'ยังไม่เทรน'));
$topic_title = array();
foreach ($C['topics'] as $x) { $topic_title[$x['id']] = $x['id'] . ' ' . $x['title']; }
$topic_title['general'] = 'เรื่องทั่วไป';
$link = function ($tid) use ($d) { return 'training?' . http_build_query(array('d' => $d, 't' => $tid)) . '#guide'; };
$page = 'settings';

/** รายการข้อเสนอแนะ (ใช้ทั้งในหัวข้อ และหน้ารวม) */
$render_fb = function (array $rows, $show_topic) use ($unlocked, $d, $t, $view, $topic_title) {
    if (!$rows) { echo '<p class="mn-tr__muted">ยังไม่มีข้อเสนอแนะ</p>'; return; }
    echo '<ul class="mn-tr__fbl">';
    foreach ($rows as $r) {
        $done = (int) $r['tf_done'] === 1;
        echo '<li class="' . ($done ? 'is-done' : '') . '"><div class="mn-tr__fbt">' . nl2br(mn_tr_h($r['tf_text'])) . '</div><div class="mn-tr__fbm">';
        if ($show_topic) {
            echo '<a href="' . mn_tr_h('training?t=' . rawurlencode($r['tf_topic']) . '&d=' . rawurlencode(substr($r['tf_topic'], 0, 1) === 't' ? 'tech' : (substr($r['tf_topic'], 0, 1) === 'd' ? 'ddt' : 'admin'))) . '#fb">'
                . mn_tr_h(isset($topic_title[$r['tf_topic']]) ? $topic_title[$r['tf_topic']] : $r['tf_topic']) . '</a> ';
        }
        echo '<span>' . mn_tr_h(trim($r['tf_by'] . ' ' . substr($r['tf_at'], 8, 2) . '/' . substr($r['tf_at'], 5, 2) . '/' . substr($r['tf_at'], 0, 4))) . '</span>';
        if ($done) { echo ' <span class="mn-tr__fbdone"><i class="fa fa-check" aria-hidden="true"></i> แก้แล้ว' . ($r['tf_done_by'] ? ' (' . mn_tr_h($r['tf_done_by']) . ')' : '') . '</span>'; }
        echo '</div>';
        if ($unlocked) {
            echo '<form method="post" action="training" class="mn-tr__fba"><input type="hidden" name="d" value="' . mn_tr_h($d) . '" /><input type="hidden" name="t" value="' . mn_tr_h($t) . '" />'
                . ($view === 'fb' ? '<input type="hidden" name="view" value="fb" />' : '') . '<input type="hidden" name="fid" value="' . (int) $r['tf_id'] . '" />'
                . ($done ? '<button type="submit" name="act" value="fb_open">เปิดใหม่</button>' : '<button type="submit" name="act" value="fb_done" class="is-ok"><i class="fa fa-check" aria-hidden="true"></i> แก้แล้ว</button>')
                . '<button type="submit" name="act" value="fb_delete" onclick="return confirm(\'ลบข้อเสนอแนะนี้?\')">ลบ</button></form>';
        }
        echo '</li>';
    }
    echo '</ul>';
};
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-training.css?v=<?php echo filemtime(__DIR__ . '/css/mn-page-training.css'); ?>" />
<title><?php echo $view === 'fb' ? 'ข้อเสนอแนะ | ' : ($topic ? mn_tr_h($topic['id'] . ' ' . $topic['title']) . ' | ' : ''); ?>เทรนการใช้งาน</title>
</head>
<body class="mn-addjob-page mn-training-page">
  <div class="header">
    <?php require_once __DIR__ . '/includes/title-h.php'; ?>
  </div>
  <div class="content mn-workspace">
    <div class="mn-tr">
      <header class="mn-tr__head">
        <a class="mn-tr__back" href="settings"><i class="fa fa-angle-left" aria-hidden="true"></i> ตั้งค่าระบบ</a>
        <h1><i class="fa fa-graduation-cap" aria-hidden="true"></i> เทรนการใช้งาน</h1>
        <a class="mn-tr__fbbtn<?php echo $view === 'fb' ? ' is-on' : ''; ?>" href="<?php echo mn_tr_h('training?d=' . rawurlencode($d) . '&view=fb'); ?>"><i class="fa fa-comments-o" aria-hidden="true"></i> ข้อเสนอแนะ<?php if ($fb_open_all > 0) { ?> <b><?php echo (int) $fb_open_all; ?></b><?php } ?></a>
        <?php if ($unlocked) { ?>
        <span class="mn-tr__lock is-open"><i class="fa fa-unlock" aria-hidden="true"></i> บันทึกได้</span>
        <?php } else { ?>
        <a class="mn-tr__lock" href="<?php echo mn_tr_h($url(array('unlock' => 1))); ?>"><i class="fa fa-lock" aria-hidden="true"></i> ใส่รหัส</a>
        <?php } ?>
      </header>
      <?php if ($flash_ok !== '') { ?><div class="mn-tr__alert is-ok" role="status"><i class="fa fa-check-circle" aria-hidden="true"></i> <?php echo mn_tr_h($flash_ok); ?></div><?php } ?>
      <?php if ($flash_err !== '') { ?><div class="mn-tr__alert is-bad" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <?php echo mn_tr_h($flash_err); ?></div><?php } ?>

      <nav class="mn-tr__depts" aria-label="ฝ่าย">
        <?php foreach ($depts as $dk => $dv) { ?>
        <a class="<?php echo $dk === $d && $view !== 'fb' ? 'is-on' : ''; ?>" href="training?d=<?php echo mn_tr_h($dk); ?>"><b><?php echo mn_tr_h($dv[0]); ?></b><small><?php echo mn_tr_h($dv[1]); ?></small></a>
        <?php } ?>
      </nav>

      <?php if ($view === 'fb') { ?>
      <section class="mn-tr__guide mn-tr__fbpage" id="fb">
        <h2><i class="fa fa-comments-o" aria-hidden="true"></i> ข้อเสนอแนะการปรับปรุงจากผู้ใช้งาน</h2>
        <p class="mn-tr__when">ยังไม่แก้ <?php echo (int) $fb_open_all; ?> เรื่อง ใครใช้งานแล้วติดตรงไหน หรืออยากให้ระบบทำอะไรเพิ่ม เขียนได้เลย</p>
        <form class="mn-tr__fbf" method="post" action="training">
          <input type="hidden" name="act" value="fb_add" /><input type="hidden" name="d" value="<?php echo mn_tr_h($d); ?>" /><input type="hidden" name="view" value="fb" />
          <label for="mn_tr_fbtopic">เรื่อง</label>
          <select name="fb_topic" id="mn_tr_fbtopic">
            <option value="general">เรื่องทั่วไป (ไม่เจาะจงหัวข้อ)</option>
            <?php foreach ($C['chapters'] as $ch) { ?>
            <optgroup label="<?php echo mn_tr_h($depts[$ch['dept']][0] . ': ' . $ch['title']); ?>">
              <?php foreach ($C['topics'] as $x) { if ($x['ch'] !== $ch['id']) { continue; } ?>
              <option value="<?php echo mn_tr_h($x['id']); ?>"><?php echo mn_tr_h($x['id'] . ' ' . $x['title']); ?></option>
              <?php } ?>
            </optgroup>
            <?php } ?>
          </select>
          <textarea name="text" rows="3" maxlength="1000" required placeholder="เช่น ปุ่มนี้หายาก / อยากให้หน้านี้แสดงข้อมูลเพิ่ม / ขั้นตอนนี้ยังไม่เข้าใจ"></textarea>
          <button type="submit" class="mn-tr__btn is-done"><i class="fa fa-paper-plane" aria-hidden="true"></i> ส่งข้อเสนอแนะ</button>
        </form>
        <?php $render_fb($fb_list, true); ?>
      </section>
      <?php } else { ?>
      <div class="mn-tr__layout">
        <aside class="mn-tr__side">
          <div class="mn-tr__sh">
            <span class="mn-tr__sum-t"><i class="fa fa-list-ul" aria-hidden="true"></i> หัวข้อ <?php echo mn_tr_h($depts[$d][0]); ?></span>
            <span class="mn-tr__sum-p"><?php echo (int) $n_done; ?>/<?php echo count($topics); ?></span>
          </div>
          <div class="mn-tr__prog"><span class="mn-tr__bar"><i style="width:<?php echo (int) $pct; ?>%"></i></span><span>เทรนแล้ว <?php echo (int) $pct; ?>%</span></div>
          <?php
          foreach ($C['chapters'] as $ch) {
              if ($ch['dept'] !== $d) { continue; }
              $cts = array_values(array_filter($topics, function ($x) use ($ch) { return $x['ch'] === $ch['id']; }));
              $cd = 0;
              foreach ($cts as $x) { if ($state[$x['id']] === 'done') { $cd++; } }
              ?>
          <div class="mn-tr__ch">
            <h3><span><?php echo mn_tr_h($ch['title']); ?></span><small><?php echo $cd; ?>/<?php echo count($cts); ?></small></h3>
            <?php foreach ($cts as $x) { $dt = $dot[$state[$x['id']]]; ?>
            <a class="mn-tr__tp<?php echo $x['id'] === $t ? ' is-on' : ''; ?>" href="<?php echo mn_tr_h($link($x['id'])); ?>" title="<?php echo mn_tr_h($dt[2]); ?>">
              <span class="mn-tr__dot <?php echo $dt[0]; ?>" aria-label="<?php echo mn_tr_h($dt[2]); ?>"><?php echo $dt[1]; ?></span>
              <span class="mn-tr__no"><?php echo mn_tr_h($x['id']); ?></span><span class="mn-tr__tt"><?php echo mn_tr_h($x['title']); ?></span>
              <?php if (!empty($fb_counts[$x['id']])) { ?><span class="mn-tr__fbc" title="ข้อเสนอแนะที่ยังไม่แก้"><i class="fa fa-comment" aria-hidden="true"></i> <?php echo (int) $fb_counts[$x['id']]; ?></span><?php } ?>
            </a>
            <?php } ?>
          </div>
          <?php } ?>
        </aside>

        <section class="mn-tr__guide" id="guide">
          <?php if (!$topic) { ?>
          <p class="mn-tr__muted">ยังไม่มีหัวข้อของฝ่ายนี้</p>
          <?php } else { $chp = mn_tr_chapter($topic['ch']); $st = $state[$t]; $dt = $dot[$st]; ?>
          <form class="mn-tr__jump" method="get" action="training">
            <input type="hidden" name="d" value="<?php echo mn_tr_h($d); ?>" />
            <label for="mn_tr_jump">หัวข้อ <?php echo $idx + 1; ?>/<?php echo count($topics); ?> | เทรนแล้ว <?php echo (int) $n_done; ?></label>
            <select name="t" id="mn_tr_jump" onchange="location.href='training?d=<?php echo mn_tr_h(rawurlencode($d)); ?>&amp;t='+encodeURIComponent(this.value)+'#guide'">
              <?php foreach ($C['chapters'] as $ch) { if ($ch['dept'] !== $d) { continue; } ?>
              <optgroup label="<?php echo mn_tr_h($ch['title']); ?>">
                <?php foreach ($topics as $x) { if ($x['ch'] !== $ch['id']) { continue; } ?>
                <option value="<?php echo mn_tr_h($x['id']); ?>"<?php echo $x['id'] === $t ? ' selected' : ''; ?>><?php echo ($state[$x['id']] === 'done' ? '✓ ' : ($state[$x['id']] === 'review' ? '! ' : '')) . mn_tr_h($x['id'] . ' ' . $x['title']); ?></option>
                <?php } ?>
              </optgroup>
              <?php } ?>
            </select>
            <noscript><button type="submit">ไป</button></noscript>
          </form>
          <p class="mn-tr__crumb"><?php echo mn_tr_h($chp['title']); ?></p>
          <h2><span class="mn-tr__gno"><?php echo mn_tr_h($topic['id']); ?></span> <?php echo mn_tr_h($topic['title']); ?></h2>
          <div class="mn-tr__chips">
            <span class="mn-tr__chip is-dept"><?php echo mn_tr_h($depts[$d][0]); ?></span>
            <span class="mn-tr__chip"><i class="fa fa-clock-o" aria-hidden="true"></i> <?php echo (int) $topic['min']; ?> นาที</span>
            <span class="mn-tr__chip"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo mn_tr_h($topic['where']); ?></span>
            <span class="mn-tr__chip mn-tr__st <?php echo $dt[0]; ?>"><?php echo mn_tr_h($dt[2]); ?><?php if ($st !== '' && isset($marks[$t])) { echo ' ' . mn_tr_h(substr($marks[$t]['at'], 8, 2) . '/' . substr($marks[$t]['at'], 5, 2)); } ?></span>
          </div>
          <p class="mn-tr__when"><b>ใช้เมื่อ</b> <?php echo mn_tr_h($topic['when']); ?></p>
          <div class="mn-tr__why"><b>ทำไมสำคัญ</b> <?php echo mn_tr_h($topic['why']); ?></div>

          <ol class="mn-tr__steps">
            <?php foreach ($topic['steps'] as $si => $step) {
                $sn = $si + 1;
                list($src, $custom) = mn_tr_img_url($t, $sn, isset($step['img']) ? $step['img'] : '');
                $pins = isset($step['pins']) ? $step['pins'] : array();
                ?>
            <li class="mn-tr__step">
              <span class="mn-tr__sn"><?php echo $sn; ?></span>
              <div class="mn-tr__sb">
                <b><?php echo mn_tr_h($step['t']); ?></b>
                <?php if ($step['d'] !== '') { ?><p><?php echo mn_tr_h($step['d']); ?></p><?php } ?>
                <?php if ($src !== '') { ?>
                <figure class="mn-tr__shot">
                  <div class="mn-tr__img">
                    <a href="<?php echo mn_tr_h($src); ?>" target="_blank" rel="noopener" title="เปิดรูปขนาดเต็ม"><img src="<?php echo mn_tr_h($src); ?>" alt="<?php echo mn_tr_h($step['t']); ?>" loading="lazy" /></a>
                    <?php if (!$custom) { foreach ($pins as $pi => $pin) { ?>
                    <span class="mn-tr__pin" style="left:<?php echo max(2, min(97, (float) $pin[0])); ?>%;top:<?php echo max(3, min(97, (float) $pin[1])); ?>%"><?php echo $pi + 1; ?></span>
                    <?php } } ?>
                  </div>
                  <?php if ($pins) { ?>
                  <figcaption><ol class="mn-tr__legend">
                    <?php foreach ($pins as $pi => $pin) { ?><li><span><?php echo $pi + 1; ?></span><?php echo mn_tr_h($pin[2]); ?></li><?php } ?>
                  </ol></figcaption>
                  <?php } ?>
                </figure>
                <?php } ?>
                <?php if ($unlocked && (isset($step['img']) || $custom)) { ?>
                <form class="mn-tr__imgform" method="post" action="training" enctype="multipart/form-data">
                  <input type="hidden" name="d" value="<?php echo mn_tr_h($d); ?>" /><input type="hidden" name="t" value="<?php echo mn_tr_h($t); ?>" /><input type="hidden" name="step" value="<?php echo $sn; ?>" />
                  <label><i class="fa fa-picture-o" aria-hidden="true"></i> เปลี่ยนรูป <input type="file" name="img" accept="image/jpeg,image/png" onchange="this.form.submit()" /></label>
                  <input type="hidden" name="act" value="img_upload" />
                  <?php if ($custom) { ?><button type="submit" name="act" value="img_reset" class="mn-tr__link">ใช้รูปตั้งต้น</button><?php } ?>
                </form>
                <?php } ?>
              </div>
            </li>
            <?php } ?>
          </ol>

          <?php if (!empty($topic['warn'])) { ?>
          <div class="mn-tr__warn"><b><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> ข้อควรระวัง</b>
            <ul><?php foreach ($topic['warn'] as $w) { ?><li><?php echo mn_tr_h($w); ?></li><?php } ?></ul></div>
          <?php } ?>
          <?php if (!empty($topic['try'])) { ?>
          <div class="mn-tr__try"><b><i class="fa fa-hand-pointer-o" aria-hidden="true"></i> ให้ผู้เข้าเทรนลองทำ</b>
            <ul><?php foreach ($topic['try'] as $w) { ?><li><?php echo mn_tr_h($w); ?></li><?php } ?></ul></div>
          <?php } ?>

          <div class="mn-tr__mark">
            <?php if ($unlocked) { ?>
            <form method="post" action="training">
              <input type="hidden" name="act" value="mark" /><input type="hidden" name="d" value="<?php echo mn_tr_h($d); ?>" /><input type="hidden" name="t" value="<?php echo mn_tr_h($t); ?>" />
              <button type="submit" name="status" value="done" class="mn-tr__btn is-done"><i class="fa fa-check" aria-hidden="true"></i> เทรนหัวข้อนี้แล้ว</button>
              <button type="submit" name="status" value="review" class="mn-tr__btn is-review"><i class="fa fa-exclamation" aria-hidden="true"></i> ต้องทวน</button>
              <?php if ($st !== '') { ?><button type="submit" name="status" value="" class="mn-tr__btn">ล้างสถานะ</button><?php } ?>
            </form>
            <?php } else { ?>
            <a class="mn-tr__unlock" href="<?php echo mn_tr_h($url(array('unlock' => 1))); ?>"><i class="fa fa-lock" aria-hidden="true"></i> ใส่รหัสเพื่อบันทึกว่าเทรนแล้ว</a>
            <?php } ?>
          </div>

          <div class="mn-tr__fb" id="fb">
            <h3><i class="fa fa-comments-o" aria-hidden="true"></i> ข้อเสนอแนะการปรับปรุง<?php if (!empty($fb_counts[$t])) { ?> <small>ยังไม่แก้ <?php echo (int) $fb_counts[$t]; ?></small><?php } ?></h3>
            <form class="mn-tr__fbf" method="post" action="training">
              <input type="hidden" name="act" value="fb_add" /><input type="hidden" name="d" value="<?php echo mn_tr_h($d); ?>" /><input type="hidden" name="t" value="<?php echo mn_tr_h($t); ?>" /><input type="hidden" name="fb_topic" value="<?php echo mn_tr_h($t); ?>" />
              <textarea name="text" rows="2" maxlength="1000" required aria-label="ข้อเสนอแนะการปรับปรุงหัวข้อนี้" placeholder="ใช้งานส่วนนี้แล้วติดตรงไหน หรืออยากให้ปรับอะไร เขียนได้เลย"></textarea>
              <button type="submit" class="mn-tr__btn is-done"><i class="fa fa-paper-plane" aria-hidden="true"></i> ส่ง</button>
            </form>
            <?php $render_fb($fb_list, false); ?>
          </div>

          <nav class="mn-tr__pn">
            <?php if ($prev) { ?><a href="<?php echo mn_tr_h($link($prev['id'])); ?>"><i class="fa fa-angle-left" aria-hidden="true"></i> <span><?php echo mn_tr_h($prev['id'] . ' ' . $prev['title']); ?></span></a><?php } else { ?><span></span><?php } ?>
            <?php if ($next) { ?><a class="is-next" href="<?php echo mn_tr_h($link($next['id'])); ?>"><span><?php echo mn_tr_h($next['id'] . ' ' . $next['title']); ?></span> <i class="fa fa-angle-right" aria-hidden="true"></i></a><?php } ?>
          </nav>
          <?php } ?>
        </section>
      </div>
      <?php } ?>
    </div>
  </div>
</body>
</html>
