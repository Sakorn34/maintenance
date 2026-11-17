<?php
/**
 * บันทึกว่าแพ็ก deploy maintenance ถูกอัปโหลดขึ้น server แล้ว (baseline สำหรับ incremental deploy)
 *
 * อ่าน MANIFEST.txt จากโฟลเดอร์แพ็ก แล้วเขียน deploy/LAST_UPLOADED.txt
 *
 * ตัวอย่าง:
 *   php tools/mark_deploy_maintenance_uploaded.php
 *   php tools/mark_deploy_maintenance_uploaded.php 2026-11-12-r1-maintenance
 */

define('DEPLOY_PACKAGE_SLUG', 'maintenance');

/**
 * หาโฟลเดอร์แพ็ก deploy ล่าสุดตามชื่อ
 *
 * @param string $deployDir path deploy/
 * @return string|null ชื่อโฟลเดอร์แพ็ก
 */
function mark_maintenance_find_latest_package($deployDir)
{
    $pattern = $deployDir . DIRECTORY_SEPARATOR . '*-' . DEPLOY_PACKAGE_SLUG;
    $dirs = glob($pattern, GLOB_ONLYDIR);
    if ($dirs === false || $dirs === array()) {
        return null;
    }
    rsort($dirs, SORT_STRING);

    return basename($dirs[0]);
}

/**
 * แยก MD5 จากบรรทัด MANIFEST
 *
 * @param string $line บรรทัด manifest
 * @return array{0: string, 1: string}|null path จาก www/ และ md5
 */
function mark_maintenance_parse_manifest_line($line)
{
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        return null;
    }
    if (strpos($line, 'relative_path') !== false || strpos($line, '---') === 0) {
        return null;
    }
    $parts = array_map('trim', explode('|', $line));
    if (count($parts) < 3) {
        return null;
    }
    $rel = $parts[0];
    $md5 = strtolower($parts[2]);
    if (strpos($rel, 'www/') !== 0 || !preg_match('/^[a-f0-9]{32}$/', $md5)) {
        return null;
    }
    $path = substr($rel, 4);

    return array($path, $md5);
}

/**
 * เขียน deploy/LAST_UPLOADED.txt
 *
 * @param string $outPath path ไฟล์ปลายทาง
 * @param string $packageId ชื่อแพ็ก
 * @param array<string, string> $pathToMd5 path จากราก maintenance => md5
 * @return void
 */
function mark_maintenance_write_last_uploaded($outPath, $packageId, array $pathToMd5)
{
    ksort($pathToMd5);
    $lines = array(
        '# สถานะ deploy maintenance บน server production (อัปเดตเมื่อผู้ใช้แจ้งว่าอัปโหลดแล้ว)',
        'package=' . $packageId,
        'recorded=' . date('c'),
        'note=แพ็กถัดไป (incremental) รวมเฉพาะไฟล์ที่ MD5 เปลี่ยนจากรายการด้านล่าง',
        '',
        'path | md5',
        str_repeat('-', 72),
    );
    foreach ($pathToMd5 as $path => $md5) {
        $lines[] = $path . ' | ' . $md5;
    }
    file_put_contents($outPath, implode("\n", $lines) . "\n");
}

$projectRoot = dirname(__DIR__);
$deployDir = $projectRoot . DIRECTORY_SEPARATOR . 'deploy';
$packageId = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? preg_replace('/[^0-9A-Za-z._-]/', '', (string) $argv[1])
    : mark_maintenance_find_latest_package($deployDir);

if ($packageId === null || $packageId === '') {
    fwrite(STDERR, "ไม่พบโฟลเดอร์แพ็ก deploy — ระบุชื่อแพ็ก หรือสร้างแพ็กก่อน\n");
    exit(1);
}

$manifestPath = $deployDir . DIRECTORY_SEPARATOR . $packageId . DIRECTORY_SEPARATOR . 'MANIFEST.txt';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "ไม่พบ MANIFEST: {$manifestPath}\n");
    exit(1);
}

$pathToMd5 = array();
$outPath = $deployDir . DIRECTORY_SEPARATOR . 'LAST_UPLOADED.txt';
if (is_file($outPath)) {
    foreach (file($outPath, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, 'package=') === 0 || strpos($line, 'recorded=') === 0 || strpos($line, 'note=') === 0) {
            continue;
        }
        if (strpos($line, 'path | md5') !== false || strpos($line, '---') === 0) {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) >= 2 && preg_match('/^[a-f0-9]{32}$/', strtolower($parts[1]))) {
            $pathToMd5[$parts[0]] = strtolower($parts[1]);
        }
    }
}

foreach (file($manifestPath, FILE_IGNORE_NEW_LINES) as $line) {
    $parsed = mark_maintenance_parse_manifest_line($line);
    if ($parsed === null) {
        continue;
    }
    $pathToMd5[$parsed[0]] = $parsed[1];
}

if ($pathToMd5 === array()) {
    fwrite(STDERR, "MANIFEST ไม่มีรายการ www/\n");
    exit(1);
}

mark_maintenance_write_last_uploaded($outPath, $packageId, $pathToMd5);

echo "บันทึก baseline อัปโหลดแล้ว: {$packageId}\n";
echo "ไฟล์: deploy/LAST_UPLOADED.txt (" . count($pathToMd5) . " ไฟล์)\n";
