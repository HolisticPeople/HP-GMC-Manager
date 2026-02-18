<?php
/**
 * Plugin Name: HP GMC Manager
 * Description: Google Merchant Center management with admin dashboard and MCP abilities. Complements Google Listings & Ads with monitoring, shipping settings, and AI-powered operations.
 * Version: 1.29.12
 * Author: Holistic People
 * Author URI: https://holisticpeople.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hp-gmc-manager
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 10.5
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('HP_GMC_VERSION', '1.29.12');
define('HP_GMC_FILE', __FILE__);
define('HP_GMC_PATH', plugin_dir_path(__FILE__));
define('HP_GMC_URL', plugin_dir_url(__FILE__));
define('HP_GMC_BASENAME', plugin_basename(__FILE__));

/** Google Ads API version (REST). Bump per https://developers.google.com/google-ads/api/docs/sunset-dates */
define('HP_GMC_ADS_API_VERSION', 'v20');

// Declare WooCommerce feature compatibility (HPOS)
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

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
 * Settings page callback. Resolves SettingsPage only when the page is viewed so a missing file shows a message instead of a fatal.
 */
function hp_gmc_render_settings_page(): void
{
    if (class_exists(\HP_GMC\Admin\SettingsPage::class)) {
        \HP_GMC\Admin\SettingsPage::render();
        return;
    }
    wp_die(
        esc_html__('GMC Manager settings could not be loaded. Re-deploy the plugin (ensure includes/Admin/SettingsPage.php exists).', 'hp-gmc-manager'),
        esc_html__('Plugin error', 'hp-gmc-manager'),
        ['response' => 500, 'back_link' => true]
    );
}

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
 * Always runs hp_gmc_activate() on version change since dbDelta handles existing tables gracefully.
 */
function hp_gmc_maybe_create_tables() {
    global $wpdb;
    $db_version = get_option('hp_gmc_db_version', '0');

    // When "Run schema upgrade on plugin load" is disabled, only run on activation (manual mode).
    $schema_on_load = get_option('hp_gmc_schema_upgrade_on_load', true);
    if (!$schema_on_load) {
        return;
    }

    // Only run if version changed
    if (version_compare($db_version, HP_GMC_VERSION, '>=')) {
        return;
    }

    // Cleanup old unique key that prevents multiple attributes per product in a feed
    $feed_products_table = $wpdb->prefix . 'hp_gmc_feed_products';
    
    // Check if table exists before running index check
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $feed_products_table));
    
    if ($table_exists) {
        $old_keys = ['product_feed', 'feed_product'];
        foreach ($old_keys as $key_name) {
            $exists = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM $feed_products_table WHERE Key_name = %s", $key_name));
            if (!empty($exists)) {
                $wpdb->query("ALTER TABLE $feed_products_table DROP INDEX $key_name");
            }
        }
    }

    // Always run activation on version change - dbDelta safely handles existing tables
    // This ensures new tables (like feeds, feed_products) get created on upgrades
    hp_gmc_activate();
    
    // Update stored version
    update_option('hp_gmc_db_version', HP_GMC_VERSION);
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

    // Audit log table
    $table_name_audit = $wpdb->prefix . 'hp_gmc_audit_log';
    $sql .= "CREATE TABLE $table_name_audit (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        action varchar(100) NOT NULL,
        params longtext,
        result longtext,
        success tinyint(1) DEFAULT NULL,
        user_id bigint(20) DEFAULT 0,
        user_display varchar(100) DEFAULT '',
        affected_products longtext,
        environment varchar(50) DEFAULT 'staging',
        dry_run tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY action (action),
        KEY user_id (user_id),
        KEY environment (environment),
        KEY created_at (created_at)
    ) $charset_collate;";

    // Feeds table - stores supplemental feed metadata
    $table_name_feeds = $wpdb->prefix . 'hp_gmc_feeds';
    $sql .= "CREATE TABLE $table_name_feeds (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        feed_type varchar(20) NOT NULL,
        category varchar(50) DEFAULT NULL,
        gmc_feed_id varchar(100) DEFAULT NULL,
        status varchar(20) DEFAULT 'draft',
        product_count int DEFAULT 0,
        file_path varchar(255) DEFAULT NULL,
        file_url varchar(255) DEFAULT NULL,
        last_uploaded datetime DEFAULT NULL,
        last_crawl_time datetime DEFAULT NULL,
        gmc_status varchar(50) DEFAULT NULL,
        target_issue_patterns longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY feed_type (feed_type),
        KEY status (status)
    ) $charset_collate;";

    // Feed products table - stores products in each feed
    $table_name_feed_products = $wpdb->prefix . 'hp_gmc_feed_products';
    $sql .= "CREATE TABLE $table_name_feed_products (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        feed_id bigint(20) NOT NULL,
        product_id bigint(20) NOT NULL,
        sku varchar(100) NOT NULL,
        gmc_id varchar(100) DEFAULT NULL,
        attribute_name varchar(50) NOT NULL,
        attribute_value text NOT NULL,
        reason varchar(100) DEFAULT NULL,
        added_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY feed_id (feed_id),
        KEY product_id (product_id),
        UNIQUE KEY feed_product_attr (feed_id, product_id, attribute_name)
    ) $charset_collate;";

    // Saved segments table (Audiences feature)
    $table_name_segments = $wpdb->prefix . 'hp_gmc_saved_segments';
    $sql .= "CREATE TABLE $table_name_segments (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        filter_definition longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_run_at datetime DEFAULT NULL,
        last_run_count int DEFAULT NULL,
        last_upload_job_id varchar(100) DEFAULT NULL,
        last_upload_at datetime DEFAULT NULL,
        last_upload_status varchar(50) DEFAULT NULL,
        gmc_user_list_id varchar(100) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY name (name),
        KEY updated_at (updated_at)
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

    // Schema & Audiences options (autoload=no)
    $audience_defaults = [
        'hp_gmc_schema_upgrade_on_load' => true,
        'hp_gmc_audience_sync_run_cap' => 5000,
        'hp_gmc_audience_force_background_over_cap' => false,
        'hp_gmc_audience_upload_disabled' => false,
    ];
    foreach ($audience_defaults as $key => $val) {
        if (get_option($key) === false) {
            add_option($key, $val, '', 'no');
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
register_deactivation_hook(__FILE__, 'hp_gmc_activate');

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
