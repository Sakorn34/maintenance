<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * สร้างไฟล์ SQL สำหรับนำเข้า MySQL โดยอ่านข้อมูลจาก Microsoft Access (`*.mdb`) แล้วเขียน `INSERT`
 * อ้างอิงโครงตาราง/คอลัมน์จาก `database/maintenance.sql` (ต้องตรงกับฐานปลายทาง)
 *
 * องค์ประกอบหลัก:
 * - parse `CREATE TABLE` จาก `maintenance.sql` → ลำดับคอลัมน์ + ชนิด (int vs string)
 * - เชื่อม ODBC ไปยังไฟล์ `.mdb` แล้ว `SELECT *` ต่อตาราง (ลองชื่อตารางแบบ `transac` / `transac`)
 * - แปลง encoding เป็น UTF-8 (ค่าเริ่ม `windows-874`) ก่อน escape เป็น string literal ของ MySQL
 *
 * Flow (CLI):
 * `php database/export_mdb_to_mysql_sql.php --mdb=database/maintenance.mdb --sql=database/maintenance.sql --out=database/mdb_import_data.sql`
 *
 * Example:
 * `php export_mdb_to_mysql_sql.php` (ใช้ path เริ่มต้นภายใต้โฟลเดอร์ `database/`)
 */

// ─────────────────────────────────────────────────────────────────
// constants
// ─────────────────────────────────────────────────────────────────

/** @var int จำนวนแถวต่อหนึ่งคำสั่ง INSERT (ลดขนาดไฟล์ / ช่วง transaction) */
define('MDB_EXPORT_ROWS_PER_INSERT', 80);

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

/**
 * Purpose:
 * อ่านอาร์กิวเมนต์ CLI แบบ `--key=value` / `--key value`
 *
 * Parameters สำคัญ:
 * - array $argv: จาก `$argv`
 *
 * Return value:
 * - array map key => string value
 */
function mdb_export_parse_cli_args(array $argv)
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
        } elseif ($i + 1 < $n && strpos($argv[$i + 1], '--') !== 0) {
            $k = substr($a, 2);
            $out[$k] = $argv[$i + 1];
            $i++;
        } else {
            $k = substr($a, 2);
            $out[$k] = '1';
        }
    }

    return $out;
}

/**
 * Purpose:
 * แยกบล็อก `CREATE TABLE` จากไฟล์ dump ของ phpMyAdmin ให้ได้ชื่อตาราง + รายการคอลัมน์ + flag ว่าเป็น int หรือไม่
 *
 * Parameters สำคัญ:
 * - string $sqlPath: path ไป `maintenance.sql`
 *
 * Return value:
 * - array map `ชื่อตาราง` => array( 'columns' => list ชื่อคอลัมน์ตามลำดับ, 'int_flags' => list bool ต่อคอลัมน์ )
 */
function mdb_export_parse_mysql_schema($sqlPath)
{
    $raw = file_get_contents($sqlPath);
    if ($raw === false) {
        fwrite(STDERR, "อ่านไฟล์ schema ไม่ได้: {$sqlPath}\n");
        exit(1);
    }

    $tables = array();
    if (!preg_match_all(
        '/CREATE TABLE `([^`]+)`\s*\((.*?)\)\s*ENGINE=/s',
        $raw,
        $blocks,
        PREG_SET_ORDER
    )) {
        fwrite(STDERR, "ไม่พบ CREATE TABLE ในไฟล์ schema\n");
        exit(1);
    }

    foreach ($blocks as $b) {
        $tname = $b[1];
        $body = $b[2];
        $lines = preg_split("/\r\n|\n|\r/", $body);
        $cols = array();
        $intFlags = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '`') !== 0) {
                continue;
            }
            if (!preg_match('/^`([^`]+)`\s+(.+)\s*,?\s*$/', $line, $m)) {
                continue;
            }
            $col = $m[1];
            $typePart = rtrim(trim($m[2]), ',');
            $cols[] = $col;
            $intFlags[] = (bool) preg_match('/^int\s*\(/i', $typePart);
        }
        if ($cols !== array()) {
            $tables[$tname] = array('columns' => $cols, 'int_flags' => $intFlags);
        }
    }

    return $tables;
}

