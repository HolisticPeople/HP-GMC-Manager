<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Monitors and caches product issues from GMC.
 */
class IssueMonitor
{
    /**
     * Sync product statuses from GMC to local cache.
     */
    public static function sync_product_statuses(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        $client = new MerchantApiClient();
        
        // Track stats
        $stats = [
            'total' => 0,
            'approved' => 0,
            'disapproved' => 0,
            'pending' => 0,
            'warning' => 0,
            'errors' => [],
        ];

        try {
            $pageToken = null;
            
            do {
                $response = $client->getProductStatuses(100, $pageToken);
                
                if (!$response['success']) {
                    $stats['errors'][] = $response['error'] ?? 'Unknown error';
                    break;
                }

                // Handle Merchant API v1beta response format
                $products = $response['data']['products'] ?? [];
                
                foreach ($products as $product) {
                    $stats['total']++;
                    
                    // Parse product ID from Merchant API v1beta format
                    // offerId may be in format "online:en:US:gla_42212" or just "gla_42212"
                    $rawOfferId = $product['offerId'] ?? '';
                    if (empty($rawOfferId) && !empty($product['name'])) {
                        // Name format: accounts/{account}/products/{channel}~{language}~{feedLabel}~{offerId}
                        $parts = explode('~', $product['name']);
                        $rawOfferId = end($parts) ?: '';
                    }
                    
                    // Strip the channel:language:feedLabel prefix if present
                    // "online:en:US:gla_42212" -> "gla_42212"
                    $glaId = self::extractCoreOfferId($rawOfferId);
                    
                    // Parse productStatus from Merchant API v1beta
                    $productStatus = $product['productStatus'] ?? [];
                    $itemLevelIssues = $productStatus['itemLevelIssues'] ?? [];
                    $destinationStatuses = $productStatus['destinationStatuses'] ?? [];
                    
                    // Derive status from itemLevelIssues severity
                    $status = self::deriveStatusFromIssues($itemLevelIssues, $destinationStatuses);
                    
                    // Format issues for storage
                    $issues = array_map(function($issue) {
                        return [
                            'severity' => $issue['severity'] ?? 'unknown',
                            'description' => $issue['description'] ?? '',
                            'resolution' => $issue['resolution'] ?? '',
                        ];
                    }, $itemLevelIssues);
                    
                    // Format destinations for storage
                    $destinations = array_map(function($dest) {
                        return [
                            'context' => $dest['reportingContext'] ?? '',
                            'approved_countries' => $dest['approvedCountries'] ?? [],
                        ];
                    }, $destinationStatuses);
                    
                    // Skip if glaId is empty
                    if (empty($glaId)) {
                        continue;
                    }

                    // Try to find the WooCommerce product ID
                    $productId = self::findWooCommerceProductId($glaId);

                    // Update stats
                    if (isset($stats[$status])) {
                        $stats[$status]++;
                    }

                    // Upsert into cache table
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table WHERE gla_id = %s",
                        $glaId
                    ));

                    $data = [
                        'product_id' => $productId,
                        'gla_id' => $glaId,
                        'status' => $status,
                        'issues' => wp_json_encode($issues),
                        'destinations' => wp_json_encode($destinations),
                        'last_updated' => current_time('mysql'),
                    ];

                    if ($existing) {
                        $wpdb->update($table, $data, ['id' => $existing]);
                    } else {
                        $wpdb->insert($table, $data);
                    }
                }

                $pageToken = $response['data']['nextPageToken'] ?? null;
                
            } while ($pageToken);

            // Update last sync time
            update_option('hp_gmc_last_sync', current_time('mysql'));

        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Extract core offer ID from potentially prefixed offerId.
     * "online:en:US:gla_42212" -> "gla_42212"
     * "gla_42212" -> "gla_42212"
     * "online:en:US:DH026" -> "DH026"
     */
    private static function extractCoreOfferId(string $offerId): string
    {
        // If offerId starts with "online:en:" pattern, strip the prefix
        if (preg_match('/^online:[a-z]{2}:[A-Z]{2}:(.+)$/', $offerId, $matches)) {
            return $matches[1];
        }
        return $offerId;
    }

