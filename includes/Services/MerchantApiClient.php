<?php
namespace HP_GMC\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merchant API v1 client wrapper.
 * Handles authentication, API calls, and dry run mode.
 */
class MerchantApiClient
{
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
            throw new \Exception('Service account path not configured or file not found.');
        }

        $json = file_get_contents($serviceAccountPath);
        $this->credentials = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Service account JSON is malformed.');
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
            
            if (!class_exists(ServiceAccountCredentials::class)) {
                return [
                    'success' => false,
                    'message' => 'Google API client not installed.',
                ];
            }

            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/content'],
                $creds
            );

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
    public function call(string $method, string $endpoint, array $data = [], string $apiType = 'merchant'): array
    {
        if ($this->mode === 'mock') {
            return ['success' => true, 'mock' => true, 'data' => []];
        }

        if ($this->mode === 'dry_run') {
            return $this->logAndSimulate($method, $endpoint, $data);
        }

        return $this->executeReal($method, $endpoint, $data, $apiType);
    }

    /**
     * Log the action and return a simulated response.
     */
    private function logAndSimulate(string $method, string $endpoint, array $data): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_dry_run_log';

        $wpdb->insert($table, [
            'action' => 'api_call',
            'endpoint' => $method . ' ' . $endpoint,
            'params' => wp_json_encode($data),
            'simulated_response' => wp_json_encode(['success' => true]),
            'created_at' => current_time('mysql'),
        ]);

        return [
            'success' => true,
            'dry_run' => true,
            'would_execute' => $method . ' ' . $endpoint,
        ];
    }

    /**
     * Execute a real API call.
     */
    private function executeReal(string $method, string $endpoint, array $data, string $apiType = 'merchant'): array
    {
        try {
            $creds = $this->loadCredentials();

            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/content'],
                $creds
            );

            $authToken = $credentials->fetchAuthToken();
            $accessToken = $authToken['access_token'] ?? null;

            if (!$accessToken) {
                throw new \Exception('Failed to obtain access token');
            }

            // Build the API URL
            if ($apiType === 'content') {
                $baseUrl = "https://shoppingcontent.googleapis.com/content/v2.1/{$this->merchantId}/";
            } elseif (strpos($endpoint, 'products') !== false || strpos($endpoint, 'productStatuses') !== false) {
                $baseUrl = "https://merchantapi.googleapis.com/products/v1beta/";
            } else {
                $baseUrl = "https://merchantapi.googleapis.com/accounts/v1beta/";
            }
            $url = $baseUrl . $endpoint;

            if ($method === 'GET' && !empty($data)) {
                $url .= '?' . http_build_query($data);
            }

            $response = wp_remote_request($url, [
                'method' => $method,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => ($method !== 'GET' && !empty($data)) ? wp_json_encode($data) : null,
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
                error_log('[HP-GMC] API Error: ' . $errorMessage . ' | Body: ' . $body . ' | URL: ' . $url);
                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'http_code' => $statusCode,
                ];
            }

            return [
                'success' => true,
                'data' => $responseData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'http_code' => 0,
            ];
        }
    }

    /**
     * Get shipping settings from GMC.
     */
    public function getShippingSettings(): array
    {
        return $this->call('GET', "accounts/{$this->merchantId}/shippingSettings");
    }

    /**
     * Get product statuses from GMC.
     */
    public function getProductStatuses(int $pageSize = 100, ?string $pageToken = null): array
    {
        $params = ['pageSize' => $pageSize];
        if ($pageToken) $params['pageToken'] = $pageToken;
        return $this->call('GET', "accounts/{$this->merchantId}/products", $params);
    }

    /**
     * Get account info from GMC.
     */
    public function getAccountStatus(): array
    {
        return $this->call('GET', "accounts/{$this->merchantId}");
    }

    /**
     * Create a supplemental datafeed in GMC.
     * 
     * Content API v2.1 requires: name, fileName, contentType.
     * For supplemental feeds, we also need targets array.
     */
    public function createSupplementalFeed(string $name, string $country = 'US'): array
    {
        // Generate unique filename from feed name
        $fileName = sanitize_file_name($name) . '-' . time() . '.tsv';
        
        $feedData = [
            'name' => $name,
            'fileName' => $fileName,
            'contentType' => 'products',
            'attributeLanguage' => 'en',
            'targets' => [
                [
                    'country' => $country,
                    'language' => 'en',
                    'includedDestinations' => ['Shopping'],
                ],
            ],
            'fetchSchedule' => [
                'fetchUrl' => 'https://example.com/pending', // Will be updated after creation
                'timeZone' => 'America/Los_Angeles',
                'hour' => 6,
            ],
            'format' => [
                'columnDelimiter' => 'tab',
                'fileEncoding' => 'utf-8',
                'quotingMode' => 'value quoting',
            ],
        ];

        return $this->call('POST', "datafeeds", $feedData, 'content');
    }

    /**
     * Update feed content (fetch URL) and trigger immediate fetch.
     * Uses PUT (full replacement) as Content API doesn't support PATCH.
     */
    public function uploadFeedContent(string $feedId, string $fileUrl): array
    {
        // First GET the existing feed to preserve all fields
        $getResult = $this->call('GET', "datafeeds/{$feedId}", [], 'content');
        
        if (!$getResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to get existing feed: ' . ($getResult['error'] ?? 'unknown'),
            ];
        }
        
        $feedData = $getResult['data'];
        
        // Update the fetch URL
        if (!isset($feedData['fetchSchedule'])) {
            $feedData['fetchSchedule'] = [];
        }
        $feedData['fetchSchedule']['fetchUrl'] = $fileUrl;
        $feedData['fetchSchedule']['paused'] = false;
        
        // Remove read-only fields that can't be sent in update (but keep id - it's required)
        unset($feedData['kind']);
        
        // PUT the updated feed (full replacement)
        $updateResult = $this->call('PUT', "datafeeds/{$feedId}", $feedData, 'content');

        if ($updateResult['success']) {
            // Trigger immediate fetch
            $fetchResult = $this->call('POST', "datafeeds/{$feedId}/fetchNow", [], 'content');
            $updateResult['fetch_triggered'] = $fetchResult['success'];
        }

        return $updateResult;
    }

    /**
     * Get datafeed configuration.
     */
    public function getDatafeed(string $feedId): array
    {
        return $this->call('GET', "datafeeds/{$feedId}", [], 'content');
    }

    /**
     * Get datafeed processing status (itemsTotal, itemsValid, processingStatus, etc.)
     */
    public function getDatafeedStatus(string $feedId): array
    {
        return $this->call('GET', "datafeedstatuses/{$feedId}", [], 'content');
    }

    /**
     * Delete a datafeed.
     */
    public function deleteDatafeed(string $feedId): array
    {
        return $this->call('DELETE', "datafeeds/{$feedId}", [], 'content');
    }

    /**
     * Delete a product from GMC.
     * 
     * @param string $offerId The full offer ID (e.g., online:en:US:gla_42304)
     * @return array Result of deletion
     */
    public function deleteProduct(string $offerId): array
    {
        // Content API v2.1 uses format: merchantId/products/channel~language~feedLabel~offerId
        // But we receive offerId in format: online:en:US:gla_42304
        
        // Convert colon-separated to tilde-separated for API
        $productId = str_replace(':', '~', $offerId);
        
        // Use Content API v2.1 for product deletion
        return $this->call('DELETE', "products/{$productId}", [], 'content');
    }

    /**
     * Batch delete products from GMC.
     * 
     * @param array $offerIds Array of offer IDs to delete
     * @return array Results with success/failure counts
     */
    public function batchDeleteProducts(array $offerIds): array
    {
        $results = [
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Content API supports batch operations via custombatch
        // But for simplicity, we'll do sequential deletes with rate limiting
        foreach ($offerIds as $index => $offerId) {
            $result = $this->deleteProduct($offerId);
            
            if ($result['success']) {
                $results['deleted']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'offer_id' => $offerId,
                    'error' => $result['error'] ?? 'Unknown error',
                ];
            }

            // Rate limit: pause every 10 deletes
            if (($index + 1) % 10 === 0) {
                usleep(500000); // 0.5 second pause
            }
        }

        return $results;
    }
}
