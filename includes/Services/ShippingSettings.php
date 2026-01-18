<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service for managing account-level shipping settings in GMC.
 */
class ShippingSettings
{
    private MerchantApiClient $client;
    private ?array $cachedSettings = null;
    private ?string $cachedEtag = null;

    public function __construct()
    {
        $this->client = new MerchantApiClient();
    }

    /**
     * Get current shipping settings.
     */
    public function get(): array
    {
        if ($this->cachedSettings !== null) {
            return [
                'success' => true,
                'data' => $this->cachedSettings,
                'etag' => $this->cachedEtag,
            ];
        }

        $result = $this->client->getShippingSettings();

        if ($result['success']) {
            $this->cachedSettings = $result['data'] ?? [];
            $this->cachedEtag = $result['data']['etag'] ?? '';
        }

        return $result;
    }

    /**
     * Get list of shipping services.
     */
    public function getServices(): array
    {
        $result = $this->get();

        if (!$result['success']) {
            return [];
        }

        return $result['data']['services'] ?? [];
    }

    /**
     * Get all countries that have shipping enabled.
     */
    public function getEnabledCountries(): array
    {
        $services = $this->getServices();
        $countries = [];

        foreach ($services as $service) {
            if (!($service['active'] ?? false)) {
                continue;
            }

            foreach ($service['deliveryCountries'] ?? [] as $country) {
                if (!in_array($country, $countries)) {
                    $countries[] = $country;
                }
            }
        }

        sort($countries);
        return $countries;
    }

    /**
     * Check if a country is enabled for shipping.
     */
    public function isCountryEnabled(string $countryCode): bool
    {
        $countryCode = strtoupper($countryCode);
        return in_array($countryCode, $this->getEnabledCountries());
    }

    /**
     * Enable a country for shipping.
     */
    public function enableCountry(string $countryCode, ?string $serviceName = null): array
    {
        $countryCode = strtoupper($countryCode);

        $result = $this->get();
        if (!$result['success']) {
            return $result;
        }

        $services = $result['data']['services'] ?? [];
        $etag = $result['etag'] ?? '';
        $modified = false;

        foreach ($services as &$service) {
            // Skip if specific service requested and this isn't it
            if ($serviceName !== null && ($service['serviceName'] ?? '') !== $serviceName) {
                continue;
            }

            // Skip inactive services unless specifically targeted
            if ($serviceName === null && !($service['active'] ?? false)) {
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
                'message' => "Country $countryCode already enabled",
                'modified' => false,
            ];
        }

        // Clear cache
        $this->cachedSettings = null;

        return $this->client->updateShippingSettings(['services' => $services], $etag);
    }

    /**
     * Disable a country for shipping (remove from all services).
     */
    public function disableCountry(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        $result = $this->get();
        if (!$result['success']) {
            return $result;
        }

        $services = $result['data']['services'] ?? [];
        $etag = $result['etag'] ?? '';
        $modified = false;
        $removedFrom = [];

        foreach ($services as &$service) {
            $countries = $service['deliveryCountries'] ?? [];

            if (in_array($countryCode, $countries)) {
                $service['deliveryCountries'] = array_values(
                    array_filter($countries, fn($c) => $c !== $countryCode)
                );
                $modified = true;
                $removedFrom[] = $service['serviceName'] ?? 'Unknown';
            }
        }

        if (!$modified) {
            return [
                'success' => true,
                'message' => "Country $countryCode not found in any services",
                'modified' => false,
            ];
        }

        // Clear cache
        $this->cachedSettings = null;

        $updateResult = $this->client->updateShippingSettings(['services' => $services], $etag);
        $updateResult['removed_from'] = $removedFrom;

        return $updateResult;
    }

    /**
     * Toggle a shipping service on/off.
     */
    public function toggleService(string $serviceName, bool $active): array
    {
        $result = $this->get();
        if (!$result['success']) {
            return $result;
        }

        $services = $result['data']['services'] ?? [];
        $etag = $result['etag'] ?? '';
        $found = false;

        foreach ($services as &$service) {
            if (($service['serviceName'] ?? '') === $serviceName) {
                $service['active'] = $active;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return [
                'success' => false,
                'error' => "Service '$serviceName' not found",
            ];
        }

        // Clear cache
        $this->cachedSettings = null;

        return $this->client->updateShippingSettings(['services' => $services], $etag);
    }

    /**
     * Get a formatted summary of shipping configuration.
     */
    public function getSummary(): array
    {
        $services = $this->getServices();

        $summary = [
            'total_services' => count($services),
            'active_services' => 0,
            'countries' => [],
            'services' => [],
        ];

        foreach ($services as $service) {
            $isActive = $service['active'] ?? false;
            
            if ($isActive) {
                $summary['active_services']++;
            }

            $serviceInfo = [
                'name' => $service['serviceName'] ?? 'Unknown',
                'active' => $isActive,
                'countries' => $service['deliveryCountries'] ?? [],
                'country_count' => count($service['deliveryCountries'] ?? []),
            ];

            if (isset($service['deliveryTime'])) {
                $serviceInfo['delivery_time'] = [
                    'handling_days' => ($service['deliveryTime']['minHandlingTimeDays'] ?? 0) . '-' . 
                                       ($service['deliveryTime']['maxHandlingTimeDays'] ?? 0),
                    'transit_days' => ($service['deliveryTime']['minTransitTimeDays'] ?? 0) . '-' . 
                                      ($service['deliveryTime']['maxTransitTimeDays'] ?? 0),
                ];
            }

            $summary['services'][] = $serviceInfo;

            // Aggregate countries
            foreach ($service['deliveryCountries'] ?? [] as $country) {
                if (!in_array($country, $summary['countries'])) {
                    $summary['countries'][] = $country;
                }
            }
        }

        sort($summary['countries']);

        return $summary;
    }
}
