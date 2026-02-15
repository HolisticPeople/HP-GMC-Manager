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
        $attributeName = $params['attribute_name'] ?? null;

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

        // Determine attribute name based on feed type or explicit parameter
        if ($attributeName) {
            // Use explicitly provided attribute name (for custom feeds)
            $attribute = sanitize_text_field($attributeName);
        } elseif ($feed['feed_type'] === 'redirect') {
            $attribute = 'ads_redirect';
        } elseif ($feed['feed_type'] === 'exclusion') {
            $attribute = 'excluded_destination';
        } else {
            // For custom feeds without explicit attribute, require it
            return ['success' => false, 'error' => 'attribute_name is required for custom feeds'];
        }

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

        try {
            $result = FeedManager::uploadToGMC($feedId);
            return $result;
        } catch (\Throwable $e) {
            error_log('[HP-GMC] Feed upload exception: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return [
                'success' => false,
                'error' => 'Exception during upload: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
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

        try {
            return FeedManager::checkGMCStatus($feedId);
        } catch (\Throwable $e) {
            error_log('[HP-GMC] Feed status check exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage(),
            ];
        }
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
     * Consolidate multiple feeds into one (general-purpose merge).
     * Use either an existing target feed (target_feed_id) or create a new one (target_feed_name + target_feed_type).
     * Deduplicates by product_id + attribute_name (last occurrence wins). Optionally deletes source feeds after merge.
     *
     * @param array $params source_feed_ids (required), target_feed_id OR (target_feed_name + target_feed_type), delete_sources_after (optional)
     * @return array
     */
    public static function consolidateFeeds(array $params): array
    {
        $sourceFeedIds = $params['source_feed_ids'] ?? [];
        if (!is_array($sourceFeedIds)) {
            $sourceFeedIds = array_filter([$sourceFeedIds]);
        }
        $sourceFeedIds = array_map('intval', array_filter($sourceFeedIds));

        $targetFeedId = isset($params['target_feed_id']) ? (int) $params['target_feed_id'] : null;
        $targetName = $params['target_feed_name'] ?? '';
        $targetType = $params['target_feed_type'] ?? 'custom';
        $targetCategory = $params['target_feed_category'] ?? null;
        $deleteSourcesAfter = (bool) ($params['delete_sources_after'] ?? false);

        if (empty($sourceFeedIds)) {
            return ['success' => false, 'error' => 'At least one source feed ID is required (source_feed_ids).'];
        }

        $targetSpec = null;
        if ($targetFeedId > 0) {
            $targetSpec = $targetFeedId;
        } elseif (!empty($targetName) && in_array($targetType, ['exclusion', 'redirect', 'custom'], true)) {
            $targetSpec = [
                'name' => $targetName,
                'type' => $targetType,
                'category' => $targetCategory,
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Provide either target_feed_id (existing feed) or both target_feed_name and target_feed_type (to create a new feed).',
            ];
        }

        $result = FeedManager::consolidateFeeds($sourceFeedIds, $targetSpec, $deleteSourcesAfter);

        if ($result['success']) {
            AuditLog::log('feed_consolidate', [
                'source_feed_ids' => $sourceFeedIds,
                'target_feed_id' => $result['target_feed_id'],
                'rows_merged' => $result['rows_merged'],
                'sources_deleted' => $result['sources_deleted'] ?? 0,
            ], $result);
        }

        return $result;
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

    /**
     * Auto-populate a feed with products matching issue patterns.
     */
    public static function autoPopulateFeed(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);
        $issuePatterns = $params['issue_patterns'] ?? [];
        $dryRun = $params['dry_run'] ?? true;

        if (!$feedId) {
            return ['success' => false, 'error' => 'feed_id is required'];
        }

        if (empty($issuePatterns)) {
            return ['success' => false, 'error' => 'issue_patterns array is required'];
        }

        $result = FeedManager::autoPopulate($feedId, $issuePatterns, $dryRun);

        if (!$dryRun && $result['success']) {
            AuditLog::log('feed_auto_populate', $params, $result);
        }

        return $result;
    }

    /**
     * Create standard policy exclusion feeds.
     */
    public static function createPolicyFeeds(array $params): array
    {
        $dryRun = $params['dry_run'] ?? true;

        // Define the standard feeds based on issue categories
        $standardFeeds = [
            [
                'name' => 'hp-exclusions-personalization',
                'type' => 'exclusion',
                'category' => 'personalization',
                'description' => 'Excludes products from personalized ads (Display, Video)',
                'exclusions' => 'Display_ads,Video_ads',
                'issue_patterns' => ['Personal.*Hardship', 'Sexual.*interest'],
            ],
            [
                'name' => 'hp-exclusions-pharma',
                'type' => 'exclusion',
                'category' => 'pharma',
                'description' => 'Excludes supplements classified as pharmaceuticals',
                'exclusions' => 'Shopping_ads,Display_ads',
                'issue_patterns' => ['Prohibited.*pharmaceuticals', 'Prohibited.*supplement'],
            ],
            [
                'name' => 'hp-exclusions-otc',
                'type' => 'exclusion',
                'category' => 'otc',
                'description' => 'Excludes OTC medication and pet pharma products',
                'exclusions' => 'Shopping_ads',
                'issue_patterns' => ['Over.*counter', 'OTC', 'Pet.*pharmaceutical'],
            ],
        ];

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'feeds_to_create' => $standardFeeds,
                'message' => 'Set dry_run=false to create these feeds',
            ];
        }

        // Check for existing feeds
        $existingFeeds = FeedManager::getAll('exclusion');
        $existingNames = array_column($existingFeeds, 'name');

        $created = [];
        $skipped = [];

        foreach ($standardFeeds as $feedDef) {
            if (in_array($feedDef['name'], $existingNames)) {
                $skipped[] = $feedDef['name'];
                continue;
            }

            $feedId = FeedManager::create($feedDef['name'], $feedDef['type'], $feedDef['category']);
            
            if ($feedId) {
                $created[] = [
                    'feed_id' => $feedId,
                    'name' => $feedDef['name'],
                    'category' => $feedDef['category'],
                ];
            }
        }

        AuditLog::log('create_policy_feeds', $params, [
            'created' => count($created),
            'skipped' => count($skipped),
        ]);

        return [
            'success' => true,
            'dry_run' => false,
            'created' => $created,
            'skipped' => $skipped,
            'message' => count($created) . ' feeds created, ' . count($skipped) . ' already existed',
            'next_steps' => [
                '1. Use gmc-auto-populate-feed to add matching products to each feed',
                '2. Review products in each feed via gmc-feed-get or dashboard',
                '3. Generate and upload feeds to GMC',
            ],
        ];
    }

    /**
     * Get detailed statistics for a feed.
     */
    public static function getFeedStatistics(array $params): array
    {
        $feedId = (int) ($params['feed_id'] ?? 0);

        if (!$feedId) {
            return ['success' => false, 'error' => 'feed_id is required'];
        }

        return FeedManager::getStatistics($feedId);
    }

    /**
     * Get correlation report between feed products and GMC issues.
     */
    public static function getFeedCorrelationReport(array $params): array
    {
        global $wpdb;
        $feedId = (int) ($params['feed_id'] ?? 0);

        if (!$feedId) {
            return ['success' => false, 'error' => 'feed_id is required'];
        }

        $feed = FeedManager::get($feedId);
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        $products = FeedManager::getProducts($feedId);
        if (empty($products)) {
            return ['success' => true, 'feed_name' => $feed['name'], 'message' => 'No products in feed'];
        }

        $report = [
            'feed_id' => $feedId,
            'feed_name' => $feed['name'],
            'total_products' => count($products),
            'resolved' => 0,
            'remaining' => 0,
            'issue_breakdown' => [],
            'details' => [],
        ];

        $statusTable = $wpdb->prefix . 'hp_gmc_product_status';

        foreach ($products as $product) {
            $status = $wpdb->get_row($wpdb->prepare(
                "SELECT status, issues FROM $statusTable WHERE product_id = %d",
                $product['product_id']
            ));

            $issues = $status ? (json_decode($status->issues, true) ?: []) : [];
            $isDisapproved = $status && in_array($status->status, ['disapproved', 'warning']);
            
            $productIssues = [];
            foreach ($issues as $issue) {
                $desc = $issue['description'] ?? (is_string($issue) ? $issue : '');
                if ($desc) {
                    $productIssues[] = $desc;
                    if (!isset($report['issue_breakdown'][$desc])) {
                        $report['issue_breakdown'][$desc] = 0;
                    }
                    $report['issue_breakdown'][$desc]++;
                }
            }

            if (empty($productIssues) && !$isDisapproved) {
                $report['resolved']++;
            } else {
                $report['remaining']++;
            }

            $report['details'][] = [
                'sku' => $product['sku'],
                'name' => $product['product_name'],
                'status' => $status->status ?? 'unknown',
                'remaining_issues' => $productIssues,
                'resolved' => empty($productIssues) && !$isDisapproved,
            ];
        }

        return [
            'success' => true,
            'report' => $report,
        ];
    }

    /**
     * Create and populate a targeted feed for specific attributes.
     */
    public static function createTargetedFeed(array $params): array
    {
        global $wpdb;
        $name = $params['name'] ?? '';
        $attribute = $params['attribute'] ?? '';
        $value = $params['value'] ?? '';
        $issuePattern = $params['issue_pattern'] ?? '';
        $categoryFilter = $params['category_filter'] ?? null;
        $dryRun = $params['dry_run'] ?? true;

        if (empty($name) || empty($attribute) || empty($value) || empty($issuePattern)) {
            return ['success' => false, 'error' => 'name, attribute, value, and issue_pattern are required'];
        }

        // 1. Create the feed if it doesn't exist
        $feeds = FeedManager::getAll('custom');
        $feedId = 0;
        foreach ($feeds as $f) {
            if ($f['name'] === $name) {
                $feedId = (int) $f['id'];
                break;
            }
        }

        if (!$feedId && !$dryRun) {
            $feedId = FeedManager::create($name, 'custom', 'targeted-fix');
        }

        // 2. Find matching products
        $statusTable = $wpdb->prefix . 'hp_gmc_product_status';
        
        $sql = "SELECT * FROM $statusTable WHERE status IN ('disapproved', 'warning')";
        $products = $wpdb->get_results($sql, ARRAY_A);

        $matches = [];
        foreach ($products as $row) {
            $issues = json_decode($row['issues'], true) ?: [];
            $match = false;
            foreach ($issues as $issue) {
                $desc = $issue['description'] ?? (is_string($issue) ? $issue : '');
                if (preg_match('/' . $issuePattern . '/i', $desc)) {
                    $match = true;
                    break;
                }
            }

            if ($match) {
                $wcProduct = wc_get_product($row['product_id']);
                if (!$wcProduct) continue;

                // Category filter if provided
                if ($categoryFilter) {
                    $terms = wp_get_post_terms($row['product_id'], 'product_cat', ['fields' => 'names']);
                    $catMatch = false;
                    foreach ($terms as $termName) {
                        if (stripos($termName, $categoryFilter) !== false) {
                            $catMatch = true;
                            break;
                        }
                    }
                    if (!$catMatch) continue;
                }

                $matches[] = [
                    'product_id' => (int) $row['product_id'],
                    'sku' => $wcProduct->get_sku(),
                    'name' => $wcProduct->get_name(),
                ];
            }
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'feed_name' => $name,
                'matching_products' => count($matches),
                'sample' => array_slice($matches, 0, 10),
                'message' => "Would add " . count($matches) . " products to feed '{$name}' with {$attribute}='{$value}'",
            ];
        }

        // 3. Populate the feed
        $added = 0;
        foreach ($matches as $m) {
            if (FeedManager::addProduct($feedId, $m['product_id'], $attribute, $value, 'Targeted fix for ' . $issuePattern)) {
                $added++;
            }
        }

        return [
            'success' => true,
            'dry_run' => false,
            'feed_id' => $feedId,
            'added' => $added,
            'message' => "Added {$added} products to feed '{$name}'",
        ];
    }

    /**
     * Get primary product feed status.
     * 
     * @param array $params
     * @return array
     */
    public static function getPrimaryFeedStatus(array $params): array
    {
        $status = \HP_GMC\Services\ProductDataFeed::getStatus();

        return [
            'success' => true,
            'feed_type' => 'primary_product_feed',
            'description' => 'Main product data feed for GMC (replaces GLA sync)',
            'feed_url' => $status['feed_url'],
            'feed_url_csv' => $status['feed_url_csv'],
            'product_count' => $status['product_count'],
            'last_generated' => $status['last_generated'],
            'last_generated_ago' => $status['last_generated_ago'],
            'cache_duration_minutes' => $status['cache_duration_minutes'],
            'instructions' => [
                'To register in GMC:',
                '1. Go to Google Merchant Center → Products → Feeds',
                '2. Click "Add primary feed" or "Add supplemental feed"',
                '3. Select "Scheduled fetch" and paste the TSV URL',
                '4. Set fetch frequency to Daily',
            ],
        ];
    }

    /**
     * Regenerate the primary product feed.
     * 
     * @param array $params
     * @return array
     */
    public static function regeneratePrimaryFeed(array $params): array
    {
        try {
            // Clear cache
            \HP_GMC\Services\ProductDataFeed::clearCache();

            // Regenerate both formats
            \HP_GMC\Services\ProductDataFeed::generateFeed('tsv', true);
            \HP_GMC\Services\ProductDataFeed::generateFeed('csv', true);

            $status = \HP_GMC\Services\ProductDataFeed::getStatus();

            AuditLog::log('primary_feed_regenerate', [], [
                'product_count' => $status['product_count'],
            ]);

            return [
                'success' => true,
                'message' => sprintf('Feed regenerated with %d products', $status['product_count']),
                'product_count' => $status['product_count'],
                'last_generated' => $status['last_generated'],
                'feed_url' => $status['feed_url'],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
