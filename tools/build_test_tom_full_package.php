<?php
/**
 * สร้างชุด "ระบบเต็ม" สำหรับทดลองใช้งานที่ test-tom/maintenance บน server (ใช้สำเนาฐานข้อมูลจริงแยกไว้)
 *
 * ต่างจาก build_test_tom_checklist_package.php (เฉพาะหน้า checklist) — ชุดนี้คือทั้งระบบเหมือน production
 * แต่มีป้าย "ระบบทดลอง" บนแถบเมนู และไม่มีไฟล์รหัสผ่านใด ๆ (ทุกค่าอ่านจาก config/env.local.php บน server)
 *
 * ใช้: php tools/build_test_tom_full_package.php 2026-11-24-f1
 * ผลลัพธ์: deploy/<เวอร์ชัน>-test-tom-full/www/ + DEPLOY.txt
 */

$root = dirname(__DIR__);
$version = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : date('Y-m-d') . '-f1';
$out = $root . '/deploy/' . $version . '-test-tom-full';
$www = $out . '/www';
if (is_dir($out)) { fwrite(STDERR, "มีโฟลเดอร์ $out อยู่แล้ว — ตั้งชื่อเวอร์ชันใหม่\n"); exit(1); }

// ไม่ใส่ในชุด: ซอร์สคอนโทรล, เอกสาร, เครื่องมือ, ฐานข้อมูลดิบ (มีข้อมูลลูกค้า), แพ็กเก่า, ไฟล์ในเครื่อง
$skip_dirs = array('.git', '.cursor', '.claude', 'deploy', 'DB-project', 'database', 'docs', 'tools', 'config/server', 'node_modules');
$skip_ext = array('md', 'sql', 'mdb', 'accdb', 'xlsx', 'xls', 'csv', 'bak', 'log', 'zip', 'ps1');
$skip_files = array('.gitignore', 'AGENTS.md');
// config: ไม่ใส่ไฟล์ที่มีรหัสผ่าน/กุญแจ — ใช้แม่แบบ (*.example.php) ที่อ่านค่าจาก env.local.php แทน
$config_from_example = array('database', 'customer_mysql', 'inventory_mysql');

function ff_rel($root, $abs)
{
    return str_replace('\\', '/', substr($abs, strlen($root) + 1));
}

function ff_copy($src, $dst)
{
    if (!is_dir(dirname($dst))) { mkdir(dirname($dst), 0777, true); }
    copy($src, $dst);
}

$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) { continue; }
    $rel = ff_rel($root, $f->getPathname());
    $skip = false;
    foreach ($skip_dirs as $d) { if ($rel === $d || strpos($rel, $d . '/') === 0) { $skip = true; break; } }
    if ($skip || in_array($rel, $skip_files, true)) { continue; }
    if (in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), $skip_ext, true)) { continue; }
    // storage: ใส่แค่ .htaccess (log / รูป / สำรอง เป็นข้อมูลในเครื่อง)
    if (strpos($rel, 'storage/') === 0 && $rel !== 'storage/.htaccess') { continue; }
    if (strpos($rel, 'config/') === 0) {
        $base = basename($rel);
        if (preg_match('/\.local\.php$/', $base) || preg_match('/\.json$/', $base)) { continue; }
        $name = basename($rel, '.php');
        if (in_array($name, $config_from_example, true)) { continue; }
    }
    ff_copy($f->getPathname(), $www . '/' . $rel);
    $files[] = $rel;
}

foreach ($config_from_example as $name) {
    ff_copy($root . '/config/' . $name . '.example.php', $www . '/config/' . $name . '.php');
    $files[] = 'config/' . $name . '.php  (จากแม่แบบ — อ่านค่าจาก env.local.php)';
}

// ป้าย "ระบบทดลอง" บนแถบเมนู
file_put_contents($www . '/config/site_banner.local.php', "<?php\n// ป้ายบนแถบเมนู — มีเฉพาะชุดทดลอง (ลบไฟล์นี้ = ไม่แสดงป้าย)\nreturn 'ระบบทดลอง (สำเนาข้อมูล ไม่กระทบระบบจริง)';\n");
$files[] = 'config/site_banner.local.php';

// token ของ checklist_export — ให้ปุ่ม "ดึงค่าจาก server" บน localhost ยังใช้ได้ (ค่าเดียวกับในเครื่อง)
$sync_local = $root . '/config/checklist_sync.local.php';
$sync_cfg = is_file($sync_local) ? require $sync_local : array();
if (is_array($sync_cfg) && !empty($sync_cfg['token'])) {
    file_put_contents($www . '/config/checklist_sync.local.php',
        "<?php\n// เปิด checklist_export ให้ localhost ดึงการตั้งค่า — token ต้องตรงกับ config/checklist_sync.local.php ในเครื่อง\n"
        . "return array(\n    'url'    => '',\n    'token'  => " . var_export((string) $sync_cfg['token'], true) . ",\n    'cainfo' => '',\n);\n");
    $files[] = 'config/checklist_sync.local.php';
}

// รหัสเข้าหน้าตั้งค่าหลังบ้าน (hash) — ค่าตั้งต้นจาก config/settings_pin.local.php ในเครื่อง (ไม่อยู่ใน git)
if (is_file($root . '/config/settings_pin.local.php')) {
    ff_copy($root . '/config/settings_pin.local.php', $www . '/config/settings_pin.local.php');
    $files[] = 'config/settings_pin.local.php';
}

file_put_contents($www . '/VERSION.txt', $version . "\n");
$files[] = 'VERSION.txt';
sort($files);

