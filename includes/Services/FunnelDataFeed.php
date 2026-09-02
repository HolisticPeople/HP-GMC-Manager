<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service for generating a product data feed for funnels.
 * 
 * This feed provides offer-level funnel data to GMC for Shopping ads.
 * Older HP-Funnels versions fall back to one lowest-price row per funnel.
 * Requires HP-Funnels (preferred) or HP-React-Widgets plugin to be active.
 */
class FunnelDataFeed
{
    /** @var string Cache key for feed content */
    private const CACHE_KEY = 'hp_gmc_funnel_feed_content';

    /** @var int Cache duration in seconds (1 hour) */
    private const CACHE_DURATION = 3600;

    /** @var string Option key for last generated timestamp */
    private const LAST_GENERATED_KEY = 'hp_gmc_funnel_feed_last_generated';

    /** @var string Option key for funnel count */
    private const FUNNEL_COUNT_KEY = 'hp_gmc_funnel_feed_count';

    /** @var string|null Cached resolved FunnelGmcService class name */
    private static ?string $resolvedGmcServiceClass = null;

    /** @var string|null Cached resolved FunnelConfigLoader class name */
    private static ?string $resolvedConfigLoaderClass = null;

    /**
     * Check if funnel integration is available.
     *
     * Checks HP-Funnels (preferred) then HP-React-Widgets (legacy fallback).
     *
     * @return bool True if FunnelGmcService is available from any source
     */
    public static function isAvailable(): bool
    {
        return self::resolveGmcServiceClass() !== null;
    }

    /**
     * Resolve the FunnelGmcService class name.
     *
     * Prefers HP-Funnels namespace, falls back to HP-React-Widgets.
     *
     * @return string|null Fully qualified class name or null
     */
    public static function resolveGmcServiceClass(): ?string
    {
        if (self::$resolvedGmcServiceClass !== null) {
            return self::$resolvedGmcServiceClass !== '' ? self::$resolvedGmcServiceClass : null;
        }

        if (class_exists('HP_Funnels\\Services\\FunnelGmcService')) {
            self::$resolvedGmcServiceClass = 'HP_Funnels\\Services\\FunnelGmcService';
        } elseif (class_exists('HP_RW\\Services\\FunnelGmcService')) {
            self::$resolvedGmcServiceClass = 'HP_RW\\Services\\FunnelGmcService';
        } else {
            self::$resolvedGmcServiceClass = '';
        }

        return self::$resolvedGmcServiceClass !== '' ? self::$resolvedGmcServiceClass : null;
    }

    /**
     * Resolve the FunnelConfigLoader class name.
     *
     * Prefers HP-Funnels namespace, falls back to HP-React-Widgets.
     *
     * @return string|null Fully qualified class name or null
     */
    public static function resolveConfigLoaderClass(): ?string
    {
        if (self::$resolvedConfigLoaderClass !== null) {
            return self::$resolvedConfigLoaderClass !== '' ? self::$resolvedConfigLoaderClass : null;
        }

        if (class_exists('HP_Funnels\\Services\\FunnelConfigLoader')) {
            self::$resolvedConfigLoaderClass = 'HP_Funnels\\Services\\FunnelConfigLoader';
        } elseif (class_exists('HP_RW\\Services\\FunnelConfigLoader')) {
            self::$resolvedConfigLoaderClass = 'HP_RW\\Services\\FunnelConfigLoader';
        } else {
            self::$resolvedConfigLoaderClass = '';
        }

        return self::$resolvedConfigLoaderClass !== '' ? self::$resolvedConfigLoaderClass : null;
    }

