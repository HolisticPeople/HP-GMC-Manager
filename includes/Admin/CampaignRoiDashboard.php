<?php
namespace HP_GMC\Admin;

use HP_GMC\Abilities\GA4Analytics;
use HP_GMC\Abilities\GoogleAdsReporting;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign ROI Dashboard.
 *
 * Combines Google Ads spend, GA4 conversion data, and GMC product status
 * into a unified view of marketing performance and return on investment.
 *
 * @since 1.25.0
 */
class CampaignRoiDashboard
{
    /**
     * Render the Campaign ROI dashboard.
     */
    public static function render(): void
    {
        $dateRange = isset($_GET['range']) ? sanitize_text_field($_GET['range']) : 'last_30_days';

        // Fetch data from each source (with error handling)
        $adsData = self::fetchAdsData($dateRange);
        $ga4Data = self::fetchGA4Data($dateRange);
        $gmcData = self::fetchGMCData();

        // Map ads date range to GA4 format
        $ga4Range = str_replace(['LAST_', '_DAYS'], ['last_', '_days'], strtolower($dateRange));

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e('Campaign ROI Dashboard', 'hp-gmc-manager'); ?>
                <span style="font-size: 12px; color: #787c82; vertical-align: middle; margin-left: 8px;">
                    v<?php echo esc_html(HP_GMC_VERSION); ?>
                </span>
            </h1>

            <!-- Filters -->
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 12px 16px; margin-bottom: 20px; display: flex; gap: 16px; align-items: center;">
                <form method="get" style="display: flex; gap: 12px; align-items: center;">
                    <input type="hidden" name="page" value="hp-gmc-campaign-roi">
                    <label for="range"><strong><?php esc_html_e('Period:', 'hp-gmc-manager'); ?></strong></label>
                    <select name="range" id="range" onchange="this.form.submit()">
                        <option value="last_7_days" <?php selected($dateRange, 'last_7_days'); ?>><?php esc_html_e('Last 7 days', 'hp-gmc-manager'); ?></option>
                        <option value="last_14_days" <?php selected($dateRange, 'last_14_days'); ?>><?php esc_html_e('Last 14 days', 'hp-gmc-manager'); ?></option>
                        <option value="last_30_days" <?php selected($dateRange, 'last_30_days'); ?>><?php esc_html_e('Last 30 days', 'hp-gmc-manager'); ?></option>
                    </select>
                </form>
                <div style="margin-left: auto; font-size: 12px; color: #787c82;">
                    <?php esc_html_e('Data from Google Ads + GA4 + GMC', 'hp-gmc-manager'); ?>
                </div>
            </div>

            <!-- Top-Level KPI Cards -->
            <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px;">
                <?php
                $totalSpend = $adsData['summary']['total_spend'] ?? 0;
                $totalRevenue = $adsData['summary']['total_revenue'] ?? 0;
                $totalConversions = $adsData['summary']['total_conversions'] ?? 0;
                $overallRoas = $totalSpend > 0 ? round(($totalRevenue / $totalSpend), 2) : 0;
                $costPerConv = $totalConversions > 0 ? round($totalSpend / $totalConversions, 2) : 0;

                $kpis = [
                    ['label' => 'Total Ad Spend', 'value' => '$' . number_format($totalSpend, 2), 'color' => '#d63638', 'icon' => 'dashicons-money'],
                    ['label' => 'Revenue (Ads)', 'value' => '$' . number_format($totalRevenue, 2), 'color' => '#00a32a', 'icon' => 'dashicons-chart-line'],
                    ['label' => 'ROAS', 'value' => $overallRoas . 'x', 'color' => $overallRoas >= 3 ? '#00a32a' : ($overallRoas >= 1 ? '#dba617' : '#d63638'), 'icon' => 'dashicons-performance'],
                    ['label' => 'Conversions', 'value' => number_format($totalConversions, 0), 'color' => '#2271b1', 'icon' => 'dashicons-yes-alt'],
                    ['label' => 'Cost / Conv', 'value' => '$' . number_format($costPerConv, 2), 'color' => '#7e5bef', 'icon' => 'dashicons-tag'],
                ];

                foreach ($kpis as $kpi):
                ?>
                    <div style="background: #fff; border: 1px solid #ccd0d4; border-top: 4px solid <?php echo esc_attr($kpi['color']); ?>; padding: 16px; text-align: center;">
                        <span class="dashicons <?php echo esc_attr($kpi['icon']); ?>" style="font-size: 24px; color: <?php echo esc_attr($kpi['color']); ?>; width: 24px; height: 24px;"></span>
                        <h3 style="margin: 8px 0 4px; font-size: 24px; line-height: 1;"><?php echo esc_html($kpi['value']); ?></h3>
                        <div style="font-size: 12px; color: #50575e; font-weight: 600;"><?php echo esc_html($kpi['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">

                <!-- Campaign Performance Table -->
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 16px;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Campaign Performance', 'hp-gmc-manager'); ?></h2>
                    <?php if (!empty($adsData['error'])): ?>
                        <div class="notice notice-warning inline" style="margin: 0;">
                            <p><?php echo esc_html($adsData['error']); ?></p>
                            <p class="description"><?php esc_html_e('Configure Google Ads credentials in GMC Manager > Settings.', 'hp-gmc-manager'); ?></p>
                        </div>
                    <?php elseif (empty($adsData['campaigns'])): ?>
                        <p style="color: #787c82;"><?php esc_html_e('No campaign data for this period.', 'hp-gmc-manager'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Campaign', 'hp-gmc-manager'); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('Spend', 'hp-gmc-manager'); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('Clicks', 'hp-gmc-manager'); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('Conv', 'hp-gmc-manager'); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('Revenue', 'hp-gmc-manager'); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('ROAS', 'hp-gmc-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($adsData['campaigns'] ?? [], 0, 15) as $campaign): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($campaign['name']); ?></strong>
                                            <div style="font-size: 11px; color: #787c82;"><?php echo esc_html($campaign['channel']); ?></div>
                                        </td>
                                        <td style="text-align: right;">$<?php echo number_format($campaign['cost'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($campaign['clicks']); ?></td>
                                        <td style="text-align: right;"><?php echo number_format($campaign['conversions'], 1); ?></td>
                                        <td style="text-align: right;">$<?php echo number_format($campaign['conversion_value'], 2); ?></td>
                                        <td style="text-align: right;">
                                            <?php
                                            $roas = $campaign['roas'] / 100;
                                            $roasColor = $roas >= 3 ? '#00a32a' : ($roas >= 1 ? '#dba617' : '#d63638');
                                            ?>
                                            <span style="color: <?php echo $roasColor; ?>; font-weight: bold;">
                                                <?php echo number_format($roas, 1); ?>x
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- GMC Product Status -->
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 16px;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('GMC Product Status', 'hp-gmc-manager'); ?></h2>
                    <?php if (!empty($gmcData['error'])): ?>
                        <div class="notice notice-warning inline" style="margin: 0;">
                            <p><?php echo esc_html($gmcData['error']); ?></p>
                        </div>
                    <?php else: ?>
                        <?php
                        $approved = (int) ($gmcData['approved'] ?? 0);
                        $disapproved = (int) ($gmcData['disapproved'] ?? 0);
                        $pending = (int) ($gmcData['pending'] ?? 0);
                        $total = $approved + $disapproved + $pending;
                        $approvalRate = $total > 0 ? round(($approved / $total) * 100) : 0;
                        ?>
                        <div style="text-align: center; padding: 20px 0;">
                            <div style="font-size: 48px; font-weight: bold; color: <?php echo $approvalRate >= 90 ? '#00a32a' : ($approvalRate >= 70 ? '#dba617' : '#d63638'); ?>;">
                                <?php echo $approvalRate; ?>%
                            </div>
                            <div style="font-size: 13px; color: #50575e; font-weight: 600;"><?php esc_html_e('Approval Rate', 'hp-gmc-manager'); ?></div>
                        </div>
                        <table class="widefat" style="margin: 0;">
                            <tr>
                                <td><span style="color: #00a32a;">&#9679;</span> <?php esc_html_e('Approved', 'hp-gmc-manager'); ?></td>
                                <td style="text-align: right; font-weight: bold;"><?php echo number_format($approved); ?></td>
                            </tr>
                            <tr>
                                <td><span style="color: #d63638;">&#9679;</span> <?php esc_html_e('Disapproved', 'hp-gmc-manager'); ?></td>
                                <td style="text-align: right; font-weight: bold;"><?php echo number_format($disapproved); ?></td>
                            </tr>
                            <tr>
                                <td><span style="color: #dba617;">&#9679;</span> <?php esc_html_e('Pending', 'hp-gmc-manager'); ?></td>
                                <td style="text-align: right; font-weight: bold;"><?php echo number_format($pending); ?></td>
                            </tr>
                        </table>
                        <?php if ($disapproved > 0): ?>
                            <p style="margin-top: 12px;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=hp-gmc-manager&tab=issues')); ?>" class="button button-small">
                                    <?php esc_html_e('View Issues', 'hp-gmc-manager'); ?> &rarr;
                                </a>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- GA4 Traffic Sources -->
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 16px; margin-bottom: 24px;">
                <h2 style="margin-top: 0;"><?php esc_html_e('GA4 Traffic Sources (Funnel Pages)', 'hp-gmc-manager'); ?></h2>
                <?php if (!empty($ga4Data['error'])): ?>
                    <div class="notice notice-warning inline" style="margin: 0;">
                        <p><?php echo esc_html($ga4Data['error']); ?></p>
                        <p class="description"><?php esc_html_e('Configure GA4 property ID in GMC Manager > Settings.', 'hp-gmc-manager'); ?></p>
                    </div>
                <?php elseif (empty($ga4Data['sources'])): ?>
                    <p style="color: #787c82;"><?php esc_html_e('No traffic data for this period.', 'hp-gmc-manager'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Source / Medium', 'hp-gmc-manager'); ?></th>
                                <th><?php esc_html_e('Campaign', 'hp-gmc-manager'); ?></th>
                                <th style="text-align: right;"><?php esc_html_e('Sessions', 'hp-gmc-manager'); ?></th>
                                <th style="text-align: right;"><?php esc_html_e('Users', 'hp-gmc-manager'); ?></th>
                                <th style="text-align: right;"><?php esc_html_e('Purchases', 'hp-gmc-manager'); ?></th>
                                <th style="text-align: right;"><?php esc_html_e('Revenue', 'hp-gmc-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($ga4Data['sources'], 0, 20) as $source): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($source['source']); ?></strong>
                                        <span style="color: #787c82;"> / <?php echo esc_html($source['medium']); ?></span>
                                    </td>
                                    <td><?php echo esc_html($source['campaign'] === '(not set)' ? '—' : $source['campaign']); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($source['sessions']); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($source['users']); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($source['purchases']); ?></td>
                                    <td style="text-align: right;">
                                        <?php if ($source['revenue'] > 0): ?>
                                            $<?php echo number_format($source['revenue'], 2); ?>
                                        <?php else: ?>
                                            <span style="color: #ccc;">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Setup Instructions (shown if any data source is missing) -->
            <?php if (!empty($adsData['error']) || !empty($ga4Data['error']) || !empty($gmcData['error'])): ?>
            <div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1; padding: 16px;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Setup Guide', 'hp-gmc-manager'); ?></h2>
                <ol>
                    <li>
                        <strong><?php esc_html_e('Google Service Account:', 'hp-gmc-manager'); ?></strong>
                        <?php esc_html_e('Create a service account in Google Cloud Console with the following API access:', 'hp-gmc-manager'); ?>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li><?php esc_html_e('Google Analytics Data API (for GA4)', 'hp-gmc-manager'); ?></li>
                            <li><?php esc_html_e('Google Ads API (for campaign data)', 'hp-gmc-manager'); ?></li>
                            <li><?php esc_html_e('Content API for Shopping (for GMC)', 'hp-gmc-manager'); ?></li>
                        </ul>
                    </li>
                    <li>
                        <strong><?php esc_html_e('GA4 Setup:', 'hp-gmc-manager'); ?></strong>
                        <?php esc_html_e('Add the service account email as a Viewer in GA4 Admin > Property Access Management.', 'hp-gmc-manager'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Google Ads Setup:', 'hp-gmc-manager'); ?></strong>
                        <?php esc_html_e('Get a developer token from Google Ads > Tools > API Center. Grant the service account access.', 'hp-gmc-manager'); ?>
                    </li>
                    <li>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=hp-gmc-settings')); ?>" class="button button-primary">
                            <?php esc_html_e('Configure in Settings', 'hp-gmc-manager'); ?> &rarr;
                        </a>
                    </li>
                </ol>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Fetch Google Ads campaign data.
     *
     * @param string $dateRange
     * @return array Campaign data or error
     */
    private static function fetchAdsData(string $dateRange): array
    {
        try {
            $result = GoogleAdsReporting::campaignPerformance([
                'date_range' => strtoupper($dateRange),
            ]);

            if (!$result['success']) {
                return ['error' => $result['error'], 'campaigns' => [], 'summary' => []];
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'campaigns' => [], 'summary' => []];
        }
    }

    /**
     * Fetch GA4 traffic source data.
     *
     * @param string $dateRange
     * @return array Traffic data or error
     */
    private static function fetchGA4Data(string $dateRange): array
    {
        try {
            $result = GA4Analytics::trafficSources([
                'date_range' => $dateRange,
            ]);

            if (!$result['success']) {
                return ['error' => $result['error'], 'sources' => []];
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'sources' => []];
        }
    }

    /**
     * Fetch GMC product status counts from the local tracking table.
     *
     * @return array Product status counts or error
     */
    private static function fetchGMCData(): array
    {
        global $wpdb;

        try {
            $table = $wpdb->prefix . 'hp_gmc_product_status';

            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                return ['error' => 'GMC status table not found.'];
            }

            $approved = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'approved'"
            );
            $disapproved = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'disapproved'"
            );
            $pending = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE status NOT IN ('approved', 'disapproved')"
            );

            return [
                'approved'    => $approved,
                'disapproved' => $disapproved,
                'pending'     => $pending,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
