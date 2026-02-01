<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\FunnelDataFeed;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP abilities for funnel GMC integration.
 */
class FunnelAbilities
{
    /**
     * List all GMC-enabled funnels.
     */
    public static function listFunnels(array $params): array
    {
        if (!FunnelDataFeed::isAvailable()) {
            return [
                'success' => false,
                'error' => 'HP-React-Widgets plugin is not active',
            ];
        }

        $funnels = FunnelDataFeed::getAllFunnels();

        return [
            'success' => true,
            'count' => count($funnels),
            'funnels' => $funnels,
        ];
    }

    /**
     * Get a single funnel's GMC data.
     */
    public static function getFunnel(array $params): array
    {
        $funnelId = (int) ($params['funnel_id'] ?? 0);

        if (!$funnelId) {
            return ['success' => false, 'error' => 'funnel_id is required'];
        }

        if (!FunnelDataFeed::isAvailable()) {
            return [
                'success' => false,
                'error' => 'HP-React-Widgets plugin is not active',
            ];
        }

        $funnel = FunnelDataFeed::getFunnel($funnelId);

        if (!$funnel) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        return [
            'success' => true,
            'funnel' => $funnel,
        ];
    }

    /**
     * Validate a funnel for GMC eligibility.
     */
    public static function validateFunnel(array $params): array
    {
        $funnelId = (int) ($params['funnel_id'] ?? 0);

        if (!$funnelId) {
            return ['success' => false, 'error' => 'funnel_id is required'];
        }

        if (!FunnelDataFeed::isAvailable()) {
            return [
                'success' => false,
                'error' => 'HP-React-Widgets plugin is not active',
            ];
        }

        $validation = FunnelDataFeed::validateFunnel($funnelId);

        return [
            'success' => true,
            'funnel_id' => $funnelId,
            'valid' => $validation['valid'],
            'errors' => $validation['errors'] ?? [],
            'warnings' => $validation['warnings'] ?? [],
        ];
    }

    /**
     * Get funnel feed status.
     */
    public static function getFeedStatus(array $params): array
    {
        $status = FunnelDataFeed::getStatus();

        return [
            'success' => true,
            'status' => $status,
        ];
    }

    /**
     * Regenerate the funnel feed.
     */
    public static function regenerateFeed(array $params): array
    {
        if (!FunnelDataFeed::isAvailable()) {
            return [
                'success' => false,
                'error' => 'HP-React-Widgets plugin is not active',
            ];
        }

        try {
            FunnelDataFeed::clearCache();
            FunnelDataFeed::generateFeed('tsv', true);
            FunnelDataFeed::generateFeed('csv', true);

            $status = FunnelDataFeed::getStatus();

            return [
                'success' => true,
                'message' => sprintf('Funnel feed regenerated with %d funnels', $status['funnel_count']),
                'status' => $status,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enable GMC sync for a funnel.
     */
    public static function enableFunnel(array $params): array
    {
        $funnelId = (int) ($params['funnel_id'] ?? 0);

        if (!$funnelId) {
            return ['success' => false, 'error' => 'funnel_id is required'];
        }

        if (!FunnelDataFeed::isAvailable()) {
            return [
                'success' => false,
                'error' => 'HP-React-Widgets plugin is not active',
            ];
        }

        // Update the ACF field
        update_field('funnel_gmc_enabled', true, $funnelId);

        // Clear cache
        FunnelDataFeed::clearCache();

        return [
            'success' => true,
            'funnel_id' => $funnelId,
            'message' => 'GMC sync enabled for funnel',
        ];
    }

    /**
     * Disable GMC sync for a funnel.
     */
    public static function disableFunnel(array $params): array
    {
        $funnelId = (int) ($params['funnel_id'] ?? 0);

        if (!$funnelId) {
            return ['success' => false, 'error' => 'funnel_id is required'];
        }

        if (!FunnelDataFeed::isAvailable()) {
            return [
                'success' => false,
                'error' => 'HP-React-Widgets plugin is not active',
            ];
        }

        // Update the ACF field
        update_field('funnel_gmc_enabled', false, $funnelId);

        // Clear cache
        FunnelDataFeed::clearCache();

        return [
            'success' => true,
            'funnel_id' => $funnelId,
            'message' => 'GMC sync disabled for funnel',
        ];
    }

    /**
     * Update GMC settings for a funnel.
     */
    public static function updateSettings(array $params): array
    {
        $funnelId = (int) ($params['funnel_id'] ?? 0);

        if (!$funnelId) {
            return ['success' => false, 'error' => 'funnel_id is required'];
        }

        if (!FunnelDataFeed::isAvailable()) {
            return [
                'success' => false,
                'error' => 'HP-React-Widgets plugin is not active',
            ];
        }

        // Updatable fields
        $updatableFields = [
            'title_override' => 'funnel_gmc_title_override',
            'description_override' => 'funnel_gmc_description_override',
            'category' => 'funnel_gmc_category',
            'brand' => 'funnel_gmc_brand',
            'custom_label_0' => 'funnel_gmc_custom_label_0',
            'custom_label_1' => 'funnel_gmc_custom_label_1',
            'custom_label_2' => 'funnel_gmc_custom_label_2',
            'custom_label_3' => 'funnel_gmc_custom_label_3',
            'custom_label_4' => 'funnel_gmc_custom_label_4',
        ];

        $updated = [];

        foreach ($updatableFields as $paramKey => $fieldName) {
            if (isset($params[$paramKey])) {
                update_field($fieldName, $params[$paramKey], $funnelId);
                $updated[] = $paramKey;
            }
        }

        // Clear cache
        FunnelDataFeed::clearCache();

        // Clear HP-RW cache if available
        if (class_exists('HP_RW\\Services\\FunnelConfigLoader')) {
            \HP_RW\Services\FunnelConfigLoader::clearCache($funnelId);
        }

        return [
            'success' => true,
            'funnel_id' => $funnelId,
            'updated_fields' => $updated,
            'message' => sprintf('Updated %d GMC settings', count($updated)),
        ];
    }
}
