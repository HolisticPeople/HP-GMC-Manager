<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Sanitized, dated operator observations. No browser endpoint or Google requests. */
final class StoreQualitySnapshot
{
    public const OPTION = 'hp_gmc_store_quality_snapshot_v1';
    public const HISTORY_OPTION = 'hp_gmc_store_quality_history_v1';
    public const WIDGET_OBSERVATION_OPTION = 'hp_gmc_store_widget_observation_v1';
    public const ERROR_OPTION = 'hp_gmc_store_quality_last_error_v1';
    public const REVIEWS_OPTION = 'hp_gmc_google_reviews_observation_v1';
    public const REVIEWS_HISTORY_OPTION = 'hp_gmc_google_reviews_history_v1';
    public const SOURCE_URL = 'https://merchants.google.com/mc/quality?a=5298746911&region=US';
    public const GCR_URL = 'https://merchants.google.com/mc/customerreviews/overview?a=5298746911';
    public const PRODUCT_REVIEWS_URL = 'https://merchants.google.com/mc/customerreviews/productreview?a=5298746911';
    private const RATINGS = ['exceptional', 'great', 'good', 'fair', 'low', 'incomplete'];
    private const ERRORS = ['source_unavailable', 'authentication_required', 'captcha_required', 'partial_observation', 'invalid_observation', 'storage_failed'];
    private const COUNTERS = ['survey_optins', 'surveys_offered', 'survey_responses', 'matched_gtins', 'product_survey_responses'];
    private const METRICS = [
        'overall_quality' => [null, 0], 'delivery' => ['days', 365],
        'shipping_cost' => ['USD', 100000], 'return_window' => ['days', 3650],
        'return_cost' => ['USD', 100000], 'promotions_rejection' => ['percent', 100],
        'ewallet' => ['count', 4], 'high_resolution_images' => ['percent', 100],
        'images_per_offer' => ['count', 100], 'store_rating' => ['stars', 5],
    ];

    /** @return true|\WP_Error */
    public static function import(array $snapshot)
    {
        $clean = self::sanitize($snapshot);
        if (is_wp_error($clean)) { self::recordFailure('invalid_observation'); return $clean; }
        $prior = self::current();
        if ($prior && $clean['observed_at'] <= $prior['observed_at']) {
            return self::error('Observation time must advance.');
        }
        $history = self::history();
        if ($prior) { array_unshift($history, $prior); }
        update_option(self::HISTORY_OPTION, array_slice($history, 0, 30), false);
        update_option(self::OPTION, $clean, false);
        if (get_option(self::OPTION) !== $clean) { self::recordFailure('storage_failed'); return self::error('Observation storage failed.'); }
        delete_option(self::ERROR_OPTION);
        return true;
    }

