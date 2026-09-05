<?php
namespace HP_GMC\Admin;

use HP_GMC\Services\ProductDataFeed;
use HP_GMC\Services\StoreQualitySnapshot;
use HP_GMC\Services\GoogleSubmittedSettings;
use HP_GMC\Services\GoogleSubmissionObservation;

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
        echo '<p>' . esc_html__('Read-only reporting by owner and evidence source. Proposed → staged → submitted → accepted → visible → Google-awarded are separate facts. No data means unknown.', 'hp-gmc-manager') . '</p>';
        echo '<style>.hp-gmc-google-submit-data table{table-layout:fixed}.hp-gmc-google-submit-data th{width:28%;text-align:left}.hp-gmc-google-submit-data td,.hp-gmc-google-submit-data th,.hp-gmc-google-submit-data code{overflow-wrap:anywhere;white-space:normal}.hp-gmc-google-submit-data .hp-gmc-observation-scroll{overflow-x:auto}.hp-gmc-google-submit-data .hp-gmc-observation-scroll table{table-layout:auto;min-width:680px}.hp-gmc-google-submit-data .hp-gmc-observation-scroll th{width:auto}.hp-gmc-google-submit-data details{margin:12px 0}</style>';
        $submitted = GoogleSubmittedSettings::current();
        self::section(__('Merchant and disclosures · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Merchant ID' => (string) get_option('hp_gmc_merchant_id', '') ?: __('Not configured', 'hp-gmc-manager'),
            'Privacy' => home_url('/privacy-policy-holisticpeople/'), 'Terms' => home_url('/terms-service-holisticpeople/'), 'Returns' => home_url('/return-policy/'),
            'Support' => $submitted ? $submitted['support']['url'] . ' · ' . $submitted['support']['email'] . ' · ' . $submitted['support']['phone'] . ' · observed ' . $submitted['observed_at'] : 'No imported Google support observation',
        ]);
        self::health('submitted', $submitted['observed_at'] ?? null);
        self::delivery($ship);
        foreach (GoogleSubmissionObservation::definitions() as $scope => $definition) { self::observation($scope, $definition); }
        $legacyDisclosures = [];
        if (!GoogleSubmissionObservation::current('returns')) {
            $legacyDisclosures['Return policy'] = $submitted ? 'Policy ' . $submitted['returns']['policy_id'] . ' · ' . $submitted['returns']['status'] . ' · ' . ($submitted['returns']['days'] ?? 'No data') . ' days · ' . $submitted['returns']['cost'] . ' · products ' . ($submitted['returns']['products'] ?? 'not observed') . ' · observed ' . $submitted['observed_at'] : 'No imported Google return observation';
        }
        if (!GoogleSubmissionObservation::current('loyalty')) {
            $legacyDisclosures['Loyalty submitted state'] = $submitted ? $submitted['loyalty']['status'] : 'No imported Google loyalty observation';
        }
        if ($legacyDisclosures) { self::section(__('Returns and loyalty disclosures · owner HP-GMC Manager', 'hp-gmc-manager'), $legacyDisclosures); }
        self::section(__('Feed and product identifiers · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Local merchant feed rows' => empty($feed['last_generated']) ? 'No data' : (string) ($feed['product_count'] ?? 'No data'),
            'Last locally generated' => (string) ($feed['last_generated'] ?? __('Never', 'hp-gmc-manager')),
            'Google receipt' => __('Not observed by this local report.', 'hp-gmc-manager'),
            'Feed fields' => implode(', ', ProductDataFeed::getSubmittedFieldNames()) . ' (no customer data)',
        ]);
        self::section(__('Customer Reviews and store widget · owner HP-GMC Manager', 'hp-gmc-manager'), [
            'Survey opt-in' => (string) get_option('hp_gmc_customer_reviews_enabled', 'disabled'),
            'Store widget' => (string) get_option('hp_gmc_store_widget_enabled', 'disabled'),
            'Environment gate' => \HP_GMC\Services\CustomerReviewsEnvironment::isOutwardSilent() ? 'Outward silent: Google scripts and surveys suppressed' : 'Production host; each feature still requires its own enabled setting',
            'Imported widget evidence' => self::widgetEvidence(),
            'Widget receipt' => __('Not observed. Script load or start attempt is not widget visibility or Google receipt.', 'hp-gmc-manager'),
        ]);
        self::health('widget', StoreQualitySnapshot::widgetObservation()['observed_at'] ?? null);
        $reviews = StoreQualitySnapshot::reviewsObservation();
        self::section(__('Google review observations · owner Google Merchant Center', 'hp-gmc-manager'), $reviews ? [
            'GCR status' => $reviews['gcr_status'], 'Survey opt-ins' => $reviews['survey_optins'] === null ? 'No data' : (string) $reviews['survey_optins'], 'Surveys offered' => $reviews['surveys_offered'] === null ? 'No data' : (string) $reviews['surveys_offered'], 'Survey responses' => $reviews['survey_responses'] === null ? 'No data' : (string) $reviews['survey_responses'], 'Product reviews status' => $reviews['product_reviews_status'], 'Matched GTINs' => $reviews['matched_gtins'] === null ? 'No data' : (string) $reviews['matched_gtins'], 'Product survey responses' => $reviews['product_survey_responses'] === null ? 'No data' : (string) $reviews['product_survey_responses'], 'Observed at' => $reviews['observed_at'],
        ] : ['Status' => 'No imported Merchant Center observation.']);
        self::health('reviews', $reviews['observed_at'] ?? null);
        $reviewHistory = StoreQualitySnapshot::reviewsHistory();
        self::section('Google review observation history', ['Previous observations'=>implode(', ', array_column(array_slice($reviewHistory, 0, 5), 'observed_at')) ?: 'None']);
        self::quality($quality);
        echo '</div>';
    }

    private static function health(string $scope, ?string $observedAt): void
    {
        $health = GoogleSubmissionObservation::health($scope, $observedAt);
        echo '<p>' . esc_html('Freshness: ' . $health['freshness'] . ' · Last successful observation: ' . ($health['last_success'] ?? 'Never') . ' · Last error: ' . ($health['last_error'] ?? 'None recorded') . ($health['error_at'] ? ' at ' . $health['error_at'] : '')) . '</p>';
    }

    private static function observation(string $scope, array $definition): void
    {
        echo '<h2>' . esc_html($definition['title'] . ' · owner ' . $definition['owner']) . '</h2>';
        $value = GoogleSubmissionObservation::current($scope);
        self::health($scope, $value['observed_at'] ?? null);
        if (str_starts_with($definition['source'], 'https://')) {
            echo '<p><a href="' . esc_url($definition['source']) . '" target="_blank" rel="noopener noreferrer">' . esc_html('Open evidence source') . '</a></p>';
        } else { echo '<p>' . esc_html('Source: existing local shipment records and operator-reviewed carrier guidance.') . '</p>'; }
        if (!$value) { echo '<p>' . esc_html('No imported observation. Configuration does not prove Google receipt.') . '</p>'; return; }
        $changes = GoogleSubmissionObservation::changedRows($scope);
        $history = GoogleSubmissionObservation::history($scope);
        self::section('Evidence', ['State'=>$value['state'], 'Observed environment'=>$value['environment'], 'Changed rows'=>$history ? (implode(', ', $changes) ?: 'None compared with previous observation') : 'No previous observation',
            'Previous observations'=>implode(', ', array_column(array_slice($history, 0, 5), 'observed_at')) ?: 'None']);
        echo '<details open><summary>' . esc_html(count($value['rows']) . ' observed rows') . '</summary><div class="hp-gmc-observation-scroll" tabindex="0" role="region" aria-label="' . esc_attr($definition['title']) . '"><table class="widefat striped"><thead><tr><th scope="col">Item</th>';
        foreach ($definition['columns'] as $name => $type) { echo '<th scope="col">' . esc_html(ucwords(str_replace('_', ' ', $name))) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($value['rows'] as $id => $row) {
            echo '<tr><th scope="row">' . esc_html($id) . '</th>';
            foreach ($definition['columns'] as $name => $type) {
                $cell = $row[$name];
                $text = $cell === null ? 'No data' : (is_bool($cell) ? ($cell ? 'Yes' : 'No') : (is_array($cell) ? implode(', ', $cell) : (string)$cell));
                echo '<td>' . esc_html($text) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></details>';
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
        self::health('quality', $snapshot['observed_at'] ?? null);
        if (!$snapshot) { self::section(__('Store Quality observations', 'hp-gmc-manager'), ['Freshness'=>$fresh['status'],'Last import error'=>(string)($fresh['error']?:'None'),'History'=>'None']); return; }
        $rows = ['Observed at' => (string) $snapshot['observed_at'], 'Source URL' => (string) $snapshot['source']['url'], 'Scope' => 'US · trailing 30 days · all stores', 'Freshness' => $fresh['status'] . ($fresh['age_seconds'] !== null ? ' (' . $fresh['age_seconds'] . ' seconds old)' : ''), 'Last import error' => (string) ($fresh['error'] ?: 'None')];
        foreach (($snapshot['metrics'] ?? []) as $name => $metric) { $rows[(string) $name] = self::metric($metric, $name); }
        $history = StoreQualitySnapshot::history();
        $rows['Changed metrics'] = $history ? (implode(', ', array_keys(StoreQualitySnapshot::diff())) ?: 'None') : 'No previous observation';
        $rows['History retained'] = implode('; ', array_map(static fn($row) => $row['observed_at'] . ' overall ' . self::metric($row['metrics']['overall_quality'], 'overall_quality') . ' · ' . implode(', ', array_map(static fn($name,$metric) => $name . ' ' . self::metric($metric, $name), array_keys($row['metrics']), $row['metrics'])), $history)) ?: 'None';
        foreach (StoreQualitySnapshot::diff() as $name => $change) { $rows['Changed ' . $name] = self::metric($change['before'], $name) . ' → ' . self::metric($change['after'], $name); }
        $rows['Observation errors'] = implode('; ', $snapshot['errors'] ?? []) ?: 'None';
        self::section(__('Imported Merchant Center snapshot', 'hp-gmc-manager'), $rows);
    }

    private static function metric(array $metric, string $name): string
    {
        if ($name === 'overall_quality') { return ucfirst($metric['rating'] ?? 'unknown'); }
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
        $rows = ['Purpose'=>'Local Google Customer Reviews invitation timing. These rules do not submit shipping speeds to Merchant Center.', 'Provider status' => is_array($ship) ? (string) ($ship['status'] ?? 'unknown') : 'Unavailable'];
        if (is_array($ship)) {
            $transit = $ship['configuration']['transit_rules'] ?? []; $handling = $ship['configuration']['handling'] ?? [];
            $rows['Valid rules'] = (string) ($transit['valid_rule_count'] ?? 0); $rows['Rejected rules'] = (string) ($transit['rejected_rule_count'] ?? 0); $rows['Ambiguous rules']=(string)($transit['ambiguous_rule_count']??0);
            $rows['Handling'] = isset($handling['max_days']) ? $handling['max_days'] . ' days · ' . ($handling['calendar'] ?? '') . ' · cutoff ' . ($handling['cutoff'] ?? '') . ' · ' . ($handling['timezone'] ?? '') : 'Unavailable';
            $rows['Limitations'] = implode(', ', array_map('strval', $ship['limitations'] ?? [])) ?: 'None reported';
            foreach (($transit['rules'] ?? []) as $i => $rule) { if (is_array($rule)) { $rows['Service ' . ($i + 1)] = implode(' · ', array_filter([(string) ($rule['service_key'] ?? ''), (string) ($rule['country'] ?? ''), implode(',', (array) ($rule['states'] ?? [])), isset($rule['max_days']) ? $rule['max_days'] . ' days' : '', (string) ($rule['day_type'] ?? ''), (string) ($rule['calendar'] ?? '')])); } }
        }
        self::section(__('Review invitation delivery configuration · owner HP ShipStation Rates', 'hp-gmc-manager'), $rows);
    }
}
