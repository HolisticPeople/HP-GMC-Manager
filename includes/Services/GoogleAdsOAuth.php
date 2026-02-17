<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth2 for Google Ads API using a user's Google account (e.g. office@holisticpeople.com).
 * Use when the service account has permission issues (403). Stores refresh token and
 * provides access tokens for the Adwords scope.
 */
class GoogleAdsOAuth
{
    private const SCOPE = 'https://www.googleapis.com/auth/adwords';
    private const OPTION_REFRESH_TOKEN = 'hp_gmc_ads_oauth_refresh_token';
    private const OPTION_USER_EMAIL = 'hp_gmc_ads_oauth_user_email';
    private const TRANSIENT_ACCESS = 'hp_gmc_ads_oauth_access_token';
    private const TRANSIENT_STATE = 'hp_gmc_ads_oauth_state';
    private const STATE_TTL = 600; // 10 min

    /**
     * Build the Google OAuth2 authorization URL.
     *
     * @param string $redirectUri Must match the URI configured in Google Cloud Console.
     * @return string Auth URL to redirect the user to
     */
    public static function getAuthUrl(string $redirectUri): string
    {
        $clientId = get_option('hp_gmc_ads_oauth_client_id', '');
        if (empty($clientId)) {
            throw new \RuntimeException('Google Ads OAuth Client ID not configured.');
        }
        $state = wp_generate_password(32, true, true);
        set_transient(self::TRANSIENT_STATE, $state, self::STATE_TTL);
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent', // Force refresh_token on first auth
            'state' => $state,
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Verify state and exchange authorization code for tokens. Saves refresh_token.
     *
     * @param string $code Authorization code from redirect
     * @param string $state State from redirect (must match stored)
     * @param string $redirectUri Same as used in getAuthUrl
     * @return array{email: string|null, error: string|null}
     */
    public static function exchangeCodeForTokens(string $code, string $state, string $redirectUri): array
    {
        $storedState = get_transient(self::TRANSIENT_STATE);
        delete_transient(self::TRANSIENT_STATE);
        if (empty($storedState) || !hash_equals((string) $storedState, (string) $state)) {
            return ['email' => null, 'error' => 'Invalid or expired state.'];
        }
        $clientId = get_option('hp_gmc_ads_oauth_client_id', '');
        $clientSecret = get_option('hp_gmc_ads_oauth_client_secret', '');
        if (empty($clientId) || empty($clientSecret)) {
            return ['email' => null, 'error' => 'OAuth Client ID or Secret not set.'];
        }
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            return ['email' => null, 'error' => $response->get_error_message()];
        }
        $code_http = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code_http >= 400) {
            $msg = is_array($data) && isset($data['error_description']) ? $data['error_description'] : $body;
            return ['email' => null, 'error' => $msg];
        }
        $refreshToken = $data['refresh_token'] ?? null;
        if (empty($refreshToken)) {
            return ['email' => null, 'error' => 'No refresh_token in response. Re-authorize with prompt=consent.'];
        }
        update_option(self::OPTION_REFRESH_TOKEN, $refreshToken);
        // Cache access token for immediate use
        $accessToken = $data['access_token'] ?? '';
        if (!empty($accessToken)) {
            $expiresIn = (int) ($data['expires_in'] ?? 3600);
            set_transient(self::TRANSIENT_ACCESS, $accessToken, max(1, $expiresIn - 60));
        }
        $email = self::fetchUserEmail($data['access_token'] ?? '');
        if ($email !== null) {
            update_option(self::OPTION_USER_EMAIL, $email);
        }
        return ['email' => $email, 'error' => null];
    }

    /**
     * Get a valid access token for the Ads scope (from cache or refresh).
     *
     * @return string|null Access token, or null if not connected
     */
    public static function getAccessToken(): ?string
    {
        $cached = get_transient(self::TRANSIENT_ACCESS);
        if (!empty($cached)) {
            return $cached;
        }
        $refreshToken = get_option(self::OPTION_REFRESH_TOKEN, '');
        if (empty($refreshToken)) {
            return null;
        }
        $clientId = get_option('hp_gmc_ads_oauth_client_id', '');
        $clientSecret = get_option('hp_gmc_ads_oauth_client_secret', '');
        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token',
            ],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code_http = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code_http >= 400) {
            return null;
        }
        $accessToken = $data['access_token'] ?? '';
        if (empty($accessToken)) {
            return null;
        }
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        set_transient(self::TRANSIENT_ACCESS, $accessToken, max(1, $expiresIn - 60));
        return $accessToken;
    }

    /**
     * Whether OAuth is configured and we have a refresh token.
     */
    public static function isConnected(): bool
    {
        return !empty(get_option(self::OPTION_REFRESH_TOKEN, ''));
    }

    /**
     * Stored user email (from last successful token exchange), if any.
     */
    public static function getStoredEmail(): ?string
    {
        $email = get_option(self::OPTION_USER_EMAIL, '');
        return $email !== '' ? $email : null;
    }

    /**
     * Disconnect: remove stored tokens and email.
     */
    public static function disconnect(): void
    {
        delete_option(self::OPTION_REFRESH_TOKEN);
        delete_option(self::OPTION_USER_EMAIL);
        delete_transient(self::TRANSIENT_ACCESS);
        delete_transient(self::TRANSIENT_STATE);
    }

    private static function fetchUserEmail(string $accessToken): ?string
    {
        if (empty($accessToken)) {
            return null;
        }
        $response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 10,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['email']) ? (string) $data['email'] : null;
    }
}
