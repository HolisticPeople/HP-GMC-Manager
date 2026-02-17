<?php
namespace HP_GMC\Rest;

use HP_GMC\Services\GoogleAdsOAuth;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth2 callback for Google Ads (user account). Google redirects here with ?code= & state=.
 * Exchanges code for tokens, saves refresh_token, redirects to settings with success/error.
 */
class OAuthCallbackEndpoint
{
    public static function permission(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function register(): void
    {
        register_rest_route('hp-gmc/v1', '/oauth-callback', [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'code' => ['required' => true, 'type' => 'string'],
                'state' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $code = $request->get_param('code');
        $state = $request->get_param('state');
        $redirectUri = rest_url('hp-gmc/v1/oauth-callback');
        $result = GoogleAdsOAuth::exchangeCodeForTokens($code, $state, $redirectUri);
        $settingsUrl = admin_url('admin.php?page=hp-gmc-settings');
        if (!empty($result['error'])) {
            $settingsUrl = add_query_arg('hp_gmc_oauth_error', rawurlencode($result['error']), $settingsUrl);
        } else {
            $settingsUrl = add_query_arg('hp_gmc_oauth', '1', $settingsUrl);
        }
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $settingsUrl);
        return $response;
    }
}
