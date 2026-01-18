<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\IssueMonitor;
use HP_GMC\Services\MerchantApiClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP abilities for product-level GMC operations.
 */
class ProductAbilities
{
    /**
     * Get dashboard summary statistics.
     */
    public static function dashboardSummary(array $params): array
    {
        $summary = IssueMonitor::getSummary();
        
        return [
            'success' => true,
            'data' => $summary,
            'dry_run' => hp_gmc_is_dry_run(),
        ];
    }

    /**
     * List products with issues.
     */
    public static function listIssues(array $params): array
    {
        $issueType = $params['issue_type'] ?? null;
        $limit = (int) ($params['limit'] ?? 50);

        $issues = IssueMonitor::getIssues($issueType, $limit);

        return [
            'success' => true,
            'count' => count($issues),
            'data' => $issues,
            'dry_run' => hp_gmc_is_dry_run(),
        ];
    }

    /**
     * Get status for a specific product.
     */
    public static function getProductStatus(array $params): array
    {
        $sku = $params['sku'] ?? '';

        if (empty($sku)) {
            return [
                'success' => false,
                'error' => 'SKU is required',
            ];
        }

        $status = IssueMonitor::getProductStatus($sku);

        if (!$status) {
            return [
                'success' => false,
                'error' => 'Product not found or not synced to GMC',
            ];
        }

        return [
            'success' => true,
            'data' => $status,
            'dry_run' => hp_gmc_is_dry_run(),
        ];
    }

    /**
     * Set exclusion destinations for a product.
     */
    public static function setExclusion(array $params): array
    {
        $sku = $params['sku'] ?? '';
        $destinations = $params['destinations'] ?? [];

        if (empty($sku)) {
            return [
                'success' => false,
                'error' => 'SKU is required',
            ];
        }

        if (empty($destinations)) {
            return [
                'success' => false,
                'error' => 'At least one destination is required',
            ];
        }

        // Validate destinations
        $validDestinations = [
            'Shopping_ads',
            'Display_ads',
            'Local_inventory_ads',
            'Free_listings',
            'Free_local_listings',
            'YouTube_Shopping',
        ];

        foreach ($destinations as $dest) {
            if (!in_array($dest, $validDestinations)) {
                return [
                    'success' => false,
                    'error' => "Invalid destination: $dest. Valid options: " . implode(', ', $validDestinations),
                ];
            }
        }

        $client = new MerchantApiClient();
        
        // Find product
        $productId = wc_get_product_id_by_sku($sku);
        if (!$productId) {
            return [
                'success' => false,
                'error' => 'Product not found with SKU: ' . $sku,
            ];
        }

        $glaId = get_post_meta($productId, '_wc_gla_mc_offer_id', true);
        if (empty($glaId)) {
            $glaId = 'gla_' . $productId;
        }

        // In live mode, this would update via API
        // For now, we use supplemental feed approach
        $result = $client->call('POST', "products/{$glaId}/exclusions", [
            'excluded_destination' => $destinations,
        ]);

        $result['product_id'] = $productId;
        $result['gla_id'] = $glaId;
        $result['sku'] = $sku;
        $result['destinations'] = $destinations;

        return $result;
    }

    /**
     * Simple test tool.
     */
    public static function testHello(array $params): array
    {
        $name = $params['name'] ?? 'World';

        return [
            'success' => true,
            'message' => "Hello, $name! GMC Manager is working.",
            'version' => HP_GMC_VERSION,
            'environment' => hp_gmc_get_environment(),
            'mode' => get_option('hp_gmc_mode', 'auto'),
            'dry_run' => hp_gmc_is_dry_run(),
        ];
    }
}
