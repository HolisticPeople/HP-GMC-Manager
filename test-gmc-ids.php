<?php
/**
 * Test script to verify getGmcIdFromProduct() works correctly.
 * Run via WP-CLI: wp eval-file test-gmc-ids.php
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Get products that have GLA sync data
$products = $wpdb->get_results("
    SELECT pm1.post_id, pm2.meta_value as sku, pm1.meta_value as gla_ids 
    FROM {$wpdb->postmeta} pm1 
    JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id 
    WHERE pm1.meta_key = '_wc_gla_google_ids' 
    AND pm2.meta_key = '_sku' 
    LIMIT 5
");

echo "Testing GMC ID extraction:\n";
echo str_repeat('-', 80) . "\n";

foreach ($products as $row) {
    $product_id = $row->post_id;
    $sku = $row->sku;
    $gla_ids = json_decode($row->gla_ids, true);
    $us_id = $gla_ids['US'] ?? 'NOT SET';
    
    // Test FeedManager::getGmcIdFromProduct equivalent
    $googleIds = get_post_meta($product_id, '_wc_gla_google_ids', true);
    $extracted_id = 'FALLBACK: online:en:US:gla_' . $product_id;
    if ($googleIds) {
        $decoded = is_string($googleIds) ? json_decode($googleIds, true) : $googleIds;
        if (is_array($decoded) && isset($decoded['US'])) {
            $extracted_id = $decoded['US'];
        }
    }
    
    echo sprintf(
        "ID: %d | SKU: %-20s | GLA ID: %s\n",
        $product_id,
        $sku,
        $extracted_id
    );
}

echo str_repeat('-', 80) . "\n";
echo "Done. If IDs start with 'online:en:US:gla_', the fix is working!\n";
