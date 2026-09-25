<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * แบ่งไฟล์ SQL ขนาดใหญ่ (เช่น `mdb_import_data.sql`) เป็นหลายไฟล์ย่อย
 * ให้แต่ละไฟล์มีขนาดไม่เกินค่าที่กำหนด (ค่าเริ่ม 8 MB) เพื่อนำเข้า phpMyAdmin ได้โดยไม่ชน limit อัปโหลด
 *
 * เงื่อนไขรูปแบบไฟล์ต้นทาง:
 * - คำสั่ง `INSERT` แต่ละก้อนจบด้วยบรรทัดที่ลงท้ายด้วย `);` (ไม่ลงท้ายด้วย `),`) ตามที่ `export_mdb_to_mysql_sql.php` สร้าง
 * - บรรทัดนำหน้า (`SET NAMES`, `START TRANSACTION` ฯลฯ) จะถูกละเว้น แล้วเขียน wrapper ใหม่ต่อไฟล์
 *
 * Flow (CLI):
 * `php database/split_sql_for_phpmyadmin.php --in=database/mdb_import_data.sql --out-dir=database/import_chunks --mb=8`
 *
 * Example:
 * นำเข้าใน phpMyAdmin ตามลำดับ `part_001.sql`, `part_002.sql`, ...
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

/** @var int ขนาดสูงสุดต่อไฟล์เมื่อไม่ส่ง --mb */
define('SQL_SPLIT_DEFAULT_MAX_BYTES', 8 * 1024 * 1024);

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

/**
 * Purpose:
 * อ่านอาร์กิวเมนต์ CLI แบบ `--key=value`
 *
 * Parameters สำคัญ:
 * - array $argv: จาก `$argv`
 *
 * Return value:
 * - array map key => string
 */
function sql_split_parse_cli_args(array $argv)
{
    $out = array();
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if (strpos($a, '--') !== 0) {
            continue;
        }
        $eq = strpos($a, '=');
        if ($eq !== false) {
            $k = substr($a, 2, $eq - 2);
            $out[$k] = substr($a, $eq + 1);
        }
    }

    return $out;
}

/**
 * Purpose:
 * ตรวจว่าบรรทัดเป็นแถวสุดท้ายของคำสั่ง `INSERT` (ลงท้าย `);` ไม่ใช่ `),`)
 *
 * Parameters สำคัญ:
 * - string $line: บรรทัดจากไฟล์
 *
 * Return value:
 * - bool
 */
function sql_split_line_closes_insert_statement($line)
{
    $t = rtrim($line);
    if ($t === '' || substr($t, -2) !== ');') {
        return false;
    }

    return !preg_match('/\),\s*$/', $t);
}

/**
 * Purpose:
 * ห่อ transaction + charset สำหรับแต่ละไฟล์ย่อย (เปิด/ปิด FK ครบในไฟล์เดียว)
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - string ข้อความหัวไฟล์
 */
function sql_split_file_header()
{
    return "SET NAMES utf8mb4;\n"
        . "SET sql_mode = '';\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n"
        . "START TRANSACTION;\n\n";
}

/**
 * Purpose:
 * ส่วนท้ายไฟล์ย่อยหลังบล็อก `INSERT`
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - string
 */
function sql_split_file_footer()
{
    return "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n";
}

// ─────────────────────────────────────────────────────────────────
// main (CLI เท่านั้น)
// ─────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    return;
}
$script = isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
if ($script === '' || realpath($script) !== realpath(__FILE__)) {
    return;
}

$args = sql_split_parse_cli_args($argv);
$inPath = isset($args['in']) ? $args['in'] : (__DIR__ . DIRECTORY_SEPARATOR . 'mdb_import_data.sql');
$outDir = isset($args['out-dir']) ? $args['out-dir'] : (__DIR__ . DIRECTORY_SEPARATOR . 'import_chunks');
$mb = isset($args['mb']) ? (float) $args['mb'] : 8.0;
$maxBytes = (int) round($mb * 1024 * 1024);
if ($maxBytes < 512 * 1024) {
    $maxBytes = SQL_SPLIT_DEFAULT_MAX_BYTES;
}

