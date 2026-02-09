<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\GoogleApiClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Ads Reporting MCP Abilities.
 *
 * Provides campaign performance, funnel-campaign mapping, and budget status
 * via the Google Ads API v18 (REST).
 *
 * @since 1.23.0
 * @see https://developers.google.com/google-ads/api/rest/reference/rest
 */
class GoogleAdsReporting
{
    private const SCOPE = 'https://www.googleapis.com/auth/adwords';
    private const API_VERSION = 'v18';

    /**
     * Campaign performance report: clicks, impressions, cost, conversions.
     *
     * @param array $params {date_range?: string, campaign_name?: string}
     * @return array Campaign metrics
     */
    public static function campaignPerformance(array $params): array
    {
        try {
            $customerId = GoogleApiClient::getAdsCustomerId();
            $dateRange = self::parseDateRange($params['date_range'] ?? 'LAST_30_DAYS');
            $campaignFilter = $params['campaign_name'] ?? '';

            $query = "SELECT "
                . "campaign.name, "
                . "campaign.id, "
                . "campaign.status, "
                . "campaign.advertising_channel_type, "
                . "metrics.impressions, "
                . "metrics.clicks, "
                . "metrics.cost_micros, "
                . "metrics.conversions, "
                . "metrics.conversions_value, "
                . "metrics.cost_per_conversion, "
                . "metrics.average_cpc, "
                . "metrics.ctr, "
                . "metrics.interaction_rate "
                . "FROM campaign "
                . "WHERE segments.date DURING {$dateRange} "
                . "AND campaign.status != 'REMOVED' ";

            if (!empty($campaignFilter)) {
                $query .= "AND campaign.name LIKE '%{$campaignFilter}%' ";
            }

            $query .= "ORDER BY metrics.cost_micros DESC "
                . "LIMIT 50";

            $response = self::executeGaql($customerId, $query);

            $campaigns = [];
            foreach ($response as $row) {
                $costMicros = (int) ($row['metrics']['costMicros'] ?? 0);
                $campaigns[] = [
                    'name'             => $row['campaign']['name'] ?? '',
                    'id'               => $row['campaign']['id'] ?? '',
                    'status'           => $row['campaign']['status'] ?? '',
                    'channel'          => $row['campaign']['advertisingChannelType'] ?? '',
                    'impressions'      => (int) ($row['metrics']['impressions'] ?? 0),
                    'clicks'           => (int) ($row['metrics']['clicks'] ?? 0),
                    'cost'             => round($costMicros / 1_000_000, 2),
                    'conversions'      => (float) ($row['metrics']['conversions'] ?? 0),
                    'conversion_value' => (float) ($row['metrics']['conversionsValue'] ?? 0),
                    'cost_per_conv'    => round((int) ($row['metrics']['costPerConversion'] ?? 0) / 1_000_000, 2),
                    'avg_cpc'          => round((int) ($row['metrics']['averageCpc'] ?? 0) / 1_000_000, 2),
                    'ctr'              => round((float) ($row['metrics']['ctr'] ?? 0) * 100, 2),
                    'roas'             => $costMicros > 0
                        ? round(((float) ($row['metrics']['conversionsValue'] ?? 0) / ($costMicros / 1_000_000)) * 100, 1)
                        : 0,
                ];
            }

            // Calculate totals
            $totalCost = array_sum(array_column($campaigns, 'cost'));
            $totalConvValue = array_sum(array_column($campaigns, 'conversion_value'));

            return [
                'success'    => true,
                'date_range' => $dateRange,
                'campaigns'  => $campaigns,
                'summary'    => [
                    'total_campaigns'  => count($campaigns),
                    'total_spend'      => round($totalCost, 2),
                    'total_clicks'     => array_sum(array_column($campaigns, 'clicks')),
                    'total_impressions' => array_sum(array_column($campaigns, 'impressions')),
                    'total_conversions' => array_sum(array_column($campaigns, 'conversions')),
                    'total_revenue'    => round($totalConvValue, 2),
                    'overall_roas'     => $totalCost > 0
                        ? round(($totalConvValue / $totalCost) * 100, 1)
                        : 0,
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Funnel-campaign mapping: which campaigns drive traffic to which funnels.
     *
     * Uses campaign name patterns to match funnels.
     *
     * @param array $params {date_range?: string}
     * @return array Funnel-campaign map
     */
    public static function funnelCampaignMap(array $params): array
    {
        try {
            $customerId = GoogleApiClient::getAdsCustomerId();
            $dateRange = self::parseDateRange($params['date_range'] ?? 'LAST_30_DAYS');

            // Get all active campaigns with URL tracking info
            $query = "SELECT "
                . "campaign.name, "
                . "campaign.id, "
                . "campaign.status, "
                . "campaign.tracking_url_template, "
                . "campaign.final_url_suffix, "
                . "metrics.impressions, "
                . "metrics.clicks, "
                . "metrics.cost_micros, "
                . "metrics.conversions, "
                . "metrics.conversions_value "
                . "FROM campaign "
                . "WHERE segments.date DURING {$dateRange} "
                . "AND campaign.status = 'ENABLED' "
                . "ORDER BY metrics.cost_micros DESC "
                . "LIMIT 50";

            $response = self::executeGaql($customerId, $query);

            // Also get funnel list from WordPress for cross-reference
            $funnels = self::getActiveFunnels();
            $map = [];

            foreach ($response as $row) {
                $name = $row['campaign']['name'] ?? '';
                $trackingUrl = $row['campaign']['trackingUrlTemplate'] ?? '';
                $finalUrlSuffix = $row['campaign']['finalUrlSuffix'] ?? '';
                $costMicros = (int) ($row['metrics']['costMicros'] ?? 0);

                // Match campaign to funnel by name pattern or URL
                $matchedFunnel = self::matchCampaignToFunnel($name, $trackingUrl, $finalUrlSuffix, $funnels);

                $map[] = [
                    'campaign_name'    => $name,
                    'campaign_id'      => $row['campaign']['id'] ?? '',
                    'matched_funnel'   => $matchedFunnel,
                    'match_confidence' => $matchedFunnel ? 'high' : 'unmatched',
                    'impressions'      => (int) ($row['metrics']['impressions'] ?? 0),
                    'clicks'           => (int) ($row['metrics']['clicks'] ?? 0),
                    'spend'            => round($costMicros / 1_000_000, 2),
                    'conversions'      => (float) ($row['metrics']['conversions'] ?? 0),
                    'revenue'          => (float) ($row['metrics']['conversionsValue'] ?? 0),
                ];
            }

            return [
                'success'    => true,
                'date_range' => $dateRange,
                'mappings'   => $map,
                'matched'    => count(array_filter($map, fn($m) => $m['matched_funnel'])),
                'unmatched'  => count(array_filter($map, fn($m) => !$m['matched_funnel'])),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Budget status: daily budget, spend, and remaining for all active campaigns.
     *
     * @param array $params (none required)
     * @return array Budget status
     */
    public static function budgetStatus(array $params): array
    {
        try {
            $customerId = GoogleApiClient::getAdsCustomerId();

            $query = "SELECT "
                . "campaign.name, "
                . "campaign.id, "
                . "campaign.status, "
                . "campaign_budget.amount_micros, "
                . "campaign_budget.total_amount_micros, "
                . "campaign_budget.delivery_method, "
                . "campaign_budget.period, "
                . "metrics.cost_micros "
                . "FROM campaign "
                . "WHERE segments.date DURING TODAY "
                . "AND campaign.status = 'ENABLED' "
                . "ORDER BY campaign_budget.amount_micros DESC";

            $response = self::executeGaql($customerId, $query);

            $budgets = [];
            $totalDailyBudget = 0;
            $totalSpentToday = 0;

            foreach ($response as $row) {
                $dailyBudgetMicros = (int) ($row['campaignBudget']['amountMicros'] ?? 0);
                $spentMicros = (int) ($row['metrics']['costMicros'] ?? 0);
                $dailyBudget = round($dailyBudgetMicros / 1_000_000, 2);
                $spent = round($spentMicros / 1_000_000, 2);

                $totalDailyBudget += $dailyBudget;
                $totalSpentToday += $spent;

                $budgets[] = [
                    'campaign'       => $row['campaign']['name'] ?? '',
                    'campaign_id'    => $row['campaign']['id'] ?? '',
                    'daily_budget'   => $dailyBudget,
                    'spent_today'    => $spent,
                    'remaining'      => round($dailyBudget - $spent, 2),
                    'utilization_pct' => $dailyBudget > 0
                        ? round(($spent / $dailyBudget) * 100, 1)
                        : 0,
                    'delivery_method' => $row['campaignBudget']['deliveryMethod'] ?? '',
                ];
            }

            return [
                'success'           => true,
                'campaigns'         => $budgets,
                'total_daily_budget' => round($totalDailyBudget, 2),
                'total_spent_today' => round($totalSpentToday, 2),
                'total_remaining'   => round($totalDailyBudget - $totalSpentToday, 2),
                'overall_utilization' => $totalDailyBudget > 0
                    ? round(($totalSpentToday / $totalDailyBudget) * 100, 1)
                    : 0,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Execute a Google Ads Query Language (GAQL) query.
     *
     * @param string $customerId Ads customer ID (no dashes)
     * @param string $gaql       GAQL query string
     * @return array Rows from the response
     * @throws \RuntimeException On failure
     */
    private static function executeGaql(string $customerId, string $gaql): array
    {
        $url = sprintf(
            'https://googleads.googleapis.com/%s/customers/%s/googleAds:searchStream',
            self::API_VERSION,
            $customerId
        );

        $managerId = GoogleApiClient::getAdsManagerId();

        // For Google Ads API, we need developer-token and optionally login-customer-id
        $devToken = get_option('hp_gmc_ads_developer_token', '');
        if (empty($devToken)) {
            throw new \RuntimeException(
                'Google Ads developer token not configured. Go to GMC Manager > Settings.'
            );
        }

        $accessToken = GoogleApiClient::getAccessToken(self::SCOPE);

        $baseHeaders = [
            'Authorization'  => 'Bearer ' . $accessToken,
            'Content-Type'   => 'application/json',
            'developer-token' => $devToken,
        ];

        // Function to make the request
        $makeRequest = function ($headers) use ($url, $gaql) {
            return wp_remote_post($url, [
                'headers' => $headers,
                'body'    => wp_json_encode(['query' => $gaql]),
                'timeout' => 30,
            ]);
        };

        // Attempt 1: With Manager ID if present
        $headers = $baseHeaders;
        if (!empty($managerId)) {
            $headers['login-customer-id'] = str_replace('-', '', $managerId);
        }

        $response = $makeRequest($headers);
        $statusCode = wp_remote_retrieve_response_code($response);

        // Retry logic: If 404/403/401 AND we sent a manager ID, try without it
        // 404: Customer not found (via that manager)
        // 403: Not authorized (via that manager)
        // 401: Unauthorized (token issue, but maybe context issue too)
        if (!empty($managerId) && ($statusCode === 404 || $statusCode === 403 || $statusCode === 401)) {
            // Log the retry
            error_log("Google Ads API: Request failed with Manager ID {$managerId} (HTTP {$statusCode}). Retrying without it.");
            
            unset($headers['login-customer-id']);
            $retryResponse = $makeRequest($headers);
            
            // If retry worked (200), use it
            if (wp_remote_retrieve_response_code($retryResponse) === 200) {
                $response = $retryResponse;
                $statusCode = 200;
            }
        }

        if (is_wp_error($response)) {
            throw new \RuntimeException('Ads API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);

        // #region agent log
        file_put_contents('c:\DEV\.cursor\debug.log', json_encode([
            'id' => 'log_' . microtime(true),
            'timestamp' => time() * 1000,
            'location' => 'GoogleAdsReporting.php:executeGaql',
            'message' => 'API Response Body',
            'data' => [
                'statusCode' => $statusCode,
                'bodySnippet' => substr($body, 0, 500),
                'decoded' => json_decode($body, true),
                'jsonLastError' => json_last_error_msg()
            ],
            'runId' => 'run1',
            'hypothesisId' => 'A,B,C,D'
        ]) . "\n", FILE_APPEND);
        // #endregion

        // searchStream returns newline-delimited JSON arrays
        $decoded = json_decode($body, true);

        if ($statusCode >= 400) {
            $errorMsg = $decoded[0]['error']['message']
                ?? $decoded['error']['message']
                ?? null;

            if (!$errorMsg) {
                // Fallback to raw body if JSON decode failed or no message found
                // Strip tags in case it's HTML
                $rawSample = substr(strip_tags($body), 0, 300);
                $errorMsg = "HTTP {$statusCode}. Response: {$rawSample}";
            }
            
            // Add context to error
            $context = !empty($managerId) && isset($headers['login-customer-id']) 
                ? " (via Manager {$managerId})" 
                : " (Direct access)";
                
            throw new \RuntimeException(sprintf('Ads API error%s: %s', $context, $errorMsg));
        }

        // Flatten results from all stream chunks
        $rows = [];
        if (is_array($decoded)) {
            foreach ($decoded as $chunk) {
                if (isset($chunk['results']) && is_array($chunk['results'])) {
                    $rows = array_merge($rows, $chunk['results']);
                }
            }
        }

        return $rows;
    }

    /**
     * Parse a user-friendly date range into GAQL date range.
     *
     * @param string $range E.g. 'last_7_days', 'LAST_30_DAYS', 'today'
     * @return string GAQL date range constant
     */
    private static function parseDateRange(string $range): string
    {
        $normalized = strtoupper(str_replace(' ', '_', $range));

        return match ($normalized) {
            'TODAY'         => 'TODAY',
            'YESTERDAY'     => 'YESTERDAY',
            'LAST_7_DAYS'   => 'LAST_7_DAYS',
            'LAST_14_DAYS'  => 'LAST_14_DAYS',
            'LAST_90_DAYS'  => 'LAST_BUSINESS_QUARTER',
            'THIS_MONTH'    => 'THIS_MONTH',
            'LAST_MONTH'    => 'LAST_MONTH',
            default         => 'LAST_30_DAYS',
        };
    }

    /**
     * Get list of active funnel slugs from WordPress.
     *
     * @return array [{slug, title}]
     */
    private static function getActiveFunnels(): array
    {
        $posts = get_posts([
            'post_type'      => 'hp-funnel',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $funnels = [];
        foreach ($posts as $postId) {
            $slug = get_post_meta($postId, 'funnel_slug', true) ?: get_post_field('post_name', $postId);
            $funnels[] = [
                'slug'  => $slug,
                'title' => get_the_title($postId),
            ];
        }

        return $funnels;
    }

    /**
     * Match a campaign to a funnel based on naming conventions.
     *
     * @param string $campaignName    Campaign name
     * @param string $trackingUrl     Tracking URL template
     * @param string $finalUrlSuffix  Final URL suffix
     * @param array  $funnels         Available funnels
     * @return string|null Matched funnel slug or null
     */
    private static function matchCampaignToFunnel(
        string $campaignName,
        string $trackingUrl,
        string $finalUrlSuffix,
        array $funnels
    ): ?string {
        $nameLower = strtolower($campaignName);
        $urlLower = strtolower($trackingUrl . ' ' . $finalUrlSuffix);

        foreach ($funnels as $funnel) {
            $slug = strtolower($funnel['slug']);

            // Check campaign name contains funnel slug
            if (str_contains($nameLower, $slug)) {
                return $funnel['slug'];
            }

            // Check URL tracking contains funnel slug
            if (!empty($urlLower) && str_contains($urlLower, $slug)) {
                return $funnel['slug'];
            }

            // Check campaign name contains funnel title words
            $titleWords = array_filter(explode(' ', strtolower($funnel['title'])), fn($w) => strlen($w) > 3);
            $matchCount = 0;
            foreach ($titleWords as $word) {
                if (str_contains($nameLower, $word)) {
                    $matchCount++;
                }
            }
            if ($matchCount >= 2 && count($titleWords) > 0) {
                return $funnel['slug'];
            }
        }

        return null;
    }
}
