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
        } elseif ($environment === 'staging' && $mode === 'live') {
            // Staging with live mode - connected to production GMC from staging
            $class .= 'hp-gmc-env-staging';
            $icon = '🟠';
            $message = __('STAGING - Live connection to Production GMC (read-only recommended)', 'hp-gmc-manager');
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
        
        // Get products with issues only (disapproved/warning)
        $issues = $wpdb->get_results("
            SELECT * FROM $table 
            WHERE status IN ('disapproved', 'warning') 
            ORDER BY status DESC, last_updated DESC
        ");
        
        // Fresh read of last sync time (bypass WP caching entirely)
        $last_sync = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'hp_gmc_last_sync'
        ));
        
        // Count statuses from the issues we're displaying (only disapproved/warning)
        $statuses = [
            'disapproved' => 0,
            'warning' => 0,
        ];
        
        // Collect unique brands and issue types with counts
        $all_brands = [];
        $issue_type_counts = [];
        
        foreach ($issues as $product_row) {
            // Count statuses
            if (isset($statuses[$product_row->status])) {
                $statuses[$product_row->status]++;
            }
            
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
        
        sort($all_brands);
        ksort($issue_type_counts);
        
        // Get fresh current time for comparison
        $now = time();
        ?>
        <div class="hp-gmc-issues">
            <div class="hp-gmc-section-header">
                <h2>
                    <?php esc_html_e('Product Issues', 'hp-gmc-manager'); ?>
                    <?php if ($last_sync): 
                        $sync_timestamp = strtotime($last_sync);
                        $diff = $now - $sync_timestamp;
                        if ($diff < 60) {
                            $time_ago = __('just now', 'hp-gmc-manager');
                        } elseif ($diff < 3600) {
                            $mins = round($diff / 60);
                            $time_ago = sprintf(_n('%d minute ago', '%d minutes ago', $mins, 'hp-gmc-manager'), $mins);
                        } elseif ($diff < 86400) {
                            $hours = round($diff / 3600);
                            $time_ago = sprintf(_n('%d hour ago', '%d hours ago', $hours, 'hp-gmc-manager'), $hours);
                        } else {
                            $days = round($diff / 86400);
                            $time_ago = sprintf(_n('%d day ago', '%d days ago', $days, 'hp-gmc-manager'), $days);
                        }
                    ?>
                        <span class="hp-gmc-last-sync-inline">
                            (<?php printf(esc_html__('Last sync: %s', 'hp-gmc-manager'), esc_html($time_ago)); ?>)
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
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';
        
        // Get products with destination data
        $products = $wpdb->get_results("
            SELECT * FROM $table 
            WHERE destinations IS NOT NULL AND destinations != '' AND destinations != '[]'
            ORDER BY status DESC, last_updated DESC
            LIMIT 100
        ");
        
        // Available destinations
        $destinations = [
            'SHOPPING_ADS' => ['label' => 'Shopping Ads', 'description' => 'Paid Shopping campaigns'],
            'FREE_LISTINGS' => ['label' => 'Free Listings', 'description' => 'Organic Shopping tab'],
            'DISPLAY_ADS' => ['label' => 'Display Ads', 'description' => 'Display network'],
            'DEMAND_GEN_ADS' => ['label' => 'Demand Gen', 'description' => 'Demand generation ads'],
            'VIDEO_ADS' => ['label' => 'Video Ads', 'description' => 'YouTube video ads'],
            'YOUTUBE_SHOPPING' => ['label' => 'YouTube Shopping', 'description' => 'YouTube product listings'],
        ];
        
        // Count products by destination approval status
        $destinationStats = [];
        foreach ($destinations as $destKey => $destInfo) {
            $destinationStats[$destKey] = ['approved' => 0, 'disapproved' => 0];
        }
        
        foreach ($products as $product) {
            $productDestinations = json_decode($product->destinations, true) ?: [];
            foreach ($productDestinations as $dest) {
                $context = $dest['context'] ?? '';
                if (isset($destinationStats[$context])) {
                    $approved = $dest['approved_countries'] ?? [];
                    if (!empty($approved)) {
                        $destinationStats[$context]['approved']++;
                    } else {
                        $destinationStats[$context]['disapproved']++;
                    }
                }
            }
        }
        ?>
        <div class="hp-gmc-exclusions">
            <div class="hp-gmc-section-header">
                <h2><?php esc_html_e('Product Destinations & Exclusions', 'hp-gmc-manager'); ?></h2>
            </div>
            <p><?php esc_html_e('View which Google destinations each product is approved for. Use exclusions to control where products appear.', 'hp-gmc-manager'); ?></p>
            
            <!-- Destination Summary -->
            <div class="hp-gmc-destination-summary">
                <?php foreach ($destinations as $destKey => $destInfo): ?>
                <div class="hp-gmc-dest-card">
                    <div class="hp-gmc-dest-header">
                        <strong><?php echo esc_html($destInfo['label']); ?></strong>
                    </div>
                    <div class="hp-gmc-dest-stats">
                        <span class="hp-gmc-dest-approved">
                            <?php echo esc_html($destinationStats[$destKey]['approved']); ?> <?php esc_html_e('approved', 'hp-gmc-manager'); ?>
                        </span>
                        <?php if ($destinationStats[$destKey]['disapproved'] > 0): ?>
                        <span class="hp-gmc-dest-disapproved">
                            <?php echo esc_html($destinationStats[$destKey]['disapproved']); ?> <?php esc_html_e('blocked', 'hp-gmc-manager'); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="hp-gmc-dest-desc">
                        <?php echo esc_html($destInfo['description']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Products with Destination Status -->
            <h3><?php esc_html_e('Products by Destination Status', 'hp-gmc-manager'); ?></h3>
            
            <?php if (empty($products)): ?>
            <p><?php esc_html_e('No product destination data yet. Run a sync to fetch data from GMC.', 'hp-gmc-manager'); ?></p>
            <?php else: ?>
            
            <div class="hp-gmc-exclusions-filters">
                <select id="hp-gmc-dest-filter">
                    <option value=""><?php esc_html_e('All Destinations', 'hp-gmc-manager'); ?></option>
                    <?php foreach ($destinations as $destKey => $destInfo): ?>
                    <option value="<?php echo esc_attr($destKey); ?>"><?php echo esc_html($destInfo['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="hp-gmc-dest-status-filter">
                    <option value=""><?php esc_html_e('All Statuses', 'hp-gmc-manager'); ?></option>
                    <option value="approved"><?php esc_html_e('Approved', 'hp-gmc-manager'); ?></option>
                    <option value="blocked"><?php esc_html_e('Blocked', 'hp-gmc-manager'); ?></option>
                </select>
            </div>
            
            <table class="wp-list-table widefat fixed striped hp-gmc-exclusions-table" id="hp-gmc-exclusions-table">
                <thead>
                    <tr>
                        <th style="width:25%"><?php esc_html_e('Product', 'hp-gmc-manager'); ?></th>
                        <th style="width:10%"><?php esc_html_e('SKU', 'hp-gmc-manager'); ?></th>
                        <th style="width:10%"><?php esc_html_e('Status', 'hp-gmc-manager'); ?></th>
                        <th style="width:55%"><?php esc_html_e('Destination Status', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($products, 0, 50) as $product): 
                        $wcProduct = wc_get_product($product->product_id);
                        $productDestinations = json_decode($product->destinations, true) ?: [];
                        
                        // Build destination status data
                        $destData = [];
                        foreach ($productDestinations as $dest) {
                            $context = $dest['context'] ?? '';
                            $approved = $dest['approved_countries'] ?? [];
                            $destData[$context] = count($approved);
                        }
                    ?>
                    <tr data-destinations="<?php echo esc_attr(json_encode(array_keys($destData))); ?>">
                        <td>
                            <?php if ($wcProduct): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($product->product_id)); ?>">
                                    <?php echo esc_html($wcProduct->get_name()); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html__('Product #', 'hp-gmc-manager') . esc_html($product->product_id); ?>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($wcProduct ? $wcProduct->get_sku() : '-'); ?></code></td>
                        <td>
                            <span class="hp-gmc-status hp-gmc-status-<?php echo esc_attr($product->status); ?>">
                                <?php echo esc_html(ucfirst($product->status)); ?>
                            </span>
                        </td>
                        <td>
                            <div class="hp-gmc-dest-badges">
                                <?php foreach ($destinations as $destKey => $destInfo): 
                                    $countryCount = $destData[$destKey] ?? 0;
                                    $isApproved = $countryCount > 0;
                                ?>
                                <span class="hp-gmc-dest-badge <?php echo $isApproved ? 'hp-gmc-dest-badge-approved' : 'hp-gmc-dest-badge-blocked'; ?>"
                                      title="<?php echo esc_attr($destInfo['description']); ?>">
                                    <?php echo esc_html($destInfo['label']); ?>
                                    <?php if ($isApproved): ?>
                                        <span class="hp-gmc-country-count">(<?php echo esc_html($countryCount); ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <!-- MCP Tools Reference -->
            <div class="hp-gmc-exclusions-tools">
                <h3><?php esc_html_e('Managing Exclusions via MCP', 'hp-gmc-manager'); ?></h3>
                <div class="hp-gmc-mcp-commands">
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-set-exclusion</code>
                        <span><?php esc_html_e('Exclude product from destinations (sku, destinations[])', 'hp-gmc-manager'); ?></span>
                    </div>
                </div>
                <p class="description" style="margin-top:15px;">
                    <?php esc_html_e('Example: To exclude a product with policy issues from Shopping ads, use gmc-set-exclusion with sku and destinations: ["Shopping_ads"]', 'hp-gmc-manager'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the shipping tab.
     */
    private static function render_shipping_tab(): void
    {
        // Get shipping settings from GMC
        $client = new \HP_GMC\Services\MerchantApiClient();
        $shippingData = $client->getShippingSettings();
        
        $services = [];
        $warehouses = [];
        $allCountries = [];
        
        if ($shippingData['success'] && isset($shippingData['data'])) {
            $services = $shippingData['data']['services'] ?? [];
            $warehouses = $shippingData['data']['warehouses'] ?? [];
            
            foreach ($services as $service) {
                $countries = $service['deliveryCountries'] ?? [];
                $allCountries = array_merge($allCountries, $countries);
            }
            $allCountries = array_unique($allCountries);
            sort($allCountries);
        }
        
        // Country regions for filtering
        $regions = [
            'North America' => ['US', 'CA', 'MX'],
            'Europe' => ['GB', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH', 'PL', 'SE', 'NO', 'DK', 'FI', 'IE', 'PT', 'CZ', 'GR', 'HU', 'RO'],
            'Asia Pacific' => ['JP', 'AU', 'NZ', 'SG', 'HK', 'TW', 'KR', 'MY', 'TH', 'PH', 'ID', 'VN', 'IN'],
            'Middle East' => ['AE', 'SA', 'IL', 'TR'],
            'South America' => ['BR', 'AR', 'CL', 'CO', 'PE'],
        ];
        ?>
        <div class="hp-gmc-shipping">
            <div class="hp-gmc-section-header">
                <h2><?php esc_html_e('Shipping Configuration', 'hp-gmc-manager'); ?></h2>
                <button type="button" class="button" id="hp-gmc-refresh-shipping">
                    <?php esc_html_e('Refresh from GMC', 'hp-gmc-manager'); ?>
                </button>
            </div>
            
            <?php if (!$shippingData['success']): ?>
            <div class="notice notice-error inline">
                <p><?php echo esc_html($shippingData['error'] ?? 'Failed to load shipping settings'); ?></p>
            </div>
            <?php else: ?>
            
            <!-- Summary -->
            <div class="hp-gmc-shipping-summary">
                <div class="hp-gmc-stat-box">
                    <span class="hp-gmc-stat-value"><?php echo count($services); ?></span>
                    <span class="hp-gmc-stat-label"><?php esc_html_e('Shipping Services', 'hp-gmc-manager'); ?></span>
                </div>
                <div class="hp-gmc-stat-box">
                    <span class="hp-gmc-stat-value"><?php echo count($allCountries); ?></span>
                    <span class="hp-gmc-stat-label"><?php esc_html_e('Countries Covered', 'hp-gmc-manager'); ?></span>
                </div>
                <div class="hp-gmc-stat-box">
                    <span class="hp-gmc-stat-value"><?php echo count($warehouses); ?></span>
                    <span class="hp-gmc-stat-label"><?php esc_html_e('Warehouses', 'hp-gmc-manager'); ?></span>
                </div>
            </div>
            
            <!-- Services List -->
            <h3><?php esc_html_e('Shipping Services', 'hp-gmc-manager'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:25%"><?php esc_html_e('Service Name', 'hp-gmc-manager'); ?></th>
                        <th style="width:10%"><?php esc_html_e('Status', 'hp-gmc-manager'); ?></th>
                        <th style="width:40%"><?php esc_html_e('Countries', 'hp-gmc-manager'); ?></th>
                        <th style="width:25%"><?php esc_html_e('Delivery Time', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): 
                        $countries = $service['deliveryCountries'] ?? [];
                        $deliveryTime = $service['deliveryTime'] ?? [];
                        $isActive = $service['active'] ?? false;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($service['serviceName'] ?? 'Unknown'); ?></strong></td>
                        <td>
                            <span class="hp-gmc-status hp-gmc-status-<?php echo $isActive ? 'approved' : 'pending'; ?>">
                                <?php echo $isActive ? esc_html__('Active', 'hp-gmc-manager') : esc_html__('Inactive', 'hp-gmc-manager'); ?>
                            </span>
                        </td>
                        <td>
                            <div class="hp-gmc-country-tags">
                                <?php foreach ($countries as $country): ?>
                                    <span class="hp-gmc-country-tag"><?php echo esc_html($country); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <?php 
                            if (!empty($deliveryTime['minTransitDays']) || !empty($deliveryTime['maxTransitDays'])) {
                                printf(
                                    '%d-%d %s',
                                    $deliveryTime['minTransitDays'] ?? 0,
                                    $deliveryTime['maxTransitDays'] ?? 0,
                                    esc_html__('days', 'hp-gmc-manager')
                                );
                            } elseif (!empty($deliveryTime['warehouseBasedDeliveryTimes'])) {
                                echo esc_html__('Carrier-based', 'hp-gmc-manager');
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Country Coverage by Region -->
            <h3><?php esc_html_e('Country Coverage', 'hp-gmc-manager'); ?></h3>
            <p class="description"><?php esc_html_e('Countries with active shipping configuration are highlighted. Use MCP tools to add/remove countries.', 'hp-gmc-manager'); ?></p>
            
            <div class="hp-gmc-regions">
                <?php foreach ($regions as $regionName => $regionCountries): ?>
                <div class="hp-gmc-region-block">
                    <h4><?php echo esc_html($regionName); ?></h4>
                    <div class="hp-gmc-country-grid">
                        <?php foreach ($regionCountries as $code): 
                            $isEnabled = in_array($code, $allCountries);
                        ?>
                        <div class="hp-gmc-country-item <?php echo $isEnabled ? 'hp-gmc-country-enabled' : 'hp-gmc-country-disabled'; ?>"
                             data-country="<?php echo esc_attr($code); ?>">
                            <span class="hp-gmc-country-code"><?php echo esc_html($code); ?></span>
                            <span class="hp-gmc-country-status"><?php echo $isEnabled ? '✓' : '○'; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Warehouses -->
            <?php if (!empty($warehouses)): ?>
            <h3><?php esc_html_e('Warehouses', 'hp-gmc-manager'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Location', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Cutoff Time', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Handling Days', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($warehouses as $warehouse): 
                        $address = $warehouse['shippingAddress'] ?? [];
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($warehouse['name'] ?? 'Unknown'); ?></strong></td>
                        <td>
                            <?php 
                            echo esc_html(sprintf(
                                '%s, %s %s',
                                $address['city'] ?? '',
                                $address['administrativeArea'] ?? '',
                                $address['regionCode'] ?? ''
                            ));
                            ?>
                        </td>
                        <td>
                            <?php 
                            $cutoff = $warehouse['cutoffTime'] ?? [];
                            if (!empty($cutoff['hour'])) {
                                printf('%02d:%02d', $cutoff['hour'], $cutoff['minute'] ?? 0);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($warehouse['handlingDays'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <?php endif; ?>
            
            <!-- MCP Tools Reference -->
            <div class="hp-gmc-shipping-tools">
                <h3><?php esc_html_e('Quick Actions (via MCP/AI)', 'hp-gmc-manager'); ?></h3>
                <div class="hp-gmc-mcp-commands">
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-enable-country</code>
                        <span><?php esc_html_e('Add a country to shipping (e.g., country_code: "GB")', 'hp-gmc-manager'); ?></span>
                    </div>
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-disable-country</code>
                        <span><?php esc_html_e('Remove a country from all services', 'hp-gmc-manager'); ?></span>
                    </div>
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-get-shipping-settings</code>
                        <span><?php esc_html_e('View full shipping configuration as JSON', 'hp-gmc-manager'); ?></span>
                    </div>
                </div>
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
