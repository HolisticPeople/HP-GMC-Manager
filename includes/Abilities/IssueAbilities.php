<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\IssueClassifier;
use HP_GMC\Services\IssueMonitor;
use HP_GMC\Services\MerchantApiClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP abilities for issue classification and text fix suggestions.
 */
class IssueAbilities
{
    /**
     * Get products grouped by issue tier.
     */
    public static function classifyIssues(array $params): array
    {
        $tier = $params['tier'] ?? null;
        $limit = min((int) ($params['limit'] ?? 50), 200);

        $products = IssueClassifier::getProductsByTier($tier, $limit);

        // If specific tier requested, return just that tier
        if ($tier) {
            return [
                'success' => true,
                'tier' => $tier,
                'count' => count($products),
                'products' => array_map(function($p) {
                    return [
                        'product_id' => $p['product_id'],
                        'product_name' => $p['product_name'],
                        'sku' => $p['sku'],
                        'status' => $p['status'],
                        'primary_tier' => $p['classification']['primary_tier'] ?? $tier,
                        'issues' => array_column($p['classification']['classifications'] ?? [], 'issue'),
                    ];
                }, $products),
            ];
        }

        // Return summary of all tiers
        $summary = [];
        foreach ($products as $tierName => $tierProducts) {
            $summary[$tierName] = [
                'count' => count($tierProducts),
                'sample' => array_slice(array_map(function($p) {
                    return [
                        'product_id' => $p['product_id'],
                        'product_name' => $p['product_name'],
                        'sku' => $p['sku'],
                    ];
                }, $tierProducts), 0, 5),
            ];
        }

        return [
            'success' => true,
            'by_tier' => $summary,
            'tier_descriptions' => [
                'fixable' => 'Can be fixed by adding attributes or editing content',
                'misclassified' => 'Likely false positives - need text corrections, DO NOT EXCLUDE',
                'restriction' => 'True policy restrictions - require exclusion feeds',
            ],
        ];
    }

    /**
     * Get summary of all issues grouped by tier.
     */
    public static function getIssueSummary(array $params): array
    {
        $summary = IssueClassifier::getIssueSummary();
        $byTier = [
            'fixable' => [
                'count' => $summary['by_tier'][IssueClassifier::TIER_FIXABLE]['count'],
                'description' => 'Products with missing attributes or content issues that can be fixed',
                'top_issues' => array_slice($summary['by_tier'][IssueClassifier::TIER_FIXABLE]['issues'], 0, 5, true),
            ],
            'misclassified' => [
                'count' => $summary['by_tier'][IssueClassifier::TIER_MISCLASSIFIED]['count'],
                'description' => 'Likely false positives (prescription drugs, tobacco) - need text corrections, DO NOT EXCLUDE',
                'top_issues' => array_slice($summary['by_tier'][IssueClassifier::TIER_MISCLASSIFIED]['issues'], 0, 5, true),
            ],
            'restriction' => [
                'count' => $summary['by_tier'][IssueClassifier::TIER_RESTRICTION]['count'],
                'description' => 'True policy restrictions requiring exclusion feeds',
                'top_issues' => array_slice($summary['by_tier'][IssueClassifier::TIER_RESTRICTION]['issues'], 0, 5, true),
            ],
        ];

        return [
            'success' => true,
            'total_products_with_issues' => $summary['total_products'],
            'by_tier' => $byTier,
            'by_destination' => $summary['by_destination'] ?? [],
            'recommended_actions' => [
                'fixable' => 'Use gmc-batch-fix-attributes or gmc-suggest-text-fix',
                'misclassified' => 'Review trigger keywords with gmc-suggest-text-fix and apply fixes',
                'restriction' => 'Use gmc-auto-populate-feed or gmc-feed-add-products',
            ],
        ];
    }

    /**
     * Batch fix fixable attributes for a list of products.
     */
    public static function batchFixAttributes(array $params): array
    {
        $productIds = $params['product_ids'] ?? [];
        if (empty($productIds)) {
            // If no IDs provided, find all products in fixable tier
            $fixable = IssueClassifier::getProductsByTier(IssueClassifier::TIER_FIXABLE, 100);
            $productIds = array_column($fixable, 'product_id');
        }

        if (empty($productIds)) {
            return ['success' => true, 'message' => 'No fixable products found', 'fixed' => 0];
        }

        $results = IssueClassifier::batchFixFixableAttributes($productIds);
        return array_merge(['success' => true], $results);
    }