/**
 * Purpose:
 * ลำดับ export ตารางให้ลดโอกาสละเมิด FK แบบ soft (transac ก่อน transac_repair)
 *
 * Parameters สำคัญ:
 * - array $schemaKeys: รายชื่อตารางที่มีใน schema
 *
 * Return value:
 * - array รายชื่อตารางเรียงลำดับแล้ว
 */
function mdb_export_table_order(array $schemaKeys)
{
    $preferred = array(
        'address_thailand',
        'employee',
        'product_name',
        'rate',
        'security_contact',
        'user_contact',
        'spare',
        'transac',
        'transac_repair',
        'transac_spare',
        'tracking_history',
        'borrow',
        'spare_log',
        'repair_history',
        'qc_log',
        'logqc_a4camera',
        'logqc_bitscan',
        'logqc_bitstamp',
        'logqc_bitsupply',
        'logqc_bitvisitor',
        'logqc_cardcam',
        'logqc_facecam',
        'logqc_keyboard',
        'logqc_magnetic',
        'logqc_mobile',
        'logqc_printer',
        'logqc_router',
        'logqc_scanner',
        'logqc_scanner_wireless',
        'logqc_smartcard',
    );
    $set = array_fill_keys($schemaKeys, true);
    $ordered = array();
    foreach ($preferred as $p) {
        if (isset($set[$p])) {
            $ordered[] = $p;
            unset($set[$p]);
        }
    }
    foreach ($schemaKeys as $k) {
        if (isset($set[$k])) {
            $ordered[] = $k;
        }
    }

    return $ordered;
}

/**
 * Purpose:
 * ลองชื่อตารางใน Access ที่เป็นไปได้ (Jet มักเก็บเป็น `transac` / `transac_repair`)
 *
 * Parameters สำคัญ:
 * - string $mysqlTable: ชื่อตารางใน MySQL (lower snake)
 *
 * Return value:
 * - array ของ string ชื่อตารางสำหรับลองใน SQL
 */
function mdb_export_access_table_name_candidates($mysqlTable)
{
    $c = array_unique(array(
        $mysqlTable,
        ucfirst($mysqlTable),
        strtoupper($mysqlTable),
    ));

    return array_values($c);
}

/**
 * Purpose:
 * map ชื่อคอลัมน์ Access → MySQL เมื่อชื่อใน MDB ไม่ตรงกับ `maintenance.sql` (เช่น typo ช่องว่าง)
 *
 * Parameters สำคัญ:
 * - string $mysqlTable: ชื่อตาราง MySQL
 * - string $mysqlColumn: ชื่อคอลัมน์ MySQL
 *
 * Return value:
 * - string|null ชื่อคอลัมน์ใน Access หรือ null ให้ใช้ชื่อเดียวกับ MySQL
 */
function mdb_export_access_column_override($mysqlTable, $mysqlColumn)
{
    if ($mysqlTable === 'spare_log' && $mysqlColumn === 'spl_sp_ product_name') {
        return 'spl_sp_product_name';
    }

    return null;
}

/**
 * Purpose:
 * เปิด connection ODBC ไปยังไฟล์ `.mdb`
 *
 * Parameters สำคัญ:
 * - string $mdbRealPath: path แบบ absolute
 *
 * Return value:
 * - resource|false handle ODBC
 */
function mdb_export_odbc_connect_mdb($mdbRealPath)
{
    if (!function_exists('odbc_connect')) {
        fwrite(STDERR, "PHP ไม่มี extension odbc — เปิด php.ini แล้ว enable `extension=odbc` / driver Access\n");
        exit(1);
    }
    $mdbRealPath = str_replace('/', '\\', $mdbRealPath);
    $dsnList = array(
        'Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $mdbRealPath . ';',
        'Driver={Microsoft Access Driver (*.mdb)};Dbq=' . $mdbRealPath . ';',
    );
    foreach ($dsnList as $dsn) {
        $c = @odbc_connect($dsn, '', '');
        if ($c) {
            return $c;
        }
    }

    return false;
}

/**
 * Purpose:
 * ตรวจว่ามีตารางใน Access หรือไม่ (ลองชื่อ candidate)
 *
 * Parameters สำคัญ:
 * - resource $conn: ODBC connection
 * - array $candidates: รายชื่อตารางที่จะลอง
 *
 * Return value:
 * - string|null ชื่อตารางที่ใช้ได้จริง
 */
