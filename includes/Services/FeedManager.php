<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages supplemental feeds for Google Merchant Center.
 * Handles feed CRUD, product management, file generation, and GMC uploads.
 */
class FeedManager
{
    // Feed types
    const TYPE_EXCLUSION = 'exclusion';
    const TYPE_REDIRECT = 'redirect';
    const TYPE_CUSTOM = 'custom';

    // Feed statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_GENERATED = 'generated';
    const STATUS_UPLOADED = 'uploaded';
    const STATUS_PROCESSING = 'processing';
    const STATUS_ACTIVE = 'active';
    const STATUS_ERROR = 'error';

    /**
     * Create a new feed.
     */
    public static function create(string $name, string $type, ?string $category = null): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feeds';

        $result = $wpdb->insert($table, [
            'name' => sanitize_text_field($name),
            'feed_type' => sanitize_text_field($type),
            'category' => $category ? sanitize_text_field($category) : null,
            'status' => self::STATUS_DRAFT,
            'product_count' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        if ($result === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Get all feeds, optionally filtered by type.
     */
    public static function getAll(?string $type = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feeds';

        $sql = "SELECT * FROM $table";
        $params = [];

        if ($type !== null) {
            $sql .= " WHERE feed_type = %s";
            $params[] = $type;
        }

        $sql .= " ORDER BY created_at DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $results = $wpdb->get_results($sql, ARRAY_A);
        return $results ?: [];
    }

    /**
     * Get a single feed by ID.
     */
    public static function get(int $feedId): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feeds';

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $feedId),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Update feed fields.
     */
    public static function update(int $feedId, array $data): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feeds';

        // Sanitize allowed fields
        $allowed = ['name', 'category', 'status', 'gmc_feed_id', 'file_path', 'file_url', 'last_uploaded', 'gmc_status', 'product_count'];
        $updateData = [];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return false;
        }

        $updateData['updated_at'] = current_time('mysql');

        $result = $wpdb->update($table, $updateData, ['id' => $feedId]);
        return $result !== false;
    }

    /**
     * Delete a feed and its products.
     */
    public static function delete(int $feedId): bool
    {
        global $wpdb;
        $feedsTable = $wpdb->prefix . 'hp_gmc_feeds';
        $productsTable = $wpdb->prefix . 'hp_gmc_feed_products';

        // Delete products first
        $wpdb->delete($productsTable, ['feed_id' => $feedId]);

        // Delete feed
        $result = $wpdb->delete($feedsTable, ['id' => $feedId]);
        return $result !== false;
    }

    /**
     * Add a product to a feed.
     */
    public static function addProduct(int $feedId, int $productId, string $attribute, string $value, ?string $reason = null): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feed_products';

        // Get product SKU and GMC ID
        $product = wc_get_product($productId);
        if (!$product) {
            return false;
        }

        $sku = $product->get_sku();
        $gmcId = get_post_meta($productId, '_wc_gla_mc_id', true) ?: '';

