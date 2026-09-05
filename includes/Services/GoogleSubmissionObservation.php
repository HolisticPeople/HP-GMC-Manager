<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Bounded, public-business observations. Import is an explicit operator action. */
final class GoogleSubmissionObservation
{
    public const OPTION = 'hp_gmc_submission_observations_v1';
    public const HISTORY = 'hp_gmc_submission_observation_history_v1';
    public const ERRORS = 'hp_gmc_observation_errors_v1';
    private const FAILURE_CODES = ['source_unavailable', 'authentication_required', 'captcha_required', 'partial_observation', 'invalid_observation', 'storage_failed'];
    private const STATES = ['proposed', 'staged', 'submitted', 'accepted', 'visible', 'google_awarded', 'not_observed'];

    /** Closed column contracts: no arbitrary API responses or customer fields. */
    public static function definitions(): array
    {
        return [
            'shipping' => ['title' => 'Shipping submitted to Google', 'owner' => 'HP-GMC Manager', 'source' => 'https://merchants.google.com/mc/shipping/services?a=5298746911', 'days' => 8,
                'columns' => ['service'=>'text', 'countries'=>'countries', 'status'=>'text', 'handling_min'=>'count', 'handling_max'=>'count', 'transit_min'=>'count', 'transit_max'=>'count', 'calendar'=>'text', 'cutoff'=>'text', 'timezone'=>'text', 'speed_source'=>'text', 'cost_source'=>'text', 'products'=>'count']],
            'countries' => ['title' => 'Country and product coverage', 'owner' => 'HP-GMC Manager', 'source' => 'https://merchants.google.com/mc/countries?a=5298746911', 'days' => 8,
                'columns' => ['country'=>'country', 'setup'=>'text', 'approved_products'=>'count', 'total_products'=>'count', 'shipping_covered'=>'bool', 'return_covered'=>'bool', 'action'=>'text']],
            'returns' => ['title' => 'Return policy submitted to Google', 'owner' => 'HP-GMC Manager', 'source' => 'https://merchants.google.com/mc/returnoverview?a=5298746911', 'days' => 8,
                'columns' => ['policy_id'=>'count', 'status'=>'text', 'countries'=>'countries', 'window_days'=>'count', 'cost'=>'text', 'refund_days'=>'count', 'restocking_fee'=>'text', 'condition'=>'text', 'method'=>'text', 'products'=>'count']],
            'payments' => ['title' => 'Payment recognition by Google', 'owner' => 'HP-GMC Manager', 'source' => 'https://merchants.google.com/mc/settings/paymentsignal/edit?a=5298746911', 'days' => 1.5,
                'columns' => ['provider'=>'text', 'status'=>'text', 'submitted_at'=>'time', 'accepted_at'=>'time']],
            'feeds' => ['title' => 'Google feed processing', 'owner' => 'HP-GMC Manager', 'source' => 'https://merchants.google.com/mc/products/sources?a=5298746911', 'days' => 1.5,
                'columns' => ['name'=>'text', 'source_id'=>'count', 'countries'=>'countries', 'language'=>'text', 'last_processed_at'=>'time', 'active_products'=>'count', 'archived_products'=>'count', 'status'=>'text']],
            'yotpo' => ['title' => 'Yotpo and Google syndication', 'owner' => 'HP-Yotpo', 'source' => 'https://reviews.yotpo.com/', 'days' => 1.5,
                'columns' => ['program'=>'text', 'account'=>'text', 'connection_status'=>'text', 'entitlement'=>'text', 'last_feed_at'=>'time', 'google_matching'=>'text', 'blocker'=>'text']],
            'seo' => ['title' => 'Product structured data and indexing', 'owner' => 'HP-Core / Yoast', 'source' => 'https://search.google.com/search-console?resource_id=https%3A%2F%2Fholisticpeople.com%2F', 'days' => 8,
                'columns' => ['check'=>'text', 'status'=>'text', 'invalid_items'=>'count', 'valid_items'=>'count', 'last_google_update'=>'time', 'detail'=>'text']],
            'loyalty' => ['title' => 'Loyalty submitted to Google', 'owner' => 'HP-GMC Manager', 'source' => 'https://merchants.google.com/mc/loyaltyprogram/landing?a=5298746911', 'days' => 8,
                'columns' => ['program'=>'text', 'status'=>'text', 'countries'=>'countries', 'benefit'=>'text']],
            'delivery_proposals' => ['title' => 'Proposed delivery estimates', 'owner' => 'HP ShipStation Rates', 'source' => 'woocommerce_local_shipping_evidence', 'days' => 8,
                'columns' => ['service'=>'text', 'countries'=>'countries', 'handling_min'=>'count', 'handling_max'=>'count', 'transit_min'=>'count', 'transit_max'=>'count', 'calendar'=>'text', 'cutoff'=>'text', 'timezone'=>'text', 'evidence_start'=>'time', 'evidence_end'=>'time', 'sample_size'=>'count', 'limitation'=>'text']],
            'analytics' => ['title' => 'Ecommerce measurement', 'owner' => 'HP-Core / HP-GMC Manager', 'source' => 'https://analytics.google.com/analytics/web/#/a364667966p500523670/reports/explorer?r=ecomm-product', 'days' => 8,
                'columns' => ['period'=>'text', 'items_viewed'=>'count', 'items_added_to_cart'=>'count', 'items_purchased'=>'count', 'item_revenue_usd'=>'number', 'reconciliation'=>'text']],
        ];
    }

