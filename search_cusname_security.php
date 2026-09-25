<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Endpoint autocomplete ค้นชื่อบริษัทรปภ. ส่งกลับเป็น `<p>` ต่อแถว
 * อ่านจาก MySQL `biton_leasing.tbl_customer` ผ่าน `includes/mysql_customer_search.php` (รูปแบบ `cus_id#cus_name`)
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_customer_search.php';

$term = isset($_REQUEST['term']) ? (string) $_REQUEST['term'] : '';
foreach (maintenance_new_mysql_search_customer_security_lines($term) as $line) {
    echo '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
}
