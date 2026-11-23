<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * สร้างชุดไฟล์ "test-tom" — หน้าตั้งค่ารายการตรวจเช็คที่แยกฐานข้อมูลทดสอบ ให้ทีมตั้งค่าบน server ได้โดยไม่แตะระบบจริง
 *
 * ผลลัพธ์: deploy/<version>-test-tom-checklist/
 *   www/   → วางทั้งหมดในโฟลเดอร์ test-tom บน server
 *   sql/01_reference_data.sql   → ตารางอ้างอิง (รุ่น / รายการซ่อม / ประวัติซ่อมแบบย่อ / ชื่อพนักงาน) import ซ้ำได้
 *   sql/02_checklist_start.sql  → ข้อตรวจตั้งต้นจากเครื่องนี้ import ครั้งแรกครั้งเดียว
 *   DEPLOY.txt
 *
 * ใช้: php tools/build_test_tom_checklist_package.php [version]   (default = วันที่วันนี้-r1)
 */

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
date_default_timezone_set('Asia/Bangkok');

$root = realpath(__DIR__ . '/..');
$version = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : date('Y-m-d') . '-r1';
$out = $root . '/deploy/' . $version . '-test-tom-checklist';
$www = $out . '/www';

require $root . '/includes/mysql_odbc_compat.php';

// ---------- files ----------

$entries = array('checklist_settings.php', 'checklist_settings_api.php', 'checklist_try.php', 'checklist_items.php', 'suggest_rates.php', 'checklist_export.php');
$assets = array(
    '.htaccess',
    'css/mn-theme.css', 'css/mn-layout.css', 'css/mn-nav.css', 'css/site-legacy.css',
    'css/mn-page-addjob.css', 'css/mn-page-checklist-settings.css', 'css/mn-page-assess-repair.css', 'css/mn-modal.css',
    'js/jquery-scroll.js', 'js/checklist_settings-page.js', 'js/assess_repair-page.js', 'js/mn-modal.js',
    'config/app_environment.php',
);
$skip = array('includes/title-h.php', 'config/database.php');

function tt_rel($root, $abs)
{
    return str_replace('\\', '/', substr($abs, strlen($root) + 1));
}

// ไล่ require_once __DIR__ . '...' ทุกชั้น (รวมที่อยู่ในฟังก์ชัน) เพื่อไม่ให้ขาดไฟล์
$queue = $entries;
$php = array();
while ($queue) {
    $rel = array_shift($queue);
    if (isset($php[$rel])) { continue; }
    $php[$rel] = true;
    $src = file_get_contents($root . '/' . $rel);
    if (!preg_match_all("/__DIR__\\s*\\.\\s*'([^']+\\.php)'/", $src, $m)) { continue; }
    foreach ($m[1] as $path) {
        $abs = realpath(dirname($root . '/' . $rel) . '/' . ltrim($path, '/'));
        if ($abs === false) { continue; }
        $r = tt_rel($root, $abs);
        if (!in_array($r, $skip, true) && !isset($php[$r])) { $queue[] = $r; }
    }
}

function tt_copy($from, $to)
{
    if (!is_dir(dirname($to))) { mkdir(dirname($to), 0777, true); }
    copy($from, $to);
}

if (is_dir($out)) { exit("มีโฟลเดอร์นี้แล้ว: $out — ใส่ version ใหม่\n"); }
$files = array_merge(array_keys($php), $assets);
sort($files);
foreach ($files as $f) { tt_copy($root . '/' . $f, $www . '/' . $f); }

tt_copy(__DIR__ . '/test_tom/title-h.php', $www . '/includes/title-h.php');
tt_copy($root . '/config/database.example.php', $www . '/config/database.php');
file_put_contents($www . '/index.php', "<?php\nheader('Location: checklist_settings');\n");
file_put_contents($www . '/config/env.local.example.php', <<<'PHP'
<?php
// คัดลอกเป็น config/env.local.php บน server แล้วใส่ user/password ของฐานข้อมูลทดสอบ
return array(
    'app_env' => 'production',
    'auth_login_url' => 'https://bit-online.net/bitlogin/index.php',
    'database' => array(
        'host' => 'localhost',
        'user' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'name' => 'biton_maintenance_test',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ),
);

PHP
);