function mdb_export_resolve_access_table($conn, array $candidates)
{
    foreach ($candidates as $name) {
        $q = 'SELECT COUNT(*) AS c FROM [' . str_replace(']', ']]', $name) . ']';
        $r = @odbc_exec($conn, $q);
        if ($r) {
            odbc_free_result($r);

            return $name;
        }
    }

    return null;
}

/**
 * Purpose:
 * แปลงสตริงจาก Access เป็น UTF-8 (ถ้าแปลงไม่ได้คืนค่าเดิม)
 *
 * Parameters สำคัญ:
 * - string|null $value: ค่าจาก ODBC
 * - string $fromCharset: เช่น `windows-874` หรือ `TIS-620`
 *
 * Return value:
 * - string|null
 */
function mdb_export_to_utf8($value, $fromCharset)
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        return $value;
    }
    if ($fromCharset === '' || strtoupper($fromCharset) === 'UTF-8') {
        return $value;
    }
    $c = @iconv($fromCharset, 'UTF-8//IGNORE', $value);
    if ($c === false) {
        return $value;
    }

    return $c;
}

/**
 * Purpose:
 * escape ค่าให้ใส่ใน string literal ของ MySQL (binary-safe ระดับพื้นฐาน)
 *
 * Parameters สำคัญ:
 * - string $s: ข้อความ UTF-8
 *
 * Return value:
 * - string ที่ escape แล้ว (ไม่รวม quote รอบนอก)
 */
function mdb_export_mysql_escape_inner($s)
{
    return str_replace(
        array('\\', "'", "\0", "\n", "\r", "\x1a"),
        array('\\\\', "''", '\\0', '\\n', '\\r', '\\Z'),
        $s
    );
}

/**
 * Purpose:
 * สร้าง SQL literal สำหรับค่าหนึ่งค่า (NULL, ตัวเลข int, หรือ quoted string)
 *
 * Parameters สำคัญ:
 * - mixed $value: ค่าหลังแปลง UTF-8
 * - bool $isIntColumn: true เมื่อคอลัมน์เป็น int ใน schema
 *
 * Return value:
 * - string ส่วนหนึ่งของ `VALUES (...)` 
 */
function mdb_export_sql_value_literal($value, $isIntColumn)
{
    if ($value === null) {
        return 'NULL';
    }
    if ($isIntColumn) {
        if (is_numeric($value)) {
            return (string) (int) $value;
        }

        return 'NULL';
    }
    $s = (string) $value;

    return "'" . mdb_export_mysql_escape_inner($s) . "'";
}

/**
 * Purpose:
 * ดึงค่าจากแถว ODBC ตามชื่อคอลัมน์ MySQL (case-insensitive + override ชื่อ Access)
 *
 * Parameters สำคัญ:
 * - array $row: แถวจาก `odbc_fetch_array`
 * - string $mysqlTable: ชื่อตาราง
 * - string $mysqlColumn: ชื่อคอลัมน์ MySQL
 *
 * Return value:
 * - mixed|null
 */
