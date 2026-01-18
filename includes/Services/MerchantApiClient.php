<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merchant API v1 client wrapper.
 * Handles authentication, API calls, and dry run mode.
 */
class MerchantApiClient
{
    private ?object $client = null;
    private string $merchantId;
    private string $mode;

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
     * Initialize the Google API client.
     */
    private function initClient(): void
    {
        if ($this->client !== null) {
            return;
        }

        $serviceAccountPath = get_option('hp_gmc_service_account_path', '');

        if (empty($serviceAccountPath) || !file_exists($serviceAccountPath)) {
            throw new \Exception('Service account JSON file not found: ' . $serviceAccountPath);
        }

        // This will use Google's PHP client library when Composer is set up
        // For now, we'll create a placeholder that can be replaced
        $this->client = new \stdClass();
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
            $this->initClient();
            
            // TODO: Implement actual API call when Composer dependencies are set up
            // For now, return a placeholder response
            return [
                'success' => true,
                'message' => 'Connection test placeholder - Composer setup required',
                'merchant_id' => $this->merchantId,
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
            $this->initClient();

            // TODO: Implement actual API calls when Composer dependencies are set up
            // This is a placeholder that should be replaced with actual Google API client calls
            
            throw new \Exception('Real API calls require Composer setup. Run: composer install');
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
     * Get product statuses from GMC.
     */
    public function getProductStatuses(int $pageSize = 100, ?string $pageToken = null): array
    {
        $endpoint = "accounts/{$this->merchantId}/productstatuses";
        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }
        return $this->call('GET', $endpoint, $params);
    }

    /**
     * Get account status from GMC.
     */
    public function getAccountStatus(): array
    {
        $endpoint = "accounts/{$this->merchantId}";
        return $this->call('GET', $endpoint);
    }
}
