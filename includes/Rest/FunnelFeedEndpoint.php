<?php
namespace HP_GMC\Rest;

use HP_GMC\Services\FunnelDataFeed;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API endpoint for the funnel data feed.
 * 
 * This endpoint serves a TSV/CSV feed for funnels that Google Merchant Center 
 * can fetch for Shopping ads. Requires HP-React-Widgets plugin.
 * 
 * Endpoint: GET /wp-json/hp-gmc/v1/funnel-feed
 */
class FunnelFeedEndpoint
{
    /**
     * Register the REST routes.
     */
    public static function register(): void
    {
        // Main feed endpoint (public for GMC to fetch)
        register_rest_route('hp-gmc/v1', '/funnel-feed', [
            'methods' => 'GET',
            'callback' => [self::class, 'serveFeed'],
            'permission_callback' => '__return_true',
            'args' => [
                'format' => [
                    'type' => 'string',
                    'default' => 'tsv',
                    'enum' => ['tsv', 'csv'],
                    'description' => 'Output format: tsv (tab-separated) or csv (comma-separated)',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Status endpoint (authenticated)
        register_rest_route('hp-gmc/v1', '/funnel-feed/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'getStatus'],
            'permission_callback' => [self::class, 'checkAdminPermission'],
        ]);

        // Regenerate endpoint (authenticated)
        register_rest_route('hp-gmc/v1', '/funnel-feed/regenerate', [
            'methods' => 'POST',
            'callback' => [self::class, 'regenerateFeed'],
            'permission_callback' => [self::class, 'checkAdminPermission'],
        ]);

        // List funnels endpoint (authenticated)
        register_rest_route('hp-gmc/v1', '/funnels', [
            'methods' => 'GET',
            'callback' => [self::class, 'listFunnels'],
            'permission_callback' => [self::class, 'checkAdminPermission'],
        ]);

        // Get single funnel endpoint (authenticated)
        register_rest_route('hp-gmc/v1', '/funnels/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'getFunnel'],
            'permission_callback' => [self::class, 'checkAdminPermission'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Funnel post ID',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Validate funnel endpoint (authenticated)
        register_rest_route('hp-gmc/v1', '/funnels/(?P<id>\d+)/validate', [
            'methods' => 'GET',
            'callback' => [self::class, 'validateFunnel'],
            'permission_callback' => [self::class, 'checkAdminPermission'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Funnel post ID',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Serve the funnel feed.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|void
     */
    public static function serveFeed(WP_REST_Request $request)
    {
        $format = $request->get_param('format') ?: 'tsv';

        // Log feed access
        error_log(json_encode([
            'event' => 'funnel_feed.accessed',
            'format' => $format,
            'refresh' => false,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'timestamp' => current_time('mysql'),
        ]));

        try {
            $content = FunnelDataFeed::generateFeed($format, false);
            $status = FunnelDataFeed::getStatus();

            // Set appropriate headers for file download
            $contentType = $format === 'csv' ? 'text/csv' : 'text/tab-separated-values';
            $filename = 'funnel-feed.' . $format;

            header('Content-Type: ' . $contentType . '; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Cache-Control: public, max-age=3600');
            header('X-Feed-Funnel-Count: ' . ($status['funnel_count'] ?? 0));
            header('X-Feed-Generated: ' . ($status['last_generated'] ?? 'unknown'));
            header('X-Feed-Available: ' . ($status['available'] ? 'yes' : 'no'));

            echo $content;
            exit;

        } catch (\Exception $e) {
            error_log(json_encode([
                'event' => 'funnel_feed.error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => current_time('mysql'),
            ]));

            return new WP_REST_Response([
                'error' => 'Failed to generate funnel feed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get funnel feed status.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function getStatus(WP_REST_Request $request): WP_REST_Response
    {
        $status = FunnelDataFeed::getStatus();

        return new WP_REST_Response([
            'success' => true,
            'data' => $status,
        ], 200);
    }

    /**
     * Regenerate the funnel feed.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function regenerateFeed(WP_REST_Request $request): WP_REST_Response
    {
        try {
            FunnelDataFeed::clearCache();
            FunnelDataFeed::generateFeed('tsv', true);
            FunnelDataFeed::generateFeed('csv', true);

            $status = FunnelDataFeed::getStatus();

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Funnel feed regenerated successfully',
                'data' => $status,
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all GMC-enabled funnels.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function listFunnels(WP_REST_Request $request): WP_REST_Response
    {
        if (!FunnelDataFeed::isAvailable()) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'HP-React-Widgets plugin not active',
                'data' => [],
            ], 200);
        }

        $funnels = FunnelDataFeed::getAllFunnels();

        return new WP_REST_Response([
            'success' => true,
            'count' => count($funnels),
            'data' => $funnels,
        ], 200);
    }

    /**
     * Get single funnel GMC data.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function getFunnel(WP_REST_Request $request): WP_REST_Response
    {
        $funnelId = $request->get_param('id');
        $funnel = FunnelDataFeed::getFunnel($funnelId);

        if (!$funnel) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Funnel not found or HP-React-Widgets not active',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $funnel,
        ], 200);
    }

    /**
     * Validate a funnel for GMC eligibility.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function validateFunnel(WP_REST_Request $request): WP_REST_Response
    {
        $funnelId = $request->get_param('id');
        $validation = FunnelDataFeed::validateFunnel($funnelId);

        return new WP_REST_Response([
            'success' => true,
            'funnel_id' => $funnelId,
            'validation' => $validation,
        ], 200);
    }

    /**
     * Check if current user has admin permission.
     *
     * @return bool
     */
    public static function checkAdminPermission(): bool
    {
        return current_user_can('manage_woocommerce');
    }
}
