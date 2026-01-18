<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\FeedManager;
use HP_GMC\Services\AuditLog;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP abilities for feed management.
 */
class FeedAbilities
{
    /**
     * Create a new feed.
     */
    public static function createFeed(array $params): array
    {
        $name = $params['name'] ?? '';
        $type = $params['type'] ?? 'exclusion';
        $category = $params['category'] ?? null;

        if (empty($name)) {
            return ['success' => false, 'error' => 'Feed name is required'];
        }

        if (!in_array($type, ['exclusion', 'redirect', 'custom'])) {
            return ['success' => false, 'error' => 'Invalid feed type. Must be: exclusion, redirect, or custom'];
        }

        $feedId = FeedManager::create($name, $type, $category);

        if ($feedId) {
            AuditLog::log('feed_create', $params, ['feed_id' => $feedId]);
            
            return [
                'success' => true,
                'feed_id' => $feedId,
                'message' => "Feed '{$name}' created successfully",
            ];
        }

        return ['success' => false, 'error' => 'Failed to create feed'];
    }

    /**
     * List all feeds.
     */
    public static function listFeeds(array $params): array
    {
        $type = $params['type'] ?? null;
        
        $feeds = FeedManager::getAll($type);
        $summary = FeedManager::getSummary();

        return [
            'success' => true,
            'feeds' => $feeds,
            'summary' => $summary,
            'count' => count($feeds),
        ];
    }

    /**
     * Get feed details.
     */
    public static function getFeed(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);

        if (!$feedId) {
            return ['success' => false, 'error' => 'Feed ID is required'];
        }

        $feed = FeedManager::get($feedId);
        
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        $products = FeedManager::getProducts($feedId);

