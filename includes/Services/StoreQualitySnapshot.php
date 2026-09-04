<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Bounded, operator-imported Store Quality observations; never a live Google read. */
final class StoreQualitySnapshot
{
    public const OPTION = 'hp_gmc_store_quality_snapshot_v1';
    public const HISTORY_OPTION = 'hp_gmc_store_quality_history_v1';
    public const WIDGET_OBSERVATION_OPTION = 'hp_gmc_store_widget_observation_v1';
    private const SOURCE_URL = 'https://merchants.google.com/mc/store-quality?merchantId=5298746911';
    private const METRICS = [
        'overall_quality', 'delivery', 'shipping_cost', 'return_window', 'return_cost',
        'promotions_rejection', 'ewallet', 'high_resolution_images', 'images_per_offer', 'store_rating',
    ];

    /**
     * Import from a trusted WP-CLI/operator path. There is no REST, form, or browser writer.
     * @return true|\WP_Error
     */
    public static function import(array $snapshot)
    {
        $clean = self::sanitize($snapshot);
        if (is_wp_error($clean)) { return $clean; }
        $history = get_option(self::HISTORY_OPTION, []);
        $history = is_array($history) ? $history : [];
        $prior = get_option(self::OPTION, null);
        if (is_array($prior)) { array_unshift($history, $prior); }
        update_option(self::OPTION, $clean, false);
        update_option(self::HISTORY_OPTION, array_slice($history, 0, 30), false);
        return true;
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        $value = get_option(self::OPTION, null);
        return is_array($value) ? $value : null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function history(): array
    {
        $value = get_option(self::HISTORY_OPTION, []);
        return is_array($value) ? array_slice($value, 0, 30) : [];
    }

    /** @return array<string,string> A display-only diff against the immediately prior snapshot. */
    public static function diff(): array
    {
        $current = self::current();
        $previous = self::history()[0] ?? null;
        if (!$current || !is_array($previous)) { return []; }
        $diff = [];
        foreach (self::METRICS as $key) {
            $before = wp_json_encode($previous['metrics'][$key] ?? null);
            $after = wp_json_encode($current['metrics'][$key] ?? null);
            if ($before !== $after) { $diff[$key] = $before . ' → ' . $after; }
        }
        return $diff;
    }

    /** Import trusted browser evidence separately; a load never becomes a receipt or visibility claim. */
    public static function importWidgetObservation(array $observation)
    {
        $allowed = ['script_loaded', 'script_load_failed', 'start_attempted', 'start_failed', 'widget_visible', 'not_observed'];
        if (($observation['version'] ?? null) !== 1 || !in_array($observation['status'] ?? '', $allowed, true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) ($observation['observed_at'] ?? ''))
            || !is_string($observation['route'] ?? null) || strlen($observation['route']) > 160) {
            return new \WP_Error('hp_gmc_widget_observation_invalid', 'Widget observation is invalid.');
        }
        update_option(self::WIDGET_OBSERVATION_OPTION, [
            'version' => 1, 'observed_at' => $observation['observed_at'], 'route' => $observation['route'],
            'status' => $observation['status'], 'google_receipt' => 'not_observed',
        ], false);
        return true;
    }

    /** @return array<string,mixed>|null */
    public static function widgetObservation(): ?array
    {
        $value = get_option(self::WIDGET_OBSERVATION_OPTION, null);
        return is_array($value) ? $value : null;
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function sanitize(array $snapshot)
    {
        if (($snapshot['version'] ?? null) !== 1 || ($snapshot['source']['url'] ?? '') !== self::SOURCE_URL
            || ($snapshot['source']['country'] ?? '') !== 'US' || ($snapshot['source']['window'] ?? '') !== 'trailing_30_days'
            || ($snapshot['source']['scope'] ?? '') !== 'all_stores') {
            return new \WP_Error('hp_gmc_store_quality_invalid_source', 'Store Quality source scope is not allowlisted.');
        }
        $observed = (string) ($snapshot['observed_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $observed)) {
            return new \WP_Error('hp_gmc_store_quality_invalid_time', 'observed_at must be UTC RFC3339.');
        }
        $metrics = $snapshot['metrics'] ?? [];
        if (!is_array($metrics) || array_diff(array_keys($metrics), self::METRICS) || array_diff(self::METRICS, array_keys($metrics))) {
            return new \WP_Error('hp_gmc_store_quality_invalid_metrics', 'Store Quality metric keys are fixed.');
        }
        foreach ($metrics as $metric) {
            if (!is_array($metric) || !in_array($metric['rating'] ?? '', ['exceptional', 'great', 'good', 'fair', 'incomplete'], true)) {
                return new \WP_Error('hp_gmc_store_quality_invalid_metric', 'A Store Quality rating is invalid.');
            }
        }
        $errors = $snapshot['errors'] ?? [];
        if (!is_array($errors) || count($errors) > 10 || array_filter($errors, static fn ($error) => !is_string($error) || strlen($error) > 180)) {
            return new \WP_Error('hp_gmc_store_quality_invalid_errors', 'Errors must be a bounded list of safe strings.');
        }
        return [
            'version' => 1, 'observed_at' => $observed,
            'source' => ['url' => self::SOURCE_URL, 'country' => 'US', 'window' => 'trailing_30_days', 'scope' => 'all_stores'],
            'metrics' => $metrics, 'errors' => array_values($errors),
        ];
    }
}
