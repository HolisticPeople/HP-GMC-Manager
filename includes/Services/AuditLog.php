<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Audit log service for tracking MCP operations.
 */
class AuditLog
{
    /**
     * Log an operation.
     */
    public static function log(string $action, array $params = [], array $result = [], ?int $userId = null): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_audit_log';

        // Get user info
        if ($userId === null) {
            $userId = get_current_user_id();
        }
        $user = get_user_by('ID', $userId);
        $userDisplay = $user ? $user->display_name : 'System/MCP';

        // Determine success status
        $success = isset($result['success']) ? (bool) $result['success'] : null;

        // Extract affected products if any
        $affectedProducts = [];
        if (isset($params['sku'])) {
            $affectedProducts[] = $params['sku'];
        }
        if (isset($params['skus'])) {
            $affectedProducts = array_merge($affectedProducts, (array) $params['skus']);
        }

        $wpdb->insert($table, [
            'action' => $action,
            'params' => wp_json_encode($params),
            'result' => wp_json_encode($result),
            'success' => $success,
            'user_id' => $userId,
            'user_display' => $userDisplay,
            'affected_products' => wp_json_encode($affectedProducts),
            'environment' => hp_gmc_get_environment(),
            'dry_run' => hp_gmc_is_dry_run() ? 1 : 0,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Get recent log entries.
     */
    public static function getRecent(int $limit = 50, ?string $action = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_audit_log';

        $where = '';
        if ($action) {
            $where = $wpdb->prepare(' WHERE action = %s', $action);
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        // Decode JSON fields
        foreach ($results as &$row) {
            $row['params'] = json_decode($row['params'], true) ?: [];
            $row['result'] = json_decode($row['result'], true) ?: [];
            $row['affected_products'] = json_decode($row['affected_products'], true) ?: [];
        }

        return $results;
    }

    /**
     * Get log entries for a specific product.
     */
    public static function getForProduct(string $sku, int $limit = 20): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_audit_log';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE affected_products LIKE %s ORDER BY created_at DESC LIMIT %d",
                '%' . $wpdb->esc_like($sku) . '%',
                $limit
            ),
            ARRAY_A
        );

        // Decode JSON fields
        foreach ($results as &$row) {
            $row['params'] = json_decode($row['params'], true) ?: [];
            $row['result'] = json_decode($row['result'], true) ?: [];
            $row['affected_products'] = json_decode($row['affected_products'], true) ?: [];
        }

        return $results;
    }

    /**
     * Get summary statistics.
     */
    public static function getSummary(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_audit_log';

        $totalOps = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $successOps = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE success = 1");
        $failedOps = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE success = 0");
        $dryRunOps = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE dry_run = 1");

        $actionCounts = $wpdb->get_results(
            "SELECT action, COUNT(*) as count FROM $table GROUP BY action ORDER BY count DESC",
            ARRAY_A
        );

        return [
            'total_operations' => $totalOps,
            'successful' => $successOps,
            'failed' => $failedOps,
            'dry_run' => $dryRunOps,
            'by_action' => $actionCounts,
        ];
    }

    /**
     * Clear old log entries.
     */
    public static function clearOld(int $daysToKeep = 30): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_audit_log';

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $daysToKeep
            )
        );

        return (int) $deleted;
    }
}
