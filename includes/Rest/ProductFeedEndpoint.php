<?php
namespace HP_GMC\Rest;

use HP_GMC\Services\ProductDataFeed;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API endpoint for the primary product data feed.
 * 
 * This endpoint serves a TSV/CSV feed that Google Merchant Center can fetch
 * to get complete product data. It replaces the broken GLA OAuth sync.
 * 
 * Endpoint: GET /wp-json/hp-gmc/v1/product-feed
 */
class ProductFeedEndpoint
{
    /**
     * Register the REST routes.
     */
    public static function register(): void
    {
        register_rest_route('hp-gmc/v1', '/product-feed', [
            'methods' => 'GET',
            'callback' => [self::class, 'serveFeed'],
            'permission_callback' => '__return_true', // Public endpoint for GMC to fetch
            'args' => [
                'format' => [
                    'type' => 'string',
                    'default' => 'tsv',
                    'enum' => ['tsv', 'csv'],
                    'description' => 'Output format: tsv (tab-separated) or csv (comma-separated)',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'profile' => [
                    'type' => 'string',
                    'default' => 'merchant',
                    'enum' => ['merchant', 'agent'],
                    'description' => 'Feed profile: merchant (canonical GMC) or agent (claim-safe agent discovery)',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        // Also register a status endpoint (authenticated)
        register_rest_route('hp-gmc/v1', '/product-feed/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'getStatus'],
            'permission_callback' => [self::class, 'checkAdminPermission'],
        ]);

        // Endpoint to manually trigger regeneration (authenticated)
        register_rest_route('hp-gmc/v1', '/product-feed/regenerate', [
            'methods' => 'POST',
            'callback' => [self::class, 'regenerateFeed'],
            'permission_callback' => [self::class, 'checkAdminPermission'],
        ]);
    }

    /**
     * Serve the product feed.
     * 
     * This is a public endpoint that GMC will fetch.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|void
     */
    public static function serveFeed(WP_REST_Request $request)
    {
        $format = $request->get_param('format') ?: 'tsv';
        $profile = $request->get_param('profile') === 'agent' ? 'agent' : 'merchant';

        // Log feed access (for monitoring)
        error_log(json_encode([
            'event' => 'primary_feed.accessed',
            'format' => $format,
            'profile' => $profile,
            'refresh' => false,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'timestamp' => current_time('mysql'),
        ]));

        try {
            $content = $profile === 'agent'
                ? ProductDataFeed::generateAgentFeed($format, false)
                : ProductDataFeed::generateFeed($format, false);

            // Set appropriate headers for file download
            $contentType = $format === 'csv' ? 'text/csv' : 'text/tab-separated-values';
            $filename = ($profile === 'agent' ? 'agent-product-feed' : 'product-feed') . '.' . $format;

            // Send headers directly (not through WP_REST_Response for raw content)
            header('Content-Type: ' . $contentType . '; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Cache-Control: public, max-age=3600'); // Allow caching for 1 hour
            header('X-Feed-Product-Count: ' . ProductDataFeed::getProductCount());
            header('X-Feed-Generated: ' . (ProductDataFeed::getLastGenerated() ?: 'unknown'));
            header('X-Feed-Profile: ' . $profile);

            echo $content;
            exit;

        } catch (\Exception $e) {
            error_log(json_encode([
                'event' => 'primary_feed.error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => current_time('mysql'),
            ]));

            return new WP_REST_Response([
                'error' => 'Failed to generate feed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get feed status.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function getStatus(WP_REST_Request $request): WP_REST_Response
    {
        $status = ProductDataFeed::getStatus();

        return new WP_REST_Response([
            'success' => true,
            'data' => $status,
        ], 200);
    }

    /**
     * Regenerate the feed (clear cache and regenerate).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function regenerateFeed(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Clear cache
            ProductDataFeed::clearCache();

            // Regenerate both formats
            ProductDataFeed::generateFeed('tsv', true);
            ProductDataFeed::generateFeed('csv', true);
            ProductDataFeed::generateAgentFeed('tsv', true);
            ProductDataFeed::generateAgentFeed('csv', true);

            $status = ProductDataFeed::getStatus();

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Feed regenerated successfully',
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
     * Check if current user has admin permission.
     *
     * @return bool
     */
    public static function checkAdminPermission(): bool
    {
        return current_user_can('manage_woocommerce');
    }
}
