<?php
/**
 * ?????????????????????:
 * Endpoint autocomplete ??????????????? MySQL `address_thailand`
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/mysql_address_search.php';

$term = isset($_REQUEST['term']) ? (string) $_REQUEST['term'] : '';
foreach (maintenance_new_mysql_search_address_thailand_lines($term) as $line) {
    echo '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
}