    /**
     * Suggest text fix for a misclassified product.
     */
    public static function suggestTextFix(array $params): array
    {
        $productId = (int) ($params['product_id'] ?? 0);
        $issue = $params['issue'] ?? '';

        if (!$productId) {
            return ['success' => false, 'error' => 'product_id is required'];
        }

        if (empty($issue)) {
            return ['success' => false, 'error' => 'issue description is required'];
        }

        return IssueClassifier::suggestTextFix($productId, $issue);
    }

    /**
     * Apply a text fix to a product.
     */
    public static function applyTextFix(array $params): array
    {
        $productId = (int) ($params['product_id'] ?? 0);
        $field = $params['field'] ?? '';
        $oldText = $params['old_text'] ?? '';
        $newText = $params['new_text'] ?? '';

        if (!$productId) {
            return ['success' => false, 'error' => 'product_id is required'];
        }

        if (empty($field) || empty($oldText)) {
            return ['success' => false, 'error' => 'field and old_text are required'];
        }

        return IssueClassifier::applyTextFix($productId, $field, $oldText, $newText);
    }

    /**
     * Analyze product fields for high-risk policy language.
     */
    public static function analyzePolicyLanguage(array $params): array
    {
        $productId = (int) ($params['product_id'] ?? 0);
        if (!$productId) {
            return ['success' => false, 'error' => 'product_id is required'];
        }

        $analysis = IssueClassifier::detectHighRiskWords($productId);
        if (!$analysis['success']) {
            return $analysis;
        }

        $suggestions = [];
        if (!empty($analysis['found'])) {
            foreach ($analysis['found'] as $word => $fields) {
                $suggestions[] = [
                    'word' => $word,
                    'fields' => $fields,
                    'clean_version' => self::getCleanAlternative($word),
                ];
            }
        }

        return [
            'success' => true,
            'product_id' => $productId,
            'product_name' => $analysis['product_name'],
            'risk_level' => $analysis['risk_level'],
            'problematic_text' => $analysis['found'],
            'suggestions' => $suggestions,
            'instructions' => 'Use gmc-apply-text-fix to replace these words in GMC supplemental feeds (or directly on site if preferred).'
        ];
    }

    /**
     * Get a clean alternative for a high-risk word.
     */
    private static function getCleanAlternative(string $word): string
    {
        $alternatives = [
            'cure' => 'support',
            'tachyon' => 'wellness',
            'shielding' => 'protection',
            'guaranteed' => 'high-quality',
            'treat' => 'manage',
            'prevent' => 'help reduce',
            'shield' => 'guard',
            '5g' => 'environmental',
            'protection' => 'support',
            'healing' => 'restorative',
            'narcotic' => 'supplement',
            'controlled' => 'quality-tested',
        ];

        return $alternatives[strtolower($word)] ?? 'wellness-focused';
    }

    /**
     * Re-sync WC↔GMC product linkage in tracking table.
     * Fixes broken links where product_id is 0.
     */
    public static function resyncLinkage(array $params): array
    {
        $stats = IssueMonitor::resyncProductLinkage();

        return [
            'success' => true,
            'message' => sprintf(
                'Fixed %d of %d orphaned products. %d still orphaned (no WC match). %d already linked.',
                $stats['fixed'],
                $stats['total'],
                $stats['still_orphaned'],
                $stats['already_linked']
            ),
            'stats' => $stats,
        ];
    }

    /**
     * Get list of truly orphaned GMC products (no WC match).
     * These can be safely deleted from GMC.
     */
    public static function getOrphanedProducts(array $params): array
    {
        $limit = min((int) ($params['limit'] ?? 100), 500);
        $orphaned = IssueMonitor::getOrphanedProducts($limit);

        return [
            'success' => true,
            'summary' => $orphaned['summary'],
            'sku_format' => $orphaned['sku_format'],
            'gla_format' => $orphaned['gla_format'],
            'instructions' => 'SKU-format orphans are likely duplicates from old sync methods - safe to delete. GLA-format orphans may be deleted WC products - verify before deletion.',
        ];
    }