// กันพลาด: ห้ามมีไฟล์ที่ดูเป็นความลับหลุดเข้าชุด
$bad = array();
foreach ($files as $f) {
    if (preg_match('#(credentials|token)[^/]*\.json$|config/env\.local\.php$|google_drive\.local\.php$#', $f)) { $bad[] = $f; }
}
if ($bad) { fwrite(STDERR, "พบไฟล์ลับในชุด — ยกเลิก: " . implode(', ', $bad) . "\n"); exit(1); }

$list = '';
foreach ($files as $f) { $list .= '  - ' . $f . "\n"; }
$count = count($files);

file_put_contents($out . '/DEPLOY.txt', <<<TXT
================================================================================
ชุดทดลอง "ระบบเต็ม" — $version
ปลายทาง: test-tom/maintenance บน bit-online.net (ใช้สำเนาฐานข้อมูลจริง biton_maintenance_test)
================================================================================

ระบบนี้เหมือนระบบหลักทุกหน้า + ของใหม่ (หน้าประเมินแบบทีละขั้น, รูปประกอบ, checklist)
แถบเมนูมีป้ายสีส้ม "ระบบทดลอง" — ข้อมูลที่บันทึกที่นี่ไม่ไปถึงระบบจริง

--------------------------------------------------------------------------------
1) คัดลอกข้อมูลจริงเข้า biton_maintenance_test (phpMyAdmin ใน Plesk)
--------------------------------------------------------------------------------
  [ก] เปิดฐาน biton_maintenance (ฐานจริง) → แท็บ Export → Custom
      - Tables: เลือกทั้งหมด แล้ว "เอาติ๊กออก" ทุกตารางที่ขึ้นต้นด้วย cl_
        (cl_* ในฐานทดสอบคือการตั้งค่า checklist ที่ทีมทำไว้ — ห้ามทับ)
      - Object creation options: ติ๊ก "Add DROP TABLE"
      - Format: SQL → Export (ได้ไฟล์ .sql)
  [ข] เปิดฐาน biton_maintenance_test → แท็บ Import → เลือกไฟล์จาก [ก] → Go
      (ไฟล์ใหญ่เกินที่ phpMyAdmin รับ: Export แบบ zip หรือแบ่งเป็นหลายไฟล์ตามกลุ่มตาราง)
  ทำซ้ำได้ทุกเมื่อที่อยากได้ข้อมูลล่าสุด — การตั้งค่า checklist (cl_*) ไม่หาย

--------------------------------------------------------------------------------
2) แก้ config/env.local.php ใน test-tom/maintenance (ไฟล์เดิมบน server — ไม่อยู่ในชุดนี้)
--------------------------------------------------------------------------------
  - database: คงไว้ที่ biton_maintenance_test (user ที่มีสิทธิ์เฉพาะฐานทดสอบ — ห้ามใช้ user ของฐานจริง)
  - เพิ่ม:  'maintenance_web_base' => '/test-tom/maintenance',
            'inventory_web_base'   => '/inventory',
  - คัดลอกบล็อก 'customer_mysql', 'address_mysql', 'inventory_mysql' จาก
    maintenance/config/env.local.php (ระบบหลัก) มาใส่ — ระบบอ่านอย่างเดียว (ค้นหาลูกค้า / ดูสถานะเบิกอะไหล่)
    ถ้าไม่ใส่: หน้าค้นหาลูกค้าและสถานะเบิกอะไหล่จะใช้ไม่ได้ ส่วนอื่นใช้ได้ปกติ

--------------------------------------------------------------------------------
3) อัปโหลดไฟล์
--------------------------------------------------------------------------------
  - copy ทุกอย่างใน www/ ทับ test-tom/maintenance (รวม .htaccess ที่ซ่อนอยู่)
  - ไฟล์ index.php ของชุดเก่า (เด้งไปหน้าตั้งค่า) จะถูกแทนด้วยหน้ารายการงานจริง
  - ไม่มีไฟล์รหัสผ่านในชุด: config/env.local.php / google_drive.local.php / กุญแจ .json บน server ไม่ถูกทับ

--------------------------------------------------------------------------------
4) (ไม่บังคับ) รูปประกอบขึ้น Google Drive
--------------------------------------------------------------------------------
  - ใน Shared Drive สร้างโฟลเดอร์ย่อย เช่น "ทดลอง" (รูปทดลองไม่ปนกับของจริง) → คัดลอก id
  - อัปโหลด google_drive_sa.credentials.json ไปที่ test-tom/maintenance/config/
  - สร้าง config/google_drive.local.php ตาม google_drive.example.php ใส่ folder_id ของโฟลเดอร์ "ทดลอง"
  - เปิด checklist_photo?action=drive_check → ต้องได้ "ok": true
  ไม่ตั้ง = รูปเก็บบน server (storage/photos) อย่างเดียว

--------------------------------------------------------------------------------
5) ตรวจหลังอัปโหลด
--------------------------------------------------------------------------------
  [ ] https://bit-online.net/test-tom/maintenance/VERSION.txt → $version
  [ ] https://bit-online.net/test-tom/maintenance/ → หน้ารายการงาน มีป้ายส้ม "ระบบทดลอง"
  [ ] เมนูทุกปุ่มพาไปหน้าใน /test-tom/maintenance (ไม่หลุดไป /maintenance ระบบจริง)
  [ ] รอประเมิน → เปิดใบงาน → หน้าประเมินแบบทีละขั้น → ลองแนบรูป → บันทึก
  [ ] รอเสนอราคา → เห็นสรุปการประเมินและรูป
  [ ] หน้าตั้งค่า checklist ยังเห็นการตั้งค่าเดิมของทีม

--------------------------------------------------------------------------------
ไฟล์ในชุด ($count ไฟล์)
--------------------------------------------------------------------------------
$list
TXT
);

echo "สร้างแล้ว: $out\n$count ไฟล์ใน www/\n";
