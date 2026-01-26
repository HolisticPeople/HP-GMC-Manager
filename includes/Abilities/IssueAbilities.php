<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\IssueClassifier;

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
}
