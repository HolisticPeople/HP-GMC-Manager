<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service for generating a primary product data feed.
 * 
 * This feed provides complete product data (title, price, image, etc.) to GMC,
 * replacing the broken GLA OAuth sync. Uses existing GLA product IDs for compatibility.
 */
class ProductDataFeed
{
    /** @var string Cache key for feed content */
    private const CACHE_KEY = 'hp_gmc_primary_feed_content';

    /** @var int Cache duration in seconds (1 hour) */
    private const CACHE_DURATION = 3600;

    /** @var string Option key for last generated timestamp */
    private const LAST_GENERATED_KEY = 'hp_gmc_primary_feed_last_generated';

    /** @var string Option key for product count */
    private const PRODUCT_COUNT_KEY = 'hp_gmc_primary_feed_product_count';

    /**
     * Generate the product data feed content.
     *
     * @param string $format Output format: 'tsv' or 'csv'
     * @param bool $forceRegenerate Skip cache and regenerate
     * @return string Feed content
     */
    public static function generateFeed(string $format = 'tsv', bool $forceRegenerate = false): string
    {
        // Check cache first (unless force regenerate)
        if (!$forceRegenerate) {
            $cached = get_transient(self::CACHE_KEY . '_' . $format);
            if ($cached !== false) {
                return $cached;
            }
        }

        $delimiter = $format === 'csv' ? ',' : "\t";
        $products = self::getPublishedProducts();
        $productSync = new ProductSync();

        // Build header row
        $headers = [
            'id',
            'title',
            'description',
            'link',
            'image_link',
            'price',
            'availability',
            'brand',
            'condition',
            'mpn',
            'gtin',
        ];

        $lines = [];
        $lines[] = implode($delimiter, $headers);

        $currency = get_woocommerce_currency();
        $count = 0;

        foreach ($products as $productId) {
            $product = wc_get_product($productId);
            if (!$product) {
                continue;
            }

            // Skip products that shouldn't be in feed
            if ($product->get_catalog_visibility() === 'hidden') {
                continue;
            }

            // Get GMC-formatted ID (uses GLA ID if available)
            $gmcId = self::getGmcOfferId($productId);

            // Build row data
            $row = [
                self::escapeField($gmcId, $format),
                self::escapeField($product->get_name(), $format),
                self::escapeField(self::getDescription($product), $format),
                self::escapeField($product->get_permalink(), $format),
                self::escapeField(self::getImageLink($product), $format),
                self::escapeField(self::formatPrice($product->get_price(), $currency), $format),
                self::escapeField($product->is_in_stock() ? 'in_stock' : 'out_of_stock', $format),
                self::escapeField(self::getBrand($product), $format),
                self::escapeField('new', $format), // Condition is always new for this store
                self::escapeField($product->get_sku(), $format),
                self::escapeField(self::getGtin($product), $format),
            ];

            $lines[] = implode($delimiter, $row);
            $count++;
        }

        $content = implode("\n", $lines);

        // Cache the result
        set_transient(self::CACHE_KEY . '_' . $format, $content, self::CACHE_DURATION);

        // Update metadata
        update_option(self::LAST_GENERATED_KEY, current_time('mysql'));
        update_option(self::PRODUCT_COUNT_KEY, $count);

        // Log generation event
        error_log(json_encode([
            'event' => 'primary_feed.generated',
            'product_count' => $count,
            'format' => $format,
            'timestamp' => current_time('mysql'),
        ]));

        return $content;
    }

    /**
     * Get the GMC offer ID for a product.
     * Uses existing GLA ID if available for compatibility.
     *
     * @param int $productId WooCommerce product ID
     * @return string GMC offer ID in format "online:en:US:gla_XXXXX"
     */
    public static function getGmcOfferId(int $productId): string
    {
        // Check for existing GLA offer ID
        $glaOfferId = get_post_meta($productId, '_wc_gla_mc_offer_id', true);

        if (!empty($glaOfferId)) {
            // GLA stores just the suffix like "gla_12345" or full ID
            if (strpos($glaOfferId, 'online:') === 0) {
                return $glaOfferId;
            }
            // Prepend the full format
            return 'online:en:US:' . $glaOfferId;
        }

        // Generate new ID using GLA format for consistency
        return 'online:en:US:gla_' . $productId;
    }

    /**
     * Get all published WooCommerce product IDs.
     *
     * @return array Array of product IDs
     */
    private static function getPublishedProducts(): array
    {
        global $wpdb;

        // Get all published products (simple and parent products only, not variations)
        $results = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product'
              AND post_status = 'publish'
            ORDER BY ID ASC
        ");

        return $results ?: [];
    }