    /** A failed remote observation never replaces the last good metrics. */
    public static function recordFailure(string $code)
    {
        if (!in_array($code, self::ERRORS, true)) { return self::error('Unknown observation failure code.'); }
        update_option(self::ERROR_OPTION, ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'code' => $code], false);
        return true;
    }

    public static function current(): ?array
    {
        $clean = self::sanitize(get_option(self::OPTION, null));
        return is_array($clean) ? $clean : null;
    }

    public static function history(): array
    {
        $history = get_option(self::HISTORY_OPTION, []);
        if (!is_array($history)) { return []; }
        $clean = [];
        foreach (array_slice($history, 0, 30) as $row) {
            $item = self::sanitize($row);
            if (is_array($item)) { $clean[] = $item; }
        }
        return $clean;
    }

    /** @return array<string,array{before:mixed,after:mixed}> */
    public static function diff(): array
    {
        $current = self::current(); $previous = self::history()[0] ?? null;
        if (!$current || !$previous) { return []; }
        $changes = [];
        foreach (array_keys(self::METRICS) as $key) {
            if ($current['metrics'][$key] !== $previous['metrics'][$key]) {
                $changes[$key] = ['before' => $previous['metrics'][$key], 'after' => $current['metrics'][$key]];
            }
        }
        return $changes;
    }

    public static function freshness(): array
    {
        $current = self::current(); $failure = get_option(self::ERROR_OPTION, null);
        $validFailure = is_array($failure) && in_array($failure['code'] ?? null, self::ERRORS, true) && self::validTime($failure['at'] ?? null);
        $age = $current ? max(0, time() - strtotime($current['observed_at'])) : null;
        return ['status' => $age === null ? 'missing' : ($age > 129600 ? 'stale' : 'fresh'),
            'age_seconds' => $age, 'error' => $validFailure ? $failure['code'] : null,
            'error_at' => $validFailure ? $failure['at'] : null];
    }

    public static function importWidgetObservation(array $observation)
    {
        $clean = self::sanitizeWidget($observation);
        if (is_wp_error($clean)) { return $clean; }
        $prior = self::widgetObservation();
        if ($prior && $clean['observed_at'] <= $prior['observed_at']) { return self::error('Widget observation time must advance.'); }
        update_option(self::WIDGET_OBSERVATION_OPTION, $clean, false);
        return get_option(self::WIDGET_OBSERVATION_OPTION) === $clean ? true : self::error('Widget observation storage failed.');
    }

    public static function widgetObservation(): ?array
    {
        $clean = self::sanitizeWidget(get_option(self::WIDGET_OBSERVATION_OPTION, null));
        return is_array($clean) ? $clean : null;
    }

    public static function importReviewsObservation(array $observation)
    {
        $clean = self::sanitizeReviews($observation);
        if (is_wp_error($clean)) { return $clean; }
        $prior = self::reviewsObservation();
        if ($prior && $clean['observed_at'] <= $prior['observed_at']) { return self::error('Review observation time must advance.'); }
        $history = self::reviewsHistory();
        if ($prior) { array_unshift($history, $prior); }
        update_option(self::REVIEWS_HISTORY_OPTION, array_slice($history, 0, 30), false);
        update_option(self::REVIEWS_OPTION, $clean, false);
        return get_option(self::REVIEWS_OPTION) === $clean ? true : self::error('Review observation storage failed.');
    }

    public static function reviewsObservation(): ?array
    {
        $clean = self::sanitizeReviews(get_option(self::REVIEWS_OPTION, null));
        return is_array($clean) ? $clean : null;
    }

    public static function reviewsHistory(): array
    {
        $history = get_option(self::REVIEWS_HISTORY_OPTION, []);
        if (!is_array($history)) { return []; }
        $clean = [];
        foreach (array_slice($history, 0, 30) as $row) {
            $item = self::sanitizeReviews($row);
            if (is_array($item)) { $clean[] = $item; }
        }
        return $clean;
    }

    private static function sanitize($snapshot)
    {
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1 || !self::validTime($snapshot['observed_at'] ?? null)
            || !self::sourceMatches($snapshot['source'] ?? null, ['url' => self::SOURCE_URL, 'country' => 'US', 'window' => 'trailing_30_days', 'scope' => 'all_stores'])) {
            return self::error('Invalid observation source, scope or UTC timestamp.');
        }
        $metrics = $snapshot['metrics'] ?? null;
        if (!is_array($metrics) || array_diff(array_keys($metrics), array_keys(self::METRICS)) || array_diff(array_keys(self::METRICS), array_keys($metrics))) {
            return self::error('Metric names must match the observed scorecard.');
        }
        $clean = [];
        foreach (self::METRICS as $name => [$unit, $maximum]) {
            $metric = $metrics[$name];
            if (!is_array($metric) || array_diff(array_keys($metric), ['value', 'unit', 'rating', 'denominator', 'providers'])
                || !array_key_exists('value', $metric) || !in_array($metric['rating'] ?? null, self::RATINGS, true)) {
                return self::error('Invalid metric fields or rating.');
            }
            $value = $metric['value'];
            if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > $maximum)) {
                return self::error('Metric value is outside its numeric range.');
            }
            if ($name === 'overall_quality' && $value !== null) { return self::error('Overall quality is a rating, not a numeric score.'); }
            if (isset($metric['unit']) && $metric['unit'] !== $unit) { return self::error('Metric unit does not match its field.'); }
            if ($name !== 'ewallet' && (isset($metric['denominator']) || isset($metric['providers']))) { return self::error('Wallet fields are restricted to the wallet metric.'); }
            $clean[$name] = ['value' => $value, 'rating' => $metric['rating']];
            if ($unit !== null) { $clean[$name]['unit'] = $unit; }
            if ($name === 'ewallet') {
                $providers = $metric['providers'] ?? [];
                if (($metric['denominator'] ?? null) !== 4 || ($value !== null && !is_int($value)) || !is_array($providers)
                    || count($providers) > 4 || array_filter($providers, static fn ($p) => !is_string($p) || !in_array($p, ['PayPal', 'Google Wallet', 'Apple Pay', 'Amazon Pay'], true))
                    || count(array_unique($providers)) !== count($providers)) { return self::error('Invalid wallet count or provider.'); }
                $clean[$name]['denominator'] = 4; $clean[$name]['providers'] = array_values($providers);
            }
        }
        $errors = $snapshot['errors'] ?? [];
        if (!is_array($errors) || count($errors) > 6 || array_filter($errors, static fn ($e) => !is_string($e) || !in_array($e, self::ERRORS, true))) {
            return self::error('Observation errors must be known safe codes.');
        }
        return ['version' => 1, 'observed_at' => $snapshot['observed_at'], 'source' => $snapshot['source'], 'metrics' => $clean, 'errors' => array_values($errors)];
    }

    private static function sanitizeWidget($value)
    {
        $statuses = ['script_loaded', 'script_load_failed', 'start_attempted', 'start_failed', 'widget_visible', 'not_observed'];
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || !self::validTime($value['observed_at'] ?? null)
            || !in_array($value['status'] ?? null, $statuses, true) || !is_string($value['route'] ?? null)
            || !preg_match('~^/(?:|shop/|reviews/|hp-checkout/|product/[a-z0-9-]+/|product-category/[a-z0-9/-]+/)$~D', $value['route'])
            || strlen($value['route']) > 160) { return self::error('Invalid public widget observation.'); }
        $variant = $value['variant'] ?? 'not_observed';
        if (!in_array($variant, ['not_observed', 'store_quality', 'store_rating', 'top_quality_store'], true)
            || ($value['status'] !== 'widget_visible' && $variant !== 'not_observed')) { return self::error('Widget variant requires visible Google evidence.'); }
        return ['version' => 1, 'observed_at' => $value['observed_at'], 'route' => $value['route'], 'status' => $value['status'], 'variant' => $variant, 'google_receipt' => 'not_observed'];
    }

    private static function sanitizeReviews($value)
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || !self::validTime($value['observed_at'] ?? null)
            || !self::sourceMatches($value['source'] ?? null, ['gcr_url' => self::GCR_URL, 'product_reviews_url' => self::PRODUCT_REVIEWS_URL, 'country' => 'all', 'window' => 'panel_default'])
            || !in_array($value['gcr_status'] ?? null, ['no_data', 'no_optin_notification_more_than_30_days', 'inactive', 'active'], true)
            || !in_array($value['product_reviews_status'] ?? null, ['inactive', 'active', 'no_data'], true)) { return self::error('Invalid Google review observation scope or status.'); }
        $clean = ['version' => 1, 'observed_at' => $value['observed_at'], 'source' => $value['source'], 'gcr_status' => $value['gcr_status'], 'product_reviews_status' => $value['product_reviews_status']];
        foreach (self::COUNTERS as $key) {
            if (!array_key_exists($key, $value) || ($value[$key] !== null && (!is_int($value[$key]) || $value[$key] < 0 || $value[$key] > 1000000000))) { return self::error('Invalid Google review counter.'); }
            $clean[$key] = $value[$key];
        }
        return $clean;
    }

    private static function sourceMatches($actual, array $expected): bool
    {
        if (!is_array($actual) || count($actual) !== count($expected)) { return false; }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) { return false; }
        }
        return true;
    }

    private static function validTime($value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        return $date && !($errors && ($errors['warning_count'] || $errors['error_count']))
            && $date->format('Y-m-d\TH:i:s\Z') === $value && $date->getTimestamp() <= time() + 60;
    }

    private static function error(string $message): \WP_Error
    {
        return new \WP_Error('hp_gmc_observation_invalid', $message);
    }
}
