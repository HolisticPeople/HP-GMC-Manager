<?php
namespace HP_GMC\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Shopping\Merchant\Accounts\V1beta\Client\AccountsServiceClient;
use Google\Shopping\Merchant\Products\V1beta\Client\ProductsServiceClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merchant API v1 client wrapper.
 * Handles authentication, API calls, and dry run mode.
 */
class MerchantApiClient
{
    private ?object $accountsClient = null;
    private ?object $productsClient = null;
    private string $merchantId;
    private string $mode;
    private ?array $credentials = null;

    public function __construct()
    {
        $this->merchantId = get_option('hp_gmc_merchant_id', '');
        $this->mode = $this->determineMode();
    }

    /**
     * Determine the operating mode.
     */
    private function determineMode(): string
    {
        $mode = get_option('hp_gmc_mode', 'auto');

        if ($mode === 'auto') {
            return hp_gmc_get_environment() === 'production' ? 'live' : 'dry_run';
        }

        return $mode;
    }

    /**
     * Check if we're in dry run mode.
     */
    public function isDryRun(): bool
    {
        return in_array($this->mode, ['dry_run', 'mock']);
    }

    /**
     * Load credentials from service account JSON.
     */
    private function loadCredentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $serviceAccountPath = get_option('hp_gmc_service_account_path', '');

        if (empty($serviceAccountPath) || !file_exists($serviceAccountPath)) {
            throw new \Exception('Service account JSON file not found: ' . $serviceAccountPath);
        }

        $json = file_get_contents($serviceAccountPath);
        $this->credentials = json_decode($json, true);

        if (!$this->credentials || empty($this->credentials['client_email'])) {
            throw new \Exception('Invalid service account JSON file');
        }