    /**
     * Get product description, cleaned for feed.
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function getDescription(\WC_Product $product): string
    {
        $description = $product->get_description();
        
        if (empty($description)) {
            $description = $product->get_short_description();
        }

        // Strip HTML and clean up whitespace
        $description = wp_strip_all_tags($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);

        // GMC has a 5000 character limit for descriptions
        if (strlen($description) > 5000) {
            $description = substr($description, 0, 4997) . '...';
        }

        return $description;
    }

    /**
     * Get product main image URL.
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function getImageLink(\WC_Product $product): string
    {
        $imageId = $product->get_image_id();
        
        if (!$imageId) {
            // Return placeholder if no image
            return wc_placeholder_img_src('full');
        }

        $imageUrl = wp_get_attachment_url($imageId);
        
        return $imageUrl ?: '';
    }

    /**
     * Format price for GMC (e.g., "29.99 USD").
     *
     * @param string|float $price
     * @param string $currency
     * @return string
     */
    private static function formatPrice($price, string $currency): string
    {
        if (empty($price) || $price <= 0) {
            return '';
        }

        return number_format((float) $price, 2, '.', '') . ' ' . $currency;
    }

    /**
     * Get brand for a product.
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function getBrand(\WC_Product $product): string
    {
        $productId = $product->get_id();

        // Check ACF manufacturer field first
        if (function_exists('get_field')) {
            $manufacturer = get_field('manufacturer', $productId);
            if ($manufacturer) {
                if (is_array($manufacturer)) {
                    return $manufacturer['name'] ?? '';
                }
                return (string) $manufacturer;
            }

            // Also check manufacturer_acf
            $manufacturerAcf = get_field('manufacturer_acf', $productId);
            if ($manufacturerAcf) {
                if (is_array($manufacturerAcf)) {
                    return $manufacturerAcf['name'] ?? '';
                }
                return (string) $manufacturerAcf;
            }
        }

        // Check yith_product_brand taxonomy
        if (taxonomy_exists('yith_product_brand')) {
            $terms = get_the_terms($productId, 'yith_product_brand');
            if ($terms && !is_wp_error($terms)) {
                return $terms[0]->name;
            }
        }

        // Check product_brand taxonomy
        $terms = wp_get_post_terms($productId, 'product_brand', ['fields' => 'names']);
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0];
        }

        // Fallback to site name
        return get_bloginfo('name');
    }

    /**
     * Get GTIN for a product.
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function getGtin(\WC_Product $product): string
    {
        $productId = $product->get_id();

        // Check common GTIN meta keys
        $gtinKeys = ['_gtin', '_ean', '_upc', 'gtin', 'ean', 'upc', '_wpm_gtin_code'];
        
        foreach ($gtinKeys as $key) {
            $gtin = get_post_meta($productId, $key, true);
            if (!empty($gtin)) {
                return (string) $gtin;
            }
        }

        return '';
    }

    /**
     * Escape a field value for TSV/CSV output.
     *
     * @param string $value
     * @param string $format 'tsv' or 'csv'
     * @return string
     */
    private static function escapeField(string $value, string $format = 'tsv'): string
    {
        // Remove newlines and tabs
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);

        if ($format === 'csv') {
            // CSV: quote fields containing comma, quote, or newline
            if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
        }

        return $value;
    }

    /**
     * Get the count of products in the feed.
     *
     * @return int
     */
    public static function getProductCount(): int
    {
        return (int) get_option(self::PRODUCT_COUNT_KEY, 0);
    }

    /**
     * Get the last generated timestamp.
     *
     * @return string|null MySQL datetime or null if never generated
     */
    public static function getLastGenerated(): ?string
    {
        $timestamp = get_option(self::LAST_GENERATED_KEY, null);
        return $timestamp ?: null;
    }

    /**
     * Get feed URL.
     *
     * @param string $format 'tsv' or 'csv'
     * @return string
     */
    public static function getFeedUrl(string $format = 'tsv'): string
    {
        return rest_url('hp-gmc/v1/product-feed') . '?format=' . $format;
    }

    /**
     * Clear the feed cache, forcing regeneration on next request.
     *
     * @return bool
     */
    public static function clearCache(): bool
    {
        delete_transient(self::CACHE_KEY . '_tsv');
        delete_transient(self::CACHE_KEY . '_csv');

        error_log(json_encode([
            'event' => 'primary_feed.cache_cleared',
            'timestamp' => current_time('mysql'),
        ]));

        return true;
    }

    /**
     * Get feed status summary for dashboard/API.
     *
     * @return array
     */
    public static function getStatus(): array
    {
        $lastGenerated = self::getLastGenerated();
        $lastGeneratedAgo = null;

        if ($lastGenerated) {
            $diff = time() - strtotime($lastGenerated);
            if ($diff < 60) {
                $lastGeneratedAgo = 'just now';
            } elseif ($diff < 3600) {
                $lastGeneratedAgo = round($diff / 60) . ' minutes ago';
            } elseif ($diff < 86400) {
                $lastGeneratedAgo = round($diff / 3600) . ' hours ago';
            } else {
                $lastGeneratedAgo = round($diff / 86400) . ' days ago';
            }
        }

        return [
            'feed_url' => self::getFeedUrl('tsv'),
            'feed_url_csv' => self::getFeedUrl('csv'),
            'product_count' => self::getProductCount(),
            'last_generated' => $lastGenerated,
            'last_generated_ago' => $lastGeneratedAgo,
            'cache_duration_minutes' => self::CACHE_DURATION / 60,
        ];
    }
}
