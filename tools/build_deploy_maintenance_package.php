<?php
/**
 * สร้างแพ็กไฟล์ deploy ระบบ maintenance สำหรับ copy ขึ้น server production
 *
 * คัดลอกไฟล์ที่เปลี่ยนไปไว้ใน deploy/<ชื่อแพ็ก>/www/ พร้อม MANIFEST.txt และ DEPLOY.txt
 * อ้างอิงแนวทางเดียวกับ inventory/tools/build_deploy_pickup_package.php
 *
 * ตัวอย่าง: php tools/build_deploy_maintenance_package.php 2026-11-12-r1
 *          php tools/build_deploy_maintenance_package.php 2026-11-12-r1 --full
 *
 * ค่าเริ่มต้น: incremental — คัดลอกเฉพาะไฟล์ที่ MD5 ต่างจาก deploy/LAST_UPLOADED.txt
 */

// ─ Constants ─────────────────────────────────────────────────────────────────

define('DEPLOY_PACKAGE_SLUG', 'maintenance');
define('DEPLOY_PACKAGE_LABEL', 'Maintenance — เชื่อม inventory เบิกอะไหล่ (status_wait_ma + API popup)');

/** @var string[] ไฟล์ที่ root maintenance */
$DEPLOY_WEB_ROOT_FILES = array(
    'status_wait_ma.php',
    'maintenance_inventory_pickup_lines.php',
);

/** @var string[] ไฟล์ใน path ย่อย (includes/, css/, js/, config/) */
$DEPLOY_WEB_RELATIVE_PATHS = array(
    'includes/maintenance_inventory_pickup_helpers.php',
    'js/status_wait_ma-page.js',
    'css/mn-page-status-wait-ma.css',
    'config/app_environment.php',
    'config/inventory_mysql.php',
);

/** @var string[] เอกสารอ้างอิง (ไม่บังคับ copy ขึ้น server) */
$DEPLOY_DOC_PATHS = array(
    'config/env.local.example.php',
    'config/inventory_mysql.example.php',
    'deploy/README.txt',
);

// ─ Helpers ───────────────────────────────────────────────────────────────────

/**
 * สร้าง path แบบปลอดภัยและตรวจว่ามีไฟล์ต้นทาง (รองรับ fallback example)
 *
 * @param string $projectRoot โฟลเดอร์รากโปรเจค
 * @param string $relativePath path จากราก
 * @return string path เต็มของไฟล์ต้นทาง
 */
function deploy_maintenance_resolve_source($projectRoot, $relativePath)
{
    $full = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($full) && $relativePath === 'config/inventory_mysql.php') {
        $full = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'inventory_mysql.example.php';
    }
    if (!is_file($full)) {
        fwrite(STDERR, "ไม่พบไฟล์: {$relativePath}\n");
        exit(1);
    }

    return $full;
}

/**
 * คัดลอกไฟล์ไปปลายทาง พร้อมสร้างโฟลเดอร์แม่
 *
 * @param string $source path ต้นทาง
 * @param string $dest path ปลายทาง
 * @return void
 */
function deploy_maintenance_copy_file($source, $dest)
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "สร้างโฟลเดอร์ไม่ได้: {$dir}\n");
        exit(1);
    }
    if (!copy($source, $dest)) {
        fwrite(STDERR, "copy ไม่สำเร็จ: {$source}\n");
        exit(1);
    }
}

/**
 * เขียน MANIFEST.txt สำหรับตรวจหลัง upload
 *
 * @param string $manifestPath path ไฟล์ manifest
 * @param array<int, array{rel: string, bytes: int, md5: string, mtime: string}> $rows
 * @return void
 */
function deploy_maintenance_write_manifest($manifestPath, array $rows)
{
    $lines = array(
        '# MANIFEST — ' . DEPLOY_PACKAGE_LABEL,
        '# สร้างเมื่อ: ' . date('Y-m-d H:i:s'),
        '# ใช้เปรียบเทียบ MD5 บน server หลัง copy',
        '',
        'relative_path | bytes | md5 | modified',
        str_repeat('-', 72),
    );

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '%s | %d | %s | %s',
            $row['rel'],
            $row['bytes'],
            $row['md5'],
            $row['mtime']
        );
    }

    file_put_contents($manifestPath, implode("\n", $lines) . "\n");
}

