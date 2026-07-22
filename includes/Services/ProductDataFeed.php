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
        // NOTE: shipping_weight uses GMC format (e.g., "0.15 lb") NOT WooCommerce "lbs"
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
            'shipping_weight',
            'google_product_category',
            'product_type',
            'color',
            // UCP / agentic-commerce enhancement columns (3.3.0). Each is emitted
            // ONLY when the product actually carries the data; the cell is left
            // blank otherwise (GMC reads a blank cell as "attribute not provided").
            'additional_image_link',
            'sale_price',
            'sale_price_effective_date',
            'shipping_length',
            'shipping_width',
            'shipping_height',
            'age_group',
            'gender',
            'identifier_exists',
            // UCP checkout-compliance columns (3.4.0). Eligibility stays inert
            // until globally enabled; notices emit only from reviewed meta.
            'native_commerce(checkout_eligibility)',
            'consumer_notice(notice_type:notice_message)',
            'merchant_item_id',
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
            if (!self::shouldIncludeInFeed($product)) {
                continue;
            }

            // Get GMC-formatted ID (uses GLA ID if available)
            $gmcId = self::getGmcOfferId($productId);
            $gtin = self::getGtin($product);

            // Build row data
            $row = [
                self::escapeField($gmcId, $format),
                self::escapeField($product->get_name(), $format),
                self::escapeField(self::getDescription($product), $format),
                self::escapeField($product->get_permalink(), $format),
                self::escapeField(self::getImageLink($product), $format),
                // price = the REGULAR (non-sale) price; when a product is on sale
                // GMC requires the sale amount in the separate sale_price column.
                self::escapeField(self::formatPrice(self::getRegularPrice($product), $currency), $format),
                self::escapeField($product->is_in_stock() ? 'in_stock' : 'out_of_stock', $format),
                self::escapeField(self::getBrand($product), $format),
                self::escapeField('new', $format), // Condition is always new for this store
                self::escapeField($product->get_sku(), $format),
                self::escapeField($gtin, $format),
                self::escapeField(self::getShippingWeight($product), $format),
                self::escapeField(self::getGoogleProductCategory($product), $format),
                self::escapeField(self::getProductType($product), $format),
                self::escapeField(self::getColor($product), $format),
                // UCP / agentic-commerce columns — each blank unless the data exists.
                self::escapeField(self::getAdditionalImageLink($product), $format),
                self::escapeField(self::getSalePrice($product, $currency), $format),
                self::escapeField(self::getSalePriceEffectiveDate($product), $format),
                self::escapeField(self::getShippingDimension($product, 'length'), $format),
                self::escapeField(self::getShippingDimension($product, 'width'), $format),
                self::escapeField(self::getShippingDimension($product, 'height'), $format),
                self::escapeField(self::getAgeGroup($product), $format),
                self::escapeField(self::getGender($product), $format),
                // Interim doctrine (2026-07-04): products without a verified GTIN
                // declare identifier_exists=no until their barcode is resolved.
                self::escapeField($gtin === '' ? 'no' : '', $format),
                // UCP checkout-compliance columns.
                self::escapeField(self::checkoutEligibility($product), $format),
                self::escapeField(self::consumerNotice($productId), $format),
                self::escapeField(self::merchantItemId($productId), $format),
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
     * IMPORTANT: GMC automatically prepends "online:en:US:" to the ID,
     * so we must send just the offer ID (e.g., "gla_42156") NOT the full format.
     *
     * @param int $productId WooCommerce product ID
     * @return string GMC offer ID in format "gla_XXXXX" (without channel/language/country prefix)
     */
    public static function getGmcOfferId(int $productId): string
    {
        // Check for existing GLA offer ID
        $glaOfferId = get_post_meta($productId, '_wc_gla_mc_offer_id', true);

        if (!empty($glaOfferId)) {
            // GLA stores just the suffix like "gla_12345" or sometimes full ID
            // Strip any "online:en:US:" prefix if present - GMC adds this automatically
            if (strpos($glaOfferId, 'online:') === 0) {
                // Remove the "online:en:US:" prefix
                $glaOfferId = preg_replace('/^online:[a-z]{2}:[A-Z]{2}:/', '', $glaOfferId);
            }
            return $glaOfferId;
        }

        // Generate new ID using GLA format for consistency
        return 'gla_' . $productId;
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
     * Check if a product should be included in the GMC feed.
     * 
     * Excludes:
     * - Hidden products (catalog_visibility = 'hidden')
     * - Products with $0 or empty price
     * - Non-purchasable products
     * - Products explicitly excluded from GMC sync
     * - Draft, private, or pending products (already filtered in query)
     *
     * @param \WC_Product $product
     * @return bool True if product should be included
     */
    private static function shouldIncludeInFeed(\WC_Product $product): bool
    {
        $productId = $product->get_id();
        $sku = $product->get_sku();

        // 1. Skip hidden products
        if ($product->get_catalog_visibility() === 'hidden') {
            return false;
        }

        // 2. Skip products with $0 or empty price
        $price = $product->get_price();
        if (empty($price) || floatval($price) <= 0) {
            return false;
        }

        // 3. Skip non-purchasable products
        if (!$product->is_purchasable()) {
            return false;
        }

        // 4. Skip products explicitly excluded from GMC sync via GLA meta
        $glaVisibility = get_post_meta($productId, '_wc_gla_visibility', true);
        if ($glaVisibility === 'dont-sync-and-show') {
            return false;
        }

        // 5. Skip products in the GMC exclusion list (our custom feed exclusions)
        $excluded = get_post_meta($productId, '_hp_gmc_excluded', true);
        if ($excluded === 'yes') {
            return false;
        }

        // 6. Skip variable products without valid variations
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();
            if (empty($variations)) {
                return false;
            }
        }

        return true;
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
     * Regular (non-sale) price for the `price` column.
     *
     * GMC requires the price attribute to hold the regular price whenever a
     * sale_price is also present. Falls back to the active price when no regular
     * price is recorded (never fabricates a value).
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function getRegularPrice(\WC_Product $product): string
    {
        $regular = $product->get_regular_price();
        if ($regular !== '' && $regular !== null && floatval($regular) > 0) {
            return (string) $regular;
        }
        return (string) $product->get_price();
    }

    /**
     * Sale price for the `sale_price` column — emitted ONLY when the product is
     * genuinely on sale (active sale price below the regular price). Returns ''
     * (blank cell) otherwise; never fabricated.
     *
     * @param \WC_Product $product
     * @param string $currency
     * @return string
     */
    private static function getSalePrice(\WC_Product $product, string $currency): string
    {
        if (!$product->is_on_sale()) {
            return '';
        }

        $sale = $product->get_sale_price();
        $regular = $product->get_regular_price();

        if ($sale === '' || $sale === null || floatval($sale) <= 0) {
            return '';
        }
        // Only trust a sale that is actually cheaper than the regular price.
        if ($regular !== '' && $regular !== null && floatval($sale) >= floatval($regular)) {
            return '';
        }

        return self::formatPrice($sale, $currency);
    }

    /**
     * Sale-price effective date range for `sale_price_effective_date` — emitted
     * ONLY when BOTH a start and end date are set on the sale. Returns '' when
     * either bound is missing (an open-ended sale needs no date range).
     *
     * Format: ISO 8601 range "START/END" (GMC spec).
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function getSalePriceEffectiveDate(\WC_Product $product): string
    {
        if (!$product->is_on_sale()) {
            return '';
        }

        $from = $product->get_date_on_sale_from();
        $to = $product->get_date_on_sale_to();

        if (!$from || !$to) {
            return '';
        }

        return $from->date('c') . '/' . $to->date('c');
    }

    /**
     * Additional product images for `additional_image_link`.
     *
     * Emits up to 10 gallery image URLs (GMC's cap), comma-separated, ONLY when
     * the product has gallery images. Returns '' when the gallery is empty.
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function getAdditionalImageLink(\WC_Product $product): string
    {
        $galleryIds = $product->get_gallery_image_ids();
        if (empty($galleryIds)) {
            return '';
        }

        $mainId = (int) $product->get_image_id();
        $urls = [];
        foreach ($galleryIds as $imageId) {
            $imageId = (int) $imageId;
            if ($imageId === $mainId) {
                continue; // Don't duplicate the primary image_link.
            }
            $url = wp_get_attachment_url($imageId);
            if ($url) {
                $urls[] = $url;
            }
            if (count($urls) >= 10) {
                break; // GMC accepts a maximum of 10 additional images.
            }
        }

        return implode(',', $urls);
    }

    /**
     * Product physical dimensions for the `shipping_length` / `shipping_width` /
     * `shipping_height` columns. Emitted ONLY when the requested WooCommerce
     * dimension is set; returns '' otherwise.
     *
     * Format: "{value} {unit}" where unit is GMC-valid (in or cm).
     *
     * @param \WC_Product $product
     * @param string $which One of 'length', 'width', 'height'
     * @return string
     */
    private static function getShippingDimension(\WC_Product $product, string $which): string
    {
        $value = match ($which) {
            'length' => $product->get_length(),
            'width'  => $product->get_width(),
            'height' => $product->get_height(),
            default  => '',
        };

        if ($value === '' || $value === null || !is_numeric($value) || floatval($value) <= 0) {
            return '';
        }

        $wcUnit = strtolower(trim((string) get_option('woocommerce_dimension_unit', 'in')));
        // GMC accepts only in or cm for shipping dimensions.
        $gmcUnit = in_array($wcUnit, ['in', 'cm'], true) ? $wcUnit : null;
        if ($gmcUnit === null) {
            // Convert the other WooCommerce units to cm so we never emit an
            // unsupported unit (m → cm, mm → cm, yd → in).
            $converted = match ($wcUnit) {
                'm'  => ['val' => floatval($value) * 100, 'unit' => 'cm'],
                'mm' => ['val' => floatval($value) / 10, 'unit' => 'cm'],
                'yd' => ['val' => floatval($value) * 36, 'unit' => 'in'],
                default => null,
            };
            if ($converted === null) {
                return '';
            }
            return rtrim(rtrim(number_format($converted['val'], 2, '.', ''), '0'), '.') . ' ' . $converted['unit'];
        }

        return rtrim(rtrim(number_format(floatval($value), 2, '.', ''), '0'), '.') . ' ' . $gmcUnit;
    }

    /**
     * Native checkout eligibility for agent surfaces.
     *
     * Inert by default; when enabled, fail closed unless the product is a
     * simple, purchasable, in-stock feed item and not explicitly ineligible.
     *
     * @param \WC_Product $product
     * @return string
     */
    private static function checkoutEligibility(\WC_Product $product): string
    {
        if (get_option('hp_gmc_ucp_checkout_eligibility', 'disabled') !== 'enabled') {
            return '';
        }

        $productId = (int) $product->get_id();
        if (get_post_meta($productId, '_hp_gmc_checkout_ineligible', true) === 'yes') {
            return self::sanitizeFeedText('FALSE');
        }

        if (!$product->is_type('simple')) {
            return self::sanitizeFeedText('FALSE');
        }

        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return self::sanitizeFeedText('FALSE');
        }

        if (get_post_meta($productId, '_wc_gla_visibility', true) === 'dont-sync-and-show') {
            return self::sanitizeFeedText('FALSE');
        }

        if (get_post_meta($productId, '_hp_gmc_excluded', true) === 'yes') {
            return self::sanitizeFeedText('FALSE');
        }

        return self::sanitizeFeedText('TRUE');
    }

    /**
     * Single consumer notice for `consumer_notice(notice_type:notice_message)`.
     *
     * @param int $productId
     * @return string
     */
    private static function consumerNotice(int $productId): string
    {
        $type = (string) get_post_meta($productId, '_hp_gmc_notice_type', true);
        $message = (string) get_post_meta($productId, '_hp_gmc_notice_message', true);
        $allowedTypes = ['legal_disclaimer', 'safety_warning', 'prop_65'];

        if (!in_array($type, $allowedTypes, true) || trim($message) === '') {
            return '';
        }

        if (function_exists('wp_kses')) {
            $message = wp_kses($message, [
                'b' => [],
                'br' => [],
                'i' => [],
                'a' => ['href' => []],
            ]);
        } else {
            $message = strip_tags($message, '<b><br><i><a>');
        }

        if (function_exists('mb_substr')) {
            $message = mb_substr($message, 0, 1000);
        } else {
            $message = substr($message, 0, 1000);
        }

        $message = trim(self::sanitizeFeedText($message));
        if ($message === '') {
            return '';
        }

        return self::sanitizeFeedText($type . ':' . $message);
    }

    /**
     * Merchant checkout item identifier.
     *
     * @param int $productId
     * @return string
     */
    private static function merchantItemId(int $productId): string
    {
        return self::sanitizeFeedText((string) max(0, $productId));
    }

    /**
     * Strip row-breaking characters before values reach the TSV/CSV escaper.
     *
     * @param string $value
     * @return string
     */
    private static function sanitizeFeedText(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
    }

    // NOTE (3.3.1): product_highlights was intentionally REMOVED. It extracted
    // <li> bullets from product descriptions straight into the feed, which
    // surfaced un-reviewed disease/claim language (e.g. "can cure…",
    // "flu virus", "infection") on products outside the claims-remediation
    // scope — a GMC healthcare-misleading-claims risk. Do NOT reintroduce a
    // free-text-from-content feed attribute without a server-side compliance
    // guard (see HP-Protocol's purpose deny-list, `purpose_withheld`) AND a
    // full-catalog claims audit of the source field.

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

        // Check native WooCommerce Brands taxonomy.
        $terms = wp_get_post_terms($productId, 'product_brand', ['fields' => 'names']);
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0];
        }

        // Temporary fallback for the staging/production migration window.
        if (taxonomy_exists('yith_product_brand')) {
            $terms = get_the_terms($productId, 'yith_product_brand');
            if ($terms && !is_wp_error($terms)) {
                return $terms[0]->name;
            }
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

        // WooCommerce native GTIN field (WC 9.2+: product edit > Inventory > GTIN/UPC/EAN/ISBN)
        if (method_exists($product, 'get_global_unique_id')) {
            $gtin = self::normalizeGtin((string) $product->get_global_unique_id());
            if ($gtin !== '') {
                return $gtin;
            }
        }

        // Check common GTIN meta keys
        $gtinKeys = ['_global_unique_id', '_gtin', '_ean', '_upc', 'gtin', 'ean', 'upc', '_wpm_gtin_code'];

        foreach ($gtinKeys as $key) {
            $gtin = self::normalizeGtin((string) get_post_meta($productId, $key, true));
            if ($gtin !== '') {
                return $gtin;
            }
        }

        return '';
    }

    /**
     * Normalize a raw GTIN value for GMC: strip separators, require a valid
     * GTIN length (8/12/13/14 digits). Invalid values return '' (fail closed) —
     * a wrong GTIN causes disapproval, a missing one falls back to brand+mpn.
     */
    private static function normalizeGtin(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (!in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return '';
        }
        return $digits;
    }

    /**
     * Get shipping weight for GMC in correct format.
     * 
     * CRITICAL: GMC requires singular unit names:
     * - "lb" NOT "lbs"
     * - "oz" NOT "ozs"
     * - "kg" NOT "kgs"
     * - "g" (grams)
     * 
     * Format: "{value} {unit}" e.g., "0.15 lb", "2.5 kg"
     *
     * @param \WC_Product $product
     * @return string Weight in GMC format (e.g., "0.15 lb") or empty if no weight
     */
    private static function getShippingWeight(\WC_Product $product): string
    {
        $weight = $product->get_weight();
        
        if (empty($weight) || !is_numeric($weight) || floatval($weight) <= 0) {
            // Default weight for products without weight set
            // Using 0.1 lb as a sensible default for supplements/small items
            return '0.1 lb';
        }

        // Get WooCommerce weight unit and convert to GMC format
        $wcUnit = get_option('woocommerce_weight_unit', 'lbs');
        $gmcUnit = self::convertToGmcWeightUnit($wcUnit);

        // Format weight with proper precision (2 decimal places for lb/kg, 1 for oz/g)
        $precision = in_array($gmcUnit, ['oz', 'g']) ? 1 : 2;
        $formattedWeight = number_format(floatval($weight), $precision, '.', '');

        return $formattedWeight . ' ' . $gmcUnit;
    }

    /**
     * Convert WooCommerce weight unit to GMC-accepted format.
     * 
     * GMC accepts: lb, oz, g, kg (singular only!)
     * WooCommerce uses: lbs, oz, g, kg, kgs
     *
     * @param string $wcUnit WooCommerce weight unit
     * @return string GMC-compatible weight unit
     */
    private static function convertToGmcWeightUnit(string $wcUnit): string
    {
        // Map WooCommerce units to GMC units
        $unitMap = [
            'lbs' => 'lb',   // WooCommerce "lbs" → GMC "lb"
            'lb'  => 'lb',
            'oz'  => 'oz',
            'ozs' => 'oz',
            'g'   => 'g',
            'kg'  => 'kg',
            'kgs' => 'kg',
        ];

        $wcUnit = strtolower(trim($wcUnit));
        
        return $unitMap[$wcUnit] ?? 'lb'; // Default to lb if unknown
    }

    /**
     * Get Google Product Category for a product.
     * 
     * Checks:
     * 1. GLA meta (_wc_gla_google_product_category)
     * 2. ACF field (google_product_category)
     * 3. Product meta (google_product_category)
     *
     * @param \WC_Product $product
     * @return string Google Product Category ID or name
     */
    private static function getGoogleProductCategory(\WC_Product $product): string
    {
        $productId = $product->get_id();

        // Check GLA meta first
        $glaCategory = get_post_meta($productId, '_wc_gla_google_product_category', true);
        if (!empty($glaCategory)) {
            return (string) $glaCategory;
        }

        // Check ACF field
        if (function_exists('get_field')) {
            $acfCategory = get_field('google_product_category', $productId);
            if (!empty($acfCategory)) {
                return (string) $acfCategory;
            }
        }

        // Check generic meta
        $metaCategory = get_post_meta($productId, 'google_product_category', true);
        if (!empty($metaCategory)) {
            return (string) $metaCategory;
        }

        // Default for products with no per-product mapping yet.
        // 469 = "Health & Beauty" (the TOP-LEVEL node) — a deliberately broad
        // catch-all, NOT the supplement leaf. The supplement leaf is
        // 525 = "Health & Beauty > Health Care > Fitness & Nutrition > Vitamins & Supplements".
        // Per-product categories are set on the ACF field 'google_product_category'
        // (read above); the full catalog was mapped 2026-07-08. Leave the default
        // at 469 so an unmapped product stays broad rather than mislabeled.
        return '469';
    }

    /**
     * Get product type (category hierarchy) for GMC.
     * 
     * Format: "Category > Subcategory > Sub-subcategory"
     *
     * @param \WC_Product $product
     * @return string Category hierarchy
     */
    private static function getProductType(\WC_Product $product): string
    {
        $productId = $product->get_id();
        $terms = get_the_terms($productId, 'product_cat');

        if (!$terms || is_wp_error($terms)) {
            return '';
        }

        // Find the deepest category (most specific)
        $deepestTerm = null;
        $maxDepth = -1;

        foreach ($terms as $term) {
            $depth = self::getCategoryDepth($term->term_id);
            if ($depth > $maxDepth) {
                $maxDepth = $depth;
                $deepestTerm = $term;
            }
        }

        if (!$deepestTerm) {
            return $terms[0]->name;
        }

        // Build hierarchy path
        $hierarchy = [];
        $currentTerm = $deepestTerm;

        while ($currentTerm) {
            array_unshift($hierarchy, $currentTerm->name);
            
            if ($currentTerm->parent > 0) {
                $currentTerm = get_term($currentTerm->parent, 'product_cat');
                if (is_wp_error($currentTerm)) {
                    break;
                }
            } else {
                break;
            }
        }

        return implode(' > ', $hierarchy);
    }

    /**
     * Get the depth of a category in the hierarchy.
     *
     * @param int $termId
     * @return int Depth (0 = root)
     */
    private static function getCategoryDepth(int $termId): int
    {
        $depth = 0;
        $term = get_term($termId, 'product_cat');

        while ($term && !is_wp_error($term) && $term->parent > 0) {
            $depth++;
            $term = get_term($term->parent, 'product_cat');
        }

        return $depth;
    }

    /**
     * Get color for a product.
     * 
     * Checks product attributes, meta, and title for color indicators.
     * Returns empty for non-apparel products (supplements, etc.)
     *
     * @param \WC_Product $product
     * @return string Color name or empty
     */
    private static function getColor(\WC_Product $product): string
    {
        $productId = $product->get_id();
        $productName = strtolower($product->get_name());

        // First check if this is an apparel/wearable product that needs color
        $needsColor = self::isApparelProduct($product);
        
        if (!$needsColor) {
            return ''; // Don't force color on supplements/vitamins
        }

        // Check product attributes for color
        $colorAttr = $product->get_attribute('color');
        if (!empty($colorAttr)) {
            return $colorAttr;
        }

        $colorAttr = $product->get_attribute('pa_color');
        if (!empty($colorAttr)) {
            return $colorAttr;
        }

        // Check product meta
        $colorMeta = get_post_meta($productId, '_color', true);
        if (!empty($colorMeta)) {
            return $colorMeta;
        }

        // Try to extract color from product name
        $colors = ['black', 'white', 'gold', 'silver', 'blue', 'red', 'green', 'purple', 
                   'pink', 'yellow', 'orange', 'brown', 'gray', 'grey', 'beige', 'navy',
                   'light gold', 'rose gold', 'light'];
        
        foreach ($colors as $color) {
            if (stripos($productName, $color) !== false) {
                return ucwords($color);
            }
        }

        // Default for wearables without explicit color
        return 'Multicolor';
    }

    /**
     * Get age group for a product.
     * 
     * GMC accepted values: adult, kids, toddler, infant, newborn
     * For health products, almost everything is "adult"
     *
     * @param \WC_Product $product
     * @return string Age group or empty
     */
    private static function getAgeGroup(\WC_Product $product): string
    {
        // Only set for apparel/wearable products
        if (!self::isApparelProduct($product)) {
            return '';
        }

        // Check product name for kids indicators
        $productName = strtolower($product->get_name());
        $kidsKeywords = ['kids', 'child', 'children', 'youth', 'junior'];
        
        foreach ($kidsKeywords as $keyword) {
            if (stripos($productName, $keyword) !== false) {
                return 'kids';
            }
        }

        // Default to adult for health products
        return 'adult';
    }

    /**
     * Get gender for a product.
     * 
     * GMC accepted values: male, female, unisex
     * For health products, most items are unisex
     *
     * @param \WC_Product $product
     * @return string Gender or empty
     */
    private static function getGender(\WC_Product $product): string
    {
        // Only set for apparel/wearable products
        if (!self::isApparelProduct($product)) {
            return '';
        }

        // Check product name for gender indicators
        $productName = strtolower($product->get_name());
        
        $maleKeywords = ['men', 'man', 'male', 'his', 'masculine'];
        foreach ($maleKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/', $productName)) {
                return 'male';
            }
        }

        $femaleKeywords = ['women', 'woman', 'female', 'her', 'feminine', 'ladies'];
        foreach ($femaleKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/', $productName)) {
                return 'female';
            }
        }

        // Default to unisex for health products
        return 'unisex';
    }

    /**
     * Check if a product is in an apparel/wearable category that requires color/gender/age_group.
     *
     * @param \WC_Product $product
     * @return bool
     */
    private static function isApparelProduct(\WC_Product $product): bool
    {
        $productId = $product->get_id();
        $productName = strtolower($product->get_name());

        // Check product name for wearable indicators
        $wearableKeywords = [
            'pendant', 'necklace', 'bracelet', 'wristband', 'headband',
            'ring', 'earring', 'jewelry', 'jewellery', 'chain', 'band',
            'shirt', 'hat', 'cap', 'clothing', 'apparel', 'wear'
        ];

        foreach ($wearableKeywords as $keyword) {
            if (stripos($productName, $keyword) !== false) {
                return true;
            }
        }

        // Check categories
        $terms = get_the_terms($productId, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            $apparelCategories = ['jewelry', 'jewellery', 'accessories', 'apparel', 'clothing', 'wearables', 'tachyon-energy'];
            
            foreach ($terms as $term) {
                $categorySlug = strtolower($term->slug);
                foreach ($apparelCategories as $apparelCat) {
                    if (stripos($categorySlug, $apparelCat) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
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
