<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * แทรก `<link>` + `<script>` สำหรับ modal กลาง (`css/mn-modal.css`, `js/mn-modal.js`)
 * ใช้ในหน้า PHP ที่อยู่รากโปรเจกต์ maintenance (path แบบ relative จาก URL หน้า)
 *
 * Parameters จากหน้าเรียก (optional):
 * - string $mnModalAssetPrefix: prefix path เช่น '' หรือ '../' ถ้าอยู่ subfolder
 */

if (!isset($mnModalAssetPrefix) || !is_string($mnModalAssetPrefix)) {
    $mnModalAssetPrefix = '';
}
$p = $mnModalAssetPrefix;
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($p . 'css/mn-modal.css', ENT_QUOTES, 'UTF-8'); ?>" />
<script type="text/javascript" src="<?php echo htmlspecialchars($p . 'js/mn-modal.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