/**
 * โหลด MD5 baseline จาก deploy/LAST_UPLOADED.txt
 *
 * @param string $deployDir path deploy/
 * @return array{package: string, paths: array<string, string>}|null
 */
function deploy_maintenance_load_last_uploaded($deployDir)
{
    $path = $deployDir . DIRECTORY_SEPARATOR . 'LAST_UPLOADED.txt';
    if (!is_file($path)) {
        return null;
    }
    $package = '';
    $paths = array();
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            if (strpos($line, 'package=') === 0) {
                $package = trim(substr($line, 8));
            }
            continue;
        }
        if (strpos($line, 'path |') === 0 || strpos($line, '---') === 0) {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) >= 2 && preg_match('/^[a-f0-9]{32}$/', strtolower($parts[1]))) {
            $paths[$parts[0]] = strtolower($parts[1]);
        }
    }
    if ($paths === array()) {
        return null;
    }

    return array('package' => $package, 'paths' => $paths);
}

/**
 * สร้างแถว manifest จากไฟล์ต้นทาง
 *
 * @param string $projectRoot รากโปรเจค
 * @param string $relativePath path จากราก
 * @param string $manifestRel path ใน manifest
 * @return array{rel: string, bytes: int, md5: string, mtime: string}
 */
function deploy_maintenance_manifest_row($projectRoot, $relativePath, $manifestRel)
{
    $source = deploy_maintenance_resolve_source($projectRoot, $relativePath);

    return array(
        'rel' => $manifestRel,
        'bytes' => (int) filesize($source),
        'md5' => md5_file($source),
        'mtime' => date('Y-m-d H:i:s', filemtime($source)),
    );
}

/**
 * เขียนคู่มือ deploy ภาษาไทย
 *
 * @param string $deployTxtPath path ไฟล์ DEPLOY.txt
 * @param string $packageDir ชื่อโฟลเดอร์แพ็ก
 * @param string $mode full|incremental
 * @param string $baselinePackage แพ็ก baseline บน server
 * @param string[] $webFiles รายการ path ไฟล์ที่อยู่ใน www/
 * @return void
 */
function deploy_maintenance_write_deploy_txt($deployTxtPath, $packageDir, $mode, $baselinePackage, array $webFiles)
{
    $fileLines = '';
    foreach ($webFiles as $f) {
        $fileLines .= "  - {$f}\n";
    }
    $modeNote = $mode === 'incremental'
        ? "【โหมด incremental】 อัปโหลดเฉพาะไฟล์ในรายการนี้ (baseline บน server: {$baselinePackage})\n\n"
        : "【โหมด full】 อัปโหลดครบทุกไฟล์ในรายการ\n\n";

    $text = <<<TXT
================================================================================
  {$packageDir}
  Maintenance — Deploy เชื่อม inventory / ปุ่มเบิกอะไหล่หน้ารอซ่อม
================================================================================

{$modeNote}【ไม่มีการเปลี่ยน DB ฝั่ง maintenance】 อ่านสถานะจาก inventory (pre_stock / log_stock)

【ต้อง deploy คู่กับ inventory】 ถ้ายังไม่ขึ้น production ให้ deploy แพ็ก pickup ล่าสุด
  (pickup_ma.php, pre_pickup_save.php — บันทึก ts_mtn_number + prefill เบิกซ่อมลูกค้า)

【ไฟล์ที่ต้องวางทับ】 (จากโฟลเดอร์ www/ ไปที่โฟลเดอร์ maintenance บน server)
{$fileLines}
【อย่าแก้ / อย่าทับ】
  - config/env.local.php, config/database.php, config/customer_mysql.php
  - ถ้ามี config/inventory_mysql.php บน server แล้ว — เปรียบเทียบก่อนทับ (หรือตั้งค่าใน env.local คีย์ inventory_mysql)

【ตั้งค่าหลัง deploy (ครั้งแรก)】
  - ใน config/env.local.php บน server ตั้ง inventory_web_base => '/inventory' (หรือ URL จริง)
  - ตรวจว่า config/inventory_mysql.php เชื่อม biton_inventory ได้ (หรือ override ใน env.local)

--------------------------------------------------------------------------------
ขั้นตอน deploy (แนะนำ)
--------------------------------------------------------------------------------
1) แจ้งทีมซ่อม / เลือกช่วงที่ใช้งานน้อย
2) Backup ไฟล์เดิมบน server (ต่อท้าย .bak.YYYYMMDD) ตามรายการด้านบน
3) Upload / copy จาก www/ ขึ้น server
4) ตรวจ MD5 กับ MANIFEST.txt
5) ทดสอบตามหัวข้อ「ทดสอบเร็ว」ด้านล่าง

