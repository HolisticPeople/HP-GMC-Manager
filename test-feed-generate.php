<?php
/**
 * Test feed generation with new ID format.
 * Run via WP-CLI: wp eval-file test-feed-generate.php
 */

if (!defined('ABSPATH')) {
    exit;
}

// Test getGmcIdFromProduct for products in feed 4 (hp-exclusions-otc)
global $wpdb;

$products = $wpdb->get_results("
    SELECT product_id, sku, gmc_id 
    FROM {$wpdb->prefix}hp_gmc_feed_products 
    WHERE feed_id = 4
");

echo "Testing feed ID generation (hp-exclusions-otc):\n";
echo str_repeat('-', 80) . "\n";

foreach ($products as $row) {
    $product_id = (int) $row->product_id;
    $stored_id = $row->gmc_id;
    
    // Simulate getGmcIdFromProduct
    $googleIds = get_post_meta($product_id, '_wc_gla_google_ids', true);
    $new_id = '';
    if ($googleIds) {
        $decoded = is_string($googleIds) ? json_decode($googleIds, true) : $googleIds;
        if (is_array($decoded) && isset($decoded['US'])) {
            $new_id = $decoded['US'];
        }
    }
    if (empty($new_id)) {
        $new_id = 'online:en:US:gla_' . $product_id;
    }
    
    echo sprintf(
        "ProductID: %5d | SKU: %-20s | Stored: %-35s | New: %s\n",
        $product_id,
        $row->sku,
        $stored_id ?: '(empty)',
        $new_id
    );
}

echo str_repeat('-', 80) . "\n";
echo "New IDs use gla_XXXXX format (even as fallback).\n";
