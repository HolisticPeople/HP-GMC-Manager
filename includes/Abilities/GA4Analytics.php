<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\GoogleApiClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GA4 Analytics MCP Abilities.
 *
 * Provides funnel performance, traffic source, and realtime analytics
 * via the Google Analytics Data API v1.
 *
 * @since 1.23.0
 * @see https://developers.google.com/analytics/devguides/reporting/data/v1
 */
class GA4Analytics
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
    private const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta/';

    /**
     * Funnel performance report: sessions, conversions, revenue by funnel.
     *
     * @param array $params {date_range?: string, funnel_slug?: string}
     * @return array Report data
     */
    public static function funnelPerformance(array $params): array
    {
        try {
            $propertyId = GoogleApiClient::getGA4PropertyId();
            $dateRange = self::parseDateRange($params['date_range'] ?? 'last_30_days');
            $funnelSlug = $params['funnel_slug'] ?? '';

            $body = [
                'dateRanges' => [$dateRange],
                'dimensions' => [
                    ['name' => 'pagePath'],
                    ['name' => 'eventName'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'eventCount'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                    ['name' => 'averagePurchaseRevenue'],
                ],
                'dimensionFilter' => self::buildFunnelFilter($funnelSlug),
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true],
                ],
                'limit' => 100,
            ];

            $url = self::BASE_URL . $propertyId . ':runReport';
            $response = GoogleApiClient::post($url, self::SCOPE, $body);

            return [
                'success'    => true,
                'date_range' => $dateRange,
                'funnel_slug' => $funnelSlug ?: 'all',
                'rows'       => self::formatReportRows($response),
                'totals'     => self::extractTotals($response),
                'row_count'  => $response['rowCount'] ?? 0,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Traffic sources report: where funnel visitors come from.
     *
     * @param array $params {date_range?: string, funnel_slug?: string}
     * @return array Traffic source data
     */
    public static function trafficSources(array $params): array
    {
        try {
            $propertyId = GoogleApiClient::getGA4PropertyId();
            $dateRange = self::parseDateRange($params['date_range'] ?? 'last_30_days');
            $funnelSlug = $params['funnel_slug'] ?? '';

            $body = [
                'dateRanges' => [$dateRange],
                'dimensions' => [
                    ['name' => 'sessionSource'],
                    ['name' => 'sessionMedium'],
                    ['name' => 'sessionCampaignName'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                    ['name' => 'userEngagementDuration'],
                ],
                'dimensionFilter' => self::buildFunnelFilter($funnelSlug),
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true],
                ],
                'limit' => 50,
            ];

            $url = self::BASE_URL . $propertyId . ':runReport';
            $response = GoogleApiClient::post($url, self::SCOPE, $body);

            return [
                'success'    => true,
                'date_range' => $dateRange,
                'funnel_slug' => $funnelSlug ?: 'all',
                'sources'    => self::formatTrafficRows($response),
                'totals'     => self::extractTotals($response),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Realtime report: active users on funnel pages right now.
     *
     * @param array $params {funnel_slug?: string}
     * @return array Realtime data
     */
    public static function realtime(array $params): array
    {
        try {
            $propertyId = GoogleApiClient::getGA4PropertyId();
            $funnelSlug = $params['funnel_slug'] ?? '';

            $body = [
                'dimensions' => [
                    ['name' => 'unifiedScreenName'],
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'eventCount'],
                ],
            ];

            // Add filter for funnel pages
            if (!empty($funnelSlug)) {
                $body['dimensionFilter'] = [
                    'filter' => [
                        'fieldName'    => 'unifiedScreenName',
                        'stringFilter' => [
                            'matchType' => 'CONTAINS',
                            'value'     => $funnelSlug,
                        ],
                    ],
                ];
            }

            $url = self::BASE_URL . $propertyId . ':runRealtimeReport';
            $response = GoogleApiClient::post($url, self::SCOPE, $body);

            $rows = [];
            foreach (($response['rows'] ?? []) as $row) {
                $rows[] = [
                    'page'         => $row['dimensionValues'][0]['value'] ?? '',
                    'active_users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                    'event_count'  => (int) ($row['metricValues'][1]['value'] ?? 0),
                ];
            }

            $totalActive = 0;
            foreach ($rows as $r) {
                $totalActive += $r['active_users'];
            }

            return [
                'success'           => true,
                'funnel_slug'       => $funnelSlug ?: 'all',
                'total_active_users' => $totalActive,
                'pages'             => $rows,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Conversion funnel report: step-by-step drop-off analysis.
     *
     * @param array $params {date_range?: string, funnel_slug?: string}
     * @return array Funnel step data
     */
    public static function conversionFunnel(array $params): array
    {
        try {
            $propertyId = GoogleApiClient::getGA4PropertyId();
            $dateRange = self::parseDateRange($params['date_range'] ?? 'last_30_days');
            $funnelSlug = $params['funnel_slug'] ?? '';

            // Query each ecommerce event separately for funnel analysis
            $events = ['view_item', 'add_to_cart', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase'];
            $steps = [];

            foreach ($events as $eventName) {
                $body = [
                    'dateRanges' => [$dateRange],
                    'dimensions' => [
                        ['name' => 'eventName'],
                    ],
                    'metrics' => [
                        ['name' => 'eventCount'],
                        ['name' => 'totalUsers'],
                    ],
                    'dimensionFilter' => [
                        'andGroup' => [
                            'expressions' => array_filter([
                                [
                                    'filter' => [
                                        'fieldName'    => 'eventName',
                                        'stringFilter' => ['matchType' => 'EXACT', 'value' => $eventName],
                                    ],
                                ],
                                !empty($funnelSlug) ? [
                                    'filter' => [
                                        'fieldName'    => 'pagePath',
                                        'stringFilter' => ['matchType' => 'CONTAINS', 'value' => $funnelSlug],
                                    ],
                                ] : null,
                            ]),
                        ],
                    ],
                ];

                $url = self::BASE_URL . $propertyId . ':runReport';
                $response = GoogleApiClient::post($url, self::SCOPE, $body);

                $eventCount = 0;
                $users = 0;
                foreach (($response['rows'] ?? []) as $row) {
                    $eventCount += (int) ($row['metricValues'][0]['value'] ?? 0);
                    $users += (int) ($row['metricValues'][1]['value'] ?? 0);
                }

                $steps[] = [
                    'step'        => $eventName,
                    'event_count' => $eventCount,
                    'users'       => $users,
                ];
            }

            // Calculate drop-off rates
            $maxUsers = $steps[0]['users'] ?? 1;
            foreach ($steps as $i => &$step) {
                $step['percentage_of_top'] = $maxUsers > 0
                    ? round(($step['users'] / $maxUsers) * 100, 1)
                    : 0;
                $step['drop_off_from_previous'] = $i > 0 && $steps[$i - 1]['users'] > 0
                    ? round((1 - $step['users'] / $steps[$i - 1]['users']) * 100, 1)
                    : 0;
            }
            unset($step);

            return [
                'success'     => true,
                'date_range'  => $dateRange,
                'funnel_slug' => $funnelSlug ?: 'all',
                'steps'       => $steps,
                'overall_conversion_rate' => $maxUsers > 0
                    ? round((end($steps)['users'] / $maxUsers) * 100, 2)
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
     * Parse a date range string into GA4 API format.
     *
     * @param string $range E.g. 'last_7_days', 'last_30_days', 'last_90_days', 'today'
     * @return array {startDate, endDate}
     */
    private static function parseDateRange(string $range): array
    {
        return match ($range) {
            'today'        => ['startDate' => 'today', 'endDate' => 'today'],
            'yesterday'    => ['startDate' => 'yesterday', 'endDate' => 'yesterday'],
            'last_7_days'  => ['startDate' => '7daysAgo', 'endDate' => 'today'],
            'last_90_days' => ['startDate' => '90daysAgo', 'endDate' => 'today'],
            default        => ['startDate' => '30daysAgo', 'endDate' => 'today'],
        };
    }

    /**
     * Build a dimension filter for funnel pages.
     *
     * @param string $funnelSlug Funnel slug (empty = all funnels)
     * @return array|null Filter expression or null
     */
    private static function buildFunnelFilter(string $funnelSlug): ?array
    {
        if (empty($funnelSlug)) {
            // Filter for any funnel page (URL contains /f/ which is the funnel route prefix)
            return [
                'filter' => [
                    'fieldName'    => 'pagePath',
                    'stringFilter' => [
                        'matchType' => 'CONTAINS',
                        'value'     => '/f/',
                    ],
                ],
            ];
        }

        return [
            'filter' => [
                'fieldName'    => 'pagePath',
                'stringFilter' => [
                    'matchType' => 'CONTAINS',
                    'value'     => $funnelSlug,
                ],
            ],
        ];
    }

    /**
     * Format report rows into a cleaner structure.
     *
     * @param array $response GA4 API response
     * @return array Formatted rows
     */
    private static function formatReportRows(array $response): array
    {
        $rows = [];
        $dimHeaders = array_map(fn($h) => $h['name'], $response['dimensionHeaders'] ?? []);
        $metHeaders = array_map(fn($h) => $h['name'], $response['metricHeaders'] ?? []);

        foreach (($response['rows'] ?? []) as $row) {
            $formatted = [];
            foreach ($row['dimensionValues'] ?? [] as $i => $val) {
                $formatted[$dimHeaders[$i] ?? "dim_{$i}"] = $val['value'] ?? '';
            }
            foreach ($row['metricValues'] ?? [] as $i => $val) {
                $name = $metHeaders[$i] ?? "met_{$i}";
                $formatted[$name] = is_numeric($val['value'] ?? '') ? (float) $val['value'] : ($val['value'] ?? '');
            }
            $rows[] = $formatted;
        }

        return $rows;
    }

    /**
     * Format traffic source rows.
     *
     * @param array $response GA4 API response
     * @return array Formatted source rows
     */
    private static function formatTrafficRows(array $response): array
    {
        $rows = [];
        foreach (($response['rows'] ?? []) as $row) {
            $rows[] = [
                'source'    => $row['dimensionValues'][0]['value'] ?? '(direct)',
                'medium'    => $row['dimensionValues'][1]['value'] ?? '(none)',
                'campaign'  => $row['dimensionValues'][2]['value'] ?? '(not set)',
                'sessions'  => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users'     => (int) ($row['metricValues'][1]['value'] ?? 0),
                'new_users' => (int) ($row['metricValues'][2]['value'] ?? 0),
                'purchases' => (int) ($row['metricValues'][3]['value'] ?? 0),
                'revenue'   => (float) ($row['metricValues'][4]['value'] ?? 0),
                'engagement_seconds' => (float) ($row['metricValues'][5]['value'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Extract totals from a GA4 report response.
     *
     * @param array $response GA4 API response
     * @return array Metric totals
     */
    private static function extractTotals(array $response): array
    {
        $totals = [];
        $metHeaders = array_map(fn($h) => $h['name'], $response['metricHeaders'] ?? []);

        foreach (($response['totals'] ?? []) as $totalRow) {
            foreach ($totalRow['metricValues'] ?? [] as $i => $val) {
                $name = $metHeaders[$i] ?? "met_{$i}";
                $totals[$name] = is_numeric($val['value'] ?? '') ? (float) $val['value'] : ($val['value'] ?? '');
            }
        }

        return $totals;
    }
}
