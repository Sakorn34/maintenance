<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * โหลด config ขนส่งจาก DB + `js/mn-logistics-tracking.js` สำหรับ copy tracking / เปิดหน้า track
 *
 * ต้องมี `$connect` (mysqli) ใน scope ก่อน require ไฟล์นี้
 *
 * ตัวแปร:
 * - $mnLogisticsTrackingAssetPrefix (optional) path ไปราก เช่น '../' สำหรับ subfolder
 */

if (is_readable(__DIR__ . '/logistics_helpers.php')) {
    require_once __DIR__ . '/logistics_helpers.php';
}

$mnLogisticsTrackingAssetPrefix = isset($mnLogisticsTrackingAssetPrefix)
    ? (string) $mnLogisticsTrackingAssetPrefix
    : '';

if (function_exists('maintenance_logistics_echo_js_config')) {
    if (isset($mnLogisticsTrackingMap) && is_array($mnLogisticsTrackingMap)) {
        maintenance_logistics_echo_js_config(null, $mnLogisticsTrackingMap);
    } elseif (isset($connect) && $connect instanceof mysqli) {
        maintenance_logistics_echo_js_config($connect);
    } else {
        maintenance_logistics_echo_js_config(null);
    }
} elseif (isset($mnLogisticsTrackingMap) && is_array($mnLogisticsTrackingMap)) {
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_HEX_AMP')) {
        $flags |= JSON_HEX_AMP;
    }
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $flags |= JSON_UNESCAPED_UNICODE;
    }
    echo '<script>window.MN_LOGISTICS_TRACKING=';
    echo json_encode($mnLogisticsTrackingMap, $flags);
    echo ';</script>' . "\n";
}
?>
<script type="text/javascript" src="<?php echo htmlspecialchars($mnLogisticsTrackingAssetPrefix, ENT_QUOTES, 'UTF-8'); ?>js/mn-logistics-tracking.js"></script>
