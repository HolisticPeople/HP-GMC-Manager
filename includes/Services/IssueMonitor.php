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

        // #region agent log - Entry point
        $logPath = 'c:\\DEV\\.cursor\\debug.log';
        file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'Entry point','data'=>['table'=>$table],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'A'])."\n", FILE_APPEND);
        // #endregion

        $client = new MerchantApiClient();
        
        // #region agent log - Check API mode
        file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'API client mode','data'=>['isDryRun'=>$client->isDryRun(),'mode'=>get_option('hp_gmc_mode','auto'),'env'=>hp_gmc_get_environment()],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'B'])."\n", FILE_APPEND);
        // #endregion
        
        // Track stats
        $stats = [
            'total' => 0,
            'approved' => 0,
            'disapproved' => 0,
            'pending' => 0,
            'warning' => 0,
            'errors' => [],
            'debug' => [], // Debug info for troubleshooting
        ];
        
        // #region agent log - Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        $stats['debug']['table_exists'] = $table_exists;
        $stats['debug']['table_name'] = $table;
        // #endregion

        try {
            $pageToken = null;
            
            do {
                $response = $client->getProductStatuses(100, $pageToken);
                
                // #region agent log - Raw API response
                file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'API response received','data'=>['success'=>$response['success']??null,'hasData'=>isset($response['data']),'dataKeys'=>isset($response['data'])?array_keys($response['data']):[],'hasProducts'=>isset($response['data']['products']),'productsCount'=>isset($response['data']['products'])?count($response['data']['products']):0,'rawResponse'=>array_slice($response,0,3)],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'C'])."\n", FILE_APPEND);
                // #endregion
                
                if (!$response['success']) {
                    // #region agent log - API failure
                    file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'API FAILED','data'=>['error'=>$response['error']??'Unknown error'],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'C'])."\n", FILE_APPEND);
                    // #endregion
                    $stats['errors'][] = $response['error'] ?? 'Unknown error';
                    break;
                }

                // Handle Merchant API v1beta response format
                $products = $response['data']['products'] ?? [];
                
                foreach ($products as $product) {
                    $stats['total']++;
                    
                    // Parse product ID from Merchant API v1beta format
                    // offerId is the product identifier, or extract from name
                    $glaId = $product['offerId'] ?? '';
                    if (empty($glaId) && !empty($product['name'])) {
                        // Name format: accounts/{account}/products/{channel}~{language}~{feedLabel}~{offerId}
                        $parts = explode('~', $product['name']);
                        $glaId = end($parts) ?: '';
                    }
                    
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

                    // #region agent log - Debug first few products
                    if ($stats['total'] <= 3) {
                        $stats['debug']['sample_products'][] = [
                            'raw_offerId' => $product['offerId'] ?? 'NOT_SET',
                            'raw_name' => $product['name'] ?? 'NOT_SET',
                            'parsed_glaId' => $glaId,
                            'status' => $status,
                            'issues_count' => count($itemLevelIssues),
                            'destinations_count' => count($destinationStatuses),
                        ];
                    }
                    // #endregion
                    
                    // Skip if glaId is empty
                    if (empty($glaId)) {
                        $stats['debug']['empty_glaId_count'] = ($stats['debug']['empty_glaId_count'] ?? 0) + 1;
                        // #region agent log - Empty glaId skip
                        if (($stats['debug']['empty_glaId_count'] ?? 0) <= 3) {
                            file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'SKIPPED: empty glaId','data'=>['productRaw'=>array_slice($product,0,5),'offerId'=>$product['offerId']??'MISSING','name'=>$product['name']??'MISSING'],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'D'])."\n", FILE_APPEND);
                        }
                        // #endregion
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
                        $result = $wpdb->update($table, $data, ['id' => $existing]);
                    } else {
                        $result = $wpdb->insert($table, $data);
                    }
                    
                    // #region agent log - Track insert/update results
                    if ($stats['total'] <= 3) {
                        $stats['debug']['db_operations'][] = [
                            'glaId' => $glaId,
                            'operation' => $existing ? 'update' : 'insert',
                            'result' => $result,
                            'last_error' => $wpdb->last_error,
                        ];
                        // Log to debug file
                        file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'DB operation','data'=>['glaId'=>$glaId,'op'=>$existing?'update':'insert','result'=>$result,'error'=>$wpdb->last_error,'tableExists'=>$table_exists],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'E'])."\n", FILE_APPEND);
                    }
                    // #endregion
                }

                $pageToken = $response['data']['nextPageToken'] ?? null;
                
            } while ($pageToken);

            // Update last sync time
            update_option('hp_gmc_last_sync', current_time('mysql'));

        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            // #region agent log - Exception
            file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'EXCEPTION','data'=>['error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'ALL'])."\n", FILE_APPEND);
            // #endregion
        }

        // #region agent log - Final stats
        file_put_contents($logPath, json_encode(['location'=>'IssueMonitor.php:sync_product_statuses','message'=>'SYNC COMPLETE','data'=>['total'=>$stats['total'],'approved'=>$stats['approved'],'disapproved'=>$stats['disapproved'],'pending'=>$stats['pending'],'errors'=>$stats['errors'],'emptyGlaIdCount'=>$stats['debug']['empty_glaId_count']??0],'timestamp'=>round(microtime(true)*1000),'hypothesisId'=>'ALL'])."\n", FILE_APPEND);
        // #endregion

        return $stats;
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
     */
    private static function findWooCommerceProductId(string $glaId): int
    {
        global $wpdb;

        // GLA IDs are typically in format "gla_12345" where 12345 is the WC product ID
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

        // Try to find by meta key (GLA stores mapping)
        $productId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wc_gla_mc_offer_id' AND meta_value = %s",
            $glaId
        ));

        return (int) $productId;
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
