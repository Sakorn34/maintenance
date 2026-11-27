<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ฉบับแก้ไขของใบประเมินสภาพเครื่อง (PDF) ต่อใบงาน — เก็บเนื้อหาเอกสารที่ช่างแก้ในหน้าตัวอย่างไว้ เปิดใหม่ได้ฉบับเดิม
 * ไม่แตะข้อมูลประเมินในใบงาน (transac_repair / cl_result)
 *
 * ตาราง cl_pdf_edit: pe_trp_id (PK), pe_html (เนื้อหาใน #sheet ผ่าน sanitize แล้ว), pe_sig (hash ต้นฉบับตอนบันทึก), pe_by, pe_at
 */

if (!defined('MN_ASSESS_PDF_MAX')) { define('MN_ASSESS_PDF_MAX', 600000); }

if (!function_exists('mn_assess_pdf_ensure')) {
    function mn_assess_pdf_ensure($connect)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        mysqli_query($connect,
            'CREATE TABLE IF NOT EXISTS `cl_pdf_edit` ('
            . '`pe_trp_id` INT NOT NULL PRIMARY KEY,'
            . '`pe_html` MEDIUMTEXT NOT NULL,'
            . '`pe_sig` VARCHAR(16) NOT NULL DEFAULT \'\','
            . '`pe_by` VARCHAR(100) NOT NULL DEFAULT \'\','
            . '`pe_at` DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

/** ฉบับแก้ไขของใบงาน หรือ null */
if (!function_exists('mn_assess_pdf_fetch')) {
    function mn_assess_pdf_fetch($connect, $trp_id)
    {
        $id = (int) $trp_id;
        if ($id < 1) { return null; }
        mn_assess_pdf_ensure($connect);
        $stmt = mysqli_prepare($connect, 'SELECT pe_html, pe_sig, pe_by, pe_at FROM cl_pdf_edit WHERE pe_trp_id = ? LIMIT 1');
        if (!$stmt) { return null; }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$row) { return null; }
        return array('html' => (string) $row['pe_html'], 'sig' => (string) $row['pe_sig'], 'by' => (string) $row['pe_by'], 'at' => (string) $row['pe_at']);
    }
}

if (!function_exists('mn_assess_pdf_save')) {
    function mn_assess_pdf_save($connect, $trp_id, $html, $sig, $by)
    {
        mn_assess_pdf_ensure($connect);
        $id = (int) $trp_id;
        $clean = mn_assess_pdf_sanitize($html);
        $sig = substr(preg_replace('/[^0-9a-f]/', '', strtolower((string) $sig)), 0, 16);
        $who = mb_substr(trim((string) $by), 0, 100, 'UTF-8');
        $now = date('Y-m-d H:i:s');
        $stmt = mysqli_prepare($connect,
            'INSERT INTO cl_pdf_edit (pe_trp_id, pe_html, pe_sig, pe_by, pe_at) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE pe_html = VALUES(pe_html), pe_sig = VALUES(pe_sig), pe_by = VALUES(pe_by), pe_at = VALUES(pe_at)');
        if (!$stmt) { return null; }
        mysqli_stmt_bind_param($stmt, 'issss', $id, $clean, $sig, $who, $now);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok ? array('html' => $clean, 'sig' => $sig, 'by' => $who, 'at' => $now) : null;
    }
}

if (!function_exists('mn_assess_pdf_delete')) {
    function mn_assess_pdf_delete($connect, $trp_id)
    {
        mn_assess_pdf_ensure($connect);
        $id = (int) $trp_id;
        $stmt = mysqli_prepare($connect, 'DELETE FROM cl_pdf_edit WHERE pe_trp_id = ?');
        if (!$stmt) { return false; }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return (bool) $ok;
    }
}

/**
 * กรองเนื้อหาเอกสารที่ส่งมาให้เหลือเฉพาะแท็ก/แอตทริบิวต์ที่ใบประเมินใช้ (กันสคริปต์แฝง เพราะเนื้อหานี้ถูกแสดงซ้ำในหน้าเว็บ)
 */
if (!function_exists('mn_assess_pdf_sanitize')) {
    function mn_assess_pdf_sanitize($html)
    {
        $html = (string) $html;
        if (trim($html) === '') { return ''; }
        $allow = array('div', 'span', 'b', 'strong', 'i', 'em', 'u', 'br', 'p', 'h1', 'h2', 'h3', 'section', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'ul', 'ol', 'li', 'img', 'svg', 'circle', 'path', 'small');
        $drop = array('script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'select', 'template', 'base', 'frame', 'frameset', 'noscript');
        $attrs = array('class', 'style', 'colspan', 'rowspan', 'src', 'alt', 'viewbox', 'width', 'height', 'd', 'cx', 'cy', 'r', 'fill',
            'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'aria-hidden');

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="mn-pdf-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $dom->getElementById('mn-pdf-root');
        if (!$root) {
            $list = $dom->getElementsByTagName('div');
            $root = $list->length ? $list->item(0) : null;
        }
        if (!$root) { return ''; }

        $walk = function (DOMNode $node) use (&$walk, $allow, $drop, $attrs) {
            for ($c = $node->firstChild; $c !== null; $c = $next) {
                $next = $c->nextSibling;
                if ($c->nodeType === XML_COMMENT_NODE || $c->nodeType === XML_PI_NODE) {
                    $node->removeChild($c);
                    continue;
                }
                if ($c->nodeType !== XML_ELEMENT_NODE) { continue; }
                $tag = strtolower($c->nodeName);
                if (in_array($tag, $drop, true)) {
                    $node->removeChild($c);
                    continue;
                }
                $walk($c);
                if (!in_array($tag, $allow, true)) {
                    // แท็กอื่น (เช่น font, a) เก็บข้อความไว้ ตัดตัวแท็กออก
                    while ($c->firstChild) { $node->insertBefore($c->firstChild, $c); }
                    $node->removeChild($c);
                    continue;
                }
                $remove = array();
                foreach ($c->attributes as $a) {
                    $name = strtolower($a->nodeName);
                    $val = (string) $a->nodeValue;
                    $bad = !in_array($name, $attrs, true);
                    if (!$bad && $name === 'style' && preg_match('/url\s*\(|expression|javascript:|@import|behavior|-moz-binding/i', $val)) { $bad = true; }
                    if (!$bad && $name === 'src' && !preg_match('~^(https?://|(?![a-z][a-z0-9+.-]*:)[^\s"\'<>]+$)~i', $val)) { $bad = true; }
                    if (!$bad && $name !== 'src' && $name !== 'style' && preg_match('/javascript:|data:/i', $val)) { $bad = true; }
                    if ($bad) { $remove[] = $a->nodeName; }
                }
                foreach ($remove as $n) { $c->removeAttribute($n); }
            }
        };
        $walk($root);

        $out = '';
        foreach ($root->childNodes as $c) { $out .= $dom->saveHTML($c); }
        return $out;
    }
}
