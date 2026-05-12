<?php
/**
 * Diagnostic: Check what wpdb returns for scraped_images isExcluded field.
 * Run via: wp eval-file tmp_debug_images.php
 */
require_once __DIR__ . '/wp-load-helper.php';

// Bootstrap WordPress if not already loaded
if (!function_exists('get_option')) {
    // Try to find wp-load.php
    $wp_load = dirname(__DIR__, 3) . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        die("Cannot find wp-load.php\n");
    }
}

global $wpdb;

$prefix = $wpdb->prefix . 'pcm_';
$table = $prefix . 'scraped_images';

// Get first image row
$row = $wpdb->get_row("SELECT id, isExcluded, isUsable FROM {$table} LIMIT 1");

if (!$row) {
    echo "No scraped images found in DB.\n";
    exit;
}

echo "=== RAW DB ROW ===\n";
echo "id: " . var_export($row->id, true) . " (type: " . gettype($row->id) . ")\n";
echo "isExcluded: " . var_export($row->isExcluded, true) . " (type: " . gettype($row->isExcluded) . ")\n";
echo "isUsable: " . var_export($row->isUsable, true) . " (type: " . gettype($row->isUsable) . ")\n";

echo "\n=== JS-EQUIVALENT TRUTHINESS ===\n";
echo "isExcluded truthy in JS? " . ($row->isExcluded === '0' ? 'YES (string \"0\" is truthy in JS!)' : ($row->isExcluded === 0 ? 'no (int 0 is falsy)' : 'value=' . var_export($row->isExcluded, true))) . "\n";

echo "\n=== JSON ENCODE ===\n";
echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