--------------------------------------------------------------------------------
ทดสอบเร็ว (5 นาที)
--------------------------------------------------------------------------------
[ ] เปิด status_wait_ma — ปุ่มใต้คอลัมน์วันที่อนุมัติซ่อม (ยังไม่เบิก / รอคลังจ่าย / เบิกแล้ว)
[ ] กด「เบิกแล้ว」หรือ「รอคลังจ่าย」— popup แสดงส่วน รอคลังจ่าย + จ่ายแล้ว ต่อใบงาน+SN
[ ] ลิงก์「เบิกอะไหล่เพิ่ม」เปิด pickup_ma พร้อมเลขใบงาน 7 ตัวและหมายเหตุ SN
[ ] สร้างเอกสารเบิกใหม่ใน inventory แล้วรีเฟรช — ปุ่มเป็นรอคลังจ่าย และ popup มีรายการรอจ่าย

--------------------------------------------------------------------------------
Rollback
--------------------------------------------------------------------------------
นำไฟล์ .bak.YYYYMMDD กลับมา rename ทับไฟล์ปัจจุบัน

--------------------------------------------------------------------------------
เอกสารอ้างอิง
--------------------------------------------------------------------------------
โฟลเดอร์ docs/ ในแพ็กนี้ — ตัวอย่าง env (ไม่ต้อง copy ขึ้น server ถ้ามีอยู่แล้ว)

================================================================================
TXT;

    file_put_contents($deployTxtPath, $text);
}

/**
 * ลบโฟลเดอร์แบบ recursive
 *
 * @param string $dir path โฟลเดอร์ที่จะลบ
 * @return void
 */
function deploy_maintenance_remove_tree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deploy_maintenance_remove_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * คืนชื่อโฟลเดอร์แพ็ก deploy
 *
 * @param array $argv อาร์กิวเมนต์ CLI
 * @return string
 */
function deploy_maintenance_resolve_package_id(array $argv)
{
    $version = date('Y-m-d');
    if (isset($argv[1]) && trim((string) $argv[1]) !== '' && strpos((string) $argv[1], '--') !== 0) {
        $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string) $argv[1]);
    }
    if ($version === '') {
        $version = date('Y-m-d-His');
    }

    return $version . '-' . DEPLOY_PACKAGE_SLUG;
}

/**
 * ตรวจว่า CLI มี flag หรือไม่
 *
 * @param array $argv อาร์กิวเมนต์ CLI
 * @param string $flag เช่น --full
 * @return bool
 */
function deploy_maintenance_argv_has_flag(array $argv, $flag)
{
    foreach ($argv as $arg) {
        if ((string) $arg === $flag) {
            return true;
        }
    }

    return false;
}

// ─ Main ──────────────────────────────────────────────────────────────────────

global $DEPLOY_WEB_ROOT_FILES, $DEPLOY_WEB_RELATIVE_PATHS, $DEPLOY_DOC_PATHS;

$cliArgv = isset($argv) ? $argv : array();
$fullBuild = deploy_maintenance_argv_has_flag($cliArgv, '--full');
$deployPackageId = deploy_maintenance_resolve_package_id($cliArgv);
$projectRoot = dirname(__DIR__);
$deployDir = $projectRoot . DIRECTORY_SEPARATOR . 'deploy';

