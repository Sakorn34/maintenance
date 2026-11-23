<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หัวเพจของชุด test-tom (หน้าตั้งค่ารายการตรวจเช็คที่แยกฐานข้อมูลทดสอบ) — ใช้แทน includes/title-h.php
 * ไม่มีเมนูไปหน้าระบบจริงและไม่ดึงยอดงานจากฐานข้อมูล
 */

require_once __DIR__ . '/session_profile.php';

$navProfileName = maintenance_new_profile_display_name();
if ($navProfileName === '') {
    $loginNameForNav = maintenance_new_profile_login_name();
    $navProfileName = ($loginNameForNav === null) ? '' : trim((string) $loginNameForNav);
}
?>
<nav class="mn-appbar" aria-label="ชุดทดสอบตั้งค่ารายการตรวจเช็ค">
  <div class="mn-appbar__inner">
    <div class="mn-appbar__brand">
      <a href="checklist_settings">Maintenance</a>
      <span class="mn-appbar__tagline">ชุดทดสอบ · ตั้งค่ารายการตรวจเช็ค (ยังไม่เชื่อมระบบจริง)</span>
    </div>
    <div class="mn-appbar__navwrap"></div>
    <?php if ($navProfileName !== '') { ?>
    <div class="mn-appbar__user">
      <span class="mn-appbar__username" title="ผู้ใช้ที่เข้าสู่ระบบ">
        <i class="fa fa-user-circle-o" aria-hidden="true"></i>
        <?php echo htmlspecialchars($navProfileName, ENT_QUOTES, 'UTF-8'); ?>
      </span>
    </div>
    <?php } ?>
  </div>
  <div class="mn-appbar__accent" aria-hidden="true"></div>
</nav>