        return [
            'success' => true,
            'feed' => $feed,
            'products' => $products,
            'product_count' => count($products),
        ];
    }

    /**
     * Add products to a feed.
     */
    public static function addProducts(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);
        $skus = $params['skus'] ?? [];
        $value = $params['value'] ?? '';
        $reason = $params['reason'] ?? null;

        if (!$feedId) {
            return ['success' => false, 'error' => 'Feed ID is required'];
        }

        if (empty($skus)) {
            return ['success' => false, 'error' => 'At least one SKU is required'];
        }

        if (empty($value)) {
            return ['success' => false, 'error' => 'Value is required'];
        }

        $feed = FeedManager::get($feedId);
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        $attribute = $feed['feed_type'] === 'redirect' ? 'ads_redirect' : 'excluded_destination';

        $added = 0;
        $failed = [];

        foreach ($skus as $sku) {
            $productId = wc_get_product_id_by_sku($sku);
            
            if (!$productId) {
                $failed[] = ['sku' => $sku, 'error' => 'Product not found'];
                continue;
            }

            $result = FeedManager::addProduct($feedId, $productId, $attribute, $value, $reason);
            
            if ($result) {
                $added++;
            } else {
                $failed[] = ['sku' => $sku, 'error' => 'Failed to add'];
            }
        }

        AuditLog::log('feed_add_products', $params, [
            'feed_id' => $feedId,
            'added' => $added,
            'failed' => count($failed),
        ]);

        return [
            'success' => true,
            'added' => $added,
            'failed' => $failed,
            'message' => "{$added} products added to feed",
        ];
    }

    /**
     * Remove a product from a feed.
     */
    public static function removeProduct(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);
        $sku = $params['sku'] ?? '';

        if (!$feedId) {
            return ['success' => false, 'error' => 'Feed ID is required'];
        }

        if (empty($sku)) {
            return ['success' => false, 'error' => 'SKU is required'];
        }

        $productId = wc_get_product_id_by_sku($sku);
        
        if (!$productId) {
            return ['success' => false, 'error' => 'Product not found'];
        }

        $result = FeedManager::removeProduct($feedId, $productId);

        if ($result) {
            AuditLog::log('feed_remove_product', $params, ['feed_id' => $feedId, 'product_id' => $productId]);
            
            return [
                'success' => true,
                'message' => "Product {$sku} removed from feed",
            ];
        }

        return ['success' => false, 'error' => 'Failed to remove product'];
    }

    /**
     * Generate feed file.
     */
    public static function generateFile(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);
        $format = $params['format'] ?? 'tsv';

        if (!$feedId) {
            return ['success' => false, 'error' => 'Feed ID is required'];
        }

        $result = FeedManager::generateFile($feedId, $format);

        if ($result['success']) {
            AuditLog::log('feed_generate', $params, $result);
        }

        return $result;
    }

    /**
     * Upload feed to GMC.
     */
    public static function uploadToGMC(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);

        if (!$feedId) {
            return ['success' => false, 'error' => 'Feed ID is required'];
        }

        $result = FeedManager::uploadToGMC($feedId);

        return $result;
    }

    /**
     * Check feed status in GMC.
     */
    public static function checkStatus(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);

        if (!$feedId) {
            return ['success' => false, 'error' => 'Feed ID is required'];
        }

        return FeedManager::checkGMCStatus($feedId);
    }

    /**
     * Delete a feed.
     */
    public static function deleteFeed(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);
        $deleteFromGmc = (bool) ($params['delete_from_gmc'] ?? false);

        if (!$feedId) {
            return ['success' => false, 'error' => 'Feed ID is required'];
        }

        $feed = FeedManager::get($feedId);
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        // Delete from GMC first if requested
        if ($deleteFromGmc && !empty($feed['gmc_feed_id'])) {
            FeedManager::deleteFromGMC($feedId);
        }

        $result = FeedManager::delete($feedId);

        if ($result) {
            AuditLog::log('feed_delete', $params, ['feed_name' => $feed['name']]);
            
            return [
                'success' => true,
                'message' => "Feed '{$feed['name']}' deleted successfully",
            ];
        }

        return ['success' => false, 'error' => 'Failed to delete feed'];
    }

    /**
     * Create a virtual product for GMC/funnels.
     */
    public static function createVirtualProduct(array $params): array
    {
        $name = $params['name'] ?? '';
        $sku = $params['sku'] ?? '';
        $price = (float) ($params['price'] ?? 0);
        $funnelUrl = $params['funnel_url'] ?? '';
        $description = $params['description'] ?? '';

        if (empty($name) || empty($sku) || $price <= 0 || empty($funnelUrl)) {
            return ['success' => false, 'error' => 'Name, SKU, price, and funnel_url are required'];
        }

        // Check if SKU already exists
        $existingId = wc_get_product_id_by_sku($sku);
        if ($existingId) {
            return ['success' => false, 'error' => "Product with SKU '{$sku}' already exists"];
        }

        // Create the product
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku($sku);
        $product->set_regular_price($price);
        $product->set_description($description);
        $product->set_short_description($description);
        $product->set_catalog_visibility('hidden'); // Hidden from store
        $product->set_status('publish');
        $product->set_virtual(true); // No shipping needed
        
        $productId = $product->save();

        if (!$productId) {
            return ['success' => false, 'error' => 'Failed to create product'];
        }

        // Set virtual product meta
        update_post_meta($productId, '_hp_gmc_virtual_product', 'yes');
        update_post_meta($productId, '_hp_gmc_ads_redirect', $funnelUrl);

        AuditLog::log('virtual_product_create', $params, ['product_id' => $productId]);

        return [
            'success' => true,
            'product_id' => $productId,
            'sku' => $sku,
            'message' => "Virtual product '{$name}' created. Hidden from store, will sync to GMC via GLA.",
            'next_steps' => [
                '1. Wait for GLA to sync product to GMC',
                '2. Add product to a redirect feed with funnel URL',
                '3. Upload feed to GMC',
            ],
        ];
    }

    /**
     * List all virtual products.
     */
    public static function listVirtualProducts(array $params): array
    {
        $args = [
            'status' => 'publish',
            'limit' => 100,
            'meta_query' => [
                [
                    'key' => '_hp_gmc_virtual_product',
                    'value' => 'yes',
                ],
            ],
        ];

        $products = wc_get_products($args);
        $results = [];

        foreach ($products as $product) {
            $productId = $product->get_id();
            $results[] = [
                'id' => $productId,
                'sku' => $product->get_sku(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'funnel_url' => get_post_meta($productId, '_hp_gmc_ads_redirect', true),
                'gla_synced' => !empty(get_post_meta($productId, '_wc_gla_mc_id', true)),
            ];
        }

        return [
            'success' => true,
            'products' => $results,
            'count' => count($results),
        ];
    }
}
