<?php
namespace HP_GMC;

use HP_GMC\Admin\Dashboard;
use HP_GMC\Admin\SettingsPage;
use HP_GMC\Services\MerchantApiClient;
use HP_GMC\Services\IssueMonitor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class for HP GMC Manager.
 */
class Plugin
{
    /**
     * Initialize the plugin.
     */
    public static function init(): void
    {
        // Register admin pages
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);

        // Register MCP abilities (if HP-Abilities is active)
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);

        // Check if we missed the hook
        if (did_action('wp_abilities_api_init')) {
            self::register_abilities();
        }

        // Cron hook for syncing product statuses
        add_action('hp_gmc_sync_status', [IssueMonitor::class, 'sync_product_statuses']);

        // AJAX handlers
        add_action('wp_ajax_hp_gmc_toggle_tool', [self::class, 'ajax_toggle_tool']);
        add_action('wp_ajax_hp_gmc_test_connection', [self::class, 'ajax_test_connection']);
        add_action('wp_ajax_hp_gmc_sync_now', [self::class, 'ajax_sync_now']);
        add_action('wp_ajax_hp_gmc_clear_dry_run_log', [self::class, 'ajax_clear_dry_run_log']);

        // Plugin action links
        add_filter('plugin_action_links_' . HP_GMC_BASENAME, [self::class, 'add_action_links']);
    }

    /**
     * Add action links to plugin row.
     */
    public static function add_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=hp-gmc-settings'),
            __('Settings', 'hp-gmc-manager')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Register admin menu pages.
     */
    public static function register_admin_menu(): void
    {
        // Main menu page
        add_menu_page(
            __('GMC Manager', 'hp-gmc-manager'),
            __('GMC Manager', 'hp-gmc-manager'),
            'manage_woocommerce',
            'hp-gmc-manager',
            [Dashboard::class, 'render'],
            'dashicons-store',
            56
        );

        // Settings submenu
        add_submenu_page(
            'hp-gmc-manager',
            __('Settings', 'hp-gmc-manager'),
            __('Settings', 'hp-gmc-manager'),
            'manage_woocommerce',
            'hp-gmc-settings',
            [SettingsPage::class, 'render']
        );
    }

    /**
     * Register plugin settings.
     */
    public static function register_settings(): void
    {
        register_setting('hp_gmc_settings', 'hp_gmc_merchant_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('hp_gmc_settings', 'hp_gmc_service_account_path', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('hp_gmc_settings', 'hp_gmc_mode', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return in_array($value, ['auto', 'live', 'dry_run', 'mock', 'passthrough']) ? $value : 'auto';
            },
        ]);

        register_setting('hp_gmc_settings', 'hp_gmc_sync_frequency', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return in_array($value, ['hourly', 'twicedaily', 'daily', 'manual']) ? $value : 'hourly';
            },
        ]);

        register_setting('hp_gmc_settings', 'hp_gmc_enabled_tools', [
            'type' => 'array',
            'sanitize_callback' => function ($value) {
                return is_array($value) ? array_map('sanitize_text_field', $value) : [];
            },
        ]);

        register_setting('hp_gmc_settings', 'hp_gmc_environment', [
            'type' => 'string',
            'sanitize_callback' => function ($value) {
                return in_array($value, ['', 'production', 'staging', 'local']) ? $value : '';
            },
        ]);
    }

    /**
     * Enqueue admin assets.
     */
    public static function enqueue_admin_assets(string $hook): void
    {
        // Only load on our pages
        if (strpos($hook, 'hp-gmc') === false) {
            return;
        }

        wp_enqueue_style(
            'hp-gmc-admin',
            HP_GMC_URL . 'assets/css/dashboard.css',
            [],
            HP_GMC_VERSION
        );

        wp_enqueue_script(
            'hp-gmc-admin',
            HP_GMC_URL . 'assets/js/dashboard.js',
            ['jquery'],
            HP_GMC_VERSION,
            true
        );

        wp_localize_script('hp-gmc-admin', 'hpGmcData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hp_gmc_admin'),
            'isDryRun' => hp_gmc_is_dry_run(),
            'environment' => hp_gmc_get_environment(),
            'strings' => [
                'confirmSync' => __('Sync product statuses now?', 'hp-gmc-manager'),
                'syncing' => __('Syncing...', 'hp-gmc-manager'),
                'syncComplete' => __('Sync complete!', 'hp-gmc-manager'),
                'error' => __('An error occurred. Please try again.', 'hp-gmc-manager'),
            ],
        ]);
    }

    /**
     * Register MCP abilities for AI-powered operations.
     */
    public static function register_abilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $enabled_tools = get_option('hp_gmc_enabled_tools', []);
        $all_tools = self::get_all_tools();

        foreach ($all_tools as $tool_id => $tool) {
            // Check if tool is enabled (default to enabled if not explicitly set)
            $is_enabled = !isset($enabled_tools[$tool_id]) || $enabled_tools[$tool_id];

            if (!$is_enabled) {
                continue;
            }

            wp_register_ability('hp-abilities/' . $tool_id, [
                'label' => $tool['title'],
                'description' => $tool['description'],
                'callback' => $tool['callback'],
                'input_schema' => $tool['input_schema'] ?? (object)[],
                'scope' => 'hp-gmc',
            ]);
        }
    }

    /**
     * Get all available MCP tools.
     */
    public static function get_all_tools(): array
    {
        return [
            // Product-level tools
            'gmc-dashboard-summary' => [
                'title' => 'GMC Dashboard Summary',
                'description' => 'Get overview stats (approved/disapproved/pending counts)',
                'callback' => [Abilities\ProductAbilities::class, 'dashboardSummary'],
                'category' => 'overview',
            ],
            'gmc-list-issues' => [
                'title' => 'List GMC Issues',
                'description' => 'List all products with issues, filterable by issue type',
                'callback' => [Abilities\ProductAbilities::class, 'listIssues'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_type' => [
                            'type' => 'string',
                            'description' => 'Filter by issue type (e.g., "disapproved", "warning")',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results',
                            'default' => 50,
                        ],
                    ],
                ],
                'category' => 'product',
            ],
            'gmc-get-product-status' => [
                'title' => 'Get Product Status',
                'description' => 'Get GMC status for a specific product by SKU',
                'callback' => [Abilities\ProductAbilities::class, 'getProductStatus'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => [
                            'type' => 'string',
                            'description' => 'Product SKU',
                        ],
                    ],
                    'required' => ['sku'],
                ],
                'category' => 'product',
            ],
            'gmc-set-exclusion' => [
                'title' => 'Set Product Exclusion',
                'description' => 'Set excluded_destination for a product',
                'callback' => [Abilities\ProductAbilities::class, 'setExclusion'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => [
                            'type' => 'string',
                            'description' => 'Product SKU',
                        ],
                        'destinations' => [
                            'type' => 'array',
                            'description' => 'Destinations to exclude (Shopping_ads, Display_ads, Free_listings, etc.)',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['sku', 'destinations'],
                ],
                'category' => 'product',
            ],

            // Account-level tools
            'gmc-get-shipping-settings' => [
                'title' => 'Get Shipping Settings',
                'description' => 'Retrieve current account shipping configuration (services, countries, rates)',
                'callback' => [Abilities\AccountAbilities::class, 'getShippingSettings'],
                'category' => 'shipping',
            ],
            'gmc-list-shipping-services' => [
                'title' => 'List Shipping Services',
                'description' => 'List all shipping services with their active countries',
                'callback' => [Abilities\AccountAbilities::class, 'listShippingServices'],
                'category' => 'shipping',
            ],
            'gmc-enable-country' => [
                'title' => 'Enable Country',
                'description' => 'Add a country to shipping destinations',
                'callback' => [Abilities\AccountAbilities::class, 'enableCountry'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'country_code' => [
                            'type' => 'string',
                            'description' => 'ISO 3166-1 alpha-2 country code (e.g., "CA", "GB")',
                        ],
                        'service_name' => [
                            'type' => 'string',
                            'description' => 'Optional: specific shipping service to add country to',
                        ],
                    ],
                    'required' => ['country_code'],
                ],
                'category' => 'shipping',
            ],
            'gmc-disable-country' => [
                'title' => 'Disable Country',
                'description' => 'Remove a country from all shipping services',
                'callback' => [Abilities\AccountAbilities::class, 'disableCountry'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'country_code' => [
                            'type' => 'string',
                            'description' => 'ISO 3166-1 alpha-2 country code (e.g., "CA", "GB")',
                        ],
                    ],
                    'required' => ['country_code'],
                ],
                'category' => 'shipping',
            ],
            'gmc-get-account-status' => [
                'title' => 'Get Account Status',
                'description' => 'Get overall Merchant Center account health and issues',
                'callback' => [Abilities\AccountAbilities::class, 'getAccountStatus'],
                'category' => 'account',
            ],

            // Test tool
            'gmc-test-hello' => [
                'title' => 'Test Hello',
                'description' => 'Simple test for GMC MCP connection',
                'callback' => [Abilities\ProductAbilities::class, 'testHello'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Name to say hello to',
                            'default' => 'World',
                        ],
                    ],
                ],
                'category' => 'test',
            ],
        ];
    }

    /**
     * AJAX: Toggle a tool on/off.
     */
    public static function ajax_toggle_tool(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $tool_id = sanitize_text_field($_POST['tool_id'] ?? '');
        $enabled = (bool) ($_POST['enabled'] ?? false);

        if (empty($tool_id)) {
            wp_send_json_error(['message' => 'Missing tool ID']);
        }

        $enabled_tools = get_option('hp_gmc_enabled_tools', []);
        $enabled_tools[$tool_id] = $enabled;
        update_option('hp_gmc_enabled_tools', $enabled_tools);

        wp_send_json_success([
            'tool_id' => $tool_id,
            'enabled' => $enabled,
        ]);
    }

    /**
     * AJAX: Test API connection.
     */
    public static function ajax_test_connection(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            $client = new MerchantApiClient();
            $result = $client->testConnection();
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Trigger manual sync.
     */
    public static function ajax_sync_now(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            $result = IssueMonitor::sync_product_statuses();
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Clear dry run log.
     */
    public static function ajax_clear_dry_run_log(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_dry_run_log';
        $wpdb->query("TRUNCATE TABLE $table");

        wp_send_json_success(['message' => 'Log cleared']);
    }
}
