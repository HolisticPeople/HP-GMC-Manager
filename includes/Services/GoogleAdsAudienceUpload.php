<?php
namespace HP_GMC\Services;

use HP_GMC\Services\SavedSegmentsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Upload audience segment to Google Ads Customer Match via OfflineUserDataJob.
 * Uses Google Ads API REST (same auth as GoogleAdsReporting).
 * Identifiers are normalized and SHA-256 hashed per Google requirements.
 *
 * @see https://developers.google.com/google-ads/api/docs/remarketing/audience-segments/customer-match/manage
 */
class GoogleAdsAudienceUpload
{
    private const SCOPE = 'https://www.googleapis.com/auth/adwords';

    private static function getAdsApiVersion(): string
    {
        return defined('HP_GMC_ADS_API_VERSION') ? HP_GMC_ADS_API_VERSION : 'v20';
    }

    private const EEA_COUNTRY_CODES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO'];

    /**
     * Upload segment to Google Ads (create or append to Customer Match list).
     *
     * @param int $segmentId Saved segment id
     * @param bool $append True = append to existing list, false = replace (remove_all then add)
     * @param string|null $userListResourceName Existing list resource name (e.g. customers/123/lists/456); if null and append=false we create a new list
     * @return array{success: bool, job_resource_name?: string, user_list_resource_name?: string, count?: int, error?: string}
     */
    public static function upload(int $segmentId, bool $append, ?string $userListResourceName = null): array
    {
        try {
            $repo = new SavedSegmentsRepository();
            $seg = $repo->get($segmentId);
            if (!$seg) {
                return ['success' => false, 'error' => 'Segment not found'];
            }
            $def = json_decode($seg['filter_definition'], true);
            if (!is_array($def)) {
                return ['success' => false, 'error' => 'Invalid filter definition'];
            }
            $rows = self::runSegment($def);
            $rows = self::applyConsentFilter($rows);
            if (empty($rows)) {
                $repo->set_last_upload($segmentId, null, 'failure', null);
                return ['success' => false, 'error' => 'No users to upload after consent filter.', 'count' => 0];
            }
            $customerId = \HP_GMC\Services\GoogleApiClient::getAdsCustomerId();
            if (!$userListResourceName) {
                // v20 uses audiences; same numeric ID works for both path forms.
            $userListResourceName = $seg['gmc_user_list_id'] ? 'customers/' . $customerId . '/audiences/' . $seg['gmc_user_list_id'] : null;
            }
            if (!$userListResourceName) {
                $createList = self::createUserList($customerId, $seg['name']);
                if (!$createList['success']) {
                    return $createList;
                }
                $userListResourceName = $createList['resource_name'];
            }
            $operations = self::buildOperations($rows, !$append);
            $jobResult = self::createAndRunJob($customerId, $userListResourceName, $operations);
            if (!$jobResult['success']) {
                $repo->set_last_upload($segmentId, null, 'failure', null);
                return $jobResult;
            }
            $jobResourceName = $jobResult['job_resource_name'];
            $listId = self::extractListIdFromResourceName($userListResourceName);
            $repo->set_last_upload($segmentId, $jobResourceName, 'pending', $listId);
            return [
                'success' => true,
                'job_resource_name' => $jobResourceName,
                'user_list_resource_name' => $userListResourceName,
                'count' => count($rows),
            ];
        } catch (\Throwable $e) {
            if (isset($repo) && isset($segmentId)) {
                $repo->set_last_upload($segmentId, null, 'failure', null);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get status of an offline user data job (PENDING, RUNNING, SUCCESS, FAILED).
     *
     * @param string $jobResourceName e.g. customers/123/offlineUserDataJobs/456
     * @return array{success: bool, status?: string, error?: string}
     */
    public static function getJobStatus(string $jobResourceName): array
    {
        try {
            $customerId = \HP_GMC\Services\GoogleApiClient::getAdsCustomerId();
            $devToken = get_option('hp_gmc_ads_developer_token', '');
            if (empty($devToken)) {
                return ['success' => false, 'error' => 'Google Ads developer token not configured.'];
            }
            $loginCustomerId = self::getLoginCustomerId($customerId);
            $accessToken = \HP_GMC\Services\GoogleApiClient::getAdsAccessToken();
            $url = 'https://googleads.googleapis.com/' . self::getAdsApiVersion() . '/customers/' . $customerId . '/googleAds:searchStream';
            $gaql = "SELECT offline_user_data_job.status, offline_user_data_job.resource_name FROM offline_user_data_job WHERE offline_user_data_job.resource_name = '" . str_replace("'", "\\'", $jobResourceName) . "'";
            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'developer-token' => $devToken,
                'login-customer-id' => $loginCustomerId,
            ];
            $response = wp_remote_post($url, [
                'headers' => $headers,
                'body' => wp_json_encode(['query' => $gaql]),
                'timeout' => 15,
            ]);
            if (is_wp_error($response)) {
                return ['success' => false, 'error' => $response->get_error_message()];
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            if ($code >= 400) {
                return ['success' => false, 'error' => self::formatApiError($code, $decoded, 'job status')];
            }
            $rows = [];
            if (is_array($decoded)) {
                foreach ($decoded as $chunk) {
                    if (isset($chunk['results']) && is_array($chunk['results'])) {
                        $rows = array_merge($rows, $chunk['results']);
                    }
                }
            }
            $status = $rows[0]['offlineUserDataJob']['status'] ?? 'UNKNOWN';
            return ['success' => true, 'status' => $status];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function runSegment(array $def): array
    {
        if (!class_exists(\HP_Abilities\Services\SegmentFilterEngine::class)) {
            return [];
        }
        $engine = new \HP_Abilities\Services\SegmentFilterEngine();
        $out = $engine->run($def, null);
        return $out['rows'];
    }

    /** Exclude EEA users without consent (placeholder: check option or user meta). */
    private static function applyConsentFilter(array $rows): array
    {
        $consentOption = get_option('hp_gmc_audience_upload_eea_consent', 'exclude');
        if ($consentOption === 'include_all') {
            return $rows;
        }
        $filtered = [];
        foreach ($rows as $row) {
            $country = strtoupper(trim($row['country'] ?? ''));
            if (!in_array($country, self::EEA_COUNTRY_CODES, true)) {
                $filtered[] = $row;
                continue;
            }
            if ($consentOption === 'exclude') {
                continue;
            }
            $filtered[] = $row;
        }
        return $filtered;
    }

    private static function hashIdentifier(string $value): string
    {
        $normalized = trim(strtolower($value));
        return hash('sha256', $normalized);
    }

    private static function buildOperations(array $rows, bool $removeAllFirst): array
    {
        $operations = [];
        if ($removeAllFirst) {
            $operations[] = ['removeAll' => true];
        }
        foreach ($rows as $row) {
            $userIdentifiers = [];
            $email = trim($row['email'] ?? '');
            if ($email !== '' && is_email($email)) {
                $userIdentifiers[] = ['hashedEmail' => self::hashIdentifier($email)];
            }
            $phone = preg_replace('/[^\d+]/', '', $row['phone'] ?? '');
            if (strlen($phone) >= 10) {
                $userIdentifiers[] = ['hashedPhoneNumber' => self::hashIdentifier($phone)];
            }
            if (!empty($userIdentifiers)) {
                $operations[] = ['create' => ['userIdentifiers' => $userIdentifiers]];
            }
        }
        return $operations;
    }

    private static function getLoginCustomerId(string $customerId): string
    {
        // When Manager Account ID is set, use it (required when OAuth user or service account accesses client via MCC).
        $managerId = \HP_GMC\Services\GoogleApiClient::getAdsManagerId();
        return $managerId !== '' ? $managerId : $customerId;
    }

    private static function createUserList(string $customerId, string $name): array
    {
        $loginCustomerId = self::getLoginCustomerId($customerId);
        // Customer Match lists are UserList resources; audiences:mutate is for Audience (dimensions/scope).
        $url = 'https://googleads.googleapis.com/' . self::getAdsApiVersion() . '/customers/' . $customerId . '/userLists:mutate';
        $devToken = get_option('hp_gmc_ads_developer_token', '');
        if (empty($devToken)) {
            return ['success' => false, 'error' => 'Google Ads developer token not configured.'];
        }
        $accessToken = \HP_GMC\Services\GoogleApiClient::getAdsAccessToken();
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'developer-token' => $devToken,
            'login-customer-id' => $loginCustomerId,
        ];
        // Staging debug: log request identity and target (no secrets).
        $authType = (class_exists(\HP_GMC\Services\GoogleAdsOAuth::class) && \HP_GMC\Services\GoogleAdsOAuth::isConnected()) ? 'oauth' : 'service_account';
        $identityEmail = $authType === 'oauth' && class_exists(\HP_GMC\Services\GoogleAdsOAuth::class)
            ? (\HP_GMC\Services\GoogleAdsOAuth::getStoredEmail() ?? 'oauth_connected')
            : null;
        if ($identityEmail === null) {
            try {
                $creds = \HP_GMC\Services\GoogleApiClient::loadCredentials();
                $identityEmail = isset($creds['client_email']) ? $creds['client_email'] : 'unknown';
            } catch (\Throwable $e) {
                $identityEmail = 'load_failed';
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log(json_encode([
                'event' => 'gmc.ads.create_user_list.request',
                'auth' => $authType,
                'customerId' => $customerId,
                'loginCustomerId' => $loginCustomerId,
                'url' => $url,
                'identity' => $identityEmail,
                'api_version' => self::getAdsApiVersion(),
            ]));
        }
        // membershipLifeSpan: max 540 days per Google Ads Customer Match policy.
        $body = [
            'operations' => [
                [
                    'create' => [
                        'name' => substr($name, 0, 80),
                        'description' => 'Customer Match list from GMC Manager',
                        'membershipLifeSpan' => 540,
                        'crmBasedUserList' => [
                            'uploadKeyType' => 'CONTACT_INFO',
                        ],
                    ],
                ],
            ],
        ];
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        $resBody = wp_remote_retrieve_body($response);
        $decoded = json_decode($resBody, true);
        if ($code >= 400) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log(json_encode([
                    'event' => 'gmc.ads.create_user_list.error',
                    'httpCode' => $code,
                    'customerId' => $customerId,
                    'loginCustomerId' => $loginCustomerId,
                    'response_body' => $resBody,
                    'decoded_error' => is_array($decoded) ? ($decoded['error'] ?? $decoded) : null,
                ]));
            }
            $err = self::formatApiError($code, $decoded, 'creating user list');
            $apiMsg = is_array($decoded) ? ($decoded['error']['message'] ?? json_encode($decoded)) : '';
            $err .= ' [Debug: customerId=' . $customerId . ', loginCustomerId=' . ($loginCustomerId ?? 'none') . ', httpCode=' . $code . ', api=' . substr($apiMsg, 0, 200) . ']';
            return ['success' => false, 'error' => $err];
        }
        $resourceName = $decoded['results'][0]['resourceName'] ?? null;
        if (!$resourceName) {
            return ['success' => false, 'error' => 'User list created but no resource name returned.'];
        }
        return ['success' => true, 'resource_name' => $resourceName];
    }

    private static function createAndRunJob(string $customerId, string $userListResourceName, array $operations): array
    {
        $url = 'https://googleads.googleapis.com/' . self::getAdsApiVersion() . '/customers/' . $customerId . '/offlineUserDataJobs';
        $devToken = get_option('hp_gmc_ads_developer_token', '');
        if (empty($devToken)) {
            return ['success' => false, 'error' => 'Google Ads developer token not configured.'];
        }
        $loginCustomerId = self::getLoginCustomerId($customerId);
        $accessToken = \HP_GMC\Services\GoogleApiClient::getAdsAccessToken();
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'developer-token' => $devToken,
            'login-customer-id' => $loginCustomerId,
        ];
        // Consent required for create operations (Customer Match policy).
        $job = [
            'type' => 'CUSTOMER_MATCH_USER_LIST',
            'customerMatchUserListMetadata' => [
                'userList' => $userListResourceName,
                'consent' => [
                    'adUserData' => 'GRANTED',
                    'adPersonalization' => 'GRANTED',
                ],
            ],
        ];
        $createResponse = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($job),
            'timeout' => 30,
        ]);
        if (is_wp_error($createResponse)) {
            return ['success' => false, 'error' => $createResponse->get_error_message()];
        }
        $createCode = wp_remote_retrieve_response_code($createResponse);
        $createBody = wp_remote_retrieve_body($createResponse);
        $createDecoded = json_decode($createBody, true);
        if ($createCode >= 400) {
            return ['success' => false, 'error' => self::formatApiError($createCode, $createDecoded, 'creating offline job')];
        }
        $jobResourceName = $createDecoded['resourceName'] ?? $createDecoded['name'] ?? null;
        if (!$jobResourceName) {
            return ['success' => false, 'error' => 'Job created but no resource name returned.'];
        }
        $addUrl = 'https://googleads.googleapis.com/' . self::getAdsApiVersion() . '/' . $jobResourceName . ':addOperations';
        $addBody = ['operations' => $operations, 'enablePartialFailure' => true];
        $addResponse = wp_remote_post($addUrl, [
            'headers' => $headers,
            'body' => wp_json_encode($addBody),
            'timeout' => 60,
        ]);
        if (is_wp_error($addResponse)) {
            return ['success' => false, 'error' => $addResponse->get_error_message()];
        }
        $addCode = wp_remote_retrieve_response_code($addResponse);
        if ($addCode >= 400) {
            $addDecoded = json_decode(wp_remote_retrieve_body($addResponse), true);
            return ['success' => false, 'error' => self::formatApiError($addCode, $addDecoded, 'adding operations')];
        }
        $runUrl = 'https://googleads.googleapis.com/' . self::getAdsApiVersion() . '/' . $jobResourceName . ':run';
        $runResponse = wp_remote_post($runUrl, [
            'headers' => $headers,
            'body' => '{}',
            'timeout' => 15,
        ]);
        if (is_wp_error($runResponse)) {
            return ['success' => true, 'job_resource_name' => $jobResourceName, 'error' => 'Job created and operations added; run failed: ' . $runResponse->get_error_message()];
        }
        if (wp_remote_retrieve_response_code($runResponse) >= 400) {
            return ['success' => true, 'job_resource_name' => $jobResourceName];
        }
        return ['success' => true, 'job_resource_name' => $jobResourceName];
    }