    /**
     * Generate the funnel data feed content.
     *
     * @param string $format Output format: 'tsv' or 'csv'
     * @param bool $forceRegenerate Skip cache and regenerate
     * @return string Feed content
     */
    public static function generateFeed(string $format = 'tsv', bool $forceRegenerate = false): string
    {
        if (!self::isAvailable()) {
            return self::generateEmptyFeed($format);
        }

        // Check cache first (unless force regenerate)
        if (!$forceRegenerate) {
            $cached = get_transient(self::CACHE_KEY . '_' . $format);
            if ($cached !== false) {
                return $cached;
            }
        }

        $delimiter = $format === 'csv' ? ',' : "\t";

        // Build header row
        // NOTE: shipping_weight uses GMC format (e.g., "0.5 oz") with singular unit
        $headers = [
            'id',
            'title',
            'description',
            'link',
            'image_link',
            'additional_image_link',
            'price',
            'availability',
            'brand',
            'condition',
            'is_bundle',
            'shipping_weight',
            'google_product_category',
            'custom_label_0',
            'custom_label_1',
            'custom_label_2',
            'custom_label_3',
            'custom_label_4',
            'item_group_id',
        ];

        $lines = [];
        $lines[] = implode($delimiter, $headers);

        // Prefer offer-level rows so distinct ExpressShop packages can be
        // targeted truthfully by Merchant Center Promotions.
        $gmcServiceClass = self::resolveGmcServiceClass();
        $funnels = method_exists($gmcServiceClass, 'getAllGmcEnabledOffers')
            ? $gmcServiceClass::getAllGmcEnabledOffers()
            : $gmcServiceClass::getAllGmcEnabledFunnels();
        $count = 0;

        foreach ($funnels as $funnel) {
            // Validate funnel data
            if (empty($funnel['title']) || $funnel['price'] <= 0) {
                continue;
            }

            // Build row data
            $row = [
                self::escapeField($funnel['feed_id'] ?? ('funnel_' . $funnel['funnel_id']), $format),
                self::escapeField($funnel['title'], $format),
                self::escapeField($funnel['description'], $format),
                self::escapeField($funnel['link'], $format),
                self::escapeField($funnel['image_link'], $format),
                self::escapeField($funnel['additional_image_link'] ?? '', $format),
                self::escapeField($funnel['price_formatted'], $format),
                self::escapeField($funnel['availability'], $format),
                self::escapeField($funnel['brand'], $format),
                self::escapeField($funnel['condition'], $format),
                self::escapeField($funnel['is_bundle'] ?? 'no', $format),
                self::escapeField($funnel['shipping_weight_formatted'] ?? '', $format),
                self::escapeField($funnel['google_product_category'], $format),
                self::escapeField($funnel['custom_label_0'], $format),
                self::escapeField($funnel['custom_label_1'], $format),
                self::escapeField($funnel['custom_label_2'], $format),
                self::escapeField($funnel['custom_label_3'], $format),
                self::escapeField($funnel['custom_label_4'], $format),
                self::escapeField($funnel['item_group_id'], $format),
            ];

            $lines[] = implode($delimiter, $row);
            $count++;
        }

        $content = implode("\n", $lines);

        // Cache the result
        set_transient(self::CACHE_KEY . '_' . $format, $content, self::CACHE_DURATION);

        // Update metadata
        update_option(self::LAST_GENERATED_KEY, current_time('mysql'));
        update_option(self::FUNNEL_COUNT_KEY, $count);

        // Log generation event
        error_log(json_encode([
            'event' => 'funnel_feed.generated',
            'funnel_count' => $count,
            'format' => $format,
            'timestamp' => current_time('mysql'),
        ]));

        return $content;
    }

    /**
     * Generate an empty feed with just headers.
     *
     * @param string $format Output format
     * @return string Empty feed with headers
     */
    private static function generateEmptyFeed(string $format): string
    {
        $delimiter = $format === 'csv' ? ',' : "\t";
        $headers = [
            'id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
            'price', 'availability', 'brand', 'condition', 'is_bundle', 'shipping_weight',
            'google_product_category', 'custom_label_0', 'custom_label_1',
            'custom_label_2', 'custom_label_3', 'custom_label_4', 'item_group_id',
        ];
        return implode($delimiter, $headers);
    }

    /**
     * Escape field value for TSV/CSV format.
     *
     * @param mixed $value Field value
     * @param string $format Output format
     * @return string Escaped value
     */
    private static function escapeField($value, string $format): string
    {
        $value = (string) $value;

        // Remove newlines and tabs
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);

        // Trim whitespace
        $value = trim($value);

        if ($format === 'csv') {
            // For CSV, escape quotes and wrap in quotes if contains comma/quote
            if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
        }

        return $value;
    }

    /**
     * Clear the funnel feed cache.
     */
    public static function clearCache(): void
    {
        delete_transient(self::CACHE_KEY . '_tsv');
        delete_transient(self::CACHE_KEY . '_csv');

        error_log(json_encode([
            'event' => 'funnel_feed.cache_cleared',
            'timestamp' => current_time('mysql'),
        ]));
    }

    /**
     * Get feed status information.
     *
     * @return array Status data
     */
    public static function getStatus(): array
    {
        $lastGenerated = get_option(self::LAST_GENERATED_KEY, null);
        $funnelCount = (int) get_option(self::FUNNEL_COUNT_KEY, 0);

        return [
            'available' => self::isAvailable(),
            'last_generated' => $lastGenerated,
            'funnel_count' => $funnelCount,
            'cache_duration' => self::CACHE_DURATION,
            'feed_urls' => [
                'tsv' => rest_url('hp-gmc/v1/funnel-feed?format=tsv'),
                'csv' => rest_url('hp-gmc/v1/funnel-feed?format=csv'),
            ],
        ];
    }

    /**
     * Get all GMC-enabled funnels with their data.
     *
     * @return array Array of funnel data
     */
    public static function getAllFunnels(): array
    {
        $gmcServiceClass = self::resolveGmcServiceClass();
        if (!$gmcServiceClass) {
            return [];
        }

        return $gmcServiceClass::getAllGmcEnabledFunnels();
    }

    /**
     * Get funnel GMC data by ID.
     *
     * @param int $funnelId Funnel post ID
     * @return array|null Funnel data or null
     */
    public static function getFunnel(int $funnelId): ?array
    {
        $gmcServiceClass = self::resolveGmcServiceClass();
        if (!$gmcServiceClass) {
            return null;
        }

        return $gmcServiceClass::getFunnelGmcData($funnelId);
    }

    /**
     * Validate a funnel for GMC eligibility.
     *
     * @param int $funnelId Funnel post ID
     * @return array Validation result
     */
    public static function validateFunnel(int $funnelId): array
    {
        $gmcServiceClass = self::resolveGmcServiceClass();
        if (!$gmcServiceClass) {
            return ['valid' => false, 'errors' => ['HP-Funnels or HP-React-Widgets plugin not active']];
        }

        return $gmcServiceClass::validateForGmc($funnelId);
    }
}
