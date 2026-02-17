<?php
namespace HP_GMC;

use HP_GMC\Admin\Dashboard;
use HP_GMC\Services\MerchantApiClient;
use HP_GMC\Services\IssueMonitor;
use HP_GMC\Rest\ProductFeedEndpoint;
use HP_GMC\Rest\FunnelFeedEndpoint;
use HP_GMC\Rest\SupplementalFeedEndpoint;
use HP_GMC\Rest\AudiencesEndpoint;
use HP_GMC\Rest\OAuthCallbackEndpoint;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class for HP GMC Manager.
 */
class Plugin
{
    /**
     * Append one NDJSON line for debug (GMC sync/status).
     * No-op unless HP_GMC_DEBUG_LOG is defined in wp-config.php (e.g. WP_CONTENT_DIR . '/uploads/hp-gmc-debug.log').
     *
     * @param string $message Short label
     * @param array  $data    Key-value data (no secrets)
     * @param string $hypothesisId Optional (e.g. H1, H2)
     */
    public static function debugLog(string $message, array $data = [], string $hypothesisId = ''): void
    {
        if (!defined('HP_GMC_DEBUG_LOG') || HP_GMC_DEBUG_LOG === '') {
            return;
        }
        $path = HP_GMC_DEBUG_LOG;
        $payload = array_filter([
            'id' => 'hp-gmc-' . uniqid('', true),
            'timestamp' => (int) round(microtime(true) * 1000),
            'location' => 'Plugin::debugLog',
            'message' => $message,
            'data' => $data,
            'hypothesisId' => $hypothesisId ?: null,
        ]);
        $line = wp_json_encode($payload) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Initialize the plugin.
     */
    public static function init(): void
    {
        // Register admin pages
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_init', [self::class, 'handle_ads_oauth_actions']);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);

        // Register GMC category (must happen before abilities)
        add_action('wp_abilities_api_categories_init', [self::class, 'register_ability_category']);
        
        // Register MCP abilities (if HP-Abilities is active)
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);

        // Check if we missed the hooks
        if (did_action('wp_abilities_api_categories_init')) {
            self::register_ability_category();
        }
        if (did_action('wp_abilities_api_init')) {
            self::register_abilities();
        }

        // Cron hook for syncing product statuses
        add_action('hp_gmc_sync_status', [IssueMonitor::class, 'sync_product_statuses']);

        // Register REST API endpoints
        add_action('rest_api_init', [ProductFeedEndpoint::class, 'register']);
        add_action('rest_api_init', [FunnelFeedEndpoint::class, 'register']);
        add_action('rest_api_init', [SupplementalFeedEndpoint::class, 'register']);
        add_action('rest_api_init', [AudiencesEndpoint::class, 'register']);
        add_action('rest_api_init', [OAuthCallbackEndpoint::class, 'register']);

        // Listen for funnel saves from HP-React-Widgets
        add_action('hp_funnel_saved', [self::class, 'on_funnel_saved'], 10, 3);
        add_action('hp_funnel_gmc_settings_updated', [self::class, 'on_funnel_gmc_settings_updated'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_hp_gmc_toggle_tool', [self::class, 'ajax_toggle_tool']);
        add_action('wp_ajax_hp_gmc_test_connection', [self::class, 'ajax_test_connection']);
        add_action('wp_ajax_hp_gmc_sync_now', [self::class, 'ajax_sync_now']);
        add_action('wp_ajax_hp_gmc_clear_dry_run_log', [self::class, 'ajax_clear_dry_run_log']);
        
        // Feed AJAX handlers
        add_action('wp_ajax_hp_gmc_create_feed', [self::class, 'ajax_create_feed']);
        add_action('wp_ajax_hp_gmc_delete_feed', [self::class, 'ajax_delete_feed']);
        add_action('wp_ajax_hp_gmc_generate_feed', [self::class, 'ajax_generate_feed']);
        add_action('wp_ajax_hp_gmc_upload_feed', [self::class, 'ajax_upload_feed']);
        add_action('wp_ajax_hp_gmc_check_feed_status', [self::class, 'ajax_check_feed_status']);
        add_action('wp_ajax_hp_gmc_force_crawl_feed', [self::class, 'ajax_force_crawl_feed']);
        add_action('wp_ajax_hp_gmc_add_product_to_feed', [self::class, 'ajax_add_product_to_feed']);
        add_action('wp_ajax_hp_gmc_remove_product_from_feed', [self::class, 'ajax_remove_product_from_feed']);
        add_action('wp_ajax_hp_gmc_search_products', [self::class, 'ajax_search_products']);
        add_action('wp_ajax_hp_gmc_bulk_add_to_feed', [self::class, 'ajax_bulk_add_to_feed']);
        add_action('wp_ajax_hp_gmc_refresh_all_feed_statuses', [self::class, 'ajax_refresh_all_feed_statuses']);
        add_action('wp_ajax_hp_gmc_publish_feed', [self::class, 'ajax_publish_feed']);
        add_action('wp_ajax_hp_gmc_bulk_feed_action', [self::class, 'ajax_bulk_feed_action']);
        add_action('wp_ajax_hp_gmc_export_feeds_json', [self::class, 'ajax_export_feeds_json']);
        add_action('wp_ajax_hp_gmc_import_feeds_json', [self::class, 'ajax_import_feeds_json']);
        add_action('wp_ajax_hp_gmc_download_feed_json_template', [self::class, 'ajax_download_feed_json_template']);
        add_action('wp_ajax_hp_gmc_get_pending_products', [self::class, 'ajax_get_pending_products']);
        add_action('wp_ajax_hp_gmc_add_pending_products', [self::class, 'ajax_add_pending_products']);
        add_action('wp_ajax_hp_gmc_remove_from_gmc', [self::class, 'ajax_remove_from_gmc']);
        
        // Issue classifier / review workflow AJAX handlers
        add_action('wp_ajax_hp_gmc_analyze_triggers', [self::class, 'ajax_analyze_triggers']);
        add_action('wp_ajax_hp_gmc_mark_as_restriction', [self::class, 'ajax_mark_as_restriction']);
        add_action('wp_ajax_hp_gmc_get_issues_subtab', [self::class, 'ajax_get_issues_subtab']);
        
        // Primary feed AJAX handlers
        add_action('wp_ajax_hp_gmc_regenerate_primary_feed', [self::class, 'ajax_regenerate_primary_feed']);
        add_action('wp_ajax_hp_gmc_get_primary_feed_status', [self::class, 'ajax_get_primary_feed_status']);

        // Funnel feed AJAX handlers
        add_action('wp_ajax_hp_gmc_regenerate_funnel_feed', [self::class, 'ajax_regenerate_funnel_feed']);

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
            'hp_gmc_render_settings_page'
        );

        // Campaign ROI submenu
        add_submenu_page(
            'hp-gmc-manager',
            __('Campaign ROI', 'hp-gmc-manager'),
            __('Campaign ROI', 'hp-gmc-manager'),
            'manage_woocommerce',
            'hp-gmc-campaign-roi',
            [Admin\CampaignRoiDashboard::class, 'render']
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

        // GA4 Analytics settings
        register_setting('hp_gmc_settings', 'hp_gmc_ga4_property_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // Google Ads settings
        register_setting('hp_gmc_settings', 'hp_gmc_ads_customer_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_ads_manager_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_ads_developer_token', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_ads_oauth_client_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_ads_oauth_client_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_ads_upload_auth', [
            'type' => 'string',
            'default' => 'oauth',
            'sanitize_callback' => function ($value) {
                return in_array($value, ['oauth', 'service_account'], true) ? $value : 'oauth';
            },
        ]);

        // Schema & Audiences
        register_setting('hp_gmc_settings', 'hp_gmc_schema_upgrade_on_load', [
            'type' => 'boolean',
            'sanitize_callback' => function ($value) {
                return (bool) $value;
            },
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_audience_sync_run_cap', [
            'type' => 'integer',
            'sanitize_callback' => function ($value) {
                $v = absint($value);
                return $v > 0 ? $v : 5000;
            },
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_audience_force_background_over_cap', [
            'type' => 'boolean',
            'sanitize_callback' => function ($value) {
                return (bool) $value;
            },
        ]);
        register_setting('hp_gmc_settings', 'hp_gmc_audience_upload_disabled', [
            'type' => 'boolean',
            'sanitize_callback' => function ($value) {
                return (bool) $value;
            },
        ]);
    }

    /**
     * Handle Google Ads OAuth: start (redirect to Google) and disconnect.
     */
    public static function handle_ads_oauth_actions(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (isset($_GET['hp_gmc_oauth_start']) && $_GET['hp_gmc_oauth_start'] === '1') {
            try {
                $redirectUri = rest_url('hp-gmc/v1/oauth-callback');
                $url = \HP_GMC\Services\GoogleAdsOAuth::getAuthUrl($redirectUri);
                wp_safe_redirect($url);
                exit;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'Client ID') !== false || stripos($msg, 'not configured') !== false) {
                    $msg .= ' ' . __("Save your Client ID and Client Secret first: scroll down and click 'Save Changes', then click 'Connect with Google' again.", 'hp-gmc-manager');
                }
                wp_die(esc_html($msg), '', ['back_link' => true]);
            }
        }
        if (isset($_GET['hp_gmc_oauth_disconnect']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'hp_gmc_oauth_disconnect')) {
                \HP_GMC\Services\GoogleAdsOAuth::disconnect();
                wp_safe_redirect(remove_query_arg(['hp_gmc_oauth_disconnect', '_wpnonce'], admin_url('admin.php?page=hp-gmc-settings')));
                exit;
            }
        }
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
     * Register the GMC ability category.
     */
    public static function register_ability_category(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category('hp-gmc', [
            'label' => __('Google Merchant Center', 'hp-gmc-manager'),
            'description' => __('Tools for managing Google Merchant Center products and settings', 'hp-gmc-manager'),
        ]);
        wp_register_ability_category('hp-marketing', [
            'label' => __('Marketing & Audiences', 'hp-gmc-manager'),
            'description' => __('Audience segments, GA4, and Google Ads for campaign workflows', 'hp-gmc-manager'),
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
                'execute_callback' => $tool['callback'],
                'permission_callback' => '__return_true',
                'input_schema' => $tool['input_schema'] ?? [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
                'category' => $tool['category'] ?? 'hp-gmc',
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
            'gmc-diagnose-product' => [
                'title' => 'Diagnose Product',
                'description' => 'Compare WooCommerce data with GMC cached status to identify issues and provide recommendations',
                'callback' => [Abilities\ProductAbilities::class, 'diagnoseProduct'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => [
                            'type' => 'string',
                            'description' => 'Product SKU to diagnose',
                        ],
                    ],
                    'required' => ['sku'],
                ],
                'category' => 'product',
            ],
            
            // Batch operation tools
            'gmc-batch-analyze' => [
                'title' => 'Batch Analyze Issues',
                'description' => 'Analyze multiple products with issues and generate a fix plan. Returns proposed fixes without executing.',
                'callback' => [Abilities\ProductAbilities::class, 'batchAnalyze'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_type' => [
                            'type' => 'string',
                            'description' => 'Filter by issue type (e.g., "shipping", "health_claims")',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum products to analyze',
                            'default' => 20,
                        ],
                    ],
                ],
                'category' => 'product',
            ],
            'gmc-batch-exclude' => [
                'title' => 'Batch Exclude Products',
                'description' => 'Exclude multiple products from specified destinations. Use dry_run=true to preview.',
                'callback' => [Abilities\ProductAbilities::class, 'batchExclude'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'skus' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of SKUs to exclude',
                        ],
                        'destinations' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Destinations to exclude from (Shopping_ads, Display_ads, etc.)',
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'If true, only show what would happen without executing',
                            'default' => true,
                        ],
                    ],
                    'required' => ['skus', 'destinations'],
                ],
                'category' => 'product',
            ],
            'gmc-get-fix-summary' => [
                'title' => 'Get Fix Summary',
                'description' => 'Get a summary of all issues and recommended fixes, grouped by issue type',
                'callback' => [Abilities\ProductAbilities::class, 'getFixSummary'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'overview',
            ],
            // NOTE: gmc-generate-exclusion-feed removed in v1.21.0 - use gmc-feed-generate instead
            'gmc-get-audit-log' => [
                'title' => 'Get Audit Log',
                'description' => 'View audit log of MCP operations with timestamps, users, and results',
                'callback' => [Abilities\ProductAbilities::class, 'getAuditLog'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum entries to return',
                            'default' => 50,
                        ],
                        'action' => [
                            'type' => 'string',
                            'description' => 'Filter by action type (e.g., set_exclusion, batch_exclude)',
                        ],
                        'sku' => [
                            'type' => 'string',
                            'description' => 'Filter by affected product SKU',
                        ],
                    ],
                ],
                'category' => 'overview',
            ],
            
            // Feed management tools
            'gmc-feed-create' => [
                'title' => 'Create Feed',
                'description' => 'Create a new supplemental feed (exclusion, redirect, or custom)',
                'callback' => [Abilities\FeedAbilities::class, 'createFeed'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Feed name (e.g., hp-exclusions-personalization)',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Feed type: exclusion, redirect, or custom',
                            'enum' => ['exclusion', 'redirect', 'custom'],
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'Optional category/reason for grouping',
                        ],
                    ],
                    'required' => ['name', 'type'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-list' => [
                'title' => 'List Feeds',
                'description' => 'List all supplemental feeds with status',
                'callback' => [Abilities\FeedAbilities::class, 'listFeeds'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'description' => 'Filter by feed type (exclusion, redirect, custom)',
                        ],
                    ],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-get' => [
                'title' => 'Get Feed Details',
                'description' => 'Get feed details including all products',
                'callback' => [Abilities\FeedAbilities::class, 'getFeed'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                    ],
                    'required' => ['feed_id'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-add-products' => [
                'title' => 'Add Products to Feed',
                'description' => 'Add one or more products to a feed. For custom feeds, specify attribute_name (e.g., color, gender, age_group)',
                'callback' => [Abilities\FeedAbilities::class, 'addProducts'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                        'skus' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of product SKUs to add',
                        ],
                        'attribute_name' => [
                            'type' => 'string',
                            'description' => 'GMC attribute name (e.g., color, gender, age_group). Required for custom feeds.',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Attribute value to set for all products in this batch',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Optional reason/note for the change',
                        ],
                    ],
                    'required' => ['feed_id', 'skus', 'value'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-remove-product' => [
                'title' => 'Remove Product from Feed',
                'description' => 'Remove a product from a feed',
                'callback' => [Abilities\FeedAbilities::class, 'removeProduct'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                        'sku' => [
                            'type' => 'string',
                            'description' => 'Product SKU to remove',
                        ],
                    ],
                    'required' => ['feed_id', 'sku'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-generate' => [
                'title' => 'Generate Feed File',
                'description' => 'Generate TSV/CSV file for a feed',
                'callback' => [Abilities\FeedAbilities::class, 'generateFile'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                        'format' => [
                            'type' => 'string',
                            'description' => 'Output format: tsv or csv',
                            'enum' => ['tsv', 'csv'],
                            'default' => 'tsv',
                        ],
                    ],
                    'required' => ['feed_id'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-upload' => [
                'title' => 'Upload Feed to GMC',
                'description' => 'Upload a feed to Google Merchant Center',
                'callback' => [Abilities\FeedAbilities::class, 'uploadToGMC'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                    ],
                    'required' => ['feed_id'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-status' => [
                'title' => 'Check Feed Status',
                'description' => 'Check GMC processing status for a feed',
                'callback' => [Abilities\FeedAbilities::class, 'checkStatus'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                    ],
                    'required' => ['feed_id'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-delete' => [
                'title' => 'Delete Feed',
                'description' => 'Delete a feed (optionally from GMC too)',
                'callback' => [Abilities\FeedAbilities::class, 'deleteFeed'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                        'delete_from_gmc' => [
                            'type' => 'boolean',
                            'description' => 'Also delete from GMC if uploaded',
                            'default' => false,
                        ],
                    ],
                    'required' => ['feed_id'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-consolidate' => [
                'title' => 'Consolidate Feeds',
                'description' => 'Merge multiple feeds into one. General-purpose: give source feed IDs and either an existing target_feed_id or target_feed_name + target_feed_type to create a new feed. Product-attribute rows are deduplicated by (product_id, attribute_name); last occurrence wins. Use delete_sources_after to remove source feeds after merge.',
                'callback' => [Abilities\FeedAbilities::class, 'consolidateFeeds'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_feed_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Feed IDs to merge from',
                        ],
                        'target_feed_id' => [
                            'type' => 'integer',
                            'description' => 'Existing feed ID to merge into (use this OR target_feed_name + target_feed_type)',
                        ],
                        'target_feed_name' => [
                            'type' => 'string',
                            'description' => 'Name for new target feed (required if not using target_feed_id)',
                        ],
                        'target_feed_type' => [
                            'type' => 'string',
                            'description' => 'Type for new target feed: exclusion, redirect, or custom',
                            'enum' => ['exclusion', 'redirect', 'custom'],
                        ],
                        'target_feed_category' => [
                            'type' => 'string',
                            'description' => 'Optional category for new target feed',
                        ],
                        'delete_sources_after' => [
                            'type' => 'boolean',
                            'description' => 'Delete source feeds after successful merge',
                            'default' => false,
                        ],
                    ],
                    'required' => ['source_feed_ids'],
                ],
                'category' => 'feed',
            ],
            'gmc-virtual-product-create' => [
                'title' => 'Create Virtual Product',
                'description' => 'Create a GMC-only product for complex funnels (hidden from store)',
                'callback' => [Abilities\FeedAbilities::class, 'createVirtualProduct'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Product name',
                        ],
                        'sku' => [
                            'type' => 'string',
                            'description' => 'Product SKU',
                        ],
                        'price' => [
                            'type' => 'number',
                            'description' => 'Product price',
                        ],
                        'funnel_url' => [
                            'type' => 'string',
                            'description' => 'Funnel URL for ads_redirect',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Product description',
                        ],
                    ],
                    'required' => ['name', 'sku', 'price', 'funnel_url'],
                ],
                'category' => 'feed',
            ],
            'gmc-virtual-product-list' => [
                'title' => 'List Virtual Products',
                'description' => 'List all virtual products created for GMC/funnels',
                'callback' => [Abilities\FeedAbilities::class, 'listVirtualProducts'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'feed',
            ],
            
            // Issue classification tools
            'gmc-classify-issues' => [
                'title' => 'Classify Issues by Tier',
                'description' => 'Get products grouped by issue tier: fixable, misclassified (review needed), or true restrictions',
                'callback' => [Abilities\IssueAbilities::class, 'classifyIssues'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tier' => [
                            'type' => 'string',
                            'description' => 'Filter by tier: fixable, misclassified, or restriction',
                            'enum' => ['fixable', 'misclassified', 'restriction'],
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum products to return',
                            'default' => 50,
                        ],
                    ],
                ],
                'category' => 'product',
            ],
            'gmc-get-issue-summary' => [
                'title' => 'Get Issue Summary',
                'description' => 'Get summary of all issues grouped by tier with counts',
                'callback' => [Abilities\IssueAbilities::class, 'getIssueSummary'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'overview',
            ],
            'gmc-suggest-text-fix' => [
                'title' => 'Suggest Text Fix',
                'description' => 'Analyze a misclassified product or a product with text-based fixable issues and suggest text corrections to avoid policy flags',
                'callback' => [Abilities\IssueAbilities::class, 'suggestTextFix'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'WooCommerce product ID',
                        ],
                        'issue' => [
                            'type' => 'string',
                            'description' => 'The issue description that triggered the misclassification',
                        ],
                    ],
                    'required' => ['product_id', 'issue'],
                ],
                'category' => 'product',
            ],
            'gmc-batch-fix-attributes' => [
                'title' => 'Batch Fix Attributes',
                'description' => 'Automatically apply standard fixes for missing attributes (age group, gender) to fixable products',
                'callback' => [Abilities\IssueAbilities::class, 'batchFixAttributes'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Optional: specific product IDs to fix. If empty, fixes all products in fixable tier.',
                        ],
                    ],
                ],
                'category' => 'product',
            ],
            'gmc-apply-text-fix' => [
                'title' => 'Apply Text Fix',
                'description' => 'Apply a text replacement to a product field to fix misclassification',
                'callback' => [Abilities\IssueAbilities::class, 'applyTextFix'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'WooCommerce product ID',
                        ],
                        'field' => [
                            'type' => 'string',
                            'description' => 'Field to update: title, description, or short_description',
                            'enum' => ['title', 'description', 'short_description'],
                        ],
                        'old_text' => [
                            'type' => 'string',
                            'description' => 'Text to replace',
                        ],
                        'new_text' => [
                            'type' => 'string',
                            'description' => 'Replacement text',
                        ],
                    ],
                    'required' => ['product_id', 'field', 'old_text', 'new_text'],
                ],
                'category' => 'product',
            ],
            'gmc-analyze-policy-language' => [
                'title' => 'Analyze Policy Language',
                'description' => 'Scan product for high-risk words (cure, tachyon, etc.) and suggest clean alternatives',
                'callback' => [Abilities\IssueAbilities::class, 'analyzePolicyLanguage'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'WooCommerce product ID',
                        ],
                    ],
                    'required' => ['product_id'],
                ],
                'category' => 'product',
            ],
            'gmc-auto-populate-feed' => [
                'title' => 'Auto-Populate Feed',
                'description' => 'Automatically add products matching issue patterns to a feed',
                'callback' => [Abilities\FeedAbilities::class, 'autoPopulateFeed'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID to populate',
                        ],
                        'issue_patterns' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Regex patterns to match issue descriptions',
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'If true, only show what would be added',
                            'default' => true,
                        ],
                    ],
                    'required' => ['feed_id', 'issue_patterns'],
                ],
                'category' => 'feed',
            ],
            'gmc-create-policy-feeds' => [
                'title' => 'Create Policy Feeds',
                'description' => 'Create all standard exclusion feeds for policy violations (personalization, pharma, otc)',
                'callback' => [Abilities\FeedAbilities::class, 'createPolicyFeeds'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'If true, only show what would be created',
                            'default' => true,
                        ],
                    ],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-statistics' => [
                'title' => 'Get Feed Statistics',
                'description' => 'Get detailed statistics for a feed including issue types and pending products',
                'callback' => [Abilities\FeedAbilities::class, 'getFeedStatistics'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                    ],
                    'required' => ['feed_id'],
                ],
                'category' => 'feed',
            ],
            'gmc-feed-correlation-report' => [
                'title' => 'Feed Correlation Report',
                'description' => 'Show how many GMC issues are covered by a feed and which ones remain after upload',
                'callback' => [Abilities\FeedAbilities::class, 'getFeedCorrelationReport'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feed_id' => [
                            'type' => 'integer',
                            'description' => 'Feed ID',
                        ],
                    ],
                    'required' => ['feed_id'],
                ],
                'category' => 'feed',
            ],
            'gmc-create-targeted-feed' => [
                'title' => 'Create Targeted Feed',
                'description' => 'Create and populate a supplemental feed for specific attribute fixes based on issue patterns (e.g., set age_group:adult for missing age group issues)',
                'callback' => [Abilities\FeedAbilities::class, 'createTargetedFeed'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Feed name',
                        ],
                        'attribute' => [
                            'type' => 'string',
                            'description' => 'GMC attribute name (e.g., color, gender, age_group)',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Value to set',
                        ],
                        'issue_pattern' => [
                            'type' => 'string',
                            'description' => 'Regex pattern to match GMC issues',
                        ],
                        'category_filter' => [
                            'type' => 'string',
                            'description' => 'Optional: Filter by product category name',
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'If true, only preview matches',
                            'default' => true,
                        ],
                    ],
                    'required' => ['name', 'attribute', 'value', 'issue_pattern'],
                ],
                'category' => 'feed',
            ],

            // Primary product feed tools
            'gmc-primary-feed-status' => [
                'title' => 'Primary Feed Status',
                'description' => 'Get status of the primary product data feed (URL, product count, last generated)',
                'callback' => [Abilities\FeedAbilities::class, 'getPrimaryFeedStatus'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'feed',
            ],
            'gmc-primary-feed-regenerate' => [
                'title' => 'Regenerate Primary Feed',
                'description' => 'Clear cache and regenerate the primary product data feed',
                'callback' => [Abilities\FeedAbilities::class, 'regeneratePrimaryFeed'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'feed',
            ],

            // Orphan cleanup tools
            'gmc-resync-linkage' => [
                'title' => 'Resync WC-GMC Linkage',
                'description' => 'Re-sync WooCommerce↔GMC product linkage in tracking table. Fixes broken links where product_id is 0.',
                'callback' => [Abilities\IssueAbilities::class, 'resyncLinkage'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'maintenance',
            ],
            'gmc-get-orphaned-products' => [
                'title' => 'Get Orphaned Products',
                'description' => 'Get list of GMC products with no WooCommerce match. These can be safely deleted from GMC.',
                'callback' => [Abilities\IssueAbilities::class, 'getOrphanedProducts'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum products to return',
                            'default' => 100,
                        ],
                    ],
                ],
                'category' => 'maintenance',
            ],
            'gmc-delete-orphaned-products' => [
                'title' => 'Delete Orphaned Products',
                'description' => 'Delete orphaned products from GMC. Use type parameter to delete by category or specify offer_ids directly.',
                'callback' => [Abilities\IssueAbilities::class, 'deleteOrphanedProducts'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'offer_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Specific GMC offer IDs to delete (format: online:en:US:XXXXX)',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Delete by orphan type: sku_format, gla_format, or all',
                            'enum' => ['sku_format', 'gla_format', 'all'],
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'If true, only show what would be deleted',
                            'default' => true,
                        ],
                    ],
                ],
                'category' => 'maintenance',
            ],
            'gmc-cleanup-stale-entries' => [
                'title' => 'Cleanup Stale Local Entries',
                'description' => 'Remove stale/duplicate entries from local tracking table. Cleans up old SKU-format IDs when gla_ format exists.',
                'callback' => [Abilities\IssueAbilities::class, 'cleanupStaleEntries'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'If true, only show what would be deleted',
                            'default' => true,
                        ],
                    ],
                ],
                'category' => 'maintenance',
            ],

            // Funnel GMC tools
            'gmc-funnel-list' => [
                'title' => 'List GMC Funnels',
                'description' => 'List all funnels enabled for Google Merchant Center advertising',
                'callback' => [Abilities\FunnelAbilities::class, 'listFunnels'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'funnel',
            ],
            'gmc-funnel-get' => [
                'title' => 'Get Funnel GMC Data',
                'description' => 'Get GMC-specific data for a funnel (price, image, category, etc.)',
                'callback' => [Abilities\FunnelAbilities::class, 'getFunnel'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'funnel_id' => [
                            'type' => 'integer',
                            'description' => 'Funnel post ID',
                        ],
                    ],
                    'required' => ['funnel_id'],
                ],
                'category' => 'funnel',
            ],
            'gmc-funnel-validate' => [
                'title' => 'Validate Funnel for GMC',
                'description' => 'Check if a funnel meets GMC requirements (title, description, image, price)',
                'callback' => [Abilities\FunnelAbilities::class, 'validateFunnel'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'funnel_id' => [
                            'type' => 'integer',
                            'description' => 'Funnel post ID',
                        ],
                    ],
                    'required' => ['funnel_id'],
                ],
                'category' => 'funnel',
            ],
            'gmc-funnel-feed-status' => [
                'title' => 'Funnel Feed Status',
                'description' => 'Get status of the funnel data feed (URL, count, last generated)',
                'callback' => [Abilities\FunnelAbilities::class, 'getFeedStatus'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'funnel',
            ],
            'gmc-funnel-feed-regenerate' => [
                'title' => 'Regenerate Funnel Feed',
                'description' => 'Clear cache and regenerate the funnel product data feed',
                'callback' => [Abilities\FunnelAbilities::class, 'regenerateFeed'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'funnel',
            ],
            'gmc-funnel-enable' => [
                'title' => 'Enable Funnel GMC Sync',
                'description' => 'Enable GMC sync for a funnel so it appears in the funnel feed',
                'callback' => [Abilities\FunnelAbilities::class, 'enableFunnel'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'funnel_id' => [
                            'type' => 'integer',
                            'description' => 'Funnel post ID',
                        ],
                    ],
                    'required' => ['funnel_id'],
                ],
                'category' => 'funnel',
            ],
            'gmc-funnel-disable' => [
                'title' => 'Disable Funnel GMC Sync',
                'description' => 'Disable GMC sync for a funnel',
                'callback' => [Abilities\FunnelAbilities::class, 'disableFunnel'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'funnel_id' => [
                            'type' => 'integer',
                            'description' => 'Funnel post ID',
                        ],
                    ],
                    'required' => ['funnel_id'],
                ],
                'category' => 'funnel',
            ],
            'gmc-funnel-update-settings' => [
                'title' => 'Update Funnel GMC Settings',
                'description' => 'Update GMC-specific settings for a funnel (title override, description, category, labels)',
                'callback' => [Abilities\FunnelAbilities::class, 'updateSettings'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'funnel_id' => [
                            'type' => 'integer',
                            'description' => 'Funnel post ID',
                        ],
                        'title_override' => [
                            'type' => 'string',
                            'description' => 'Override title for GMC (max 150 chars)',
                        ],
                        'description_override' => [
                            'type' => 'string',
                            'description' => 'Override description for GMC (max 5000 chars)',
                        ],
                        'category' => [
                            'type' => 'integer',
                            'description' => 'Google product category ID',
                        ],
                        'brand' => [
                            'type' => 'string',
                            'description' => 'Brand name override',
                        ],
                        'custom_label_0' => [
                            'type' => 'string',
                            'description' => 'Custom label 0 for campaign segmentation',
                        ],
                        'custom_label_1' => [
                            'type' => 'string',
                            'description' => 'Custom label 1 for campaign segmentation',
                        ],
                        'custom_label_2' => [
                            'type' => 'string',
                            'description' => 'Custom label 2 for campaign segmentation',
                        ],
                        'custom_label_3' => [
                            'type' => 'string',
                            'description' => 'Custom label 3 for campaign segmentation',
                        ],
                        'custom_label_4' => [
                            'type' => 'string',
                            'description' => 'Custom label 4 for campaign segmentation',
                        ],
                    ],
                    'required' => ['funnel_id'],
                ],
                'category' => 'funnel',
            ],

            // =========================================================================
            // Audiences (saved segments for Google Ads Customer Match)
            // =========================================================================
            'gmc-audiences-segments-list' => [
                'title' => 'List Audience Segments',
                'description' => 'List saved audience segments (id, name, last_run_at, last_upload_at, count)',
                'callback' => [Abilities\AudiencesAbilities::class, 'segmentsList'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-audiences-segment-get' => [
                'title' => 'Get Audience Segment',
                'description' => 'Get one saved segment by id (definition + last run/upload metadata)',
                'callback' => [Abilities\AudiencesAbilities::class, 'segmentGet'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'segment_id' => [
                            'type' => 'integer',
                            'description' => 'Saved segment ID',
                        ],
                    ],
                    'required' => ['segment_id'],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-audiences-segment-run' => [
                'title' => 'Run Audience Segment',
                'description' => 'Run a saved segment and return count; updates last_run metadata',
                'callback' => [Abilities\AudiencesAbilities::class, 'segmentRun'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'segment_id' => [
                            'type' => 'integer',
                            'description' => 'Saved segment ID',
                        ],
                    ],
                    'required' => ['segment_id'],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-audiences-segment-save' => [
                'title' => 'Save Audience Segment',
                'description' => 'Create or update a saved segment (name, filter_definition; segment_id for update)',
                'callback' => [Abilities\AudiencesAbilities::class, 'segmentSave'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Segment name'],
                        'filter_definition' => ['type' => 'string', 'description' => 'JSON filter definition (logic + conditions)'],
                        'segment_id' => ['type' => 'integer', 'description' => 'For update only'],
                    ],
                    'required' => ['name', 'filter_definition'],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-audiences-segment-duplicate' => [
                'title' => 'Duplicate Audience Segment',
                'description' => 'Duplicate a segment (same logic, new name)',
                'callback' => [Abilities\AudiencesAbilities::class, 'segmentDuplicate'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'segment_id' => ['type' => 'integer', 'description' => 'Source segment ID'],
                        'name' => ['type' => 'string', 'description' => 'Optional new name (default: Copy of ...)'],
                    ],
                    'required' => ['segment_id'],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-audiences-segment-export-csv' => [
                'title' => 'Export Audience Segment CSV',
                'description' => 'Run segment and return CSV in Google Customer Match format (Email, Phone, First name, Last name, Country, Zip)',
                'callback' => [Abilities\AudiencesAbilities::class, 'segmentExportCsv'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'segment_id' => ['type' => 'integer', 'description' => 'Saved segment ID'],
                    ],
                    'required' => ['segment_id'],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-audiences-segment-upload' => [
                'title' => 'Upload Audience Segment to Google Ads',
                'description' => 'Run segment and upload to Google Ads Customer Match (append or replace list). Requires upload enabled in Settings.',
                'callback' => [Abilities\AudiencesAbilities::class, 'segmentUpload'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'segment_id' => ['type' => 'integer', 'description' => 'Saved segment ID'],
                        'append' => ['type' => 'boolean', 'description' => 'True = append to existing list; false = replace list', 'default' => false],
                    ],
                    'required' => ['segment_id'],
                ],
                'category' => 'hp-marketing',
            ],

            // =========================================================================
            // GA4 Analytics Tools
            // =========================================================================
            'gmc-ga4-funnel-performance' => [
                'title' => 'GA4 Funnel Performance',
                'description' => 'Get GA4 funnel performance metrics (sessions, conversions, revenue) by funnel',
                'callback' => [Abilities\GA4Analytics::class, 'funnelPerformance'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_range' => [
                            'type' => 'string',
                            'description' => 'Date range: today, yesterday, last_7_days, last_30_days, last_90_days',
                            'default' => 'last_30_days',
                        ],
                        'funnel_slug' => [
                            'type' => 'string',
                            'description' => 'Filter by funnel slug (empty = all funnels)',
                        ],
                    ],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-ga4-traffic-sources' => [
                'title' => 'GA4 Traffic Sources',
                'description' => 'Get traffic source breakdown (source, medium, campaign) for funnel pages',
                'callback' => [Abilities\GA4Analytics::class, 'trafficSources'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_range' => [
                            'type' => 'string',
                            'description' => 'Date range: today, yesterday, last_7_days, last_30_days, last_90_days',
                            'default' => 'last_30_days',
                        ],
                        'funnel_slug' => [
                            'type' => 'string',
                            'description' => 'Filter by funnel slug (empty = all funnels)',
                        ],
                    ],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-ga4-realtime' => [
                'title' => 'GA4 Realtime',
                'description' => 'Get realtime active users on funnel pages right now',
                'callback' => [Abilities\GA4Analytics::class, 'realtime'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'funnel_slug' => [
                            'type' => 'string',
                            'description' => 'Filter by funnel slug (empty = all pages)',
                        ],
                    ],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-ga4-conversion-funnel' => [
                'title' => 'GA4 Conversion Funnel',
                'description' => 'Get step-by-step conversion funnel with drop-off rates (view_item -> purchase)',
                'callback' => [Abilities\GA4Analytics::class, 'conversionFunnel'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_range' => [
                            'type' => 'string',
                            'description' => 'Date range: today, yesterday, last_7_days, last_30_days, last_90_days',
                            'default' => 'last_30_days',
                        ],
                        'funnel_slug' => [
                            'type' => 'string',
                            'description' => 'Filter by funnel slug (empty = all funnels)',
                        ],
                    ],
                ],
                'category' => 'hp-marketing',
            ],

            // =========================================================================
            // Google Ads Reporting Tools
            // =========================================================================
            'gmc-ads-campaign-performance' => [
                'title' => 'Google Ads Campaign Performance',
                'description' => 'Get campaign performance metrics (clicks, impressions, cost, conversions, ROAS)',
                'callback' => [Abilities\GoogleAdsReporting::class, 'campaignPerformance'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_range' => [
                            'type' => 'string',
                            'description' => 'Date range: today, yesterday, last_7_days, last_30_days, this_month, last_month',
                            'default' => 'last_30_days',
                        ],
                        'campaign_name' => [
                            'type' => 'string',
                            'description' => 'Filter by campaign name (partial match)',
                        ],
                    ],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-ads-funnel-campaign-map' => [
                'title' => 'Funnel-Campaign Map',
                'description' => 'Map Google Ads campaigns to funnels based on naming and URL patterns',
                'callback' => [Abilities\GoogleAdsReporting::class, 'funnelCampaignMap'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_range' => [
                            'type' => 'string',
                            'description' => 'Date range: today, yesterday, last_7_days, last_30_days',
                            'default' => 'last_30_days',
                        ],
                    ],
                ],
                'category' => 'hp-marketing',
            ],
            'gmc-ads-budget-status' => [
                'title' => 'Ads Budget Status',
                'description' => 'Get daily budget, spend, and remaining for all active campaigns',
                'callback' => [Abilities\GoogleAdsReporting::class, 'budgetStatus'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
                'category' => 'hp-marketing',
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

    /**
     * AJAX: Create a new feed.
     */
    public static function ajax_create_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $name = sanitize_text_field($_POST['name'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'exclusion');
        $category = sanitize_text_field($_POST['category'] ?? '');

        if (empty($name)) {
            wp_send_json_error(['message' => 'Feed name is required']);
        }

        $feedId = Services\FeedManager::create($name, $type, $category ?: null);

        if ($feedId) {
            wp_send_json_success([
                'feed_id' => $feedId,
                'message' => 'Feed created successfully',
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to create feed']);
        }
    }

    /**
     * AJAX: Delete a feed.
     */
    public static function ajax_delete_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $result = Services\FeedManager::delete($feedId);

        if ($result) {
            wp_send_json_success(['message' => 'Feed deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete feed']);
        }
    }

    /**
     * AJAX: Generate feed file.
     */
    public static function ajax_generate_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $format = sanitize_text_field($_POST['format'] ?? 'tsv');

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $result = Services\FeedManager::generateFile($feedId, $format);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Upload feed to GMC.
     */
    public static function ajax_upload_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $result = Services\FeedManager::uploadToGMC($feedId);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Check feed status in GMC.
     */
    public static function ajax_check_feed_status(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $result = Services\FeedManager::checkGMCStatus($feedId);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Force GMC to crawl the feed now (trigger immediate fetch).
     */
    public static function ajax_force_crawl_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);
        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $result = Services\FeedManager::triggerGmcFetch($feedId);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Refresh all feed statuses from GMC.
     * First syncs feeds that were added manually in GMC (match by URL), then refreshes status for all with gmc_feed_id.
     */
    public static function ajax_refresh_all_feed_statuses(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        // Sync GMC feed IDs for feeds that have URL but no id (added manually in GMC)
        $syncResult = Services\FeedManager::syncGmcFeedIdsFromList();

        $feeds = Services\FeedManager::getAll();
        $results = [];
        $refreshed = 0;

        foreach ($feeds as $feed) {
            if (!empty($feed['gmc_feed_id'])) {
                $result = Services\FeedManager::checkGMCStatus((int)$feed['id']);
                $results[$feed['id']] = $result;
                if ($result['success']) {
                    $refreshed++;
                }
            }
        }

        $debug = [
            'sync_matched' => $syncResult['matched'] ?? 0,
            'sync_updated' => $syncResult['updated'] ?? [],
            'sync_list_success' => $syncResult['debug']['list_success'] ?? null,
            'sync_list_error' => $syncResult['debug']['list_error'] ?? null,
            'sync_datafeeds_count' => $syncResult['debug']['datafeeds_count'] ?? null,
            'sync_datafeed_fetch_urls_sample' => $syncResult['debug']['datafeed_fetch_urls_sample'] ?? [],
            'sync_datasources_success' => $syncResult['debug']['datasources_success'] ?? null,
            'sync_datasources_count' => $syncResult['debug']['datasources_count'] ?? null,
            'sync_datasource_fetch_urls_sample' => $syncResult['debug']['datasource_fetch_urls_sample'] ?? [],
            'sync_debug_expected_by_feed' => $syncResult['debug']['sync_debug_expected_by_feed'] ?? [],
            'sync_debug_gmc_supplemental_urls' => $syncResult['debug']['sync_debug_gmc_supplemental_urls'] ?? [],
            'feeds_with_gmc_id' => array_values(array_filter(array_map(function ($f) {
                return $f['id'] . ':' . ($f['gmc_feed_id'] ?? '');
            }, $feeds))),
            'refreshed_count' => $refreshed,
            'per_feed_results' => $results,
        ];

        wp_send_json_success([
            'results' => $results,
            'refreshed' => $refreshed,
            'total' => count($feeds),
            'sync_matched' => $syncResult['matched'] ?? 0,
            'sync_updated' => $syncResult['updated'] ?? [],
            'debug' => $debug,
        ]);
    }

    /**
     * AJAX: Publish feed (generate + upload in one step).
     */
    public static function ajax_publish_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        // Step 1: Generate the file
        $generateResult = Services\FeedManager::generateFile($feedId);
        
        if (!$generateResult['success']) {
            wp_send_json_error([
                'message' => 'Failed to generate file: ' . ($generateResult['error'] ?? 'Unknown error'),
                'step' => 'generate',
            ]);
        }

        // Step 2: Upload to GMC
        $uploadResult = Services\FeedManager::uploadToGMC($feedId);
        
        if (!$uploadResult['success']) {
            wp_send_json_error([
                'message' => 'File generated but upload failed: ' . ($uploadResult['error'] ?? 'Unknown error'),
                'step' => 'upload',
                'file_url' => $generateResult['file_url'] ?? null,
            ]);
        }

        $payload = [
            'message' => $uploadResult['message'] ?? 'Feed published successfully',
            'file_url' => $generateResult['file_url'] ?? null,
            'gmc_feed_id' => $uploadResult['gmc_feed_id'] ?? null,
        ];
        if (!empty($uploadResult['supplemental_only']) && !empty($uploadResult['supplemental_url'])) {
            $payload['supplemental_only'] = true;
            $payload['supplemental_url'] = $uploadResult['supplemental_url'];
        }
        wp_send_json_success($payload);
    }

    /**
     * AJAX: Bulk feed action (refresh, upload, delete).
     */
    public static function ajax_bulk_feed_action(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $feedIds = array_map('intval', (array) ($_POST['feed_ids'] ?? []));

        if (empty($action) || empty($feedIds)) {
            wp_send_json_error(['message' => 'Action and feed IDs are required']);
        }

        $results = [];
        $successCount = 0;

        foreach ($feedIds as $feedId) {
            switch ($action) {
                case 'refresh':
                    $feed = Services\FeedManager::get($feedId);
                    if ($feed && !empty($feed['gmc_feed_id'])) {
                        $result = Services\FeedManager::checkGMCStatus($feedId);
                        $results[$feedId] = $result;
                        if ($result['success']) {
                            $successCount++;
                        }
                    }
                    break;

                case 'upload':
                    $feed = Services\FeedManager::get($feedId);
                    if ($feed && !empty($feed['file_url'])) {
                        $result = Services\FeedManager::uploadToGMC($feedId);
                        $results[$feedId] = $result;
                        if ($result['success']) {
                            $successCount++;
                        }
                    }
                    break;

                case 'delete':
                    $result = Services\FeedManager::delete($feedId);
                    $results[$feedId] = ['success' => $result];
                    if ($result) {
                        $successCount++;
                    }
                    break;
            }
        }

        wp_send_json_success([
            'action' => $action,
            'results' => $results,
            'success_count' => $successCount,
            'total' => count($feedIds),
        ]);
    }

    /**
     * AJAX: Export selected feeds to one JSON file (portable format).
     */
    public static function ajax_export_feeds_json(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedIds = array_map('intval', (array) ($_POST['feed_ids'] ?? []));
        if (empty($feedIds)) {
            wp_send_json_error(['message' => 'Select at least one feed.']);
        }

        $data = Services\FeedManager::exportFeedsToArray($feedIds);
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $filename = 'gmc-feeds-export-' . gmdate('Y-m-d-His') . '.json';

        wp_send_json_success(['json' => $json, 'filename' => $filename]);
    }

    /**
     * AJAX: Import feeds from uploaded JSON file.
     */
    public static function ajax_import_feeds_json(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $raw = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['file']['tmp_name']);
        } elseif (!empty($_POST['json'])) {
            $raw = wp_unslash($_POST['json']);
        }

        if ($raw === '') {
            wp_send_json_error(['message' => 'No file or JSON provided.']);
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Invalid JSON: ' . json_last_error_msg()]);
        }

        if (!is_array($data)) {
            wp_send_json_error(['message' => 'JSON must be an object or array.']);
        }

        $result = Services\FeedManager::importFeedsFromArray($data);
        wp_send_json_success($result);
    }

    /**
     * AJAX: Return JSON template for feed import (download as file).
     */
    public static function ajax_download_feed_json_template(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $template = [
            'version' => 1,
            'exported_at' => gmdate('c'),
            'feeds' => [
                [
                    'name' => 'example-feed-one',
                    'feed_type' => 'custom',
                    'category' => 'example',
                    'products' => [
                        [
                            'sku' => 'YOUR-PRODUCT-SKU-1',
                            'attribute_name' => 'gender',
                            'attribute_value' => 'Unisex',
                            'reason' => null,
                        ],
                        [
                            'sku' => 'YOUR-PRODUCT-SKU-2',
                            'attribute_name' => 'age_group',
                            'attribute_value' => 'adult',
                            'reason' => null,
                        ],
                    ],
                ],
                [
                    'name' => 'example-feed-two',
                    'feed_type' => 'exclusion',
                    'category' => 'example',
                    'products' => [
                        [
                            'sku' => 'YOUR-PRODUCT-SKU-3',
                            'attribute_name' => 'excluded_destination',
                            'attribute_value' => 'Display_ads',
                            'reason' => null,
                        ],
                        [
                            'sku' => 'YOUR-PRODUCT-SKU-4',
                            'attribute_name' => 'excluded_destination',
                            'attribute_value' => 'Video_ads',
                            'reason' => null,
                        ],
                    ],
                ],
            ],
        ];
        $json = wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $filename = 'gmc-feeds-import-template.json';

        wp_send_json_success(['json' => $json, 'filename' => $filename]);
    }

    /**
     * AJAX: Get pending products for a feed.
     */
    public static function ajax_get_pending_products(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $feed = Services\FeedManager::get($feedId);
        if (!$feed) {
            wp_send_json_error(['message' => 'Feed not found']);
        }

        $result = Services\FeedManager::getPendingProducts($feed['category']);
        $pending = $result['products'] ?? [];

        wp_send_json_success([
            'feed_id' => $feedId,
            'feed_name' => $feed['name'],
            'category' => $feed['category'],
            'pending_count' => count($pending),
            'products' => $pending,
        ]);
    }

    /**
     * AJAX: Add all pending products to a feed.
     */
    public static function ajax_add_pending_products(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $result = Services\FeedManager::addPendingProducts($feedId);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Remove feed from GMC (keep locally).
     */
    public static function ajax_remove_from_gmc(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);

        if (!$feedId) {
            wp_send_json_error(['message' => 'Feed ID is required']);
        }

        $result = Services\FeedManager::deleteFromGMC($feedId);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => __('Feed removed from GMC successfully', 'hp-gmc-manager'),
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? __('Failed to remove feed from GMC', 'hp-gmc-manager'),
            ]);
        }
    }

    /**
     * AJAX: Add product to feed.
     */
    public static function ajax_add_product_to_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        $value = sanitize_text_field($_POST['value'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? '');

        if (!$feedId || !$productId) {
            wp_send_json_error(['message' => 'Feed ID and Product ID are required']);
        }

        // Get feed to determine attribute name
        $feed = Services\FeedManager::get($feedId);
        if (!$feed) {
            wp_send_json_error(['message' => 'Feed not found']);
        }

        $attribute = $feed['feed_type'] === 'redirect' ? 'ads_redirect' : 'excluded_destination';

        $result = Services\FeedManager::addProduct($feedId, $productId, $attribute, $value, $reason ?: null);

        if ($result) {
            wp_send_json_success(['message' => 'Product added successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to add product']);
        }
    }

    /**
     * AJAX: Remove product from feed.
     */
    public static function ajax_remove_product_from_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);

        if (!$feedId || !$productId) {
            wp_send_json_error(['message' => 'Feed ID and Product ID are required']);
        }

        $result = Services\FeedManager::removeProduct($feedId, $productId);

        if ($result) {
            wp_send_json_success(['message' => 'Product removed successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to remove product']);
        }
    }

    /**
     * AJAX: Search products for autocomplete.
     */
    public static function ajax_search_products(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $term = sanitize_text_field($_POST['term'] ?? '');

        if (strlen($term) < 2) {
            wp_send_json_success(['products' => []]);
        }

        $args = [
            'status' => 'publish',
            'limit' => 20,
            's' => $term,
        ];

        $products = wc_get_products($args);
        $results = [];

        foreach ($products as $product) {
            $results[] = [
                'id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'name' => $product->get_name(),
                'label' => $product->get_sku() . ' - ' . $product->get_name(),
            ];
        }

        // Also search by SKU
        $skuArgs = [
            'status' => 'publish',
            'limit' => 10,
            'sku' => $term,
        ];
        $skuProducts = wc_get_products($skuArgs);
        
        foreach ($skuProducts as $product) {
            $exists = false;
            foreach ($results as $r) {
                if ($r['id'] === $product->get_id()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $results[] = [
                    'id' => $product->get_id(),
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'label' => $product->get_sku() . ' - ' . $product->get_name(),
                ];
            }
        }

        wp_send_json_success(['products' => $results]);
    }

    /**
     * AJAX: Bulk add products to a feed.
     */
    public static function ajax_bulk_add_to_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $productIds = isset($_POST['product_ids']) ? array_map('intval', (array) $_POST['product_ids']) : [];
        $value = sanitize_text_field($_POST['value'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? '');

        if (!$feedId || empty($productIds)) {
            wp_send_json_error(['message' => 'Feed ID and product IDs are required']);
        }

        // Get feed to determine attribute name
        $feed = Services\FeedManager::get($feedId);
        if (!$feed) {
            wp_send_json_error(['message' => 'Feed not found']);
        }

        $attribute = $feed['feed_type'] === 'redirect' ? 'ads_redirect' : 'excluded_destination';

        $result = Services\FeedManager::bulkAddProducts($feedId, $productIds, $attribute, $value, $reason ?: null);

        wp_send_json_success([
            'added' => $result['added'],
            'failed' => $result['failed'],
            'message' => sprintf('Added %d products to feed', $result['added']),
        ]);
    }

    /**
     * AJAX: Analyze trigger keywords for a misclassified product.
     */
    public static function ajax_analyze_triggers(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $productId = (int) ($_POST['product_id'] ?? 0);
        $issue = sanitize_text_field($_POST['issue'] ?? '');

        if (!$productId) {
            wp_send_json_error(['error' => 'Product ID is required']);
        }

        // Get suggestion from IssueClassifier
        $result = Services\IssueClassifier::suggestTextFix($productId, $issue);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Mark a misclassified product as a true restriction.
     */
    public static function ajax_mark_as_restriction(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $productId = (int) ($_POST['product_id'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? 'Manually marked by admin');

        if (!$productId) {
            wp_send_json_error(['error' => 'Product ID is required']);
        }

        $result = Services\IssueClassifier::markAsRestriction($productId, $reason);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler to get issues sub-tab content.
     * This allows client-side tab switching without full page reload.
     */
    public static function ajax_get_issues_subtab(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $subtab = sanitize_key($_POST['subtab'] ?? 'fixable');
        
        if (!in_array($subtab, ['fixable', 'misclassified', 'restriction'])) {
            $subtab = 'fixable';
        }

        // Get products grouped by tier
        $productsByTier = Services\IssueClassifier::getProductsByTier();
        
        // Start output buffering to capture the HTML
        ob_start();
        
        switch ($subtab) {
            case 'fixable':
                Admin\Dashboard::render_fixable_issues_subtab_public($productsByTier[Services\IssueClassifier::TIER_FIXABLE]);
                break;
            case 'misclassified':
                Admin\Dashboard::render_misclassified_issues_subtab_public($productsByTier[Services\IssueClassifier::TIER_MISCLASSIFIED]);
                break;
            case 'restriction':
                Admin\Dashboard::render_restriction_issues_subtab_public($productsByTier[Services\IssueClassifier::TIER_RESTRICTION]);
                break;
        }
        
        $html = ob_get_clean();
        
        wp_send_json_success(['html' => $html, 'subtab' => $subtab]);
    }

    /**
     * AJAX: Regenerate primary product feed.
     */
    public static function ajax_regenerate_primary_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            // Clear cache and regenerate
            Services\ProductDataFeed::clearCache();
            Services\ProductDataFeed::generateFeed('tsv', true);
            Services\ProductDataFeed::generateFeed('csv', true);

            $status = Services\ProductDataFeed::getStatus();

            wp_send_json_success([
                'message' => sprintf(
                    __('Feed regenerated successfully with %d products', 'hp-gmc-manager'),
                    $status['product_count']
                ),
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX: Get primary feed status.
     */
    public static function ajax_get_primary_feed_status(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $status = Services\ProductDataFeed::getStatus();
        wp_send_json_success($status);
    }

    /**
     * AJAX: Regenerate funnel feed.
     */
    public static function ajax_regenerate_funnel_feed(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            if (!Services\FunnelDataFeed::isAvailable()) {
                wp_send_json_error([
                    'message' => __('HP-React-Widgets plugin is not active', 'hp-gmc-manager'),
                ]);
            }

            // Clear cache and regenerate
            Services\FunnelDataFeed::clearCache();
            Services\FunnelDataFeed::generateFeed('tsv', true);
            Services\FunnelDataFeed::generateFeed('csv', true);

            $status = Services\FunnelDataFeed::getStatus();

            wp_send_json_success([
                'message' => sprintf(
                    __('Funnel feed regenerated successfully with %d funnels', 'hp-gmc-manager'),
                    $status['funnel_count']
                ),
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle funnel save event from HP-React-Widgets.
     *
     * @param int $post_id Funnel post ID
     * @param \WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public static function on_funnel_saved(int $post_id, \WP_Post $post, bool $update): void
    {
        // Clear funnel feed cache when any funnel is saved
        if (Services\FunnelDataFeed::isAvailable()) {
            Services\FunnelDataFeed::clearCache();
        }
    }

    /**
     * Handle funnel GMC settings update from HP-React-Widgets.
     *
     * @param int $funnel_id Funnel post ID
     * @param bool $gmc_enabled Whether GMC is enabled for this funnel
     */
    public static function on_funnel_gmc_settings_updated(int $funnel_id, bool $gmc_enabled): void
    {
        // Clear funnel feed cache when GMC settings change
        if (Services\FunnelDataFeed::isAvailable()) {
            Services\FunnelDataFeed::clearCache();
        }
    }
}
