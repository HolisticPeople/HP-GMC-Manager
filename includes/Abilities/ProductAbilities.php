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

    /**
     * Debug: Get raw API response for products endpoint.
     */
    public static function debugProductsApi(array $params): array
    {
        $client = new MerchantApiClient();
        $pageSize = (int) ($params['page_size'] ?? 5);
        
        // Call the products API directly
        $response = $client->getProductStatuses($pageSize);
        
        return [
            'success' => true,
            'debug' => true,
            'raw_response' => $response,
            'response_keys' => is_array($response['data'] ?? null) ? array_keys($response['data']) : 'not_array',
            'has_products_key' => isset($response['data']['products']),
            'sample_data' => isset($response['data']) ? array_slice((array)$response['data'], 0, 2, true) : null,
        ];
    }

    /**
     * Diagnose a product by comparing WooCommerce data with GMC cached status.
     * Helps identify root causes of issues.
     */
    public static function diagnoseProduct(array $params): array
    {
        $sku = $params['sku'] ?? '';

        if (empty($sku)) {
            return [
                'success' => false,
                'error' => 'SKU is required',
            ];
        }

        // Get WooCommerce product
        $productId = wc_get_product_id_by_sku($sku);
        if (!$productId) {
            return [
                'success' => false,
                'error' => 'Product not found with SKU: ' . $sku,
            ];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return [
                'success' => false,
                'error' => 'Could not load product: ' . $productId,
            ];
        }

        // Gather WooCommerce data
        $wcData = [
            'id' => $productId,
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'status' => $product->get_status(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'weight' => $product->get_weight(),
            'weight_unit' => get_option('woocommerce_weight_unit', 'kg'),
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
            'dimension_unit' => get_option('woocommerce_dimension_unit', 'cm'),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'image_id' => $product->get_image_id(),
            'has_image' => !empty($product->get_image_id()),
        ];

        // Gather GLA (Google Listings & Ads) meta data
        $glaMeta = [
            'sync_status' => get_post_meta($productId, '_wc_gla_sync_status', true) ?: 'not set',
            'mc_offer_id' => get_post_meta($productId, '_wc_gla_mc_offer_id', true) ?: 'not set',
            'visibility' => get_post_meta($productId, '_wc_gla_visibility', true) ?: 'not set',
            'errors' => get_post_meta($productId, '_wc_gla_errors', true) ?: [],
            'google_product_category' => get_post_meta($productId, '_wc_gla_google_product_category', true) ?: 'not set',
        ];

        // Get cached GMC status from our database
        $gmcStatus = IssueMonitor::getProductStatus($sku);

        // Get shipping settings for context
        $client = new MerchantApiClient();
        $shippingSettings = $client->getShippingSettings();
        $configuredCountries = [];
        if ($shippingSettings['success'] && isset($shippingSettings['data']['services'])) {
            foreach ($shippingSettings['data']['services'] as $service) {
                $configuredCountries = array_merge($configuredCountries, $service['deliveryCountries'] ?? []);
            }
            $configuredCountries = array_unique($configuredCountries);
        }

        // Analyze and identify issues
        $identifiedIssues = [];
        $recommendations = [];

        // Check weight
        if (empty($wcData['weight'])) {
            $identifiedIssues[] = 'Missing weight in WooCommerce';
            $recommendations[] = 'Set product weight in WooCommerce to fix shipping calculations';
        }

        // Check dimensions
        if (empty($wcData['length']) || empty($wcData['width']) || empty($wcData['height'])) {
            $identifiedIssues[] = 'Missing dimensions in WooCommerce';
            $recommendations[] = 'Consider setting product dimensions for accurate shipping';
        }

        // Check image
        if (!$wcData['has_image']) {
            $identifiedIssues[] = 'Missing product image';
            $recommendations[] = 'Add a product image to improve visibility';
        }

        // Check GLA sync status
        if ($glaMeta['sync_status'] === 'not set' || $glaMeta['sync_status'] === 'not-synced') {
            $identifiedIssues[] = 'Product not synced via Google Listings & Ads';
            $recommendations[] = 'Ensure product is visible and synced in GLA settings';
        }

        // Analyze GMC issues
        if ($gmcStatus && !empty($gmcStatus['issues'])) {
            $issueTypes = [];
            foreach ($gmcStatus['issues'] as $issue) {
                $desc = $issue['description'] ?? '';
                if (!in_array($desc, $issueTypes)) {
                    $issueTypes[] = $desc;
                }
            }
            
            foreach ($issueTypes as $issueType) {
                if (strpos($issueType, 'Missing shipping in some countries') !== false) {
                    $identifiedIssues[] = 'Missing shipping configuration for some target countries';
                    $recommendations[] = 'Either add shipping for more countries or exclude product from unsupported countries';
                    $recommendations[] = 'Currently configured shipping countries: ' . implode(', ', $configuredCountries);
                }
                if (strpos($issueType, 'shipping_weight') !== false || strpos($issueType, 'shipping weight') !== false) {
                    $identifiedIssues[] = 'Shipping weight issue reported by GMC';
                    $recommendations[] = 'Check weight format - GMC expects numeric value with proper unit';
                }
                if (strpos($issueType, 'health claims') !== false) {
                    $identifiedIssues[] = 'Health claims policy violation';
                    $recommendations[] = 'Review product title and description for prohibited health claims';
                }
                if (strpos($issueType, 'Prohibited pharmaceuticals') !== false) {
                    $identifiedIssues[] = 'Prohibited pharmaceuticals policy violation';
                    $recommendations[] = 'This product may need to be excluded from Shopping ads';
                }
            }
        }

        return [
            'success' => true,
            'sku' => $sku,
            'woocommerce_data' => $wcData,
            'gla_meta' => $glaMeta,
            'gmc_cached_status' => $gmcStatus,
            'shipping_countries_configured' => $configuredCountries,
            'identified_issues' => $identifiedIssues,
            'recommendations' => $recommendations,
            'dry_run' => hp_gmc_is_dry_run(),
        ];
    }
}