function mdb_export_pick_cell(array $row, $mysqlTable, $mysqlColumn)
{
    $accessCol = mdb_export_access_column_override($mysqlTable, $mysqlColumn);
    $try = $accessCol !== null ? $accessCol : $mysqlColumn;
    $map = array();
    foreach ($row as $k => $v) {
        $map[strtolower((string) $k)] = $v;
    }
    $key = strtolower($try);
    if (array_key_exists($key, $map)) {
        return $map[$key];
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────
// main (CLI เท่านั้น — กันรันเมื่อถูก require จากสคริปต์อื่น)
// ─────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    return;
}
$script = isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
if ($script === '' || realpath($script) !== realpath(__FILE__)) {
    return;
}

$dbDir = __DIR__;
$args = mdb_export_parse_cli_args(isset($argv) ? $argv : array());

$mdbArg = isset($args['mdb']) ? $args['mdb'] : ($dbDir . DIRECTORY_SEPARATOR . 'maintenance.mdb');
$sqlArg = isset($args['sql']) ? $args['sql'] : ($dbDir . DIRECTORY_SEPARATOR . 'maintenance.sql');
$outArg = isset($args['out']) ? $args['out'] : ($dbDir . DIRECTORY_SEPARATOR . 'mdb_import_data.sql');
$charsetArg = isset($args['charset']) ? $args['charset'] : 'windows-874';

$mdbReal = realpath($mdbArg);
if ($mdbReal === false || !is_file($mdbReal)) {
    fwrite(STDERR, "ไม่พบไฟล์ MDB: {$mdbArg}\nวางไฟล์ `maintenance.mdb` ไว้ที่โฟลเดอร์ `database/` หรือส่ง `--mdb=...`\n");
    exit(1);
}
$sqlReal = realpath($sqlArg);
if ($sqlReal === false) {
    fwrite(STDERR, "ไม่พบไฟล์ schema SQL: {$sqlArg}\n");
    exit(1);
}

$schema = mdb_export_parse_mysql_schema($sqlReal);
$tableOrder = mdb_export_table_order(array_keys($schema));

$conn = mdb_export_odbc_connect_mdb($mdbReal);
if (!$conn) {
    fwrite(STDERR, "เชื่อม ODBC ไปยัง MDB ไม่ได้ — ติดตั้ง Microsoft Access Database Engine (ODBC) และเปิด extension `odbc` ใน PHP\n");
    exit(1);
}

$fh = fopen($outArg, 'wb');
if ($fh === false) {
    fwrite(STDERR, "เขียนไฟล์ output ไม่ได้: {$outArg}\n");
    exit(1);
}

fwrite($fh, "-- สร้างโดย export_mdb_to_mysql_sql.php\n");
fwrite($fh, "-- Source MDB: " . str_replace(array("\r", "\n"), ' ', $mdbReal) . "\n");
fwrite($fh, "-- Schema: " . str_replace(array("\r", "\n"), ' ', $sqlReal) . "\n");
fwrite($fh, "-- Charset conversion: {$charsetArg} -> UTF-8\n");
fwrite($fh, "SET NAMES utf8mb4;\nSET sql_mode = '';\nSET FOREIGN_KEY_CHECKS = 0;\nSTART TRANSACTION;\n\n");

$totalRows = 0;
foreach ($tableOrder as $mysqlTable) {
    if (!isset($schema[$mysqlTable])) {
        continue;
    }
    $candidates = mdb_export_access_table_name_candidates($mysqlTable);
    $accessTable = mdb_export_resolve_access_table($conn, $candidates);
    if ($accessTable === null) {
        fwrite($fh, "-- ข้ามตาราง `{$mysqlTable}` (ไม่พบชื่อตารางใน MDB)\n\n");
        continue;
    }

    $cols = $schema[$mysqlTable]['columns'];
    $intF = $schema[$mysqlTable]['int_flags'];
    $colSqlParts = array();
    foreach ($cols as $c) {
        $colSqlParts[] = '`' . str_replace('`', '``', $c) . '`';
    }
    $colList = implode(', ', $colSqlParts);

    $selectSql = 'SELECT * FROM [' . str_replace(']', ']]', $accessTable) . ']';
    $res = @odbc_exec($conn, $selectSql);
    if (!$res) {
        fwrite($fh, "-- ข้าม `{$mysqlTable}` (SELECT ล้มเหลว: " . str_replace(array("\r", "\n"), ' ', (string) odbc_errormsg($conn)) . ")\n\n");
        continue;
    }

    $batch = 0;
    $buf = array();
    while ($row = odbc_fetch_array($res)) {
        if ($row === false) {
            break;
        }
        $lits = array();
        foreach ($cols as $idx => $colName) {
            $raw = mdb_export_pick_cell($row, $mysqlTable, $colName);
            $utf = mdb_export_to_utf8($raw, $charsetArg);
            $lits[] = mdb_export_sql_value_literal($utf, !empty($intF[$idx]));
        }
        $buf[] = '(' . implode(', ', $lits) . ')';
        $batch++;
        $totalRows++;
        if ($batch >= MDB_EXPORT_ROWS_PER_INSERT) {
            fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $mysqlTable) . "` ({$colList}) VALUES\n");
            fwrite($fh, implode(",\n", $buf) . ";\n\n");
            $buf = array();
            $batch = 0;
        }
    }
    odbc_free_result($res);

    if ($buf !== array()) {
        fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $mysqlTable) . "` ({$colList}) VALUES\n");
        fwrite($fh, implode(",\n", $buf) . ";\n\n");
    }
    fwrite($fh, "-- end `{$mysqlTable}` (source Access: `{$accessTable}`)\n\n");
}

fwrite($fh, "COMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fh);
odbc_close($conn);

fwrite(STDOUT, "เขียนไฟล์แล้ว: {$outArg} (แถวรวมประมาณ {$totalRows})\n");