$baseline = deploy_maintenance_load_last_uploaded($deployDir);
$incremental = !$fullBuild && $baseline !== null;
$baselinePackage = $baseline !== null ? $baseline['package'] : '';

/** @var array<string, string> $allWebRel */
$allWebRel = array();
foreach ($DEPLOY_WEB_ROOT_FILES as $basename) {
    $allWebRel[$basename] = 'www/' . $basename;
}
foreach ($DEPLOY_WEB_RELATIVE_PATHS as $relativePath) {
    $allWebRel[$relativePath] = 'www/' . str_replace('\\', '/', $relativePath);
}

$toCopy = array();
foreach ($allWebRel as $relPath => $manifestRel) {
    $row = deploy_maintenance_manifest_row($projectRoot, $relPath, $manifestRel);
    if (!$incremental) {
        $toCopy[$relPath] = $row;
        continue;
    }
    $prevMd5 = isset($baseline['paths'][$relPath]) ? $baseline['paths'][$relPath] : null;
    if ($prevMd5 === null || $prevMd5 !== strtolower($row['md5'])) {
        $toCopy[$relPath] = $row;
    }
}

if ($toCopy === array()) {
    echo "ไม่มีไฟล์ deploy เปลี่ยนหลัง baseline";
    if ($baselinePackage !== '') {
        echo " ({$baselinePackage})";
    }
    echo "\n";
    echo "ถ้าต้องการแพ็กครบ: php tools/build_deploy_maintenance_package.php <เวอร์ชัน> --full\n";
    exit(0);
}

$packageRoot = $deployDir . DIRECTORY_SEPARATOR . $deployPackageId;
$wwwDir = $packageRoot . DIRECTORY_SEPARATOR . 'www';
$docsDir = $packageRoot . DIRECTORY_SEPARATOR . 'docs';

if (is_dir($packageRoot)) {
    deploy_maintenance_remove_tree($packageRoot);
}
mkdir($wwwDir, 0755, true);
mkdir($docsDir, 0755, true);

$manifestRows = array();
$deployWebList = array();

foreach ($toCopy as $relPath => $row) {
    $source = deploy_maintenance_resolve_source($projectRoot, $relPath);
    $dest = $wwwDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    deploy_maintenance_copy_file($source, $dest);
    $manifestRows[] = $row;
    $deployWebList[] = str_replace('\\', '/', $relPath);
}

if ($fullBuild || !$incremental) {
    foreach ($DEPLOY_DOC_PATHS as $docRel) {
        $source = deploy_maintenance_resolve_source($projectRoot, $docRel);
        $dest = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $docRel);
        deploy_maintenance_copy_file($source, $dest);
    }
}

$buildMode = $incremental ? 'incremental' : 'full';
deploy_maintenance_write_manifest($packageRoot . DIRECTORY_SEPARATOR . 'MANIFEST.txt', $manifestRows);
deploy_maintenance_write_deploy_txt(
    $packageRoot . DIRECTORY_SEPARATOR . 'DEPLOY.txt',
    $deployPackageId,
    $buildMode,
    $baselinePackage,
    $deployWebList
);

$versionFile = $packageRoot . DIRECTORY_SEPARATOR . 'VERSION.txt';
file_put_contents(
    $versionFile,
    "package=" . $deployPackageId . "\n"
    . "label=" . DEPLOY_PACKAGE_LABEL . "\n"
    . "built=" . date('c') . "\n"
    . "mode=" . $buildMode . "\n"
    . "baseline=" . $baselinePackage . "\n"
    . "web_files=" . count($manifestRows) . "\n"
);

echo "สร้างแพ็ก deploy เรียบร้อย: {$packageRoot}\n";
echo "package_id={$deployPackageId}\n";
echo "mode={$buildMode} web_files=" . count($manifestRows) . "\n";
if ($incremental) {
    echo "baseline={$baselinePackage}\n";
}
echo "ขั้นถัดไป: copy จาก www/ ในโฟลเดอร์แพ็กขึ้น server ตาม DEPLOY.txt\n";