    public static function import(array $value)
    {
        $clean = self::sanitize($value);
        if ($clean instanceof \WP_Error) {
            if (is_string($value['section'] ?? null) && isset(self::definitions()[$value['section']])) { self::recordFailure('invalid_observation', $value['section']); }
            return $clean;
        }
        $scope = $clean['section'];
        $all = get_option(self::OPTION, []);
        if (!is_array($all)) { $all = []; }
        $prior = self::current($scope);
        if ($prior && $clean['observed_at'] <= $prior['observed_at']) { return self::error('Observation time must advance.'); }
        $history = get_option(self::HISTORY, []);
        if (!is_array($history)) { $history = []; }
        $rows = self::history($scope);
        if ($prior) { array_unshift($rows, $prior); }
        $history[$scope] = array_slice($rows, 0, 30);
        $all[$scope] = $clean;
        update_option(self::HISTORY, $history, false);
        update_option(self::OPTION, $all, false);
        if (self::current($scope) !== $clean) { self::recordFailure('storage_failed', $scope); return self::error('Observation storage failed.'); }
        self::clearFailure($scope);
        return true;
    }

    public static function current(string $scope): ?array
    {
        $all = get_option(self::OPTION, []);
        $clean = self::sanitize(is_array($all) ? ($all[$scope] ?? null) : null);
        return is_array($clean) && $clean['section'] === $scope ? $clean : null;
    }

    public static function history(string $scope): array
    {
        $all = get_option(self::HISTORY, []);
        $rows = is_array($all) && is_array($all[$scope] ?? null) ? $all[$scope] : [];
        $clean = [];
        foreach (array_slice($rows, 0, 30) as $row) {
            $item = self::sanitize($row);
            if (is_array($item) && $item['section'] === $scope) { $clean[] = $item; }
        }
        return $clean;
    }

    /** Ignore observation timestamps when detecting substantive changes. */
    public static function changedRows(string $scope): array
    {
        $current = self::current($scope); $prior = self::history($scope)[0] ?? null;
        if (!$current || !$prior) { return []; }
        $changes = [];
        foreach (array_unique(array_merge(array_keys($current['rows']), array_keys($prior['rows']))) as $id) {
            if (($current['rows'][$id] ?? null) !== ($prior['rows'][$id] ?? null)) { $changes[] = $id; }
        }
        if ($current['state'] !== $prior['state'] || $current['environment'] !== $prior['environment']) { $changes[] = 'evidence_state'; }
        return $changes;
    }

    public static function recordFailure(string $code, string $scope = 'quality')
    {
        if (!self::knownScope($scope) || !in_array($code, self::FAILURE_CODES, true)) { return self::error('Unknown observation scope or failure code.'); }
        $errors = get_option(self::ERRORS, []);
        if (!is_array($errors)) { $errors = []; }
        $errors[$scope] = ['code'=>$code, 'at'=>gmdate('Y-m-d\TH:i:s\Z')];
        update_option(self::ERRORS, $errors, false);
        return true;
    }

    public static function clearFailure(string $scope): void
    {
        $errors = get_option(self::ERRORS, []);
        if (is_array($errors) && isset($errors[$scope])) {
            unset($errors[$scope]); update_option(self::ERRORS, $errors, false);
        }
    }

    /** Preserve the legacy importer's storage failure instead of relabelling it. */
    public static function trackImportResult(string $scope, $result): void
    {
        if ($result === true) { self::clearFailure($scope); return; }
        $message = $result instanceof \WP_Error ? $result->get_error_message() : '';
        self::recordFailure(stripos($message, 'storage failed') !== false ? 'storage_failed' : 'invalid_observation', $scope);
    }

