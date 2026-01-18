<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\MerchantApiClient;
use HP_GMC\Services\ShippingSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP abilities for account-level GMC operations.
 */
class AccountAbilities
{
    /**
     * Get shipping settings from GMC.
     */
    public static function getShippingSettings(array $params): array
    {
        $client = new MerchantApiClient();
        $result = $client->getShippingSettings();

        return $result;
    }

    /**
     * List all shipping services with their countries.
     */
    public static function listShippingServices(array $params): array
    {
        $client = new MerchantApiClient();
        $result = $client->getShippingSettings();

        if (!$result['success']) {
            return $result;
        }

        $services = $result['data']['services'] ?? [];
        $formatted = [];

        foreach ($services as $service) {
            $formatted[] = [
                'name' => $service['serviceName'] ?? 'Unknown',
                'active' => $service['active'] ?? false,
                'countries' => $service['deliveryCountries'] ?? [],
                'delivery_time' => $service['deliveryTime'] ?? null,
            ];
        }

        return [
            'success' => true,
            'count' => count($formatted),
            'services' => $formatted,
            'dry_run' => hp_gmc_is_dry_run(),
        ];
    }

    /**
     * Enable shipping to a country.
     */
    public static function enableCountry(array $params): array
    {
        $countryCode = strtoupper($params['country_code'] ?? '');
        $serviceName = $params['service_name'] ?? null;

        if (empty($countryCode) || strlen($countryCode) !== 2) {
            return [
                'success' => false,
                'error' => 'Invalid country code. Use ISO 3166-1 alpha-2 format (e.g., "CA", "GB")',
            ];
        }

        $client = new MerchantApiClient();
        
        // Get current settings
        $current = $client->getShippingSettings();
        
        if (!$current['success']) {
            return $current;
        }

        $services = $current['data']['services'] ?? [];
        $etag = $current['data']['etag'] ?? '';
        $modified = false;

        foreach ($services as &$service) {
            // If service name specified, only update that one
            if ($serviceName && ($service['serviceName'] ?? '') !== $serviceName) {
                continue;
            }

            $countries = $service['deliveryCountries'] ?? [];
            
            if (!in_array($countryCode, $countries)) {
                $countries[] = $countryCode;
                $service['deliveryCountries'] = $countries;
                $modified = true;
            }
        }

        if (!$modified) {
            return [
                'success' => true,
                'message' => "Country $countryCode already enabled in shipping services",
                'dry_run' => hp_gmc_is_dry_run(),
            ];
        }

        // Update settings
        $result = $client->updateShippingSettings(['services' => $services], $etag);
        $result['country_code'] = $countryCode;
        $result['action'] = 'enable_country';

        return $result;
    }

    /**
     * Disable shipping to a country.
     */
    public static function disableCountry(array $params): array
    {
        $countryCode = strtoupper($params['country_code'] ?? '');

        if (empty($countryCode) || strlen($countryCode) !== 2) {
            return [
                'success' => false,
                'error' => 'Invalid country code. Use ISO 3166-1 alpha-2 format (e.g., "CA", "GB")',
            ];
        }

        $client = new MerchantApiClient();
        
        // Get current settings
        $current = $client->getShippingSettings();
        
        if (!$current['success']) {
            return $current;
        }

        $services = $current['data']['services'] ?? [];
        $etag = $current['data']['etag'] ?? '';
        $modified = false;
        $removedFrom = [];

        foreach ($services as &$service) {
            $countries = $service['deliveryCountries'] ?? [];
            
            if (in_array($countryCode, $countries)) {
                $service['deliveryCountries'] = array_values(array_filter($countries, fn($c) => $c !== $countryCode));
                $modified = true;
                $removedFrom[] = $service['serviceName'] ?? 'Unknown';
            }
        }

        if (!$modified) {
            return [
                'success' => true,
                'message' => "Country $countryCode not found in any shipping services",
                'dry_run' => hp_gmc_is_dry_run(),
            ];
        }

        // Update settings
        $result = $client->updateShippingSettings(['services' => $services], $etag);
        $result['country_code'] = $countryCode;
        $result['action'] = 'disable_country';
        $result['removed_from_services'] = $removedFrom;

        return $result;
    }

    /**
     * Get overall account status.
     */
    public static function getAccountStatus(array $params): array
    {
        $client = new MerchantApiClient();
        $result = $client->getAccountStatus();

        // Add local stats
        $result['local_stats'] = \HP_GMC\Services\IssueMonitor::getSummary();

        return $result;
    }
}
