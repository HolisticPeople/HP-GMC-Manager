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
        $ship = function_exists('hp_ss_get_google_submit_data_v1') ? hp_ss_get_google_submit_data_v1() : null;
        echo '<div class="wrap hp-gmc-google-submit-data"><h1>' . esc_html__('Google Submit Data', 'hp-gmc-manager') . '</h1>';
        echo '<p>' . esc_html__('Read-only local reporting. Configuration, local generation, and Google receipt are different facts.', 'hp-gmc-manager') . '</p>';
        self::section(__('Merchant and disclosures', 'hp-gmc-manager'), [
            'Merchant ID' => (string) get_option('hp_gmc_merchant_id', '') ?: __('Not configured', 'hp-gmc-manager'),
            'Privacy' => home_url('/privacy-policy-holisticpeople/'), 'Terms' => home_url('/terms-service-holisticpeople/'), 'Returns' => home_url('/return-policy/'),
            'Support' => __('No support submission is asserted by this local report.', 'hp-gmc-manager'),
        ]);
        self::section(__('Delivery configuration', 'hp-gmc-manager'), [
            'Owner' => 'HP ShipStation Rates',
            'Provider report' => is_array($ship) ? wp_json_encode($ship, JSON_UNESCAPED_SLASHES) : __('Unavailable; no provider report was read.', 'hp-gmc-manager'),
        ]);
        self::section(__('Returns and loyalty disclosures', 'hp-gmc-manager'), [
            'Return policy schema' => __('Local canonical schema is present; this is not a Merchant Center receipt.', 'hp-gmc-manager'),
            'Loyalty/review incentive' => __('No reward or incentive is granted for Google reviews.', 'hp-gmc-manager'),
        ]);
        self::section(__('Feed and product identifiers', 'hp-gmc-manager'), [
            'Local merchant feed rows' => (string) ($feed['product_count'] ?? 0),
            'Last locally generated' => (string) ($feed['last_generated'] ?? __('Never', 'hp-gmc-manager')),
            'Google receipt' => __('Not observed by this local report.', 'hp-gmc-manager'),
            'Submitted data shape' => 'id, brand, mpn, gtin, identifier_exists (no customer data)',
        ]);
        self::section(__('Customer Reviews and store widget', 'hp-gmc-manager'), [
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
        $rows = ['Observed at' => (string) $snapshot['observed_at'], 'Scope' => 'US · trailing 30 days · all stores'];
        foreach (($snapshot['metrics'] ?? []) as $name => $metric) { $rows[(string) $name] = wp_json_encode($metric); }
        $rows['Diff from previous snapshot'] = wp_json_encode(StoreQualitySnapshot::diff());
        $rows['History retained'] = (string) count(StoreQualitySnapshot::history()) . ' / 30';
        $rows['Errors'] = wp_json_encode($snapshot['errors'] ?? []);
        self::section(__('Imported Merchant Center snapshot', 'hp-gmc-manager'), $rows);
    }
}