    /** Reads only. Legacy observations use this same per-source freshness view. */
    public static function health(string $scope, ?string $observedAt): array
    {
        $days = self::definitions()[$scope]['days'] ?? (in_array($scope, ['submitted'], true) ? 8 : 1.5);
        $age = self::validTime($observedAt) ? max(0, time() - strtotime($observedAt)) : null;
        $errors = get_option(self::ERRORS, []);
        $failure = is_array($errors) ? ($errors[$scope] ?? null) : null;
        $valid = is_array($failure) && in_array($failure['code'] ?? null, self::FAILURE_CODES, true) && self::validTime($failure['at'] ?? null);
        return ['freshness'=>$age === null ? 'missing' : ($age > $days * 86400 ? 'stale' : 'fresh'), 'last_success'=>$age === null ? null : $observedAt,
            'last_error'=>$valid ? $failure['code'] : null, 'error_at'=>$valid ? $failure['at'] : null];
    }

    private static function knownScope(string $scope): bool
    {
        return isset(self::definitions()[$scope]) || in_array($scope, ['quality', 'reviews', 'widget', 'submitted'], true);
    }

    private static function sanitize($value)
    {
        if (!is_array($value) || array_diff(array_keys($value), ['version','section','source','environment','state','observed_at','rows'])
            || ($value['version'] ?? null) !== 1 || !is_string($value['section'] ?? null) || !isset(self::definitions()[$value['section']])
            || !self::validTime($value['observed_at'] ?? null) || !in_array($value['state'] ?? null, self::STATES, true)
            || !in_array($value['environment'] ?? null, ['production','staging'], true) || !is_array($value['rows'] ?? null) || count($value['rows']) > 250) {
            return self::error('Invalid observation envelope.');
        }
        $definition = self::definitions()[$value['section']];
        if (($value['source'] ?? null) !== $definition['source']) { return self::error('Observation source does not match this section.'); }
        $rows = [];
        foreach ($value['rows'] as $id => $row) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) || !is_array($row) || array_diff(array_keys($row), array_keys($definition['columns']))) { return self::error('Unknown row identifier or field.'); }
            $rows[$id] = [];
            foreach ($definition['columns'] as $key => $type) {
                $cell = $row[$key] ?? null;
                if (!self::validCell($cell, $type)) { return self::error('Invalid observation value for ' . $key . '.'); }
                $rows[$id][$key] = $cell;
                if ($type === 'countries' && is_array($cell)) { sort($rows[$id][$key]); }
            }
            foreach (['handling','transit'] as $range) {
                if (isset($rows[$id][$range.'_min'], $rows[$id][$range.'_max']) && $rows[$id][$range.'_min'] > $rows[$id][$range.'_max']) { return self::error('Invalid delivery range.'); }
            }
            if (isset($rows[$id]['approved_products'], $rows[$id]['total_products']) && $rows[$id]['approved_products'] > $rows[$id]['total_products']) { return self::error('Approved product count exceeds total.'); }
        }
        ksort($rows);
        return ['version'=>1, 'section'=>$value['section'], 'source'=>$definition['source'], 'environment'=>$value['environment'], 'state'=>$value['state'], 'observed_at'=>$value['observed_at'], 'rows'=>$rows];
    }

    private static function validCell($value, string $type): bool
    {
        if ($value === null) { return true; }
        switch ($type) {
            case 'count': return is_int($value) && $value >= 0 && $value <= 100000000000;
            case 'number': return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0 && $value <= 100000000000;
            case 'bool': return is_bool($value);
            case 'time': return self::validTime($value);
            case 'country': return is_string($value) && preg_match('/^[A-Z]{2}$/D', $value);
            case 'countries': return is_array($value) && array_is_list($value) && count($value) <= 250 && count(array_unique($value, SORT_REGULAR)) === count($value) && !array_filter($value, static fn($v)=>!is_string($v) || !preg_match('/^[A-Z]{2}$/D', $v));
            case 'text': return is_string($value) && strlen($value) <= 240 && !preg_match('/[<>@\x00-\x1F]/', $value) && !preg_match('~https?://|bearer\s|(?:token|secret|password|order_id|email)\s*[:=]~i', $value);
        }
        return false;
    }

    private static function validTime($value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        return $date && !($errors && ($errors['warning_count'] || $errors['error_count'])) && $date->format('Y-m-d\TH:i:s\Z') === $value && $date->getTimestamp() <= time() + 60;
    }

    private static function error(string $message): \WP_Error { return new \WP_Error('hp_gmc_submission_observation_invalid', $message); }
}
