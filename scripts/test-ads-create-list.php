#!/usr/bin/env php
<?php
/**
 * CLI test: create a single Google Ads Customer Match user list (userLists:mutate).
 * Use this to verify that adding the service account domain to Allowed domains fixed 403.
 *
 * Run from plugin root (where composer.json is):
 *   php scripts/test-ads-create-list.php --credentials=/path/to/service-account.json --developer-token=YOUR_TOKEN --customer-id=6629157252 [--manager-id=6063247756]
 *
 * Get developer token, customer ID, and manager ID from GMC Manager > Settings.
 * credentials path = same as "Service Account JSON Path" in Settings.
 */

$pluginRoot = dirname(__DIR__);
if (!is_file($pluginRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run composer install in the plugin root: cd $pluginRoot && composer install\n");
    exit(1);
}
require_once $pluginRoot . '/vendor/autoload.php';

$options = getopt('', ['credentials:', 'developer-token:', 'customer-id:', 'manager-id:']);
$credPath = $options['credentials'] ?? null;
$devToken = $options['developer-token'] ?? null;
$customerId = preg_replace('/[^0-9]/', '', (string) ($options['customer-id'] ?? ''));
$managerId = isset($options['manager-id']) ? preg_replace('/[^0-9]/', '', (string) $options['manager-id']) : '';

if (!$credPath || !$devToken || !$customerId) {
    fwrite(STDERR, "Usage: php scripts/test-ads-create-list.php --credentials=/path/to/service-account.json --developer-token=TOKEN --customer-id=6629157252 [--manager-id=6063247756]\n");
    exit(1);
}
if (!is_readable($credPath)) {
    fwrite(STDERR, "Credentials file not readable: $credPath\n");
    exit(1);
}

$scope = 'https://www.googleapis.com/auth/adwords';
$creds = json_decode(file_get_contents($credPath), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($creds)) {
    fwrite(STDERR, "Invalid JSON in credentials file.\n");
    exit(1);
}

$credentials = new \Google\Auth\Credentials\ServiceAccountCredentials([$scope], $creds);
$authToken = $credentials->fetchAuthToken();
$accessToken = $authToken['access_token'] ?? '';
if (empty($accessToken)) {
    fwrite(STDERR, "Failed to obtain access token.\n");
    exit(1);
}

$loginCustomerId = $managerId !== '' ? $managerId : $customerId;
$url = 'https://googleads.googleapis.com/v20/customers/' . $customerId . '/userLists:mutate';
$name = 'CLI test list ' . date('Y-m-d H:i:s');
$body = [
    'operations' => [
        [
            'create' => [
                'name' => substr($name, 0, 80),
                'description' => 'Customer Match list from GMC Manager (CLI test)',
                'membershipLifeSpan' => 540,
                'crmBasedUserList' => [
                    'uploadKeyType' => 'CONTACT_INFO',
                ],
            ],
        ],
    ],
];

$headers = [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json',
    'developer-token: ' . $devToken,
    'login-customer-id: ' . $loginCustomerId,
];

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => json_encode($body),
        'timeout' => 30,
    ],
]);

$response = @file_get_contents($url, false, $ctx);
$code = 0;
if (isset($http_response_header[0]) && preg_match('/ (\d{3}) /', $http_response_header[0], $m)) {
    $code = (int) $m[1];
}

$decoded = $response ? json_decode($response, true) : null;

if ($code >= 200 && $code < 300) {
    $resourceName = $decoded['results'][0]['resourceName'] ?? null;
    echo "OK (HTTP $code)\n";
    echo "resourceName: " . ($resourceName ?? '(none)') . "\n";
    if ($decoded) {
        echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit(0);
}

fwrite(STDERR, "HTTP $code\n");
fwrite(STDERR, $response ?: "(empty body)\n");
if (is_array($decoded) && isset($decoded['error']['message'])) {
    fwrite(STDERR, "API message: " . $decoded['error']['message'] . "\n");
}
exit(1);