    /**
     * Derive product status from Merchant API v1beta itemLevelIssues and destinationStatuses.
     * 
     * Severity values: DISAPPROVED, DEMOTED, NOT_IMPACTED, PENDING
     * - If any issue has DISAPPROVED severity → disapproved
     * - If any issue has DEMOTED severity → warning
     * - If no destination has approved countries → pending
     * - Otherwise → approved
     */
    private static function deriveStatusFromIssues(array $itemLevelIssues, array $destinationStatuses): string
    {
        // Check for disapproved issues
        foreach ($itemLevelIssues as $issue) {
            $severity = strtoupper($issue['severity'] ?? '');
            if ($severity === 'DISAPPROVED' || $severity === 'ERROR') {
                return 'disapproved';
            }
        }
        
        // Check for warning-level issues (demoted)
        foreach ($itemLevelIssues as $issue) {
            $severity = strtoupper($issue['severity'] ?? '');
            if ($severity === 'DEMOTED' || $severity === 'WARNING') {
                return 'warning';
            }
        }
        
        // Check destination statuses for approval
        $hasApprovedCountries = false;
        foreach ($destinationStatuses as $dest) {
            $approvedCountries = $dest['approvedCountries'] ?? [];
            if (!empty($approvedCountries)) {
                $hasApprovedCountries = true;
                break;
            }
        }
        
        // If we have approved countries and no issues, it's approved
        if ($hasApprovedCountries) {
            return 'approved';
        }
        
        // If no destinations yet, it's pending
        if (empty($destinationStatuses)) {
            return 'pending';
        }
        
        // Default to pending if no approved countries
        return 'pending';
    }

    /**
     * Normalize status string to our standard values (legacy, kept for compatibility).
     */
    private static function normalizeStatus(string $status): string
    {
        $status = strtolower($status);
        
        $map = [
            'approved' => 'approved',
            'disapproved' => 'disapproved',
            'pending' => 'pending',
            'warning' => 'warning',
            'expiring' => 'warning',
            'eligible_limited' => 'warning',
        ];

        return $map[$status] ?? 'pending';
    }

    /**
     * Find WooCommerce product ID from GLA ID.
     * Handles multiple ID formats:
     * - gla_XXXXX: WooCommerce product ID format (from GLA plugin)
     * - SKU format: Legacy sync or manual entries (e.g., DH026, ME-Boron-8)
     */
    private static function findWooCommerceProductId(string $glaId): int
    {
        global $wpdb;

        // 1. GLA IDs in format "gla_12345" where 12345 is the WC product ID
        if (preg_match('/^gla_(\d+)$/', $glaId, $matches)) {
            $potentialId = (int) $matches[1];
            
            // Verify the product exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'product'",
                $potentialId
            ));