// token ของ checklist_export (ให้ localhost ดึงการตั้งค่าจาก server) — ใช้ค่าเดียวกับ config/checklist_sync.local.php ในเครื่อง
$sync_extra = array();
$sync_local = $root . '/config/checklist_sync.local.php';
$sync_cfg = is_file($sync_local) ? require $sync_local : array();
if (is_array($sync_cfg) && !empty($sync_cfg['token'])) {
    file_put_contents($www . '/config/checklist_sync.local.php',
        "<?php\n// เปิด checklist_export ให้ localhost ดึงการตั้งค่า — token ต้องตรงกับ config/checklist_sync.local.php ในเครื่อง\n"
        . "return array(\n    'url'    => '',\n    'token'  => " . var_export((string) $sync_cfg['token'], true) . ",\n    'cainfo' => '',\n);\n");
    $sync_extra[] = 'config/checklist_sync.local.php';
} else {
    echo "คำเตือน: ไม่มี config/checklist_sync.local.php (token) — ปุ่มดึงค่าจาก server จะใช้ไม่ได้\n";
}

// ---------- SQL ----------

function tt_val($c, $v)
{
    return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($c, (string) $v) . "'";
}

function tt_inserts($c, $table, $sql, $ignore)
{
    $res = mysqli_query($c, $sql);
    $buf = '';
    $rows = array();
    $cols = null;
    $n = 0;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if ($cols === null) { $cols = '`' . implode('`, `', array_keys($r)) . '`'; }
        $rows[] = '(' . implode(', ', array_map(function ($v) use ($c) { return tt_val($c, $v); }, array_values($r))) . ')';
        $n++;
        if (count($rows) === 200) {
            $buf .= 'INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO `$table` ($cols) VALUES\n" . implode(",\n", $rows) . ";\n";
            $rows = array();
        }
    }
    if ($rows) { $buf .= 'INSERT ' . ($ignore ? 'IGNORE ' : '') . "INTO `$table` ($cols) VALUES\n" . implode(",\n", $rows) . ";\n"; }
    return array($buf, $n);
}

function tt_create($c, $table, $ifNotExists)
{
    $row = mysqli_fetch_row(mysqli_query($c, "SHOW CREATE TABLE `$table`"));
    $sql = preg_replace('/ AUTO_INCREMENT=\d+/', '', $row[1]);
    return ($ifNotExists ? str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql) : $sql) . ";\n";
}

$c = $connect;
$head = "-- สร้างโดย tools/build_test_tom_checklist_package.php เมื่อ " . date('Y-m-d H:i') . "\n"
    . "-- ข้อมูลจากฐาน " . mysqli_fetch_row(mysqli_query($c, 'SELECT DATABASE()'))[0] . " บนเครื่องพัฒนา\n"
    . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
$counts = array();

$ref = $head;
foreach (array('product_name', 'rate') as $t) {
    $ref .= "DROP TABLE IF EXISTS `$t`;\n" . tt_create($c, $t, false);
    list($ins, $counts[$t]) = tt_inserts($c, $t, "SELECT * FROM `$t`", false);
    $ref .= $ins . "\n";
}
$trCols = array('trp_id', 'trp_product', 'trp_receive_date', 'trp_repair_inform', 'trp_repair_technician');
for ($i = 1; $i <= 10; $i++) { $trCols[] = 'trp_repair_no' . $i; }
$defs = array();
$res = mysqli_query($c, "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transac_repair'");
while ($r = mysqli_fetch_assoc($res)) { $defs[$r['COLUMN_NAME']] = $r['COLUMN_TYPE']; }
$lines = array();
foreach ($trCols as $col) { $lines[] = "  `$col` " . $defs[$col] . ($col === 'trp_id' ? ' NOT NULL' : ' NULL DEFAULT NULL'); }
$ref .= "-- ประวัติซ่อมแบบย่อ (เฉพาะคอลัมน์ที่หน้าตั้งค่าใช้)\nDROP TABLE IF EXISTS `transac_repair`;\nCREATE TABLE `transac_repair` (\n"
    . implode(",\n", $lines) . ",\n  PRIMARY KEY (`trp_id`),\n  KEY `idx_trp_product` (`trp_product`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
