<?php
/**
 * Plugin Name: HP GMC Manager
 * Description: Google Merchant Center management with admin dashboard and MCP abilities. Complements Google Listings & Ads with monitoring, shipping settings, and AI-powered operations.
 * Version: 1.1.2
 * Author: Holistic People
 * Author URI: https://holisticpeople.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hp-gmc-manager
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('HP_GMC_VERSION', '1.1.2');
define('HP_GMC_FILE', __FILE__);
define('HP_GMC_PATH', plugin_dir_path(__FILE__));
define('HP_GMC_URL', plugin_dir_url(__FILE__));
define('HP_GMC_BASENAME', plugin_basename(__FILE__));

// Composer autoloader
if (file_exists(HP_GMC_PATH . 'vendor/autoload.php')) {
    require_once HP_GMC_PATH . 'vendor/autoload.php';
}

// Plugin autoloader
spl_autoload_register(function ($class) {
    $prefix = 'HP_GMC\\';
    $base_dir = HP_GMC_PATH . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Initialize the plugin.
 */
function hp_gmc_init() {
    // Check for WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('HP GMC Manager requires WooCommerce to be installed and activated.', 'hp-gmc-manager');
            echo '</p></div>';
        });
        return;
    }

    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('HP GMC Manager requires PHP 8.0 or higher.', 'hp-gmc-manager');
            echo '</p></div>';
        });
        return;
    }

    // Ensure tables exist (for Git-deployed plugins that skip activation hook)
    hp_gmc_maybe_create_tables();

    // Initialize the plugin
    \HP_GMC\Plugin::init();
}

/**
 * Create tables if they don't exist (handles Git deployments).
 */
function hp_gmc_maybe_create_tables() {
    $db_version = get_option('hp_gmc_db_version', '0');
    
    // Only run if version changed or tables missing
    if (version_compare($db_version, HP_GMC_VERSION, '>=')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'hp_gmc_product_status';
    
    // Quick check if main table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    
    if (!$table_exists) {
        // Run the full activation routine
        hp_gmc_activate();
        
        // Only update version if table was actually created
        $table_exists_after = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if ($table_exists_after) {
            update_option('hp_gmc_db_version', HP_GMC_VERSION);
        }
    } else {
        // Table already exists, update stored version
        update_option('hp_gmc_db_version', HP_GMC_VERSION);
    }
}
add_action('plugins_loaded', 'hp_gmc_init', 20);

/**
 * Plugin activation hook.
 */
function hp_gmc_activate() {
    // Create custom tables for caching GMC data
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Product status cache table
    // Note: dbDelta() does NOT support IF NOT EXISTS - it handles existence checks internally
    $table_name = $wpdb->prefix . 'hp_gmc_product_status';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        gla_id varchar(100) NOT NULL,
        status varchar(50) NOT NULL,
        issues longtext,
        destinations longtext,
        last_updated datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY gla_id (gla_id),
        KEY product_id (product_id),
        KEY status (status)
    ) $charset_collate;";

    // Dry run log table
    $table_name_log = $wpdb->prefix . 'hp_gmc_dry_run_log';
    $sql .= "CREATE TABLE $table_name_log (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        action varchar(100) NOT NULL,
        endpoint varchar(255) NOT NULL,
        params longtext,
        simulated_response longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY action (action),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Set default options
    $defaults = [
        'hp_gmc_merchant_id' => '',
        'hp_gmc_service_account_path' => '',
        'hp_gmc_mode' => 'auto',
        'hp_gmc_sync_frequency' => 'hourly',
        'hp_gmc_enabled_tools' => [],
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key) === false) {
            add_option($key, $value);
        }
    }

    // Schedule cron for issue monitoring
    if (!wp_next_scheduled('hp_gmc_sync_status')) {
        wp_schedule_event(time(), 'hourly', 'hp_gmc_sync_status');
    }
}
register_activation_hook(__FILE__, 'hp_gmc_activate');

/**
 * Plugin deactivation hook.
 */
function hp_gmc_deactivate() {
    wp_clear_scheduled_hook('hp_gmc_sync_status');
}
register_deactivation_hook(__FILE__, 'hp_gmc_deactivate');

/**
 * Get the current environment (production, staging, local).
 */
function hp_gmc_get_environment(): string {
    $site_url = get_site_url();
    
    // Check for explicit setting first
    $explicit = get_option('hp_gmc_environment', '');
    if ($explicit && in_array($explicit, ['production', 'staging', 'local'])) {
        return $explicit;
    }

    // Auto-detect based on URL
    if (strpos($site_url, 'kinsta.cloud') !== false || strpos($site_url, 'staging') !== false) {
        return 'staging';
    }
    if (strpos($site_url, 'localhost') !== false || strpos($site_url, '.local') !== false) {
        return 'local';
    }

    return 'production';
}

/**
 * Check if currently in dry run mode.
 */
function hp_gmc_is_dry_run(): bool {
    $mode = get_option('hp_gmc_mode', 'auto');
    
    if ($mode === 'auto') {
        return hp_gmc_get_environment() !== 'production';
    }
    
    return in_array($mode, ['dry_run', 'mock']);
}
