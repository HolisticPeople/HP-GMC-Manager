<?php
namespace HP_GMC\Admin;

use HP_GMC\Services\ProductDataFeed;
use HP_GMC\Services\StoreQualitySnapshot;

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
        self::section(__('Merchant and disclosures · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Merchant ID' => (string) get_option('hp_gmc_merchant_id', '') ?: __('Not configured', 'hp-gmc-manager'),
            'Privacy' => home_url('/privacy-policy-holisticpeople/'), 'Terms' => home_url('/terms-service-holisticpeople/'), 'Returns' => home_url('/return-policy/'),
            'Support' => __('No support submission is asserted by this local report.', 'hp-gmc-manager'),
        ]);
        self::section(__('Delivery configuration · owner HP ShipStation Rates', 'hp-gmc-manager'), [
            'Owner' => 'HP ShipStation Rates',
            'Provider status' => is_array($ship) ? (string) ($ship['status'] ?? 'unknown') : __('Unavailable', 'hp-gmc-manager'),
            'Transit rules' => is_array($ship) ? (string) (($ship['configuration']['transit_rules']['valid_rules'] ?? 0)) : __('Unavailable', 'hp-gmc-manager'),
        ]);
        self::section(__('Returns and loyalty disclosures · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Return policy URL' => home_url('/return-policy/'),
            'Loyalty submitted state' => __('No locally stored Merchant Center loyalty submission.', 'hp-gmc-manager'),
        ]);
        self::section(__('Feed and product identifiers · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Local merchant feed rows' => (string) ($feed['product_count'] ?? 0),
            'Last locally generated' => (string) ($feed['last_generated'] ?? __('Never', 'hp-gmc-manager')),
            'Google receipt' => __('Not observed by this local report.', 'hp-gmc-manager'),
            'Submitted data shape' => 'id, brand, mpn, gtin, identifier_exists (no customer data)',
        ]);
        self::section(__('Customer Reviews and store widget · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Survey opt-in' => (string) get_option('hp_gmc_customer_reviews_enabled', 'disabled'),
            'Store widget' => (string) get_option('hp_gmc_store_widget_enabled', 'disabled'),
            'Imported widget evidence' => wp_json_encode(StoreQualitySnapshot::widgetObservation() ?: ['status' => 'not_observed', 'google_receipt' => 'not_observed']),
            'Widget receipt' => __('Not observed. Script load or start attempt is not widget visibility or Google receipt.', 'hp-gmc-manager'),
        ]);
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
        if (!$snapshot) { echo '<p>' . esc_html__('No imported observation. This page does not contact Google.', 'hp-gmc-manager') . '</p>'; return; }
        $fresh = StoreQualitySnapshot::freshness();
        $rows = ['Observed at' => (string) $snapshot['observed_at'], 'Source URL' => (string) $snapshot['source']['url'], 'Scope' => 'US · trailing 30 days · all stores', 'Freshness' => $fresh['status'] . ($fresh['age_seconds'] !== null ? ' (' . $fresh['age_seconds'] . ' seconds old)' : ''), 'Last import error' => (string) ($fresh['error'] ?: 'None')];
        foreach (($snapshot['metrics'] ?? []) as $name => $metric) { $rows[(string) $name] = self::metric($metric); }
        $rows['Changed metrics'] = implode(', ', array_keys(StoreQualitySnapshot::diff())) ?: 'None';
        $rows['History retained'] = (string) count(StoreQualitySnapshot::history()) . ' / 30';
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
}