    /**
     * Turn a bare HTTP code or API error into a clear message for the UI (no debug log needed).
     */
    private static function formatApiError(int $code, $decoded, string $step): string
    {
        $msg = null;
        if (is_array($decoded)) {
            $msg = $decoded['error']['message'] ?? $decoded['error']['message'] ?? null;
            if ($msg === null && isset($decoded[0]['error']['message'])) {
                $msg = $decoded[0]['error']['message'];
            }
        }
        if ($msg === null || $msg === '' || preg_match('/^HTTP \d+$/i', $msg)) {
            $hint = $code === 404
                ? ' Not found — check Google Ads customer ID (Settings), manager ID, and that the account exists.'
                : ($code === 403 ? ' Forbidden — check developer token and OAuth scope (AdWords).' : '');
            $msg = "Google Ads API ({$step}): HTTP {$code}{$hint}";
        } else {
            $msg = "Google Ads API ({$step}): " . $msg;
        }
        // When Ads API rejects the token or permission, point to the right setup (OAuth vs service account).
        $usingOAuth = class_exists(\HP_GMC\Services\GoogleAdsOAuth::class) && \HP_GMC\Services\GoogleAdsOAuth::isConnected();
        if ($code === 401 || $code === 403 || $code === 500) {
            if (stripos($msg, 'missing required authentication credential') !== false
                || stripos($msg, 'invalid authentication credential') !== false
                || stripos($msg, 'Expected OAuth 2 access token') !== false) {
                if ($usingOAuth) {
                    $msg .= ' Reconnect in GMC Manager > Settings > Upload to Google Ads (OAuth): Disconnect then Connect with Google again.';
                } else {
                    $msg .= ' Add the service account email (from GMC Manager > Settings) as a user in Google Ads: Admin > Access and security, then grant access to the customer/manager account.';
                }
            }
            if (stripos($msg, 'caller does not have permission') !== false
                || stripos($msg, 'PERMISSION_DENIED') !== false
                || stripos($msg, 'does not have permission') !== false) {
                if ($usingOAuth) {
                    $msg .= ' You are using OAuth. Check: (1) Connected Google account has Standard or Admin access to the manager account (Manager Account ID in Settings) and the manager has access to the client — Google Ads > Admin > Access and security. (2) Developer token is approved for production (not Test account): Google Ads > Tools & settings > API Center. (3) The client account is eligible for Customer Match (see support.google.com/google-ads/answer/6334160).';
                } else {
                    $msg .= ' In Google Ads (client account): give the service account Admin access (Admin > Access and security). If the service account shows an "allowed domains" warning, add the relevant domain in Access and security > Allowed domains so API access is not restricted. Ensure the account is eligible for Customer Match and the developer token is approved for production.';
                }
            }
        }
        return $msg;
    }

    private static function extractListIdFromResourceName(string $resourceName): ?string
    {
        // UserList resource name is customers/xxx/userLists/yyy.
        if (preg_match('#/userLists/(\d+)$#', $resourceName, $m)) {
            return $m[1];
        }
        return null;
    }
}