list($ins, $counts['transac_repair']) = tt_inserts($c, 'transac_repair', 'SELECT `' . implode('`, `', $trCols) . '` FROM transac_repair', false);
$ref .= $ins . "\n";
$ref .= "-- ชื่อพนักงานสำหรับช่อง «แก้ไขโดย» (ไม่รวมรหัสผ่าน)\nDROP TABLE IF EXISTS `employee`;\nCREATE TABLE `employee` (\n"
    . "  `emp_id` int(11) NOT NULL AUTO_INCREMENT,\n  `emp_name` varchar(50) DEFAULT NULL,\n  `emp_password` varchar(10) DEFAULT NULL,\n"
    . "  `emp_position` varchar(20) DEFAULT NULL,\n  PRIMARY KEY (`emp_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
list($ins, $counts['employee']) = tt_inserts($c, 'employee', 'SELECT emp_id, emp_name, emp_position FROM employee', false);
$ref .= $ins . "\nSET FOREIGN_KEY_CHECKS = 1;\n";

$start = $head . "-- ข้อตรวจตั้งต้น — import ครั้งแรกครั้งเดียว (CREATE IF NOT EXISTS + INSERT IGNORE ไม่ทับงานที่ทีมแก้แล้ว)\n\n";
foreach (array('cl_symptom', 'cl_group', 'cl_product', 'cl_item', 'cl_rate_bundle', 'cl_rate_reason') as $t) {
    $start .= tt_create($c, $t, true);
    list($ins, $counts[$t]) = tt_inserts($c, $t, "SELECT * FROM `$t`", true);
    $start .= $ins . "\n";
}
$start .= "SET FOREIGN_KEY_CHECKS = 1;\n";

mkdir($out . '/sql', 0777, true);
file_put_contents($out . '/sql/01_reference_data.sql', $ref);
file_put_contents($out . '/sql/02_checklist_start.sql', $start);
maintenance_db_odbc_close($c);

// ---------- DEPLOY.txt ----------

$list = '';
$all = array_merge($files, array('includes/title-h.php', 'config/database.php', 'config/env.local.example.php', 'index.php'), $sync_extra);
sort($all);
foreach (array_unique($all) as $f) { $list .= '  - ' . $f . ($f === 'includes/title-h.php' ? '   (หัวเพจแบบเรียบของชุดทดสอบ)' : '') . "\n"; }
$cnt = '';
foreach ($counts as $t => $n) { $cnt .= sprintf("  %-15s %5d แถว\n", $t, $n); }

file_put_contents($out . '/DEPLOY.txt', <<<TXT
================================================================================
  $version-test-tom-checklist
  หน้าตั้งค่ารายการตรวจเช็ค — ชุดทดสอบ (แยกฐานข้อมูล ไม่เชื่อมระบบจริง)
================================================================================

ทีมใช้หน้านี้ตั้งค่าข้อตรวจ / อาการ / กลุ่มชิ้นส่วน ของแต่ละรุ่น
ทุกอย่างบันทึกลงฐาน biton_maintenance_test — ไม่แตะ biton_maintenance และไม่แตะไฟล์ในโฟลเดอร์ maintenance
ปุ่ม「ทดลองใช้งาน」ในหน้าตั้งค่าเปิด checklist_try — หน้าเดียวกับที่ช่างใช้ประเมิน แต่ไม่บันทึกข้อมูล

【ถ้าเคยขึ้นชุดก่อนหน้าแล้ว (อัปเดต)】
  - อัปโหลดเฉพาะ www/ ทับของเดิม (config/env.local.php บน server ไม่อยู่ในชุด จึงไม่ถูกทับ)
  - ห้าม import sql/02_checklist_start.sql อีก — จะทับข้อตรวจที่ทีมตั้งไว้
  - ข้าม 1), 2), 4) ไปทดสอบที่ 5) ได้เลย

--------------------------------------------------------------------------------
1) สร้างฐานข้อมูลทดสอบ (phpMyAdmin / cPanel)
--------------------------------------------------------------------------------
  - สร้าง database: biton_maintenance_test   (Collation: utf8mb4_unicode_ci)
  - แนะนำสร้าง user ใหม่ที่มีสิทธิ์เฉพาะฐานนี้ (ALL PRIVILEGES เฉพาะ biton_maintenance_test)
    เพื่อให้หน้าทดสอบเข้าถึงฐานจริงไม่ได้เลย

