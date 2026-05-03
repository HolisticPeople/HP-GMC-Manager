<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service for syncing WooCommerce products to GMC format.
 * Works alongside Google Listings & Ads plugin in complement mode.
 */
class ProductSync
{
    private MerchantApiClient $client;

    public function __construct()
    {
        $this->client = new MerchantApiClient();
    }

    /**
     * Get GLA ID for a WooCommerce product.
     */
    public function getGlaId(int $productId): string
    {
        // Check if GLA has assigned an ID
        $glaId = get_post_meta($productId, '_wc_gla_mc_offer_id', true);
        
        if (!empty($glaId)) {
            return $glaId;
        }

        // Default format
        return 'gla_' . $productId;
    }

    /**
     * Check if a product is synced to GMC via GLA.
     */
    public function isSyncedViaGla(int $productId): bool
    {
        $status = get_post_meta($productId, '_wc_gla_sync_status', true);
        return !empty($status) && $status !== 'not-synced';
    }

    /**
     * Get sync status from GLA meta.
     */
    public function getGlaSyncStatus(int $productId): array
    {
        return [
            'status' => get_post_meta($productId, '_wc_gla_sync_status', true) ?: 'unknown',
            'offer_id' => get_post_meta($productId, '_wc_gla_mc_offer_id', true) ?: null,
            'visibility' => get_post_meta($productId, '_wc_gla_visibility', true) ?: 'sync-and-show',
            'errors' => get_post_meta($productId, '_wc_gla_errors', true) ?: [],
        ];
    }

    /**
     * Map a WooCommerce product to GMC format.
     * Used for direct API pushes (standalone mode).
     */
    public function mapProductToGmc(\WC_Product $product): array
    {
        $data = [
            'offerId' => $this->getGlaId($product->get_id()),
            'title' => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_description()),
            'link' => $product->get_permalink(),
            'imageLink' => wp_get_attachment_url($product->get_image_id()),
            'availability' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
            'price' => [
                'value' => $product->get_price(),
                'currency' => get_woocommerce_currency(),
            ],
            'brand' => $this->getBrand($product),
            'gtin' => $this->getGtin($product),
            'mpn' => $product->get_sku(),
            'condition' => 'new',
            'channel' => 'online',
            'contentLanguage' => substr(get_locale(), 0, 2),
            'targetCountry' => wc_get_base_location()['country'] ?? 'US',
        ];

        // Add categories
        $categories = $this->getGoogleProductCategory($product);
        if ($categories) {
            $data['googleProductCategory'] = $categories;
        }

        // Add shipping weight if available
        if ($product->get_weight()) {
            $data['shippingWeight'] = [
                'value' => $product->get_weight(),
                'unit' => get_option('woocommerce_weight_unit', 'kg'),
            ];
        }

        // Add dimensions if available
        if ($product->get_length() && $product->get_width() && $product->get_height()) {
            $unit = get_option('woocommerce_dimension_unit', 'cm');
            $data['shippingLength'] = ['value' => $product->get_length(), 'unit' => $unit];
            $data['shippingWidth'] = ['value' => $product->get_width(), 'unit' => $unit];
            $data['shippingHeight'] = ['value' => $product->get_height(), 'unit' => $unit];
        }

        return $data;
    }

    /**
     * Get brand for a product.
     */
    private function getBrand(\WC_Product $product): string
    {
        // Check ACF manufacturer fields first.
        if (function_exists('get_field')) {
            $brand = get_field('manufacturer', $product->get_id());
            if ($brand) {
                return is_array($brand) ? ($brand['name'] ?? '') : $brand;
            }

            $brand = get_field('manufacturer_acf', $product->get_id());
            if ($brand) {
                return is_array($brand) ? ($brand['name'] ?? '') : $brand;
            }
        }

        // Check native WooCommerce Brands taxonomy.
        $terms = wp_get_post_terms($product->get_id(), 'product_brand', ['fields' => 'names']);
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0];
        }

        // Temporary fallback for the staging/production migration window.
        if (taxonomy_exists('yith_product_brand')) {
            $terms = get_the_terms($product->get_id(), 'yith_product_brand');
            if ($terms && !is_wp_error($terms)) {
                return $terms[0]->name;
            }
        }

        // Fallback to site name
        return get_bloginfo('name');
    }

    /**
     * Get GTIN for a product.
     */
    private function getGtin(\WC_Product $product): ?string
    {
        // Check common GTIN meta keys
        $gtin_keys = ['_gtin', '_ean', '_upc', 'gtin', 'ean', 'upc', '_wpm_gtin_code'];
        
        foreach ($gtin_keys as $key) {
            $gtin = get_post_meta($product->get_id(), $key, true);
            if (!empty($gtin)) {
                return $gtin;
            }
        }

        return null;
    }

    /**
     * Get Google Product Category.
     */
    private function getGoogleProductCategory(\WC_Product $product): ?string
    {
        // Check for GLA category mapping
        $category = get_post_meta($product->get_id(), '_wc_gla_google_product_category', true);
        if (!empty($category)) {
            return $category;
        }

        return null;
    }

    /**
     * Push a single product to GMC.
     * Only used in standalone mode or for urgent updates.
     */
    public function pushProduct(int $productId): array
    {
        $product = wc_get_product($productId);
        
        if (!$product) {
            return [
                'success' => false,
                'error' => 'Product not found',
            ];
        }

        $data = $this->mapProductToGmc($product);
        $merchantId = get_option('hp_gmc_merchant_id', '');

        $result = $this->client->call(
            'POST',
            "accounts/{$merchantId}/products",
            $data
        );

        $result['product_id'] = $productId;
        $result['gla_id'] = $data['offerId'];

        return $result;
    }

    /**
     * Get products that need attention (not synced or with errors).
     */
    public function getProductsNeedingAttention(int $limit = 50): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title,
                   pm_status.meta_value as sync_status,
                   pm_errors.meta_value as sync_errors
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_wc_gla_sync_status'
            LEFT JOIN {$wpdb->postmeta} pm_errors ON p.ID = pm_errors.post_id AND pm_errors.meta_key = '_wc_gla_errors'
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND (pm_status.meta_value IS NULL 
                   OR pm_status.meta_value = 'not-synced'
                   OR pm_errors.meta_value IS NOT NULL)
            ORDER BY p.post_modified DESC
            LIMIT %d
        ", $limit), ARRAY_A);

        foreach ($results as &$row) {
            $row['errors'] = maybe_unserialize($row['sync_errors']) ?: [];
            unset($row['sync_errors']);
        }

        return $results;
    }

    /**
     * Count products by sync status.
     */
    public function countByStatus(): array
    {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT pm.meta_value as status, COUNT(*) as count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wc_gla_sync_status'
            WHERE p.post_type = 'product' AND p.post_status = 'publish'
            GROUP BY pm.meta_value
        ", ARRAY_A);

        $counts = [
            'synced' => 0,
            'not-synced' => 0,
            'pending' => 0,
            'has-errors' => 0,
            'unknown' => 0,
        ];

        foreach ($results as $row) {
            $status = $row['status'] ?: 'unknown';
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['count'];
            } else {
                $counts['unknown'] += (int) $row['count'];
            }
        }

        return $counts;
    }
}
