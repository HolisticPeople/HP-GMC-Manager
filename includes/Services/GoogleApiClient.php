<?php
namespace HP_GMC\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generic Google API client for GA4, Ads, and other Google services.
 *
 * Reuses the service account from GMC Manager settings.
 * Each API scope gets its own access token (cached via transients).
 *
 * @since 1.23.0
 */
class GoogleApiClient
{
    /** OAuth2 scope for Google Ads API. */
    public const SCOPE_ADWORDS = 'https://www.googleapis.com/auth/adwords';

    /** @var array<string, string> Cached access tokens keyed by scope */
    private static array $tokenCache = [];

    /**
     * Load the service account credentials from the GMC Manager setting.
     *
     * @return array Decoded service account JSON
     * @throws \RuntimeException If credentials cannot be loaded
     */
    public static function loadCredentials(): array
    {
        $path = get_option('hp_gmc_service_account_path', '');

        if (empty($path) || !file_exists($path)) {
            throw new \RuntimeException(
                'Google service account not configured. Go to GMC Manager > Settings.'
            );
        }

        $json = file_get_contents($path);
        $creds = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($creds)) {
            throw new \RuntimeException('Service account JSON is malformed.');
        }

        return $creds;
    }

    /**
     * Get an access token for a specific Google API scope.
     *
     * Caches tokens in a transient for 50 minutes (tokens last 60 min).
     *
     * @param string $scope OAuth2 scope URL
     * @return string Access token
     * @throws \RuntimeException If token cannot be obtained
     */
    public static function getAccessToken(string $scope): string
    {
        // Check in-memory cache first
        if (!empty(self::$tokenCache[$scope])) {
            return self::$tokenCache[$scope];
        }

        // Check transient cache
        $transientKey = 'hp_gapi_token_' . md5($scope);
        $cached = get_transient($transientKey);
        if ($cached) {
            self::$tokenCache[$scope] = $cached;
            return $cached;
        }

        // Fetch new token
        $creds = self::loadCredentials();

        if (!class_exists(ServiceAccountCredentials::class)) {
            throw new \RuntimeException(
                'google/auth library not installed. Run composer install in hp-gmc-manager.'
            );
        }

        $credentials = new ServiceAccountCredentials([$scope], $creds);
        $authToken = $credentials->fetchAuthToken();
        $accessToken = $authToken['access_token'] ?? '';

        if (empty($accessToken)) {
            throw new \RuntimeException(
                sprintf('Failed to obtain access token for scope: %s', $scope)
            );
        }

        // Cache for 50 minutes
        set_transient($transientKey, $accessToken, 50 * MINUTE_IN_SECONDS);
        self::$tokenCache[$scope] = $accessToken;

        return $accessToken;
    }

    /**
     * Get an access token for the Google Ads API. Prefers OAuth (user account) if connected, else service account.
     *
     * @return string Access token
     * @throws \RuntimeException If neither OAuth nor service account can provide a token
     */
    public static function getAdsAccessToken(): string
    {
        if (class_exists(GoogleAdsOAuth::class) && GoogleAdsOAuth::isConnected()) {
            $token = GoogleAdsOAuth::getAccessToken();
            if (!empty($token)) {
                return $token;
            }
        }
        return self::getAccessToken(self::SCOPE_ADWORDS);
    }

    /**
     * Make a GET request to a Google API.
     *
     * @param string $url Full API URL
     * @param string $scope OAuth2 scope
     * @param array  $params Query parameters
     * @return array Decoded JSON response
     * @throws \RuntimeException On API error
     */
    public static function get(string $url, string $scope, array $params = []): array
    {
        return self::request('GET', $url, $scope, $params);
    }

    /**
     * Make a POST request to a Google API.
     *
     * @param string $url  Full API URL
     * @param string $scope OAuth2 scope
     * @param array  $body  Request body
     * @return array Decoded JSON response
     * @throws \RuntimeException On API error
     */
    public static function post(string $url, string $scope, array $body = []): array
    {
        return self::request('POST', $url, $scope, $body);
    }

    /**
     * Execute an HTTP request against a Google API.
     *
     * @param string $method HTTP method
     * @param string $url    Full API URL
     * @param string $scope  OAuth2 scope
     * @param array  $data   Query params (GET) or body (POST)
     * @return array Decoded JSON response
     * @throws \RuntimeException On failure
     */
    private static function request(string $method, string $url, string $scope, array $data = []): array
    {
        $start = microtime(true);
        $accessToken = self::getAccessToken($scope);

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($method !== 'GET' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);
        $durationMs = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            error_log(json_encode([
                'event'       => 'google_api.request.failed',
                'method'      => $method,
                'url'         => $url,
                'error'       => $response->get_error_message(),
                'duration_ms' => $durationMs,
            ]));
            throw new \RuntimeException('Google API request failed: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true) ?? [];

        if ($statusCode >= 400) {
            $errorMsg = $decoded['error']['message'] ?? "HTTP {$statusCode}";
            if (preg_match('/^HTTP \d+$/i', $errorMsg)) {
                $errorMsg = $statusCode === 404
                    ? 'Not found. Check Google Ads customer ID and manager ID in Settings.'
                    : ($statusCode === 403 ? 'Forbidden. Check developer token and OAuth scope.' : $errorMsg);
            }
            if (function_exists('error_log')) {
                error_log(json_encode([
                    'event'       => 'google_api.request.http_error',
                    'method'      => $method,
                    'url'         => $url,
                    'status'      => $statusCode,
                    'error'       => $errorMsg,
                    'duration_ms' => $durationMs,
                ]));
            }

            // If 401, clear token cache and retry once
            if ($statusCode === 401) {
                $transientKey = 'hp_gapi_token_' . md5($scope);
                delete_transient($transientKey);
                unset(self::$tokenCache[$scope]);
            }

            throw new \RuntimeException(
                sprintf('Google API error (%d): %s', $statusCode, $errorMsg)
            );
        }

        return $decoded;
    }

    /**
     * Get the GA4 property ID from plugin settings.
     *
     * @return string Property ID (e.g., "properties/123456789")
     * @throws \RuntimeException If not configured
     */
    public static function getGA4PropertyId(): string
    {
        $propertyId = get_option('hp_gmc_ga4_property_id', '');

        if (empty($propertyId)) {
            throw new \RuntimeException(
                'GA4 property ID not configured. Go to GMC Manager > Settings.'
            );
        }

        // Ensure it has the "properties/" prefix
        if (strpos($propertyId, 'properties/') !== 0) {
            $propertyId = 'properties/' . $propertyId;
        }

        return $propertyId;
    }

    /**
     * Get the Google Ads customer ID from plugin settings.
     *
     * @return string Customer ID (e.g., "1234567890" without dashes)
     * @throws \RuntimeException If not configured
     */
    public static function getAdsCustomerId(): string
    {
        $customerId = get_option('hp_gmc_ads_customer_id', '');

        if (empty($customerId)) {
            throw new \RuntimeException(
                'Google Ads customer ID not configured. Go to GMC Manager > Settings.'
            );
        }

        // Strip everything except numbers
        return preg_replace('/[^0-9]/', '', $customerId);
    }

    /**
     * Get the Google Ads manager account ID (for API headers).
     *
     * @return string Manager account ID (may be empty if direct account)
     */
    public static function getAdsManagerId(): string
    {
        $managerId = get_option('hp_gmc_ads_manager_id', '');
        // Strip everything except numbers
        return preg_replace('/[^0-9]/', '', $managerId);
    }
}