--------------------------------------------------------------------------------
2) Import SQL เข้า biton_maintenance_test ตามลำดับ
--------------------------------------------------------------------------------
  [1] sql/01_reference_data.sql   รุ่นสินค้า / รายการซ่อม / ประวัติซ่อมแบบย่อ / ชื่อพนักงาน
                                  (import ซ้ำได้ภายหลังเพื่ออัปเดตข้อมูลอ้างอิง)
  [2] sql/02_checklist_start.sql  ข้อตรวจตั้งต้นที่ออกแบบไว้แล้ว
                                  ** import ครั้งแรกครั้งเดียว ** ถ้า import ซ้ำ ข้อที่ทีมลบไปจะกลับมา

  จำนวนข้อมูลในชุดนี้:
$cnt
--------------------------------------------------------------------------------
3) อัปโหลดไฟล์
--------------------------------------------------------------------------------
  - copy ทุกอย่างใน www/ ไปที่โฟลเดอร์ test-tom บน server (รวมไฟล์ .htaccess ที่ซ่อนอยู่)
  - ต้องอยู่โดเมนเดียวกับระบบ maintenance (bit-online.net) เพื่อใช้ล็อกอิน BIT ร่วมกัน

  ไฟล์ในชุด:
$list
--------------------------------------------------------------------------------
4) ตั้งค่าการเชื่อมต่อฐานข้อมูล (บน server)
--------------------------------------------------------------------------------
  - ในโฟลเดอร์ test-tom: copy config/env.local.example.php → config/env.local.php
  - แก้ user / password ให้ตรงกับ user ของ biton_maintenance_test
  - ห้ามใส่ user/password ของฐานจริง

--------------------------------------------------------------------------------
5) ทดสอบ
--------------------------------------------------------------------------------
  [ ] เปิดโฟลเดอร์ที่วางไฟล์ เช่น https://bit-online.net/test-tom/maintenance/  → เด้งไปหน้าตั้งค่า (ถ้ายังไม่ล็อกอินจะไปหน้า BIT login ก่อน)
      หลังล็อกอิน ถ้าระบบพากลับไปหน้า maintenance ให้เปิดลิงก์ test-tom อีกครั้ง
  [ ] รายชื่อรุ่นทางซ้ายแสดง พร้อมจำนวนงานซ่อม
  [ ] เลือก bitVisitor Plus → เห็นข้อตรวจตั้งต้น / แท็บประวัติซ่อมแสดงตาราง
  [ ] ลองเพิ่มข้อตรวจ → บันทึก → รีเฟรชแล้วยังอยู่ → ลบทิ้ง
  [ ] หัวเพจขึ้นคำว่า "ชุดทดสอบ" (ยืนยันว่าไม่ได้เปิดหน้าของระบบจริง)
  [ ] กด「ทดลองใช้งาน」→ ตอบข้อตรวจครบ 4 ขั้น → ปุ่มสุดท้ายแสดงผลที่จะบันทึก (ไม่บันทึกจริง)

  ถ้าขึ้น "เชื่อมต่อ MySQL ไม่สำเร็จ" → ตรวจ config/env.local.php
  ถ้าเปิด checklist_settings แล้ว 404 → server ต้องเปิด mod_rewrite + AllowOverride (เหมือนโฟลเดอร์ maintenance)

--------------------------------------------------------------------------------
ตอนเปิดใช้จริง (ภายหลัง)
--------------------------------------------------------------------------------
  export เฉพาะ 6 ตาราง cl_symptom, cl_group, cl_product, cl_item, cl_rate_bundle, cl_rate_reason จาก biton_maintenance_test
  ไป import ที่ biton_maintenance พร้อมกับ deploy ระบบ checklist ขึ้นโฟลเดอร์ maintenance
  (ไม่ต้องย้าย cl_job_header / cl_job_item — เป็นผลตรวจของใบงาน)
  จากนั้นลบโฟลเดอร์ test-tom และฐาน biton_maintenance_test ได้

TXT
);

echo "สร้างแล้ว: $out\n";
echo count($all) . " ไฟล์ใน www/\n";
foreach ($counts as $t => $n) { echo "  $t: $n\n"; }