$inReal = realpath($inPath);
if ($inReal === false || !is_readable($inReal)) {
    fwrite(STDERR, "อ่านไฟล์ต้นทางไม่ได้: {$inPath}\n");
    exit(1);
}
if (!is_dir($outDir)) {
    if (!@mkdir($outDir, 0775, true)) {
        fwrite(STDERR, "สร้างโฟลเดอร์ output ไม่ได้: {$outDir}\n");
        exit(1);
    }
}
$outDirReal = realpath($outDir);
if ($outDirReal === false) {
    fwrite(STDERR, "ไม่พบโฟลเดอร์ output: {$outDir}\n");
    exit(1);
}

$fh = fopen($inReal, 'rb');
if ($fh === false) {
    fwrite(STDERR, "เปิดไฟล์ต้นทางไม่ได้\n");
    exit(1);
}

$insertBuf = '';
$inInsert = false;
$chunkBody = '';
$chunkSize = 0;
$part = 0;

/**
 * Purpose:
 * เขียนไฟล์ part หนึ่งไฟล์
 *
 * Parameters สำคัญ:
 * - string $body: เฉพาะคำสั่ง `INSERT` + comment ระหว่างกัน
 *
 * Return value:
 * - void
 */
$write_chunk = function ($body) use (&$part, $outDirReal) {
    if ($body === '') {
        return;
    }
    $part++;
    $name = $outDirReal . DIRECTORY_SEPARATOR . 'part_' . str_pad((string) $part, 3, '0', STR_PAD_LEFT) . '.sql';
    $fp = fopen($name, 'wb');
    if ($fp === false) {
        fwrite(STDERR, "เขียนไฟล์ไม่ได้: {$name}\n");
        exit(1);
    }
    fwrite($fp, sql_split_file_header());
    fwrite($fp, $body);
    fwrite($fp, sql_split_file_footer());
    fclose($fp);
    $bytes = strlen($body);
    fwrite(STDOUT, "เขียน {$name} (body " . number_format($bytes) . " bytes)\n");
};

while (($line = fgets($fh)) !== false) {
    $trim = ltrim($line);
    if (!$inInsert) {
        if (stripos($trim, 'INSERT INTO') === 0) {
            $inInsert = true;
            $insertBuf = $line;
            if (sql_split_line_closes_insert_statement($line)) {
                $stmt = $insertBuf;
                $insertBuf = '';
                $inInsert = false;
                $stmtLen = strlen($stmt);
                if ($chunkBody !== '' && $chunkSize + $stmtLen > $maxBytes) {
                    $write_chunk($chunkBody);
                    $chunkBody = '';
                    $chunkSize = 0;
                }
                $chunkBody .= $stmt;
                $chunkSize += $stmtLen;
            }
        }
        continue;
    }

    $insertBuf .= $line;
    if (sql_split_line_closes_insert_statement($line)) {
        $stmt = $insertBuf;
        $insertBuf = '';
        $inInsert = false;
        $stmtLen = strlen($stmt);
        if ($chunkBody !== '' && $chunkSize + $stmtLen > $maxBytes) {
            $write_chunk($chunkBody);
            $chunkBody = '';
            $chunkSize = 0;
        }
        $chunkBody .= $stmt;
        $chunkSize += $stmtLen;
    }
}
fclose($fh);

if ($insertBuf !== '') {
    fwrite(STDERR, "ไฟล์ต้นทางไม่สมบูรณ์ (ค้างในกลางคำสั่ง INSERT)\n");
    exit(1);
}

if ($chunkBody === '') {
    fwrite(STDERR, "ไม่พบคำสั่ง INSERT — ใช้ไฟล์จาก export_mdb_to_mysql_sql.php\n");
    exit(1);
}

$write_chunk($chunkBody);

fwrite(STDOUT, "เสร็จแล้ว รวม {$part} ไฟล์ใน {$outDirReal}\n");
