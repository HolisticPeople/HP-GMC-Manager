<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service for syncing WooCommerce products to GMC format.
 * Works alongside Google Listings & Ads plugin in complement mode.
 */
class ProductSync
{
    /**
     * Get GLA ID for a WooCommerce product.
     */
    public function getGlaId(int $productId): string
    {
        // Check if GLA has assigned an ID
        $glaId = get_post_meta($productId, '_wc_gla_mc_offer_id', true);
        
        if (!empty($glaId)) {
            return $glaId;
        }

        // Default format
        return 'gla_' . $productId;
    }

    /**
     * Check if a product is synced to GMC via GLA.
     */
    public function isSyncedViaGla(int $productId): bool
    {
        $status = get_post_meta($productId, '_wc_gla_sync_status', true);
        return !empty($status) && $status !== 'not-synced';
    }

    /**
     * Get sync status from GLA meta.
     */
    public function getGlaSyncStatus(int $productId): array
    {
        return [
            'status' => get_post_meta($productId, '_wc_gla_sync_status', true) ?: 'unknown',
            'offer_id' => get_post_meta($productId, '_wc_gla_mc_offer_id', true) ?: null,
            'visibility' => get_post_meta($productId, '_wc_gla_visibility', true) ?: 'sync-and-show',
            'errors' => get_post_meta($productId, '_wc_gla_errors', true) ?: [],
        ];
    }

    /**
     * Get products that need attention (not synced or with errors).
     */
    public function getProductsNeedingAttention(int $limit = 50): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title,
                   pm_status.meta_value as sync_status,
                   pm_errors.meta_value as sync_errors
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_wc_gla_sync_status'
            LEFT JOIN {$wpdb->postmeta} pm_errors ON p.ID = pm_errors.post_id AND pm_errors.meta_key = '_wc_gla_errors'
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND (pm_status.meta_value IS NULL 
                   OR pm_status.meta_value = 'not-synced'
                   OR pm_errors.meta_value IS NOT NULL)
            ORDER BY p.post_modified DESC
            LIMIT %d
        ", $limit), ARRAY_A);

        foreach ($results as &$row) {
            $row['errors'] = maybe_unserialize($row['sync_errors']) ?: [];
            unset($row['sync_errors']);
        }

        return $results;
    }

    /**
     * Count products by sync status.
     */
    public function countByStatus(): array
    {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT pm.meta_value as status, COUNT(*) as count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wc_gla_sync_status'
            WHERE p.post_type = 'product' AND p.post_status = 'publish'
            GROUP BY pm.meta_value
        ", ARRAY_A);

        $counts = [
            'synced' => 0,
            'not-synced' => 0,
            'pending' => 0,
            'has-errors' => 0,
            'unknown' => 0,
        ];

        foreach ($results as $row) {
            $status = $row['status'] ?: 'unknown';
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['count'];
            } else {
                $counts['unknown'] += (int) $row['count'];
            }
        }

        return $counts;
    }
}