        // Check if product+attribute already exists in this feed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE feed_id = %d AND product_id = %d AND attribute_name = %s",
            $feedId,
            $productId,
            $attribute
        ));

        if ($existing) {
            // Update existing attribute value
            $result = $wpdb->update(
                $table,
                [
                    'attribute_value' => sanitize_text_field($value),
                    'reason' => $reason ? sanitize_text_field($reason) : null,
                    'gmc_id' => $gmcId,
                ],
                ['id' => $existing]
            );
        } else {
            // Insert new
            $result = $wpdb->insert($table, [
                'feed_id' => $feedId,
                'product_id' => $productId,
                'sku' => $sku,
                'gmc_id' => $gmcId,
                'attribute_name' => sanitize_text_field($attribute),
                'attribute_value' => sanitize_text_field($value),
                'reason' => $reason ? sanitize_text_field($reason) : null,
                'added_at' => current_time('mysql'),
            ]);
        }

        if ($result !== false) {
            // Update product count
            self::updateProductCount($feedId);
        }

        return $result !== false;
    }

    /**
     * Remove a product from a feed.
     */
    public static function removeProduct(int $feedId, int $productId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feed_products';

        $result = $wpdb->delete($table, [
            'feed_id' => $feedId,
            'product_id' => $productId,
        ]);

        if ($result !== false) {
            self::updateProductCount($feedId);
        }

        return $result !== false;
    }

    /**
     * Get all products in a feed.
     */
    public static function getProducts(int $feedId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feed_products';

        $results = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE feed_id = %d ORDER BY added_at DESC", $feedId),
            ARRAY_A
        );

        // Enrich with product details
        foreach ($results as &$row) {
            $product = wc_get_product($row['product_id']);
            if ($product) {
                $row['product_name'] = $product->get_name();
                $row['product_url'] = get_edit_post_link($row['product_id'], 'raw');
            } else {
                $row['product_name'] = '(Product deleted)';
                $row['product_url'] = '';
            }
        }

        return $results ?: [];
    }

    /**
     * Update the product count for a feed.
     */
    private static function updateProductCount(int $feedId): void
    {
        global $wpdb;
        $feedsTable = $wpdb->prefix . 'hp_gmc_feeds';
        $productsTable = $wpdb->prefix . 'hp_gmc_feed_products';

        // Count unique products (not attribute rows)
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT product_id) FROM $productsTable WHERE feed_id = %d",
            $feedId
        ));

        $wpdb->update($feedsTable, ['product_count' => $count], ['id' => $feedId]);
    }

    /**
     * Generate feed file (TSV or CSV).
     */
    public static function generateFile(int $feedId, string $format = 'tsv'): array
    {
        $feed = self::get($feedId);
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        $products = self::getProducts($feedId);
        if (empty($products)) {
            return ['success' => false, 'error' => 'No products in feed'];
        }

        // Determine delimiter
        $delimiter = $format === 'csv' ? ',' : "\t";
        $extension = $format === 'csv' ? 'csv' : 'tsv';

        // Build file content with pivoted attributes
        $lines = [];

        // Collect all unique attribute names
        $attributeNames = array_unique(array_column($products, 'attribute_name'));
        sort($attributeNames); // Consistent column order

        // Group products by GMC ID, collecting all their attributes
        $productData = [];
        foreach ($products as $product) {
            $gmcId = $product['gmc_id'] ?: self::buildGmcId($product['sku']);
            if (!isset($productData[$gmcId])) {
                $productData[$gmcId] = [];
            }
            $productData[$gmcId][$product['attribute_name']] = $product['attribute_value'];
        }

        // Build header with all attribute columns
        $lines[] = implode($delimiter, array_merge(['id'], $attributeNames));

        // Build data rows with all columns
        foreach ($productData as $gmcId => $attrs) {
            $row = [$gmcId];
            foreach ($attributeNames as $attrName) {
                $row[] = $attrs[$attrName] ?? '';
            }
            $lines[] = implode($delimiter, $row);
        }

        $content = implode("\n", $lines);
        $uniqueProductCount = count($productData);

        // Create uploads directory
        $uploadDir = wp_upload_dir();
        $feedDir = $uploadDir['basedir'] . '/hp-gmc/';
        
        if (!file_exists($feedDir)) {
            wp_mkdir_p($feedDir);
        }

        // Generate filename
        $filename = sanitize_file_name($feed['name']) . '-' . date('Y-m-d-His') . '.' . $extension;
        $filePath = $feedDir . $filename;
        $fileUrl = $uploadDir['baseurl'] . '/hp-gmc/' . $filename;

        // Write file
        $result = file_put_contents($filePath, $content);
        if ($result === false) {
            return ['success' => false, 'error' => 'Failed to write file'];
        }

        // Update feed record
        self::update($feedId, [
            'file_path' => $filePath,
            'file_url' => $fileUrl,
            'status' => self::STATUS_GENERATED,
        ]);

        return [
            'success' => true,
            'file_path' => $filePath,
            'file_url' => $fileUrl,
            'product_count' => $uniqueProductCount,
            'attribute_count' => count($attributeNames),
            'format' => $format,
        ];
    }

    /**
     * Build a GMC product ID from SKU (fallback if not synced via GLA).
     */
    private static function buildGmcId(string $sku): string
    {
        // Standard format: online:en:US:SKU
        return 'online:en:US:' . $sku;
    }

    /**
     * Get the file URL for a feed.
     */
    public static function getFileUrl(int $feedId): ?string
    {
        $feed = self::get($feedId);
        return $feed['file_url'] ?? null;
    }

    /**
     * Upload feed to GMC via API.
     */
    public static function uploadToGMC(int $feedId): array
    {
        $feed = self::get($feedId);
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        // Ensure file exists
        if (empty($feed['file_path']) || !file_exists($feed['file_path'])) {
            // Generate file first
            $generateResult = self::generateFile($feedId, 'tsv');
            if (!$generateResult['success']) {
                return $generateResult;
            }
            $feed = self::get($feedId);
        }

        // Get file URL
        $fileUrl = self::getFileUrl($feedId);
        if (empty($fileUrl)) {
            return ['success' => false, 'error' => 'Feed file URL not found'];
        }

        $client = new MerchantApiClient();

        // Check if feed already exists in GMC
        if (!empty($feed['gmc_feed_id'])) {
            // Update existing feed URL and fetch
            $result = $client->uploadFeedContent($feed['gmc_feed_id'], $fileUrl);
        } else {
            // Create new supplemental feed
            $createResult = $client->createSupplementalFeed($feed['name']);
            
            if (!$createResult['success']) {
                self::update($feedId, ['status' => self::STATUS_ERROR, 'gmc_status' => 'create_failed']);
                return $createResult;
            }

            $gmcFeedId = $createResult['data']['id'] ?? $createResult['data']['feedId'] ?? null;
            
            if (!$gmcFeedId) {
                self::update($feedId, ['status' => self::STATUS_ERROR, 'gmc_status' => 'create_failed']);
                return [
                    'success' => false,
                    'error' => 'Failed to get GMC feed ID from create response',
                    'api_response' => $createResult,
                ];
            }

            self::update($feedId, ['gmc_feed_id' => $gmcFeedId]);

            // Update URL and fetch
            $result = $client->uploadFeedContent($gmcFeedId, $fileUrl);
        }

        if ($result['success']) {
            self::update($feedId, [
                'status' => self::STATUS_UPLOADED,
                'last_uploaded' => current_time('mysql'),
                'gmc_status' => 'processing',
            ]);

            // Log to audit
            AuditLog::log('feed_upload', [
                'feed_id' => $feedId,
                'feed_name' => $feed['name'],
                'product_count' => $feed['product_count'],
            ], $result);
        } else {
            self::update($feedId, ['status' => self::STATUS_ERROR, 'gmc_status' => 'upload_failed']);
        }

        return $result;
    }

    /**
     * Check GMC processing status for a feed.
     */
    public static function checkGMCStatus(int $feedId): array
    {
        $feed = self::get($feedId);
        if (!$feed || empty($feed['gmc_feed_id'])) {
            return ['success' => false, 'error' => 'Feed not found or not uploaded'];
        }

        $client = new MerchantApiClient();
        $result = $client->getDatafeedStatus($feed['gmc_feed_id']);

        if ($result['success']) {
            $status = $result['data']['processingStatus'] ?? 'unknown';
            $newStatus = self::STATUS_PROCESSING;

            if (in_array($status, ['success', 'active', 'completed'])) {
                $newStatus = self::STATUS_ACTIVE;
            } elseif (in_array($status, ['failed', 'error'])) {
                $newStatus = self::STATUS_ERROR;
            }

            self::update($feedId, [
                'status' => $newStatus,
                'gmc_status' => $status,
            ]);
        }

        return $result;
    }

    /**
     * Delete feed from GMC.
     */
    public static function deleteFromGMC(int $feedId): array
    {
        $feed = self::get($feedId);
        if (!$feed || empty($feed['gmc_feed_id'])) {
            return ['success' => false, 'error' => 'Feed not found or not uploaded'];
        }

        $client = new MerchantApiClient();
        $result = $client->deleteDatafeed($feed['gmc_feed_id']);

        if ($result['success']) {
            self::update($feedId, [
                'gmc_feed_id' => null,
                'status' => self::STATUS_DRAFT,
                'gmc_status' => null,
                'last_uploaded' => null,
            ]);

            AuditLog::log('feed_delete', [
                'feed_id' => $feedId,
                'feed_name' => $feed['name'],
                'gmc_feed_id' => $feed['gmc_feed_id'],
            ], $result);
        }

        return $result;
    }

    /**
     * Get summary stats for all feeds.
     */
    public static function getSummary(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_feeds';

        $stats = $wpdb->get_results(
            "SELECT feed_type, status, COUNT(*) as count, SUM(product_count) as total_products 
             FROM $table 
             GROUP BY feed_type, status",
            ARRAY_A
        );

        $summary = [
            'exclusion' => ['total' => 0, 'products' => 0, 'active' => 0],
            'redirect' => ['total' => 0, 'products' => 0, 'active' => 0],
            'custom' => ['total' => 0, 'products' => 0, 'active' => 0],
        ];

        foreach ($stats as $row) {
            $type = $row['feed_type'];
            if (!isset($summary[$type])) {
                $summary[$type] = ['total' => 0, 'products' => 0, 'active' => 0];
            }
            $summary[$type]['total'] += (int) $row['count'];
            $summary[$type]['products'] += (int) $row['total_products'];
            if ($row['status'] === self::STATUS_ACTIVE) {
                $summary[$type]['active'] += (int) $row['count'];
            }
        }

        return $summary;
    }

    /**
     * Get feeds by product ID.
     */
    public static function getFeedsForProduct(int $productId): array
    {
        global $wpdb;
        $feedsTable = $wpdb->prefix . 'hp_gmc_feeds';
        $productsTable = $wpdb->prefix . 'hp_gmc_feed_products';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, fp.attribute_name, fp.attribute_value, fp.reason 
             FROM $feedsTable f 
             JOIN $productsTable fp ON f.id = fp.feed_id 
             WHERE fp.product_id = %d",
            $productId
        ), ARRAY_A) ?: [];
    }

    /**
     * Bulk add products to a feed.
     */
    public static function bulkAddProducts(int $feedId, array $productIds, string $attribute, string $value, ?string $reason = null): array
    {
        $added = 0;
        $failed = 0;
        $errors = [];

        foreach ($productIds as $productId) {
            $result = self::addProduct($feedId, (int) $productId, $attribute, $value, $reason);
            if ($result) {
                $added++;
            } else {
                $failed++;
                $errors[] = $productId;
            }
        }

        return [
            'success' => $failed === 0,
            'added' => $added,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Get statistics for a feed, including issue types covered and pending products.
     */
    public static function getStatistics(int $feedId): array
    {
        global $wpdb;
        $feed = self::get($feedId);
        
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        $products = self::getProducts($feedId);
        
        // Get issue types covered by products in this feed
        $issueTypes = [];
        $statusTable = $wpdb->prefix . 'hp_gmc_product_status';
        
        foreach ($products as $product) {
            $status = $wpdb->get_row($wpdb->prepare(
                "SELECT issues FROM $statusTable WHERE product_id = %d",
                $product['product_id']
            ));
            
            if ($status && $status->issues) {
                $issues = json_decode($status->issues, true) ?: [];
                foreach ($issues as $issue) {
                    $desc = $issue['description'] ?? (is_string($issue) ? $issue : '');
                    if ($desc && !isset($issueTypes[$desc])) {
                        $issueTypes[$desc] = 0;
                    }
                    if ($desc) {
                        $issueTypes[$desc]++;
                    }
                }
            }
        }

        // Count products with matching issues that are NOT yet in any feed
        $pendingCount = self::countProductsNotInFeeds($feed['category']);

        return [
            'success' => true,
            'feed_id' => $feedId,
            'feed_name' => $feed['name'],
            'product_count' => (int) $feed['product_count'],
            'issue_types' => $issueTypes,
            'pending_products' => $pendingCount,
            'status' => $feed['status'],
            'last_uploaded' => $feed['last_uploaded'],
        ];
    }

    /**
     * Get category patterns for matching issues.
     */
    public static function getCategoryPatterns(): array
    {
        return [
            'personalization' => ['Personal Hardships', 'Sexual interests'],
            'pharma' => ['Prohibited pharmaceuticals', 'Prohibited supplement'],
            'otc' => ['Over.*counter', 'OTC', 'Pet.*pharmaceutical'],
        ];
    }

    /**
     * Count products with issues matching a category that are not in any feed.
     * Note: This uses a faster counting method without loading WC products.
     */
    private static function countProductsNotInFeeds(?string $category): int
    {
        global $wpdb;
        
        if (!$category) {
            return 0;
        }

        $categoryPatterns = self::getCategoryPatterns();
        $patterns = $categoryPatterns[strtolower($category)] ?? [];
        if (empty($patterns)) {
            return 0;
        }

        $statusTable = $wpdb->prefix . 'hp_gmc_product_status';
        $feedProductsTable = $wpdb->prefix . 'hp_gmc_feed_products';

        // Get products with issues that are not in any feed
        $products = $wpdb->get_results(
            "SELECT ps.product_id FROM $statusTable ps 
             WHERE ps.status IN ('disapproved', 'warning')
             AND ps.product_id NOT IN (
                 SELECT fp.product_id FROM $feedProductsTable fp
             )",
            ARRAY_A
        );

        $matchCount = 0;
        foreach ($products as $row) {
            $productStatus = $wpdb->get_row($wpdb->prepare(
                "SELECT issues FROM $statusTable WHERE product_id = %d",
                $row['product_id']
            ));
            
            if ($productStatus && $productStatus->issues) {
                $issues = json_decode($productStatus->issues, true) ?: [];
                foreach ($issues as $issue) {
                    $desc = $issue['description'] ?? (is_string($issue) ? $issue : '');
                    foreach ($patterns as $pattern) {
                        if (preg_match('/' . $pattern . '/i', $desc)) {
                            $matchCount++;
                            break 2; // Count product only once
                        }
                    }
                }
            }
        }

        return $matchCount;
    }

    /**
     * Get pending products (products with matching issues not yet in any feed).
     * Uses same query pattern as countProductsNotInFeeds for consistency.
     * 
     * @param string|null $category Feed category to match
     * @return array List of pending products with details
     */
    public static function getPendingProducts(?string $category): array
    {
        global $wpdb;
        
        if (!$category) {
            return [];
        }

        $categoryPatterns = self::getCategoryPatterns();
        $patterns = $categoryPatterns[strtolower($category)] ?? [];
        if (empty($patterns)) {
            return [];
        }

        $statusTable = $wpdb->prefix . 'hp_gmc_product_status';
        $feedProductsTable = $wpdb->prefix . 'hp_gmc_feed_products';

        // Get products with issues that are not in any feed (same query as count)
        $products = $wpdb->get_results(
            "SELECT ps.product_id FROM $statusTable ps 
             WHERE ps.status IN ('disapproved', 'warning')
             AND ps.product_id NOT IN (
                 SELECT fp.product_id FROM $feedProductsTable fp
             )",
            ARRAY_A
        );

        $pending = [];
        
        foreach ($products as $row) {
            $productStatus = $wpdb->get_row($wpdb->prepare(
                "SELECT sku, issues, status FROM $statusTable WHERE product_id = %d",
                $row['product_id']
            ));
            
            if ($productStatus && $productStatus->issues) {
                $issues = json_decode($productStatus->issues, true) ?: [];
                
                foreach ($issues as $issue) {
                    $desc = $issue['description'] ?? (is_string($issue) ? $issue : '');
                    foreach ($patterns as $pattern) {
                        if (preg_match('/' . $pattern . '/i', $desc)) {
                            $product = wc_get_product($row['product_id']);
                            
                            $pending[] = [
                                'product_id' => (int) $row['product_id'],
                                'sku' => $productStatus->sku ?: ($product ? $product->get_sku() : ''),
                                'name' => $product ? $product->get_name() : 'Unknown',
                                'status' => $productStatus->status ?? 'unknown',
                                'matched_issue' => $desc,
                                'matched_pattern' => $pattern,
                            ];
                            break 2; // Count product only once
                        }
                    }
                }
            }
        }
        
        return [
            'products' => $pending,
        ];
    }

    /**
     * Add all pending products to a feed.
     * 
     * @param int $feedId Feed ID
     * @return array Result with added count
     */
    public static function addPendingProducts(int $feedId): array
    {
        $feed = self::get($feedId);
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        $result = self::getPendingProducts($feed['category']);
        $pending = $result['products'] ?? [];
        if (empty($pending)) {
            return ['success' => true, 'added' => 0, 'message' => 'No pending products to add'];
        }

        $attribute = $feed['feed_type'] === 'redirect' ? 'ads_redirect' : 'excluded_destination';
        
        // Determine default exclusion value based on feed category
        $categoryExclusions = [
            'personalization' => 'Display_ads,Video_ads',
            'pharma' => 'Shopping_ads,Display_ads',
            'otc' => 'Shopping_ads',
        ];
        $defaultValue = $categoryExclusions[$feed['category']] ?? 'Shopping_ads';

        $added = 0;
        $failed = 0;
        $errors = [];

        foreach ($pending as $product) {
            $result = self::addProduct(
                $feedId,
                $product['product_id'],
                $attribute,
                $defaultValue,
                'Pending: ' . $product['matched_issue']
            );
            
            if ($result['success']) {
                $added++;
            } else {
                $failed++;
                $errors[] = $product['sku'] . ': ' . ($result['error'] ?? 'Unknown error');
            }
        }

        return [
            'success' => $failed === 0,
            'added' => $added,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Auto-populate a feed with products matching issue patterns.
     * 
     * @param int $feedId Feed ID to populate
     * @param array $issuePatterns Array of regex patterns to match issue descriptions
     * @param bool $dryRun If true, only return what would be added
     */
    public static function autoPopulate(int $feedId, array $issuePatterns, bool $dryRun = true): array
    {
        global $wpdb;
        $feed = self::get($feedId);
        
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found'];
        }

        $statusTable = $wpdb->prefix . 'hp_gmc_product_status';
        $feedProductsTable = $wpdb->prefix . 'hp_gmc_feed_products';

        // Get products with issues that are not yet in this feed
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT ps.* FROM $statusTable ps 
             WHERE ps.status IN ('disapproved', 'warning')
             AND ps.product_id NOT IN (
                 SELECT fp.product_id FROM $feedProductsTable fp WHERE fp.feed_id = %d
             )",
            $feedId
        ), ARRAY_A);

        $matches = [];
        $attribute = $feed['feed_type'] === 'redirect' ? 'ads_redirect' : 'excluded_destination';

        // Determine default exclusion value based on feed category
        $categoryExclusions = [
            'personalization' => 'Display_ads,Video_ads',
            'pharma' => 'Shopping_ads,Display_ads',
            'otc' => 'Shopping_ads',
        ];
        $defaultValue = $categoryExclusions[$feed['category']] ?? 'Shopping_ads';

        foreach ($products as $row) {
            $issues = json_decode($row['issues'], true) ?: [];
            
            foreach ($issues as $issue) {
                $desc = $issue['description'] ?? (is_string($issue) ? $issue : '');
                
                foreach ($issuePatterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $desc)) {
                        $product = wc_get_product($row['product_id']);
                        $matches[] = [
                            'product_id' => (int) $row['product_id'],
                            'sku' => $product ? $product->get_sku() : '',
                            'product_name' => $product ? $product->get_name() : 'Unknown',
                            'matched_issue' => $desc,
                            'matched_pattern' => $pattern,
                        ];
                        break 2; // Only add each product once
                    }
                }
            }
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'feed_id' => $feedId,
                'feed_name' => $feed['name'],
                'would_add' => count($matches),
                'products' => $matches,
                'suggested_value' => $defaultValue,
            ];
        }

        // Actually add the products
        $added = 0;
        $failed = 0;

        foreach ($matches as $match) {
            $result = self::addProduct(
                $feedId,
                $match['product_id'],
                $attribute,
                $defaultValue,
                'Auto-populated: ' . $match['matched_issue']
            );
            
            if ($result) {
                $added++;
            } else {
                $failed++;
            }
        }

        // Log to audit
        AuditLog::log('feed_auto_populate', [
            'feed_id' => $feedId,
            'feed_name' => $feed['name'],
            'patterns' => $issuePatterns,
            'matched' => count($matches),
            'added' => $added,
            'failed' => $failed,
        ], ['success' => true, 'added' => $added]);

        return [
            'success' => true,
            'dry_run' => false,
            'feed_id' => $feedId,
            'feed_name' => $feed['name'],
            'added' => $added,
            'failed' => $failed,
            'products' => $matches,
        ];
    }

    /**
     * Get products not in any exclusion feed.
     */
    public static function getProductsNotInAnyFeed(int $limit = 100): array
    {
        global $wpdb;
        $statusTable = $wpdb->prefix . 'hp_gmc_product_status';
        $feedProductsTable = $wpdb->prefix . 'hp_gmc_feed_products';

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT ps.* FROM $statusTable ps 
             WHERE ps.status IN ('disapproved', 'warning')
             AND ps.product_id NOT IN (
                 SELECT DISTINCT fp.product_id FROM $feedProductsTable fp
             )
             ORDER BY ps.last_updated DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        foreach ($products as &$row) {
            $row['issues'] = json_decode($row['issues'], true) ?: [];
            $product = wc_get_product($row['product_id']);
            $row['product_name'] = $product ? $product->get_name() : 'Unknown';
            $row['sku'] = $product ? $product->get_sku() : '';
        }

        return $products;
    }
}
