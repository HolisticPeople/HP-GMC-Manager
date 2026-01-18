<?php
namespace HP_GMC\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main dashboard page for HP GMC Manager.
 */
class Dashboard
{
    /**
     * Render the dashboard page.
     */
    public static function render(): void
    {
        $environment = hp_gmc_get_environment();
        $mode = get_option('hp_gmc_mode', 'auto');
        $is_dry_run = hp_gmc_is_dry_run();
        ?>
        <div class="wrap hp-gmc-dashboard">
            <h1>
                <?php esc_html_e('GMC Manager', 'hp-gmc-manager'); ?>
                <span class="hp-gmc-version">v<?php echo esc_html(HP_GMC_VERSION); ?></span>
            </h1>

            <?php self::render_environment_banner($environment, $is_dry_run); ?>

            <nav class="nav-tab-wrapper hp-gmc-tabs">
                <a href="#overview" class="nav-tab nav-tab-active" data-tab="overview">
                    <?php esc_html_e('Overview', 'hp-gmc-manager'); ?>
                </a>
                <a href="#issues" class="nav-tab" data-tab="issues">
                    <?php esc_html_e('Issues', 'hp-gmc-manager'); ?>
                </a>
                <a href="#exclusions" class="nav-tab" data-tab="exclusions">
                    <?php esc_html_e('Exclusions', 'hp-gmc-manager'); ?>
                </a>
                <a href="#shipping" class="nav-tab" data-tab="shipping">
                    <?php esc_html_e('Shipping', 'hp-gmc-manager'); ?>
                </a>
                <a href="#tools" class="nav-tab" data-tab="tools">
                    <?php esc_html_e('MCP Tools', 'hp-gmc-manager'); ?>
                </a>
                <?php if ($is_dry_run): ?>
                <a href="#dry-run-log" class="nav-tab" data-tab="dry-run-log">
                    <?php esc_html_e('Dry Run Log', 'hp-gmc-manager'); ?>
                </a>
                <?php endif; ?>
            </nav>

            <div class="hp-gmc-tab-content">
                <div id="tab-overview" class="hp-gmc-tab-panel active">
                    <?php self::render_overview_tab(); ?>
                </div>
                <div id="tab-issues" class="hp-gmc-tab-panel">
                    <?php self::render_issues_tab(); ?>
                </div>
                <div id="tab-exclusions" class="hp-gmc-tab-panel">
                    <?php self::render_exclusions_tab(); ?>
                </div>
                <div id="tab-shipping" class="hp-gmc-tab-panel">
                    <?php self::render_shipping_tab(); ?>
                </div>
                <div id="tab-tools" class="hp-gmc-tab-panel">
                    <?php self::render_tools_tab(); ?>
                </div>
                <?php if ($is_dry_run): ?>
                <div id="tab-dry-run-log" class="hp-gmc-tab-panel">
                    <?php self::render_dry_run_log_tab(); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render environment banner.
     */
    private static function render_environment_banner(string $environment, bool $is_dry_run): void
    {
        $mode = get_option('hp_gmc_mode', 'auto');
        
        $class = 'hp-gmc-env-banner ';
        $icon = '';
        $message = '';

        if ($environment === 'production' && !$is_dry_run) {
            $class .= 'hp-gmc-env-production';
            $icon = '🟢';
            $message = __('PRODUCTION - Live connection to Google Merchant Center', 'hp-gmc-manager');
        } elseif ($mode === 'passthrough') {
            $class .= 'hp-gmc-env-danger';
            $icon = '🔴';
            $message = __('DANGER: Staging connected to Production GMC!', 'hp-gmc-manager');
        } elseif ($mode === 'mock') {
            $class .= 'hp-gmc-env-mock';
            $icon = '🟣';
            $message = __('MOCK DATA - Testing mode with simulated data', 'hp-gmc-manager');
        } elseif ($environment === 'staging') {
            $class .= 'hp-gmc-env-staging';
            $icon = '🟠';
            $message = __('STAGING - Dry run mode, actions are logged but not executed', 'hp-gmc-manager');
        } else {
            $class .= 'hp-gmc-env-dryrun';
            $icon = '🟡';
            $message = __('DRY RUN - Actions are logged but not executed', 'hp-gmc-manager');
        }
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <span class="hp-gmc-env-icon"><?php echo $icon; ?></span>
            <span class="hp-gmc-env-message"><?php echo esc_html($message); ?></span>
            <span class="hp-gmc-env-details">
                (<?php echo esc_html(ucfirst($environment)); ?> / Mode: <?php echo esc_html($mode); ?>)
            </span>
        </div>
        <?php
    }

    /**
     * Render the overview tab.
     */
    private static function render_overview_tab(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';
        
        // Get counts from cache table
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'approved'");
        $disapproved = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'disapproved'");
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        $warning = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'warning'");

        $last_sync = get_option('hp_gmc_last_sync', null);
        ?>
        <div class="hp-gmc-overview">
            <div class="hp-gmc-cards">
                <div class="hp-gmc-card hp-gmc-card-total">
                    <h3><?php esc_html_e('Total Products', 'hp-gmc-manager'); ?></h3>
                    <div class="hp-gmc-card-value"><?php echo esc_html(number_format($total)); ?></div>
                </div>
                <div class="hp-gmc-card hp-gmc-card-approved">
                    <h3><?php esc_html_e('Approved', 'hp-gmc-manager'); ?></h3>
                    <div class="hp-gmc-card-value"><?php echo esc_html(number_format($approved)); ?></div>
                </div>
                <div class="hp-gmc-card hp-gmc-card-disapproved">
                    <h3><?php esc_html_e('Disapproved', 'hp-gmc-manager'); ?></h3>
                    <div class="hp-gmc-card-value"><?php echo esc_html(number_format($disapproved)); ?></div>
                </div>
                <div class="hp-gmc-card hp-gmc-card-pending">
                    <h3><?php esc_html_e('Pending', 'hp-gmc-manager'); ?></h3>
                    <div class="hp-gmc-card-value"><?php echo esc_html(number_format($pending)); ?></div>
                </div>
                <div class="hp-gmc-card hp-gmc-card-warning">
                    <h3><?php esc_html_e('Warnings', 'hp-gmc-manager'); ?></h3>
                    <div class="hp-gmc-card-value"><?php echo esc_html(number_format($warning)); ?></div>
                </div>
            </div>

            <div class="hp-gmc-actions">
                <button type="button" class="button button-primary" id="hp-gmc-sync-now">
                    <?php esc_html_e('Sync Now', 'hp-gmc-manager'); ?>
                </button>
                <span class="hp-gmc-last-sync">
                    <?php if ($last_sync): ?>
                        <?php printf(
                            esc_html__('Last sync: %s', 'hp-gmc-manager'),
                            esc_html(human_time_diff(strtotime($last_sync), time()) . ' ago')
                        ); ?>
                    <?php else: ?>
                        <?php esc_html_e('Never synced', 'hp-gmc-manager'); ?>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($total === 0): ?>
            <div class="hp-gmc-empty-state">
                <p><?php esc_html_e('No product data yet. Click "Sync Now" to fetch product statuses from Google Merchant Center.', 'hp-gmc-manager'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the issues tab.
     */
    private static function render_issues_tab(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';
        
        // Get ALL products from cache table for status counts
        $all_products = $wpdb->get_results("SELECT * FROM $table ORDER BY status DESC, last_updated DESC");
        
        // Fresh read of last sync time (bypass any caching)
        $last_sync = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'hp_gmc_last_sync' LIMIT 1");
        
        // Count statuses across ALL products
        $statuses = [
            'approved' => 0,
            'disapproved' => 0,
            'pending' => 0,
            'warning' => 0,
        ];
        
        // Collect unique brands and issue types with counts
        $all_brands = [];
        $issue_type_counts = [];
        
        // Products to display (with issues)
        $issues = [];
        
        foreach ($all_products as $product_row) {
            // Count all statuses
            if (isset($statuses[$product_row->status])) {
                $statuses[$product_row->status]++;
            }
            
            // Only process products with issues for display and brand/issue collection
            if (in_array($product_row->status, ['disapproved', 'warning'])) {
                $issues[] = $product_row;
                
                $product = wc_get_product($product_row->product_id);
                if ($product) {
                    $brand = self::get_product_brand($product);
                    if ($brand && !in_array($brand, $all_brands)) {
                        $all_brands[] = $brand;
                    }
                }
                
                // Collect issue types with counts
                $issue_data = json_decode($product_row->issues, true) ?: [];
                foreach ($issue_data as $i) {
                    $desc = $i['description'] ?? (is_string($i) ? $i : '');
                    if ($desc) {
                        if (!isset($issue_type_counts[$desc])) {
                            $issue_type_counts[$desc] = 0;
                        }
                        $issue_type_counts[$desc]++;
                    }
                }
            }
        }
        
        sort($all_brands);
        ksort($issue_type_counts);
        ?>
        <div class="hp-gmc-issues">
            <div class="hp-gmc-section-header">
                <h2>
                    <?php esc_html_e('Product Issues', 'hp-gmc-manager'); ?>
                    <?php if ($last_sync): ?>
                        <span class="hp-gmc-last-sync-inline">
                            (<?php printf(
                                esc_html__('Last sync: %s', 'hp-gmc-manager'),
                                esc_html(human_time_diff(strtotime($last_sync), time()) . ' ago')
                            ); ?>)
                        </span>
                    <?php endif; ?>
                </h2>
                <button type="button" class="button" id="hp-gmc-refresh-issues">
                    <?php esc_html_e('Refresh', 'hp-gmc-manager'); ?>
                </button>
            </div>
            
            <?php if (empty($issues)): ?>
            <p><?php esc_html_e('No issues found. All products are approved!', 'hp-gmc-manager'); ?></p>
            <?php else: ?>
            
            <!-- Filters -->
            <div class="hp-gmc-filters">
                <select id="hp-gmc-filter-status" class="hp-gmc-filter">
                    <option value=""><?php esc_html_e('All Statuses', 'hp-gmc-manager'); ?></option>
                    <?php foreach ($statuses as $status_key => $count): ?>
                        <?php if ($count > 0): ?>
                            <option value="<?php echo esc_attr($status_key); ?>">
                                <?php echo esc_html(ucfirst($status_key) . ' (' . $count . ')'); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                
                <select id="hp-gmc-filter-brand" class="hp-gmc-filter">
                    <option value=""><?php esc_html_e('All Brands', 'hp-gmc-manager'); ?></option>
                    <?php foreach ($all_brands as $brand): ?>
                        <option value="<?php echo esc_attr($brand); ?>"><?php echo esc_html($brand); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select id="hp-gmc-filter-issue" class="hp-gmc-filter">
                    <option value=""><?php esc_html_e('All Issue Types', 'hp-gmc-manager'); ?></option>
                    <?php foreach ($issue_type_counts as $issue_type => $count): ?>
                        <option value="<?php echo esc_attr($issue_type); ?>">
                            <?php echo esc_html($issue_type . ' (' . $count . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="button" class="button" id="hp-gmc-clear-filters">
                    <?php esc_html_e('Clear Filters', 'hp-gmc-manager'); ?>
                </button>
                
                <span class="hp-gmc-filter-count">
                    <?php printf(esc_html__('Showing %d products', 'hp-gmc-manager'), count($issues)); ?>
                </span>
            </div>
            
            <table class="wp-list-table widefat fixed striped hp-gmc-issues-table" id="hp-gmc-issues-table">
                <thead>
                    <tr>
                        <th class="column-product"><?php esc_html_e('Product', 'hp-gmc-manager'); ?></th>
                        <th class="column-sku"><?php esc_html_e('SKU', 'hp-gmc-manager'); ?></th>
                        <th class="column-brand"><?php esc_html_e('Brand', 'hp-gmc-manager'); ?></th>
                        <th class="column-gmc-id"><?php esc_html_e('GMC ID', 'hp-gmc-manager'); ?></th>
                        <th class="column-status"><?php esc_html_e('Status', 'hp-gmc-manager'); ?></th>
                        <th class="column-issues"><?php esc_html_e('Issues', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issues as $issue): 
                        $product = wc_get_product($issue->product_id);
                        $issue_data = json_decode($issue->issues, true) ?: [];
                        $sku = $product ? $product->get_sku() : '';
                        $brand = $product ? self::get_product_brand($product) : '';
                        
                        // Deduplicate issues and count occurrences
                        $issue_counts = [];
                        foreach ($issue_data as $i) {
                            $desc = $i['description'] ?? (is_string($i) ? $i : '');
                            if ($desc) {
                                if (!isset($issue_counts[$desc])) {
                                    $issue_counts[$desc] = 0;
                                }
                                $issue_counts[$desc]++;
                            }
                        }
                    ?>
                    <tr data-status="<?php echo esc_attr($issue->status); ?>" 
                        data-brand="<?php echo esc_attr($brand); ?>"
                        data-issues="<?php echo esc_attr(implode('|', array_keys($issue_counts))); ?>">
                        <td class="column-product">
                            <?php if ($product): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($issue->product_id)); ?>">
                                    <?php echo esc_html($product->get_name()); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html__('Product #', 'hp-gmc-manager') . esc_html($issue->product_id); ?>
                            <?php endif; ?>
                        </td>
                        <td class="column-sku"><code><?php echo esc_html($sku ?: '-'); ?></code></td>
                        <td class="column-brand"><?php echo esc_html($brand ?: '-'); ?></td>
                        <td class="column-gmc-id"><code><?php echo esc_html($issue->gla_id); ?></code></td>
                        <td class="column-status">
                            <span class="hp-gmc-status hp-gmc-status-<?php echo esc_attr($issue->status); ?>">
                                <?php echo esc_html(ucfirst($issue->status)); ?>
                            </span>
                        </td>
                        <td class="column-issues">
                            <?php if (!empty($issue_counts)): ?>
                                <ul class="hp-gmc-issue-list">
                                    <?php foreach ($issue_counts as $desc => $count): ?>
                                        <li>
                                            <?php echo esc_html($desc); ?>
                                            <?php if ($count > 1): ?>
                                                <span class="hp-gmc-issue-count">(×<?php echo esc_html($count); ?>)</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Get brand from product using same logic as HP-Product-Manager.
     * Source of truth: yith_product_brand taxonomy, fallback to 'brand' attribute.
     */
    private static function get_product_brand($product): string
    {
        if (!$product) {
            return '';
        }
        
        $product_id = $product->get_id();
        $names = [];
        
        // Primary: yith_product_brand taxonomy (same as HP-Product-Manager)
        if (taxonomy_exists('yith_product_brand')) {
            $terms = get_the_terms($product_id, 'yith_product_brand');
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $names[$term->name] = true;
                }
            }
        }
        
        if (!empty($names)) {
            return implode(', ', array_keys($names));
        }
        
        // Fallback: 'brand' attribute (same as HP-Product-Manager)
        $brand = $product->get_attribute('brand');
        if ($brand) {
            return $brand;
        }
        
        return '';
    }

    /**
     * Render the exclusions tab.
     */
    private static function render_exclusions_tab(): void
    {
        ?>
        <div class="hp-gmc-exclusions">
            <h2><?php esc_html_e('Product Exclusions', 'hp-gmc-manager'); ?></h2>
            <p><?php esc_html_e('Manage which products are excluded from specific Google destinations.', 'hp-gmc-manager'); ?></p>
            
            <div class="hp-gmc-exclusions-info">
                <p><strong><?php esc_html_e('Available Destinations:', 'hp-gmc-manager'); ?></strong></p>
                <ul>
                    <li><code>Shopping_ads</code> - Paid Shopping campaigns</li>
                    <li><code>Display_ads</code> - Display network</li>
                    <li><code>Local_inventory_ads</code> - Local store inventory</li>
                    <li><code>Free_listings</code> - Organic Shopping tab</li>
                    <li><code>Free_local_listings</code> - Free local results</li>
                    <li><code>YouTube_Shopping</code> - YouTube product listings</li>
                </ul>
            </div>

            <p class="description">
                <?php esc_html_e('Use the MCP tool "gmc-set-exclusion" to manage exclusions via AI, or upload a supplemental feed in Google Merchant Center.', 'hp-gmc-manager'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the shipping tab.
     */
    private static function render_shipping_tab(): void
    {
        ?>
        <div class="hp-gmc-shipping">
            <div class="hp-gmc-section-header">
                <h2><?php esc_html_e('Shipping Settings', 'hp-gmc-manager'); ?></h2>
                <button type="button" class="button" id="hp-gmc-refresh-shipping">
                    <?php esc_html_e('Refresh', 'hp-gmc-manager'); ?>
                </button>
            </div>
            <p><?php esc_html_e('Manage account-level shipping configuration in Google Merchant Center.', 'hp-gmc-manager'); ?></p>

            <div id="hp-gmc-shipping-data">
                <p class="description"><?php esc_html_e('Click "Refresh" to load current shipping settings from GMC.', 'hp-gmc-manager'); ?></p>
            </div>

            <div class="hp-gmc-shipping-tools">
                <h3><?php esc_html_e('Quick Actions (via MCP)', 'hp-gmc-manager'); ?></h3>
                <ul>
                    <li><code>gmc-get-shipping-settings</code> - View all shipping services</li>
                    <li><code>gmc-enable-country</code> - Add a country to shipping</li>
                    <li><code>gmc-disable-country</code> - Remove a country from shipping</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render the MCP tools tab.
     */
    private static function render_tools_tab(): void
    {
        $all_tools = \HP_GMC\Plugin::get_all_tools();
        $enabled_tools = get_option('hp_gmc_enabled_tools', []);

        // Group tools by category
        $categories = [
            'overview' => __('Overview', 'hp-gmc-manager'),
            'product' => __('Product', 'hp-gmc-manager'),
            'shipping' => __('Shipping', 'hp-gmc-manager'),
            'account' => __('Account', 'hp-gmc-manager'),
            'test' => __('Test', 'hp-gmc-manager'),
        ];
        ?>
        <div class="hp-gmc-tools">
            <h2><?php esc_html_e('MCP Tools Management', 'hp-gmc-manager'); ?></h2>
            <p><?php esc_html_e('Enable or disable individual tools to control which abilities are available to Cursor.', 'hp-gmc-manager'); ?></p>

            <div class="hp-gmc-tools-actions">
                <button type="button" class="button" id="hp-gmc-enable-all">
                    <?php esc_html_e('Enable All', 'hp-gmc-manager'); ?>
                </button>
                <button type="button" class="button" id="hp-gmc-disable-all">
                    <?php esc_html_e('Disable All', 'hp-gmc-manager'); ?>
                </button>
                <select id="hp-gmc-preset">
                    <option value=""><?php esc_html_e('Load Preset...', 'hp-gmc-manager'); ?></option>
                    <option value="minimal"><?php esc_html_e('Minimal (Dashboard only)', 'hp-gmc-manager'); ?></option>
                    <option value="product"><?php esc_html_e('Product Focus', 'hp-gmc-manager'); ?></option>
                    <option value="full"><?php esc_html_e('Full (All tools)', 'hp-gmc-manager'); ?></option>
                </select>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php esc_html_e('Status', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Tool Name', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Category', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Description', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_tools as $tool_id => $tool): 
                        $is_enabled = !isset($enabled_tools[$tool_id]) || $enabled_tools[$tool_id];
                        $category = $tool['category'] ?? 'other';
                    ?>
                    <tr data-tool-id="<?php echo esc_attr($tool_id); ?>">
                        <td>
                            <label class="hp-gmc-toggle">
                                <input type="checkbox" 
                                       class="hp-gmc-tool-toggle" 
                                       data-tool-id="<?php echo esc_attr($tool_id); ?>"
                                       <?php checked($is_enabled); ?>>
                                <span class="hp-gmc-toggle-slider"></span>
                            </label>
                        </td>
                        <td><code><?php echo esc_html($tool_id); ?></code></td>
                        <td><?php echo esc_html($categories[$category] ?? ucfirst($category)); ?></td>
                        <td><?php echo esc_html($tool['description']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description">
                <?php esc_html_e('Note: Changes take effect immediately. Disabled tools will not appear in Cursor.', 'hp-gmc-manager'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the dry run log tab.
     */
    private static function render_dry_run_log_tab(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_dry_run_log';
        
        $logs = $wpdb->get_results("
            SELECT * FROM $table 
            ORDER BY created_at DESC 
            LIMIT 100
        ");
        ?>
        <div class="hp-gmc-dry-run-log">
            <div class="hp-gmc-section-header">
                <h2><?php esc_html_e('Dry Run Log', 'hp-gmc-manager'); ?></h2>
                <div class="hp-gmc-log-actions">
                    <button type="button" class="button" id="hp-gmc-refresh-log">
                        <?php esc_html_e('Refresh', 'hp-gmc-manager'); ?>
                    </button>
                    <button type="button" class="button" id="hp-gmc-clear-log">
                        <?php esc_html_e('Clear Log', 'hp-gmc-manager'); ?>
                    </button>
                    <button type="button" class="button" id="hp-gmc-export-log">
                        <?php esc_html_e('Export JSON', 'hp-gmc-manager'); ?>
                    </button>
                </div>
            </div>
            <p><?php esc_html_e('Actions that would have been executed in live mode.', 'hp-gmc-manager'); ?></p>

            <?php if (empty($logs)): ?>
            <p><?php esc_html_e('No dry run actions logged yet.', 'hp-gmc-manager'); ?></p>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Action', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Endpoint', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Parameters', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log->created_at); ?></td>
                        <td><code><?php echo esc_html($log->action); ?></code></td>
                        <td><code><?php echo esc_html($log->endpoint); ?></code></td>
                        <td>
                            <details>
                                <summary><?php esc_html_e('View', 'hp-gmc-manager'); ?></summary>
                                <pre><?php echo esc_html($log->params); ?></pre>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