        return $this->credentials;
    }

    /**
     * Test the API connection.
     */
    public function testConnection(): array
    {
        if ($this->isDryRun()) {
            return [
                'success' => true,
                'dry_run' => true,
                'message' => 'Dry run mode - connection test simulated',
                'merchant_id' => $this->merchantId,
            ];
        }

        try {
            $creds = $this->loadCredentials();
            
            // Check if Google API classes are available
            if (!class_exists(ServiceAccountCredentials::class)) {
                return [
                    'success' => false,
                    'message' => 'Google API client not installed. Run: composer install',
                ];
            }

            // Create credentials and verify they work
            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/content'],
                $creds
            );

            // Try to get an access token (this validates the credentials)
            $authToken = $credentials->fetchAuthToken();

            if (empty($authToken['access_token'])) {
                return [
                    'success' => false,
                    'message' => 'Failed to obtain access token from Google',
                ];
            }

            return [
                'success' => true,
                'message' => 'Successfully connected to Google Merchant API',
                'merchant_id' => $this->merchantId,
                'service_account' => $creds['client_email'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make an API call (or simulate it in dry run mode).
     */
    public function call(string $method, string $endpoint, array $data = []): array
    {
        if ($this->mode === 'mock') {
            return $this->getMockResponse($method, $endpoint, $data);
        }

        if ($this->mode === 'dry_run') {
            return $this->logAndSimulate($method, $endpoint, $data);
        }

        // Live mode - make actual API call
        return $this->executeReal($method, $endpoint, $data);
    }

    /**
     * Log the action and return a simulated response.
     */
    private function logAndSimulate(string $method, string $endpoint, array $data): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_dry_run_log';

        // Extract action name from endpoint
        $action = $this->extractActionName($endpoint);

        $wpdb->insert($table, [
            'action' => $action,
            'endpoint' => $method . ' ' . $endpoint,
            'params' => wp_json_encode($data),
            'simulated_response' => wp_json_encode(['success' => true]),
            'created_at' => current_time('mysql'),
        ]);

        return [
            'success' => true,
            'dry_run' => true,
            'action' => $action,
            'params' => $data,
            'would_execute' => $method . ' ' . $endpoint,
            'logged_at' => current_time('c'),
        ];
    }

    /**
     * Get a mock response for testing.
     */
    private function getMockResponse(string $method, string $endpoint, array $data): array
    {
        // Return realistic mock data based on the endpoint
        if (strpos($endpoint, 'shippingSettings') !== false) {
            return $this->getMockShippingSettings();
        }

        if (strpos($endpoint, 'productstatuses') !== false) {
            return $this->getMockProductStatuses();
        }

        return [
            'success' => true,
            'mock' => true,
            'data' => [],
        ];
    }

    /**
     * Execute a real API call.
     */
    private function executeReal(string $method, string $endpoint, array $data): array
    {
        try {
            $creds = $this->loadCredentials();

            // For now, implement a basic HTTP call using the auth token
            // The full Google API client usage can be expanded later
            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/content'],
                $creds
            );

            $authToken = $credentials->fetchAuthToken();
            $accessToken = $authToken['access_token'] ?? null;

            if (!$accessToken) {
                throw new \Exception('Failed to obtain access token');
            }

            // Build the API URL - determine base URL based on endpoint type
            if (strpos($endpoint, 'products') !== false || strpos($endpoint, 'productStatuses') !== false) {
                $baseUrl = 'https://merchantapi.googleapis.com/products/v1beta/';
            } else {
                $baseUrl = 'https://merchantapi.googleapis.com/accounts/v1beta/';
            }
            $url = $baseUrl . $endpoint;

            if ($method === 'GET' && !empty($data)) {
                $url .= '?' . http_build_query($data);
            }

            // Make the HTTP request
            $response = wp_remote_request($url, [
                'method' => $method,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => $method !== 'GET' ? wp_json_encode($data) : null,
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $statusCode = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $responseData = json_decode($body, true);

            if ($statusCode >= 400) {
                $errorMessage = $responseData['error']['message'] ?? 'API request failed with status ' . $statusCode;
                throw new \Exception($errorMessage);
            }

            return [
                'success' => true,
                'data' => $responseData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract a readable action name from an endpoint.
     */
    private function extractActionName(string $endpoint): string
    {
        if (strpos($endpoint, 'shippingSettings') !== false) {
            return 'shipping_settings';
        }
        if (strpos($endpoint, 'productstatuses') !== false) {
            return 'product_statuses';
        }
        if (strpos($endpoint, 'products') !== false) {
            return 'products';
        }
        return 'unknown';
    }

    /**
     * Get mock shipping settings.
     */
    private function getMockShippingSettings(): array
    {
        return [
            'success' => true,
            'mock' => true,
            'data' => [
                'services' => [
                    [
                        'serviceName' => 'Standard Shipping',
                        'deliveryCountries' => ['US'],
                        'active' => true,
                        'deliveryTime' => [
                            'minHandlingTimeDays' => 1,
                            'maxHandlingTimeDays' => 2,
                            'minTransitTimeDays' => 3,
                            'maxTransitTimeDays' => 7,
                        ],
                    ],
                    [
                        'serviceName' => 'Express Shipping',
                        'deliveryCountries' => ['US'],
                        'active' => true,
                        'deliveryTime' => [
                            'minHandlingTimeDays' => 0,
                            'maxHandlingTimeDays' => 1,
                            'minTransitTimeDays' => 1,
                            'maxTransitTimeDays' => 2,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get mock product statuses.
     */
    private function getMockProductStatuses(): array
    {
        return [
            'success' => true,
            'mock' => true,
            'data' => [
                'products' => [
                    [
                        'productId' => 'gla_12345',
                        'title' => 'Sample Product 1',
                        'status' => 'approved',
                        'issues' => [],
                    ],
                    [
                        'productId' => 'gla_12346',
                        'title' => 'Sample Product 2',
                        'status' => 'disapproved',
                        'issues' => [
                            ['description' => 'Missing shipping information'],
                        ],
                    ],
                    [
                        'productId' => 'gla_12347',
                        'title' => 'Sample Product 3',
                        'status' => 'warning',
                        'issues' => [
                            ['description' => 'Low quality image'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get shipping settings from GMC.
     */
    public function getShippingSettings(): array
    {
        $endpoint = "accounts/{$this->merchantId}/shippingSettings";
        return $this->call('GET', $endpoint);
    }

    /**
     * Update shipping settings in GMC.
     */
    public function updateShippingSettings(array $settings, string $etag): array
    {
        $endpoint = "accounts/{$this->merchantId}/shippingSettings:insert";
        $settings['etag'] = $etag;
        return $this->call('POST', $endpoint, $settings);
    }

    /**
     * Get product statuses from GMC (list products with status info).
     */
    public function getProductStatuses(int $pageSize = 100, ?string $pageToken = null): array
    {
        // Use products endpoint with productStatuses view
        $endpoint = "accounts/{$this->merchantId}/products";
        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }
        return $this->call('GET', $endpoint, $params);
    }

    /**
     * Get account info from GMC.
     */
    public function getAccountStatus(): array
    {
        $endpoint = "accounts/{$this->merchantId}";
        return $this->call('GET', $endpoint);
    }
    
    /**
     * Get business info from GMC.
     */
    public function getBusinessInfo(): array
    {
        $endpoint = "accounts/{$this->merchantId}/businessInfo";
        return $this->call('GET', $endpoint);
    }
}