            if ($exists) {
                return $potentialId;
            }
        }

        // 2. Try to find by _wc_gla_mc_offer_id meta (legacy GLA mapping)
        $productId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wc_gla_mc_offer_id' AND meta_value = %s",
            $glaId
        ));
        if ($productId) {
            return (int) $productId;
        }

        // 3. Try to find by _wc_gla_google_ids meta (current GLA mapping)
        // Format: {"US":"online:en:US:gla_XXXXX"} - search for gla_id within JSON
        $searchPattern = '%' . $wpdb->esc_like($glaId) . '%';
        $productId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wc_gla_google_ids' AND meta_value LIKE %s LIMIT 1",
            $searchPattern
        ));
        if ($productId) {
            return (int) $productId;
        }

        // 4. If glaId looks like a SKU (not gla_ format), try SKU lookup
        if (!str_starts_with($glaId, 'gla_')) {
            $productId = wc_get_product_id_by_sku($glaId);
            if ($productId) {
                return (int) $productId;
            }
        }

        return 0;
    }

    /**
     * Re-sync product linkage for all cached GMC products.
     * Fixes broken WC↔GMC links in the tracking table.
     * 
     * @return array Stats about the re-sync operation
     */
    public static function resyncProductLinkage(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        $stats = [
            'total' => 0,
            'fixed' => 0,
            'still_orphaned' => 0,
            'already_linked' => 0,
        ];

        // Get all products with product_id = 0 (broken linkage)
        $orphaned = $wpdb->get_results(
            "SELECT id, gla_id FROM $table WHERE product_id = 0 OR product_id IS NULL",
            ARRAY_A
        );

        $stats['total'] = count($orphaned);

        foreach ($orphaned as $row) {
            $glaId = $row['gla_id'];
            $productId = self::findWooCommerceProductId($glaId);

            if ($productId > 0) {
                $wpdb->update(
                    $table,
                    ['product_id' => $productId],
                    ['id' => $row['id']]
                );
                $stats['fixed']++;
            } else {
                $stats['still_orphaned']++;
            }
        }

        // Also count already linked products
        $stats['already_linked'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE product_id > 0"
        );

        return $stats;
    }

    /**
     * Get list of truly orphaned GMC products (no WC match after resync).
     * These can be safely deleted from GMC.
     * 
     * @param int $limit Max number to return
     * @return array List of orphaned GMC IDs with metadata
     */
    public static function getOrphanedProducts(int $limit = 100): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        // First resync to ensure linkage is up to date
        self::resyncProductLinkage();

        // Get products still orphaned after resync
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT gla_id, status, issues, last_updated 
             FROM $table 
             WHERE product_id = 0 OR product_id IS NULL 
             ORDER BY last_updated DESC 
             LIMIT %d",
            $limit
        ), ARRAY_A);

        $orphaned = [
            'sku_format' => [],
            'gla_format' => [],
        ];

        foreach ($results as $row) {
            $glaId = $row['gla_id'];
            $entry = [
                'gmc_offer_id' => 'online:en:US:' . $glaId,
                'gla_id' => $glaId,
                'status' => $row['status'],
                'issues' => json_decode($row['issues'], true) ?: [],
                'last_updated' => $row['last_updated'],
            ];

            // Categorize by ID format
            if (str_starts_with($glaId, 'gla_')) {
                $orphaned['gla_format'][] = $entry;
            } else {
                $orphaned['sku_format'][] = $entry;
            }
        }

        $orphaned['summary'] = [
            'total' => count($results),
            'sku_format_count' => count($orphaned['sku_format']),
            'gla_format_count' => count($orphaned['gla_format']),
        ];

        return $orphaned;
    }

    /**
     * Get cached status for a product.
     */
    public static function getProductStatus(string $sku): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        // Find product by SKU
        $productId = wc_get_product_id_by_sku($sku);
        if (!$productId) {
            return null;
        }

        // Get the GLA ID for this product
        $glaId = get_post_meta($productId, '_wc_gla_mc_offer_id', true);
        if (empty($glaId)) {
            $glaId = 'gla_' . $productId;
        }

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE gla_id = %s OR product_id = %d",
            $glaId,
            $productId
        ), ARRAY_A);

        if ($result) {
            $result['issues'] = json_decode($result['issues'], true) ?: [];
            $result['destinations'] = json_decode($result['destinations'], true) ?: [];
        }

        return $result;
    }

    /**
     * Get summary statistics.
     */
    public static function getSummary(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'approved'");
        $disapproved = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'disapproved'");
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        $warning = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'warning'");
        $lastSync = get_option('hp_gmc_last_sync', null);

        return [
            'total' => $total,
            'approved' => $approved,
            'disapproved' => $disapproved,
            'pending' => $pending,
            'warning' => $warning,
            'last_sync' => $lastSync,
            'approval_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get products with issues.
     */
    public static function getIssues(?string $issueType = null, int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        $where = "WHERE status IN ('disapproved', 'warning')";
        if ($issueType === 'disapproved') {
            $where = "WHERE status = 'disapproved'";
        } elseif ($issueType === 'warning') {
            $where = "WHERE status = 'warning'";
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table $where ORDER BY status DESC, last_updated DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        foreach ($results as &$row) {
            $row['issues'] = json_decode($row['issues'], true) ?: [];
            $row['destinations'] = json_decode($row['destinations'], true) ?: [];
            
            // Add product name if available
            $product = wc_get_product($row['product_id']);
            $row['product_name'] = $product ? $product->get_name() : 'Unknown Product';
            $row['sku'] = $product ? $product->get_sku() : '';
        }

        return $results;
    }
}
