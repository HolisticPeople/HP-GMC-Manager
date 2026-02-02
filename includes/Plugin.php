<?php
namespace HP_GMC;

use HP_GMC\Admin\Dashboard;
use HP_GMC\Admin\SettingsPage;
use HP_GMC\Services\MerchantApiClient;
use HP_GMC\Services\IssueMonitor;
use HP_GMC\Rest\ProductFeedEndpoint;
use HP_GMC\Rest\FunnelFeedEndpoint;

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
        add_action('wp_ajax_hp_gmc_add_product_to_feed', [self::class, 'ajax_add_product_to_feed']);
        add_action('wp_ajax_hp_gmc_remove_product_from_feed', [self::class, 'ajax_remove_product_from_feed']);
        add_action('wp_ajax_hp_gmc_search_products', [self::class, 'ajax_search_products']);
        add_action('wp_ajax_hp_gmc_bulk_add_to_feed', [self::class, 'ajax_bulk_add_to_feed']);
        add_action('wp_ajax_hp_gmc_refresh_all_feed_statuses', [self::class, 'ajax_refresh_all_feed_statuses']);
        add_action('wp_ajax_hp_gmc_publish_feed', [self::class, 'ajax_publish_feed']);
        add_action('wp_ajax_hp_gmc_bulk_feed_action', [self::class, 'ajax_bulk_feed_action']);
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
                'category' => 'hp-gmc',
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
            'gmc-debug-products-api' => [
                'title' => 'Debug Products API',
                'description' => 'Debug: Get raw API response for products endpoint to diagnose issues',
                'callback' => [Abilities\ProductAbilities::class, 'debugProductsApi'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_size' => [
                            'type' => 'integer',
                            'description' => 'Number of products to fetch',
                            'default' => 5,
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
     * AJAX: Refresh all feed statuses from GMC.
     */
    public static function ajax_refresh_all_feed_statuses(): void
    {
        check_ajax_referer('hp_gmc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

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

        wp_send_json_success([
            'results' => $results,
            'refreshed' => $refreshed,
            'total' => count($feeds),
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

        wp_send_json_success([
            'message' => 'Feed published successfully',
            'file_url' => $generateResult['file_url'] ?? null,
            'gmc_feed_id' => $uploadResult['gmc_feed_id'] ?? null,
        ]);
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

            error_log(json_encode([
                'event' => 'funnel_feed.cache_cleared_on_save',
                'funnel_id' => $post_id,
                'update' => $update,
                'timestamp' => current_time('mysql'),
            ]));
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

            error_log(json_encode([
                'event' => 'funnel_feed.gmc_settings_updated',
                'funnel_id' => $funnel_id,
                'gmc_enabled' => $gmc_enabled,
                'timestamp' => current_time('mysql'),
            ]));
        }
    }
}
