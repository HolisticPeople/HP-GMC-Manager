<?php
namespace HP_GMC\Rest;

use HP_GMC\Services\FeedManager;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoint for supplemental feed content (stable URL for GMC).
 *
 * GMC requires supplemental sources to be added manually in the UI. This endpoint
 * provides a stable URL that always serves the latest feed content so the merchant
 * can add it once in GMC as a Supplemental source (not Primary).
 *
 * Endpoint: GET /wp-json/hp-gmc/v1/supplemental-feed/{id}?format=tsv
 */
class SupplementalFeedEndpoint
{
    /**
     * Register the REST route.
     */
    public static function register(): void
    {
        register_rest_route('hp-gmc/v1', '/supplemental-feed/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'serveFeed'],
            'permission_callback' => '__return_true', // Public so GMC can fetch
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Feed ID from the plugin',
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && (int) $param > 0;
                    },
                ],
                'format' => [
                    'type' => 'string',
                    'default' => 'tsv',
                    'enum' => ['tsv', 'csv'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Generate and serve the supplemental feed content.
     *
     * Serves RAW text via header()+echo+exit exactly like ProductFeedEndpoint.
     * Returning the string through WP_REST_Response JSON-encodes it into one
     * quoted blob, which GMC's fetcher cannot parse — this bug silently
     * disabled every supplemental override/exclusion since the feeds were
     * linked (fixed in 3.2.1; verified via Content API products.get showing
     * no overrides/exclusions ever applied).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|void
     */
    public static function serveFeed(WP_REST_Request $request)
    {
        $feedId = (int) $request->get_param('id');
        $format = $request->get_param('format') ?: 'tsv';

        $feed = FeedManager::get($feedId);
        if (!$feed) {
            return new WP_REST_Response(['error' => 'Feed not found'], 404);
        }

        $generateResult = FeedManager::generateFile($feedId, $format);
        if (!$generateResult['success']) {
            return new WP_REST_Response([
                'error' => $generateResult['error'] ?? 'Failed to generate feed',
            ], 500);
        }

        $filePath = $generateResult['file_path'] ?? null;
        if (!$filePath || !is_readable($filePath)) {
            return new WP_REST_Response(['error' => 'Feed file not readable'], 500);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return new WP_REST_Response(['error' => 'Failed to read feed file'], 500);
        }

        $contentType = $format === 'csv' ? 'text/csv' : 'text/tab-separated-values';
        $filename = sanitize_file_name($feed['name']) . '.' . $format;

        // Send headers directly (not through WP_REST_Response for raw content).
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: public, max-age=300');
        header('X-Feed-Id: ' . $feedId);
        header('X-Feed-Name: ' . $feed['name']);

        echo $content;
        exit;
    }

    /**
     * Get the stable URL for a supplemental feed (for use in GMC and admin UI).
     *
     * @param int $feedId Feed ID
     * @param string $format tsv or csv
     * @return string Full URL
     */
    public static function getSupplementalFeedUrl(int $feedId, string $format = 'tsv'): string
    {
        return rest_url('hp-gmc/v1/supplemental-feed/' . $feedId . '?format=' . $format);
    }
}