    /**
     * Delete orphaned products from GMC.
     * 
     * @param array $params Contains:
     *   - offer_ids: Array of GMC offer IDs to delete (format: online:en:US:XXXXX)
     *   - type: 'sku_format' | 'gla_format' | 'all' - which orphans to delete
     *   - dry_run: If true, only simulate deletion
     */
    public static function deleteOrphanedProducts(array $params): array
    {
        $offerIds = $params['offer_ids'] ?? [];
        $type = $params['type'] ?? null;
        $dryRun = (bool) ($params['dry_run'] ?? true);

        // If no specific IDs provided, get from type
        if (empty($offerIds) && $type) {
            $orphaned = IssueMonitor::getOrphanedProducts(500);
            
            if ($type === 'sku_format') {
                $offerIds = array_column($orphaned['sku_format'], 'gmc_offer_id');
            } elseif ($type === 'gla_format') {
                $offerIds = array_column($orphaned['gla_format'], 'gmc_offer_id');
            } elseif ($type === 'all') {
                $offerIds = array_merge(
                    array_column($orphaned['sku_format'], 'gmc_offer_id'),
                    array_column($orphaned['gla_format'], 'gmc_offer_id')
                );
            }
        }

        if (empty($offerIds)) {
            return [
                'success' => false,
                'error' => 'No offer_ids provided and no orphans found for type: ' . ($type ?? 'none'),
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'would_delete' => count($offerIds),
                'offer_ids' => array_slice($offerIds, 0, 20),
                'message' => sprintf('Would delete %d products from GMC. Set dry_run=false to execute.', count($offerIds)),
            ];
        }

        // Execute deletion
        $client = new MerchantApiClient();
        $results = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($offerIds as $offerId) {
            $result = $client->deleteProduct($offerId);
            $error = $result['error'] ?? '';
            
            if ($result['success']) {
                $results['deleted']++;
                // Remove from local cache
                self::removeFromLocalCache($offerId);
            } elseif (stripos($error, 'not found') !== false) {
                // "Not found" means it doesn't exist in GMC - still clean from local cache
                $results['deleted']++;
                $results['not_in_gmc'] = ($results['not_in_gmc'] ?? 0) + 1;
                self::removeFromLocalCache($offerId);
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'offer_id' => $offerId,
                    'error' => $error ?: 'Unknown error',
                ];
            }
        }

        $results['message'] = sprintf(
            'Deleted %d products from GMC. %d failed.',
            $results['deleted'],
            $results['failed']
        );

        return $results;
    }

    /**
     * Remove a product from local cache table.
     * Handles both old format (gla_id with prefix) and new format (core ID only).
     */
    private static function removeFromLocalCache(string $offerId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        // Try multiple formats to ensure we catch old and new entries
        // offerId might be: "online:en:US:online:en:US:DH142" or "online:en:US:DH142" or "online:en:US:gla_42212"
        
        // 1. Extract core ID (last segment after all colons)
        $parts = explode(':', $offerId);
        $coreId = end($parts);
        
        // 2. Also try stripping one level of prefix (for double-prefixed IDs)
        // "online:en:US:online:en:US:DH142" -> "online:en:US:DH142"
        $singlePrefixId = preg_replace('/^online:[a-z]{2}:[A-Z]{2}:/', '', $offerId);
        
        // Delete by all possible gla_id formats
        $wpdb->delete($table, ['gla_id' => $coreId]);
        $wpdb->delete($table, ['gla_id' => $singlePrefixId]);
        $wpdb->delete($table, ['gla_id' => $offerId]);
    }

    /**
     * Clean up stale entries from local tracking table.
     * 
     * Removes duplicate entries where same product_id has multiple gla_ids
     * (keeps gla_XXXXX format, deletes old SKU-based format).
     */
    public static function cleanupStaleEntries(array $params): array
    {
        $dryRun = (bool) ($params['dry_run'] ?? true);
        
        $results = IssueMonitor::cleanup_stale_entries($dryRun);
        
        $summary = [
            'success' => true,
            'dry_run' => $dryRun,
            'old_format_deleted' => $results['old_format_deleted'],
            'duplicates_deleted' => $results['duplicates_deleted'],
            'total_cleaned' => $results['old_format_deleted'] + $results['duplicates_deleted'],
        ];
        
        if ($dryRun) {
            $summary['would_delete'] = count($results['old_format_entries']) + count($results['duplicate_entries']);
            $summary['sample_entries'] = array_slice(
                array_merge($results['old_format_entries'], $results['duplicate_entries']),
                0,
                10
            );
            $summary['message'] = sprintf(
                'Would delete %d stale entries. Set dry_run=false to execute.',
                $summary['would_delete']
            );
        } else {
            $summary['message'] = sprintf(
                'Deleted %d stale entries (%d old format, %d duplicates).',
                $summary['total_cleaned'],
                $results['old_format_deleted'],
                $results['duplicates_deleted']
            );
        }
        
        return $summary;
    }
}
