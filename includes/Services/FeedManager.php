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

        // Check if product already exists in this feed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE feed_id = %d AND product_id = %d",
            $feedId,
            $productId
        ));

        if ($existing) {
            // Update existing
            $result = $wpdb->update(
                $table,
                [
                    'attribute_name' => sanitize_text_field($attribute),
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

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $productsTable WHERE feed_id = %d",
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

        // Build file content
        $lines = [];

        // Header row - use attribute name from first product
        $attributeName = $products[0]['attribute_name'] ?? 'excluded_destination';
        $lines[] = implode($delimiter, ['id', $attributeName]);

        // Data rows
        foreach ($products as $product) {
            $gmcId = $product['gmc_id'] ?: self::buildGmcId($product['sku']);
            $lines[] = implode($delimiter, [$gmcId, $product['attribute_value']]);
        }

        $content = implode("\n", $lines);

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
            'product_count' => count($products),
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

        // Read file content
        $content = file_get_contents($feed['file_path']);
        if ($content === false) {
            return ['success' => false, 'error' => 'Failed to read feed file'];
        }

        $client = new MerchantApiClient();

        // Check if feed already exists in GMC
        if (!empty($feed['gmc_feed_id'])) {
            // Update existing feed
            $result = $client->uploadFeedContent($feed['gmc_feed_id'], $content);
        } else {
            // Create new supplemental feed
            $createResult = $client->createSupplementalFeed($feed['name']);
            
            if (!$createResult['success']) {
                self::update($feedId, ['status' => self::STATUS_ERROR, 'gmc_status' => 'create_failed']);
                return $createResult;
            }

            $gmcFeedId = $createResult['data']['feedId'] ?? $createResult['data']['name'] ?? null;
            
            if ($gmcFeedId) {
                self::update($feedId, ['gmc_feed_id' => $gmcFeedId]);
            }

            // Upload content
            $result = $client->uploadFeedContent($gmcFeedId, $content);
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
}
