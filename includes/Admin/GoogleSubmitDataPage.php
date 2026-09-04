<?php
namespace HP_GMC\Admin;

use HP_GMC\Services\ProductDataFeed;
use HP_GMC\Services\StoreQualitySnapshot;
use HP_GMC\Services\GoogleSubmittedSettings;

if (!defined('ABSPATH')) { exit; }

/** Read-only operator view. It never calls Google or changes local state. */
final class GoogleSubmitDataPage
{
    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You do not have permission to view Google Submit Data.', 'hp-gmc-manager')); }
        $feed = ProductDataFeed::getStatus();
        $quality = StoreQualitySnapshot::current();
        try { $ship = function_exists('hp_ss_get_google_submit_data_v1') ? hp_ss_get_google_submit_data_v1() : null; } catch (\Throwable $error) { $ship = null; }
        echo '<div class="wrap hp-gmc-google-submit-data"><h1>' . esc_html__('Google Submit Data', 'hp-gmc-manager') . '</h1>';
        echo '<p>' . esc_html__('Read-only local reporting. Configuration, local generation, and Google receipt are different facts.', 'hp-gmc-manager') . '</p>';
        $submitted = GoogleSubmittedSettings::current();
        self::section(__('Merchant and disclosures · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Merchant ID' => (string) get_option('hp_gmc_merchant_id', '') ?: __('Not configured', 'hp-gmc-manager'),
            'Privacy' => home_url('/privacy-policy-holisticpeople/'), 'Terms' => home_url('/terms-service-holisticpeople/'), 'Returns' => home_url('/return-policy/'),
            'Support' => $submitted ? $submitted['support']['url'] . ' · ' . $submitted['support']['email'] . ' · ' . $submitted['support']['phone'] . ' · observed ' . $submitted['observed_at'] : 'No imported Google support observation',
        ]);
        self::delivery($ship);
        self::section(__('Returns and loyalty disclosures · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Return policy' => $submitted ? 'Policy ' . $submitted['returns']['policy_id'] . ' · ' . $submitted['returns']['status'] . ' · ' . $submitted['returns']['days'] . ' days · ' . $submitted['returns']['cost'] . ' · products ' . ($submitted['returns']['products'] ?? 'not observed') . ' · observed ' . $submitted['observed_at'] : 'No imported Google return observation',
            'Loyalty submitted state' => $submitted ? $submitted['loyalty']['status'] : 'No imported Google loyalty observation',
        ]);
        self::section(__('Feed and product identifiers · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Local merchant feed rows' => (string) ($feed['product_count'] ?? 0),
            'Last locally generated' => (string) ($feed['last_generated'] ?? __('Never', 'hp-gmc-manager')),
            'Google receipt' => __('Not observed by this local report.', 'hp-gmc-manager'),
            'Feed fields' => implode(', ', ProductDataFeed::getSubmittedFieldNames()) . ' (no customer data)',
        ]);
        self::section(__('Customer Reviews and store widget · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Survey opt-in' => (string) get_option('hp_gmc_customer_reviews_enabled', 'disabled'),
            'Store widget' => (string) get_option('hp_gmc_store_widget_enabled', 'disabled'),
            'Imported widget evidence' => self::widgetEvidence(),
            'Widget receipt' => __('Not observed. Script load or start attempt is not widget visibility or Google receipt.', 'hp-gmc-manager'),
        ]);
        $reviews = StoreQualitySnapshot::reviewsObservation();
        self::section(__('Google review observations · owner Google Merchant Center', 'hp-gmc-manager'), $reviews ? [
            'GCR status' => $reviews['gcr_status'], 'Survey opt-ins' => $reviews['survey_optins'] === null ? 'No data' : (string) $reviews['survey_optins'], 'Surveys offered' => $reviews['surveys_offered'] === null ? 'No data' : (string) $reviews['surveys_offered'], 'Survey responses' => $reviews['survey_responses'] === null ? 'No data' : (string) $reviews['survey_responses'], 'Product reviews status' => $reviews['product_reviews_status'], 'Matched GTINs' => $reviews['matched_gtins'] === null ? 'No data' : (string) $reviews['matched_gtins'], 'Product survey responses' => $reviews['product_survey_responses'] === null ? 'No data' : (string) $reviews['product_survey_responses'], 'Observed at' => $reviews['observed_at'],
        ] : ['Status' => 'No imported Merchant Center observation.']);
        self::quality($quality);
        echo '</div>';
    }

    /** @param array<string,string> $rows */
    private static function section(string $title, array $rows): void
    {
        echo '<h2>' . esc_html($title) . '</h2><table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) { echo '<tr><th>' . esc_html($label) . '</th><td><code>' . esc_html($value) . '</code></td></tr>'; }
        echo '</tbody></table>';
    }

    /** @param array<string,mixed>|null $snapshot */
    private static function quality(?array $snapshot): void
    {
        echo '<h2>' . esc_html__('Store Quality observations', 'hp-gmc-manager') . '</h2>';
        $fresh = StoreQualitySnapshot::freshness();
        if (!$snapshot) { self::section(__('Store Quality observations', 'hp-gmc-manager'), ['Freshness'=>$fresh['status'],'Last import error'=>(string)($fresh['error']?:'None'),'History'=>'None']); return; }
        $rows = ['Observed at' => (string) $snapshot['observed_at'], 'Source URL' => (string) $snapshot['source']['url'], 'Scope' => 'US · trailing 30 days · all stores', 'Freshness' => $fresh['status'] . ($fresh['age_seconds'] !== null ? ' (' . $fresh['age_seconds'] . ' seconds old)' : ''), 'Last import error' => (string) ($fresh['error'] ?: 'None')];
        foreach (($snapshot['metrics'] ?? []) as $name => $metric) { $rows[(string) $name] = self::metric($metric); }
        $rows['Changed metrics'] = implode(', ', array_keys(StoreQualitySnapshot::diff())) ?: 'None';
        $history = StoreQualitySnapshot::history(); $rows['History retained'] = implode('; ', array_map(static fn($row) => $row['observed_at'] . ' delivery ' . self::metric($row['metrics']['delivery']), $history)) ?: 'None';
        $rows['Observation errors'] = implode('; ', $snapshot['errors'] ?? []) ?: 'None';
        self::section(__('Imported Merchant Center snapshot', 'hp-gmc-manager'), $rows);
    }

    private static function metric(array $metric): string
    {
        $value = array_key_exists('value', $metric) && $metric['value'] !== null ? (string) $metric['value'] : 'No data';
        if (isset($metric['denominator'])) { $value .= '/' . $metric['denominator']; }
        if (isset($metric['unit'])) { $value .= ' ' . $metric['unit']; }
        if (!empty($metric['providers'])) { $value .= ' (' . implode(', ', $metric['providers']) . ')'; }
        return $value . ' · ' . ($metric['rating'] ?? 'unknown');
    }

    private static function widgetEvidence(): string
    {
        $value = StoreQualitySnapshot::widgetObservation();
        return !$value ? 'Not observed' : ($value['status'] ?? 'not_observed') . ' · ' . ($value['observed_at'] ?? 'unknown time') . ' · Google receipt: not observed';
    }

    private static function delivery($ship): void
    {
        $rows = ['Provider status' => is_array($ship) ? (string) ($ship['status'] ?? 'unknown') : 'Unavailable'];
        if (is_array($ship)) {
            $transit = $ship['configuration']['transit_rules'] ?? []; $handling = $ship['configuration']['handling'] ?? [];
            $rows['Valid rules'] = (string) ($transit['valid_rule_count'] ?? 0); $rows['Rejected rules'] = (string) ($transit['rejected_rule_count'] ?? 0); $rows['Ambiguous rules']=(string)($transit['ambiguous_rule_count']??0);
            $rows['Handling'] = isset($handling['max_days']) ? $handling['max_days'] . ' days · ' . ($handling['calendar'] ?? '') . ' · cutoff ' . ($handling['cutoff'] ?? '') . ' · ' . ($handling['timezone'] ?? '') : 'Unavailable';
            $rows['Limitations'] = implode(', ', array_map('strval', $ship['limitations'] ?? [])) ?: 'None reported';
            foreach (($transit['rules'] ?? []) as $i => $rule) { if (is_array($rule)) { $rows['Service ' . ($i + 1)] = implode(' · ', array_filter([(string) ($rule['service_key'] ?? ''), (string) ($rule['country'] ?? ''), implode(',', (array) ($rule['states'] ?? [])), isset($rule['max_days']) ? $rule['max_days'] . ' days' : '', (string) ($rule['day_type'] ?? ''), (string) ($rule['calendar'] ?? '')])); } }
        }
        self::section(__('Delivery configuration · owner HP ShipStation Rates', 'hp-gmc-manager'), $rows);
    }
}
