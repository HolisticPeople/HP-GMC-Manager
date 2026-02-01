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
                <a href="#feeds" class="nav-tab" data-tab="feeds">
                    <?php esc_html_e('Feeds', 'hp-gmc-manager'); ?>
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
                <div id="tab-feeds" class="hp-gmc-tab-panel">
                    <?php self::render_feeds_tab(); ?>
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
     * Render the issues tab with 3-tier sub-tabs.
     */
    private static function render_issues_tab(): void
    {
        global $wpdb;
        
        // Fresh read of last sync time
        $last_sync = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'hp_gmc_last_sync'
        ));
        
        // Get products grouped by tier
        $productsByTier = \HP_GMC\Services\IssueClassifier::getProductsByTier();
        
        $tierCounts = [
            'fixable' => count($productsByTier[\HP_GMC\Services\IssueClassifier::TIER_FIXABLE]),
            'misclassified' => count($productsByTier[\HP_GMC\Services\IssueClassifier::TIER_MISCLASSIFIED]),
            'restriction' => count($productsByTier[\HP_GMC\Services\IssueClassifier::TIER_RESTRICTION]),
        ];
        
        $totalIssues = array_sum($tierCounts);
        
        // Determine active sub-tab
        $activeSubTab = isset($_GET['issues_tab']) ? sanitize_key($_GET['issues_tab']) : 'fixable';
        if (!in_array($activeSubTab, ['fixable', 'misclassified', 'restriction'])) {
            $activeSubTab = 'fixable';
        }
        
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
            
            <?php if ($totalIssues === 0): ?>
            <p><?php esc_html_e('No issues found. All products are approved!', 'hp-gmc-manager'); ?></p>
            <?php else: ?>
            
            <!-- Sub-tab Navigation -->
            <div class="hp-gmc-issues-subtabs">
                <a href="<?php echo esc_url(add_query_arg('issues_tab', 'fixable') . '#issues'); ?>" 
                   class="hp-gmc-subtab <?php echo $activeSubTab === 'fixable' ? 'active' : ''; ?>"
                   data-subtab="fixable">
                    <span class="hp-gmc-subtab-icon">🔧</span>
                    <span class="hp-gmc-subtab-label"><?php esc_html_e('Fixable Issues', 'hp-gmc-manager'); ?></span>
                    <span class="hp-gmc-subtab-count"><?php echo esc_html($tierCounts['fixable']); ?></span>
                </a>
                <a href="<?php echo esc_url(add_query_arg('issues_tab', 'misclassified') . '#issues'); ?>" 
                   class="hp-gmc-subtab hp-gmc-subtab-warning <?php echo $activeSubTab === 'misclassified' ? 'active' : ''; ?>"
                   data-subtab="misclassified">
                    <span class="hp-gmc-subtab-icon">⚠️</span>
                    <span class="hp-gmc-subtab-label"><?php esc_html_e('Review Needed', 'hp-gmc-manager'); ?></span>
                    <span class="hp-gmc-subtab-count"><?php echo esc_html($tierCounts['misclassified']); ?></span>
                </a>
                <a href="<?php echo esc_url(add_query_arg('issues_tab', 'restriction') . '#issues'); ?>" 
                   class="hp-gmc-subtab <?php echo $activeSubTab === 'restriction' ? 'active' : ''; ?>"
                   data-subtab="restriction">
                    <span class="hp-gmc-subtab-icon">🚫</span>
                    <span class="hp-gmc-subtab-label"><?php esc_html_e('True Restrictions', 'hp-gmc-manager'); ?></span>
                    <span class="hp-gmc-subtab-count"><?php echo esc_html($tierCounts['restriction']); ?></span>
                </a>
            </div>
            
            <!-- Sub-tab Content -->
            <div class="hp-gmc-issues-content">
                <?php 
                switch ($activeSubTab) {
                    case 'fixable':
                        self::render_fixable_issues_subtab($productsByTier[\HP_GMC\Services\IssueClassifier::TIER_FIXABLE]);
                        break;
                    case 'misclassified':
                        self::render_misclassified_issues_subtab($productsByTier[\HP_GMC\Services\IssueClassifier::TIER_MISCLASSIFIED]);
                        break;
                    case 'restriction':
                        self::render_restriction_issues_subtab($productsByTier[\HP_GMC\Services\IssueClassifier::TIER_RESTRICTION]);
                        break;
                }
                ?>
            </div>
            
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Public wrapper for AJAX access to render_fixable_issues_subtab.
     */
    public static function render_fixable_issues_subtab_public(array $products): void
    {
        self::render_fixable_issues_subtab($products);
    }

    /**
     * Public wrapper for AJAX access to render_misclassified_issues_subtab.
     */
    public static function render_misclassified_issues_subtab_public(array $products): void
    {
        self::render_misclassified_issues_subtab($products);
    }

    /**
     * Public wrapper for AJAX access to render_restriction_issues_subtab.
     */
    public static function render_restriction_issues_subtab_public(array $products): void
    {
        self::render_restriction_issues_subtab($products);
    }

    /**
     * Render the Fixable Issues sub-tab (Tier 1).
     */
    private static function render_fixable_issues_subtab(array $products): void
    {
        ?>
        <div class="hp-gmc-subtab-header">
            <p><?php esc_html_e('These issues can be fixed by adding missing attributes or editing product content.', 'hp-gmc-manager'); ?></p>
            <div class="hp-gmc-subtab-actions">
                <button type="button" class="button" id="hp-gmc-export-fixable">
                    <?php esc_html_e('Export CSV', 'hp-gmc-manager'); ?>
                </button>
            </div>
        </div>
        
        <?php if (empty($products)): ?>
        <p><?php esc_html_e('No fixable issues found.', 'hp-gmc-manager'); ?></p>
        <?php else: ?>
        
        <table class="wp-list-table widefat fixed striped hp-gmc-fixable-table">
            <thead>
                <tr>
                    <th style="width:5%"><input type="checkbox" id="hp-gmc-select-all-fixable"></th>
                    <th style="width:25%"><?php esc_html_e('Product', 'hp-gmc-manager'); ?></th>
                    <th style="width:10%"><?php esc_html_e('SKU', 'hp-gmc-manager'); ?></th>
                    <th style="width:20%"><?php esc_html_e('Issue', 'hp-gmc-manager'); ?></th>
                    <th style="width:10%"><?php esc_html_e('Fix Type', 'hp-gmc-manager'); ?></th>
                    <th style="width:22%"><?php esc_html_e('Suggested Fix', 'hp-gmc-manager'); ?></th>
                    <th style="width:8%"><?php esc_html_e('Action', 'hp-gmc-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): 
                    $classification = $product['classification']['classifications'][0] ?? [];
                ?>
                <tr data-product-id="<?php echo esc_attr($product['product_id']); ?>">
                    <td><input type="checkbox" class="hp-gmc-fixable-checkbox" value="<?php echo esc_attr($product['product_id']); ?>"></td>
                    <td>
                        <?php if ($product['edit_url']): ?>
                        <a href="<?php echo esc_url($product['edit_url']); ?>"><?php echo esc_html($product['product_name']); ?></a>
                        <?php else: ?>
                        <?php echo esc_html($product['product_name']); ?>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($product['sku'] ?: '-'); ?></code></td>
                    <td>
                        <?php 
                        $issues = array_column($product['classification']['classifications'], 'issue');
                        echo esc_html(implode(', ', array_slice($issues, 0, 2)));
                        if (count($issues) > 2) {
                            echo ' <small>(+' . (count($issues) - 2) . ')</small>';
                        }
                        ?>
                    </td>
                    <td>
                        <span class="hp-gmc-fix-type hp-gmc-fix-type-<?php echo esc_attr($classification['fix_type'] ?? 'manual'); ?>">
                            <?php echo esc_html(ucfirst($classification['fix_type'] ?? 'Manual')); ?>
                        </span>
                    </td>
                    <td>
                        <span class="hp-gmc-suggested-fix"><?php echo esc_html($classification['suggested_fix'] ?? '—'); ?></span>
                    </td>
                    <td>
                        <a href="<?php echo esc_url($product['edit_url']); ?>" class="button button-small">
                            <?php esc_html_e('Edit', 'hp-gmc-manager'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Render the Review Needed sub-tab (Tier 2 - Misclassified).
     */
    private static function render_misclassified_issues_subtab(array $products): void
    {
        ?>
        <div class="hp-gmc-subtab-header hp-gmc-subtab-header-warning">
            <div class="hp-gmc-warning-banner">
                <strong>⚠️ <?php esc_html_e('DO NOT EXCLUDE these products!', 'hp-gmc-manager'); ?></strong>
                <p><?php esc_html_e('These products are likely misclassified by Google. Your store does not sell prescription drugs or tobacco. Review the triggering text and apply fixes to avoid the policy flags.', 'hp-gmc-manager'); ?></p>
            </div>
        </div>
        
        <?php if (empty($products)): ?>
        <p><?php esc_html_e('No misclassified products found.', 'hp-gmc-manager'); ?></p>
        <?php else: ?>
        
        <table class="wp-list-table widefat fixed striped hp-gmc-misclassified-table">
            <thead>
                <tr>
                    <th style="width:20%"><?php esc_html_e('Product', 'hp-gmc-manager'); ?></th>
                    <th style="width:8%"><?php esc_html_e('SKU', 'hp-gmc-manager'); ?></th>
                    <th style="width:15%"><?php esc_html_e('Policy Flag', 'hp-gmc-manager'); ?></th>
                    <th style="width:20%"><?php esc_html_e('Likely Cause', 'hp-gmc-manager'); ?></th>
                    <th style="width:12%"><?php esc_html_e('Status', 'hp-gmc-manager'); ?></th>
                    <th style="width:25%"><?php esc_html_e('Actions', 'hp-gmc-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): 
                    $classification = $product['classification']['classifications'][0] ?? [];
                    $reviewStatus = \HP_GMC\Services\IssueClassifier::getReviewStatus($product['product_id']);
                ?>
                <tr data-product-id="<?php echo esc_attr($product['product_id']); ?>">
                    <td>
                        <?php if ($product['edit_url']): ?>
                        <a href="<?php echo esc_url($product['edit_url']); ?>"><?php echo esc_html($product['product_name']); ?></a>
                        <?php else: ?>
                        <?php echo esc_html($product['product_name']); ?>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($product['sku'] ?: '-'); ?></code></td>
                    <td>
                        <span class="hp-gmc-policy-flag"><?php echo esc_html($classification['issue'] ?? '—'); ?></span>
                    </td>
                    <td>
                        <span class="hp-gmc-likely-cause"><?php echo esc_html($classification['likely_cause'] ?? '—'); ?></span>
                    </td>
                    <td>
                        <?php 
                        $statusLabels = [
                            '' => ['label' => 'Pending Review', 'class' => 'pending'],
                            'pending_review' => ['label' => 'Pending Review', 'class' => 'pending'],
                            'fix_applied' => ['label' => 'Fix Applied', 'class' => 'success'],
                            'awaiting_recrawl' => ['label' => 'Awaiting Re-crawl', 'class' => 'info'],
                            'marked_restriction' => ['label' => 'Marked Restriction', 'class' => 'warning'],
                        ];
                        $statusInfo = $statusLabels[$reviewStatus ?? ''] ?? $statusLabels[''];
                        ?>
                        <span class="hp-gmc-review-status hp-gmc-review-status-<?php echo esc_attr($statusInfo['class']); ?>">
                            <?php echo esc_html($statusInfo['label']); ?>
                        </span>
                    </td>
                    <td class="hp-gmc-review-actions">
                        <button type="button" class="button button-small hp-gmc-analyze-triggers" 
                                data-product-id="<?php echo esc_attr($product['product_id']); ?>"
                                data-issue="<?php echo esc_attr($classification['issue'] ?? ''); ?>">
                            <?php esc_html_e('Analyze', 'hp-gmc-manager'); ?>
                        </button>
                        <a href="<?php echo esc_url($product['edit_url']); ?>" class="button button-small">
                            <?php esc_html_e('Edit', 'hp-gmc-manager'); ?>
                        </a>
                        <button type="button" class="button button-small hp-gmc-mark-restriction" 
                                data-product-id="<?php echo esc_attr($product['product_id']); ?>"
                                title="<?php esc_attr_e('Mark as true restriction (moves to Tier 3)', 'hp-gmc-manager'); ?>">
                            <?php esc_html_e('→ Tier 3', 'hp-gmc-manager'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- Trigger Analysis Modal -->
        <div id="hp-gmc-trigger-modal" class="hp-gmc-modal" style="display:none;">
            <div class="hp-gmc-modal-content hp-gmc-modal-lg">
                <div class="hp-gmc-modal-header">
                    <h3><?php esc_html_e('Trigger Analysis', 'hp-gmc-manager'); ?></h3>
                    <button type="button" class="hp-gmc-modal-close">&times;</button>
                </div>
                <div class="hp-gmc-modal-body" id="hp-gmc-trigger-modal-body">
                    <p><?php esc_html_e('Loading...', 'hp-gmc-manager'); ?></p>
                </div>
                <div class="hp-gmc-modal-footer">
                    <button type="button" class="button hp-gmc-modal-close"><?php esc_html_e('Close', 'hp-gmc-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the True Restrictions sub-tab (Tier 3).
     */
    private static function render_restriction_issues_subtab(array $products): void
    {
        // Get available feeds for assignment
        $feeds = \HP_GMC\Services\FeedManager::getAll(\HP_GMC\Services\FeedManager::TYPE_EXCLUSION);
        ?>
        <div class="hp-gmc-subtab-header">
            <p><?php esc_html_e('These products genuinely fall under restricted categories (supplements, health products) and require exclusion feeds.', 'hp-gmc-manager'); ?></p>
            <div class="hp-gmc-subtab-actions">
                <button type="button" class="button button-primary" id="hp-gmc-bulk-add-to-feed">
                    <?php esc_html_e('Add Selected to Feed', 'hp-gmc-manager'); ?>
                </button>
            </div>
        </div>
        
        <?php if (empty($products)): ?>
        <p><?php esc_html_e('No products with true policy restrictions found.', 'hp-gmc-manager'); ?></p>
        <?php else: ?>
        
        <table class="wp-list-table widefat fixed striped hp-gmc-restriction-table">
            <thead>
                <tr>
                    <th style="width:5%"><input type="checkbox" id="hp-gmc-select-all-restriction"></th>
                    <th style="width:22%"><?php esc_html_e('Product', 'hp-gmc-manager'); ?></th>
                    <th style="width:8%"><?php esc_html_e('SKU', 'hp-gmc-manager'); ?></th>
                    <th style="width:18%"><?php esc_html_e('Policy Issue', 'hp-gmc-manager'); ?></th>
                    <th style="width:15%"><?php esc_html_e('Recommended Exclusions', 'hp-gmc-manager'); ?></th>
                    <th style="width:15%"><?php esc_html_e('Feed Status', 'hp-gmc-manager'); ?></th>
                    <th style="width:17%"><?php esc_html_e('Actions', 'hp-gmc-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): 
                    $classification = $product['classification']['classifications'][0] ?? [];
                    $productFeeds = \HP_GMC\Services\FeedManager::getFeedsForProduct($product['product_id']);
                ?>
                <tr data-product-id="<?php echo esc_attr($product['product_id']); ?>">
                    <td>
                        <?php if (empty($productFeeds)): ?>
                        <input type="checkbox" class="hp-gmc-restriction-checkbox" value="<?php echo esc_attr($product['product_id']); ?>">
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($product['edit_url']): ?>
                        <a href="<?php echo esc_url($product['edit_url']); ?>"><?php echo esc_html($product['product_name']); ?></a>
                        <?php else: ?>
                        <?php echo esc_html($product['product_name']); ?>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($product['sku'] ?: '-'); ?></code></td>
                    <td>
                        <span class="hp-gmc-policy-issue"><?php echo esc_html($classification['issue'] ?? '—'); ?></span>
                    </td>
                    <td>
                        <?php 
                        $exclusions = $classification['exclusions'] ?? [];
                        foreach ($exclusions as $exclusion): 
                        ?>
                        <span class="hp-gmc-exclusion-badge"><?php echo esc_html($exclusion); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if (!empty($productFeeds)): ?>
                            <?php foreach ($productFeeds as $feed): ?>
                            <span class="hp-gmc-feed-badge hp-gmc-feed-badge-<?php echo esc_attr($feed['status']); ?>">
                                <?php echo esc_html($feed['name']); ?>
                            </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="hp-gmc-not-in-feed"><?php esc_html_e('Not in any feed', 'hp-gmc-manager'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($productFeeds)): ?>
                        <select class="hp-gmc-feed-select" data-product-id="<?php echo esc_attr($product['product_id']); ?>">
                            <option value=""><?php esc_html_e('Select feed...', 'hp-gmc-manager'); ?></option>
                            <?php foreach ($feeds as $feed): ?>
                            <option value="<?php echo esc_attr($feed['id']); ?>"><?php echo esc_html($feed['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-small hp-gmc-add-to-feed" 
                                data-product-id="<?php echo esc_attr($product['product_id']); ?>"
                                data-exclusions="<?php echo esc_attr(implode(',', $exclusions)); ?>">
                            <?php esc_html_e('Add', 'hp-gmc-manager'); ?>
                        </button>
                        <?php else: ?>
                        <span class="hp-gmc-in-feed-check">✓</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- Bulk Add to Feed Modal -->
        <div id="hp-gmc-bulk-feed-modal" class="hp-gmc-modal" style="display:none;">
            <div class="hp-gmc-modal-content">
                <div class="hp-gmc-modal-header">
                    <h3><?php esc_html_e('Add Products to Feed', 'hp-gmc-manager'); ?></h3>
                    <button type="button" class="hp-gmc-modal-close">&times;</button>
                </div>
                <div class="hp-gmc-modal-body">
                    <p>
                        <label for="hp-gmc-bulk-feed-select"><?php esc_html_e('Select Feed:', 'hp-gmc-manager'); ?></label>
                        <select id="hp-gmc-bulk-feed-select">
                            <option value=""><?php esc_html_e('Select a feed...', 'hp-gmc-manager'); ?></option>
                            <?php foreach ($feeds as $feed): ?>
                            <option value="<?php echo esc_attr($feed['id']); ?>"><?php echo esc_html($feed['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label for="hp-gmc-bulk-exclusions"><?php esc_html_e('Exclusion Destinations:', 'hp-gmc-manager'); ?></label>
                        <input type="text" id="hp-gmc-bulk-exclusions" class="regular-text" value="Shopping_ads,Display_ads">
                    </p>
                    <p id="hp-gmc-bulk-selected-count"></p>
                </div>
                <div class="hp-gmc-modal-footer">
                    <button type="button" class="button hp-gmc-modal-close"><?php esc_html_e('Cancel', 'hp-gmc-manager'); ?></button>
                    <button type="button" class="button button-primary" id="hp-gmc-bulk-add-confirm"><?php esc_html_e('Add to Feed', 'hp-gmc-manager'); ?></button>
                </div>
            </div>
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
     * Render the feeds tab.
     */
    private static function render_feeds_tab(): void
    {
        $feeds = \HP_GMC\Services\FeedManager::getAll();
        $summary = \HP_GMC\Services\FeedManager::getSummary();
        
        // Get selected feed for detail view
        $selectedFeedId = isset($_GET['feed_id']) ? (int) $_GET['feed_id'] : 0;
        $selectedFeed = $selectedFeedId ? \HP_GMC\Services\FeedManager::get($selectedFeedId) : null;
        $feedProducts = $selectedFeed ? \HP_GMC\Services\FeedManager::getProducts($selectedFeedId) : [];
        
        // Get primary feed status
        $primaryFeedStatus = \HP_GMC\Services\ProductDataFeed::getStatus();
        ?>
        <div class="hp-gmc-feeds">
            
            <!-- Primary Data Source Section -->
            <div class="hp-gmc-primary-feed-section">
                <div class="hp-gmc-section-header">
                    <h2>
                        <span class="dashicons dashicons-database"></span>
                        <?php esc_html_e('Primary Data Source', 'hp-gmc-manager'); ?>
                    </h2>
                </div>
                
                <div class="hp-gmc-primary-feed-info-box">
                    <div class="hp-gmc-primary-feed-icon">
                        <span class="dashicons dashicons-database" style="font-size: 48px; width: 48px; height: 48px; color: #2271b1;"></span>
                    </div>
                    <div class="hp-gmc-primary-feed-details">
                        <p class="hp-gmc-primary-feed-intro">
                            <strong><?php esc_html_e('This data source provides complete product data to Google Merchant Center.', 'hp-gmc-manager'); ?></strong><br>
                            <?php esc_html_e('Unlike supplemental sources below, this is your main product catalog that replaces GLA sync.', 'hp-gmc-manager'); ?>
                        </p>
                        
                        <table class="hp-gmc-primary-feed-meta">
                            <tr>
                                <th><?php esc_html_e('Feed URL (TSV):', 'hp-gmc-manager'); ?></th>
                                <td>
                                    <code id="hp-gmc-primary-feed-url-tsv"><?php echo esc_url($primaryFeedStatus['feed_url']); ?></code>
                                    <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('hp-gmc-primary-feed-url-tsv').textContent); this.textContent='✓'; setTimeout(() => this.textContent='Copy', 1500);">
                                        <?php esc_html_e('Copy', 'hp-gmc-manager'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Feed URL (CSV):', 'hp-gmc-manager'); ?></th>
                                <td>
                                    <code id="hp-gmc-primary-feed-url-csv"><?php echo esc_url($primaryFeedStatus['feed_url_csv']); ?></code>
                                    <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('hp-gmc-primary-feed-url-csv').textContent); this.textContent='✓'; setTimeout(() => this.textContent='Copy', 1500);">
                                        <?php esc_html_e('Copy', 'hp-gmc-manager'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Products:', 'hp-gmc-manager'); ?></th>
                                <td><strong><?php echo esc_html(number_format($primaryFeedStatus['product_count'])); ?></strong></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Last Generated:', 'hp-gmc-manager'); ?></th>
                                <td><?php echo esc_html($primaryFeedStatus['last_generated_ago'] ?: __('Never', 'hp-gmc-manager')); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Cache Duration:', 'hp-gmc-manager'); ?></th>
                                <td><?php printf(esc_html__('%d minutes', 'hp-gmc-manager'), $primaryFeedStatus['cache_duration_minutes']); ?></td>
                            </tr>
                        </table>
                        
                        <div class="hp-gmc-primary-feed-actions" style="margin-top: 15px;">
                            <a href="<?php echo esc_url($primaryFeedStatus['feed_url']); ?>" class="button" target="_blank">
                                <span class="dashicons dashicons-external" style="vertical-align: text-top;"></span>
                                <?php esc_html_e('Preview Feed', 'hp-gmc-manager'); ?>
                            </a>
                            <button type="button" class="button" id="hp-gmc-regenerate-primary-feed-feeds-tab">
                                <span class="dashicons dashicons-update" style="vertical-align: text-top;"></span>
                                <?php esc_html_e('Regenerate Now', 'hp-gmc-manager'); ?>
                            </button>
                        </div>
                        
                        <div class="hp-gmc-primary-feed-help" style="margin-top: 15px; padding: 10px; background: #f0f6fc; border-left: 4px solid #2271b1;">
                            <strong><?php esc_html_e('To register in GMC:', 'hp-gmc-manager'); ?></strong>
                            <ol style="margin: 5px 0 0 20px;">
                                <li><?php esc_html_e('Go to Google Merchant Center → Settings → Data sources', 'hp-gmc-manager'); ?></li>
                                <li><?php esc_html_e('Click "Add product source" under Primary sources', 'hp-gmc-manager'); ?></li>
                                <li><?php esc_html_e('Select "Add products from a file" → "Enter a link to your file"', 'hp-gmc-manager'); ?></li>
                                <li><?php esc_html_e('Paste the TSV URL above and continue', 'hp-gmc-manager'); ?></li>
                                <li><?php esc_html_e('Set country/language and fetch schedule (Daily recommended)', 'hp-gmc-manager'); ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <hr style="margin: 30px 0; border-top: 2px solid #ddd;">
            
            <!-- Supplemental Data Sources Section -->
            <div class="hp-gmc-section-header">
                <h2><?php esc_html_e('Supplemental Data Sources', 'hp-gmc-manager'); ?></h2>
                <button type="button" class="button button-primary" id="hp-gmc-create-feed">
                    <?php esc_html_e('+ New Source', 'hp-gmc-manager'); ?>
                </button>
            </div>
            
            <p><?php esc_html_e('Manage supplemental data sources for GMC. Use exclusion sources to fix policy violations, redirect sources to send ad traffic to funnels.', 'hp-gmc-manager'); ?></p>
            
            <!-- Feed Summary -->
            <div class="hp-gmc-feeds-summary">
                <div class="hp-gmc-feed-type-card hp-gmc-feed-type-exclusion">
                    <div class="hp-gmc-feed-type-header">
                        <span class="hp-gmc-feed-type-icon">🚫</span>
                        <strong><?php esc_html_e('Exclusion Feeds', 'hp-gmc-manager'); ?></strong>
                    </div>
                    <div class="hp-gmc-feed-type-stats">
                        <span class="hp-gmc-feed-count"><?php echo esc_html($summary['exclusion']['total']); ?> <?php esc_html_e('feeds', 'hp-gmc-manager'); ?></span>
                        <span class="hp-gmc-product-count"><?php echo esc_html($summary['exclusion']['products']); ?> <?php esc_html_e('products', 'hp-gmc-manager'); ?></span>
                    </div>
                    <p class="hp-gmc-feed-type-desc"><?php esc_html_e('Exclude products from specific ad destinations', 'hp-gmc-manager'); ?></p>
                </div>
                
                <div class="hp-gmc-feed-type-card hp-gmc-feed-type-redirect">
                    <div class="hp-gmc-feed-type-header">
                        <span class="hp-gmc-feed-type-icon">🔗</span>
                        <strong><?php esc_html_e('Redirect Feeds', 'hp-gmc-manager'); ?></strong>
                    </div>
                    <div class="hp-gmc-feed-type-stats">
                        <span class="hp-gmc-feed-count"><?php echo esc_html($summary['redirect']['total']); ?> <?php esc_html_e('feeds', 'hp-gmc-manager'); ?></span>
                        <span class="hp-gmc-product-count"><?php echo esc_html($summary['redirect']['products']); ?> <?php esc_html_e('products', 'hp-gmc-manager'); ?></span>
                    </div>
                    <p class="hp-gmc-feed-type-desc"><?php esc_html_e('Redirect ad clicks to funnel pages', 'hp-gmc-manager'); ?></p>
                </div>
            </div>
            
            <?php if ($selectedFeed): ?>
            <!-- Feed Detail View -->
            <div class="hp-gmc-feed-detail">
                <div class="hp-gmc-feed-detail-header">
                    <a href="<?php echo esc_url(remove_query_arg('feed_id') . '#feeds'); ?>" class="button">&larr; <?php esc_html_e('Back to List', 'hp-gmc-manager'); ?></a>
                    <h3><?php echo esc_html($selectedFeed['name']); ?></h3>
                    <span class="hp-gmc-feed-type-badge hp-gmc-feed-type-<?php echo esc_attr($selectedFeed['feed_type']); ?>">
                        <?php echo esc_html(ucfirst($selectedFeed['feed_type'])); ?>
                    </span>
                    <span class="hp-gmc-feed-status-badge hp-gmc-feed-status-<?php echo esc_attr($selectedFeed['status']); ?>">
                        <?php echo esc_html(ucfirst($selectedFeed['status'])); ?>
                    </span>
                </div>
                
                <div class="hp-gmc-feed-actions">
                    <button type="button" class="button hp-gmc-feed-generate" data-feed-id="<?php echo esc_attr($selectedFeedId); ?>">
                        <?php esc_html_e('Generate File', 'hp-gmc-manager'); ?>
                    </button>
                    <?php if ($selectedFeed['file_url']): ?>
                    <a href="<?php echo esc_url($selectedFeed['file_url']); ?>" class="button" download>
                        <?php esc_html_e('Download', 'hp-gmc-manager'); ?>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="button button-primary hp-gmc-feed-upload" data-feed-id="<?php echo esc_attr($selectedFeedId); ?>">
                        <?php esc_html_e('Upload to GMC', 'hp-gmc-manager'); ?>
                    </button>
                    <?php if ($selectedFeed['gmc_feed_id']): ?>
                    <button type="button" class="button hp-gmc-feed-check-status" data-feed-id="<?php echo esc_attr($selectedFeedId); ?>">
                        <?php esc_html_e('Check Status', 'hp-gmc-manager'); ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="button hp-gmc-feed-delete" data-feed-id="<?php echo esc_attr($selectedFeedId); ?>" style="color:#dc3232;">
                        <?php esc_html_e('Delete', 'hp-gmc-manager'); ?>
                    </button>
                </div>
                
                <div class="hp-gmc-feed-info">
                    <p>
                        <strong><?php esc_html_e('Products:', 'hp-gmc-manager'); ?></strong> <?php echo esc_html($selectedFeed['product_count']); ?>
                        <?php if ($selectedFeed['last_uploaded']): ?>
                        &nbsp;|&nbsp;
                        <strong><?php esc_html_e('Last Upload:', 'hp-gmc-manager'); ?></strong> <?php echo esc_html($selectedFeed['last_uploaded']); ?>
                        <?php endif; ?>
                        <?php if ($selectedFeed['gmc_feed_id']): ?>
                        &nbsp;|&nbsp;
                        <strong><?php esc_html_e('GMC ID:', 'hp-gmc-manager'); ?></strong> <code><?php echo esc_html($selectedFeed['gmc_feed_id']); ?></code>
                        <?php endif; ?>
                        <?php if ($selectedFeed['gmc_status']): ?>
                        &nbsp;|&nbsp;
                        <strong><?php esc_html_e('GMC Status:', 'hp-gmc-manager'); ?></strong> <?php echo esc_html($selectedFeed['gmc_status']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <!-- Products in Feed -->
                <h4><?php esc_html_e('Products in Feed', 'hp-gmc-manager'); ?></h4>
                
                <?php if (empty($feedProducts)): ?>
                <p><?php esc_html_e('No products in this feed yet. Add products using the MCP tools or the form below.', 'hp-gmc-manager'); ?></p>
                <?php else: ?>
                <table class="wp-list-table widefat fixed striped hp-gmc-feed-products-table">
                    <thead>
                        <tr>
                            <th style="width:5%"><input type="checkbox" id="hp-gmc-select-all-products"></th>
                            <th style="width:12%"><?php esc_html_e('SKU', 'hp-gmc-manager'); ?></th>
                            <th style="width:25%"><?php esc_html_e('Product', 'hp-gmc-manager'); ?></th>
                            <th style="width:30%"><?php esc_html_e('Value', 'hp-gmc-manager'); ?></th>
                            <th style="width:18%"><?php esc_html_e('Reason', 'hp-gmc-manager'); ?></th>
                            <th style="width:10%"><?php esc_html_e('Actions', 'hp-gmc-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feedProducts as $fp): ?>
                        <tr data-product-id="<?php echo esc_attr($fp['product_id']); ?>">
                            <td><input type="checkbox" class="hp-gmc-product-checkbox" value="<?php echo esc_attr($fp['product_id']); ?>"></td>
                            <td><code><?php echo esc_html($fp['sku']); ?></code></td>
                            <td>
                                <?php if ($fp['product_url']): ?>
                                <a href="<?php echo esc_url($fp['product_url']); ?>"><?php echo esc_html($fp['product_name']); ?></a>
                                <?php else: ?>
                                <?php echo esc_html($fp['product_name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($fp['attribute_value']); ?></code></td>
                            <td><?php echo esc_html($fp['reason'] ?: '-'); ?></td>
                            <td>
                                <button type="button" class="button button-small hp-gmc-remove-product" 
                                        data-feed-id="<?php echo esc_attr($selectedFeedId); ?>"
                                        data-product-id="<?php echo esc_attr($fp['product_id']); ?>">
                                    <?php esc_html_e('Remove', 'hp-gmc-manager'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <!-- Add Product Form -->
                <div class="hp-gmc-add-product-form">
                    <h4><?php esc_html_e('Add Product', 'hp-gmc-manager'); ?></h4>
                    <div class="hp-gmc-add-product-fields">
                        <input type="text" id="hp-gmc-product-search" placeholder="<?php esc_attr_e('Search by SKU or name...', 'hp-gmc-manager'); ?>">
                        <input type="text" id="hp-gmc-product-value" 
                               placeholder="<?php echo esc_attr($selectedFeed['feed_type'] === 'redirect' ? __('Redirect URL', 'hp-gmc-manager') : __('Destinations (comma-separated)', 'hp-gmc-manager')); ?>">
                        <input type="text" id="hp-gmc-product-reason" placeholder="<?php esc_attr_e('Reason (optional)', 'hp-gmc-manager'); ?>">
                        <button type="button" class="button button-primary" id="hp-gmc-add-product-btn" data-feed-id="<?php echo esc_attr($selectedFeedId); ?>">
                            <?php esc_html_e('Add', 'hp-gmc-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Feed List View -->
            <?php if (empty($feeds)): ?>
            <div class="hp-gmc-empty-state">
                <p><?php esc_html_e('No feeds created yet. Click "New Feed" to create your first supplemental feed.', 'hp-gmc-manager'); ?></p>
            </div>
            <?php else: ?>
            
            <!-- Bulk Actions Bar -->
            <div class="hp-gmc-feeds-toolbar">
                <div class="hp-gmc-bulk-actions">
                    <input type="checkbox" id="hp-gmc-select-all-feeds" title="<?php esc_attr_e('Select All', 'hp-gmc-manager'); ?>">
                    <select id="hp-gmc-bulk-action">
                        <option value=""><?php esc_html_e('Bulk Actions', 'hp-gmc-manager'); ?></option>
                        <option value="refresh"><?php esc_html_e('Refresh Status', 'hp-gmc-manager'); ?></option>
                        <option value="upload"><?php esc_html_e('Upload to GMC', 'hp-gmc-manager'); ?></option>
                        <option value="delete"><?php esc_html_e('Delete', 'hp-gmc-manager'); ?></option>
                    </select>
                    <button type="button" class="button" id="hp-gmc-apply-bulk-action"><?php esc_html_e('Apply', 'hp-gmc-manager'); ?></button>
                </div>
                <div class="hp-gmc-feeds-toolbar-right">
                    <button type="button" class="button" id="hp-gmc-refresh-all-feeds" title="<?php esc_attr_e('Refresh GMC status for all uploaded feeds', 'hp-gmc-manager'); ?>">
                        <span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh All', 'hp-gmc-manager'); ?>
                    </button>
                </div>
            </div>
            
            <h3><?php esc_html_e('All Feeds', 'hp-gmc-manager'); ?></h3>
            <table class="wp-list-table widefat fixed striped hp-gmc-feeds-table">
                <thead>
                    <tr>
                        <th style="width:3%" class="check-column"><span class="screen-reader-text"><?php esc_html_e('Select', 'hp-gmc-manager'); ?></span></th>
                        <th style="width:4%" title="<?php esc_attr_e('Feed health: green = active, yellow = pending products, red = errors', 'hp-gmc-manager'); ?>"></th>
                        <th style="width:18%"><?php esc_html_e('Feed Name', 'hp-gmc-manager'); ?></th>
                        <th style="width:7%"><?php esc_html_e('Type', 'hp-gmc-manager'); ?></th>
                        <th style="width:8%" title="<?php esc_attr_e('Local feed state (Draft/Ready/Uploaded/Live)', 'hp-gmc-manager'); ?>"><?php esc_html_e('Local', 'hp-gmc-manager'); ?></th>
                        <th style="width:9%" title="<?php esc_attr_e('Google Merchant Center processing state', 'hp-gmc-manager'); ?>"><?php esc_html_e('GMC', 'hp-gmc-manager'); ?></th>
                        <th style="width:13%"><?php esc_html_e('Products', 'hp-gmc-manager'); ?></th>
                        <th style="width:10%"><?php esc_html_e('Last Upload', 'hp-gmc-manager'); ?></th>
                        <th style="width:10%"><?php esc_html_e('Last Crawl', 'hp-gmc-manager'); ?></th>
                        <th style="width:28%"><?php esc_html_e('Actions', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feeds as $feed): 
                        // Get statistics for this feed
                        $stats = \HP_GMC\Services\FeedManager::getStatistics((int)$feed['id']);
                        $pendingCount = $stats['pending_products'] ?? 0;
                        
                        // Map LOCAL status to display labels
                        // Local status = state of our local feed (not GMC state)
                        $statusLabels = [
                            'draft' => ['label' => 'Draft', 'class' => 'draft'],           // No file generated
                            'generated' => ['label' => 'Ready', 'class' => 'generated'],   // File generated, not uploaded
                            'uploaded' => ['label' => 'Uploaded', 'class' => 'uploaded'],  // Sent to GMC, awaiting processing
                            'processing' => ['label' => 'Uploaded', 'class' => 'uploaded'],// Same as uploaded (GMC is processing)
                            'active' => ['label' => 'Live', 'class' => 'active'],          // GMC confirmed active
                            'error' => ['label' => 'Error', 'class' => 'error'],           // Upload/processing failed
                        ];
                        $statusInfo = $statusLabels[$feed['status']] ?? $statusLabels['draft'];
                        
                        // Determine health indicator
                        $healthClass = 'neutral';
                        $healthTitle = __('Not uploaded', 'hp-gmc-manager');
                        if ($feed['status'] === 'error' || $feed['gmc_status'] === 'failed' || $feed['gmc_status'] === 'error') {
                            $healthClass = 'error';
                            $healthTitle = __('Feed has errors', 'hp-gmc-manager');
                        } elseif ($feed['status'] === 'active' && in_array($feed['gmc_status'], ['success', 'active', 'completed'])) {
                            if ($pendingCount > 0) {
                                $healthClass = 'warning';
                                $healthTitle = sprintf(__('Live but %d pending products', 'hp-gmc-manager'), $pendingCount);
                            } else {
                                $healthClass = 'success';
                                $healthTitle = __('Feed active, all products covered', 'hp-gmc-manager');
                            }
                        } elseif ($feed['status'] === 'processing' || $feed['gmc_status'] === 'processing') {
                            $healthClass = 'processing';
                            $healthTitle = __('Processing in GMC', 'hp-gmc-manager');
                        } elseif ($feed['file_url'] && !$feed['gmc_feed_id']) {
                            $healthClass = 'ready';
                            $healthTitle = __('Ready to upload', 'hp-gmc-manager');
                        }
                    ?>
                    <tr data-feed-id="<?php echo esc_attr($feed['id']); ?>">
                        <td class="check-column">
                            <input type="checkbox" class="hp-gmc-feed-checkbox" value="<?php echo esc_attr($feed['id']); ?>">
                        </td>
                        <td class="hp-gmc-health-column">
                            <span class="hp-gmc-health-indicator hp-gmc-health-<?php echo esc_attr($healthClass); ?>" title="<?php echo esc_attr($healthTitle); ?>"></span>
                        </td>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url(add_query_arg('feed_id', $feed['id']) . '#feeds'); ?>" class="hp-gmc-feed-link">
                                    <?php echo esc_html($feed['name']); ?>
                                </a>
                            </strong>
                            <?php if ($feed['category']): ?>
                            <br><small class="hp-gmc-feed-category"><?php echo esc_html(ucfirst($feed['category'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="hp-gmc-feed-type-badge hp-gmc-feed-type-<?php echo esc_attr($feed['feed_type']); ?>">
                                <?php echo esc_html(ucfirst($feed['feed_type'])); ?>
                            </span>
                        </td>
                        <td>
                            <span class="hp-gmc-feed-status-badge hp-gmc-feed-status-<?php echo esc_attr($statusInfo['class']); ?>">
                                <?php echo esc_html($statusInfo['label']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($feed['gmc_status']): ?>
                            <span class="hp-gmc-gmc-status hp-gmc-gmc-status-<?php echo esc_attr($feed['gmc_status']); ?>">
                                <?php echo esc_html(ucfirst($feed['gmc_status'])); ?>
                            </span>
                            <?php else: ?>
                            <span class="hp-gmc-gmc-status hp-gmc-gmc-status-none"><?php esc_html_e('Not uploaded', 'hp-gmc-manager'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="hp-gmc-feed-product-stats">
                                <strong><?php echo esc_html($feed['product_count']); ?></strong>
                                <?php if ($pendingCount > 0): ?>
                                <a href="#" class="hp-gmc-pending-count hp-gmc-view-pending" 
                                   data-feed-id="<?php echo esc_attr($feed['id']); ?>"
                                   data-feed-name="<?php echo esc_attr($feed['name']); ?>"
                                   title="<?php esc_attr_e('Click to view and add pending products', 'hp-gmc-manager'); ?>">
                                    (+<?php echo esc_html($pendingCount); ?>)
                                </a>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            if ($feed['last_uploaded']) {
                                // Standard WP way: human_time_diff between two timestamps
                                // current_time('timestamp') is site-local
                                // strtotime($feed['last_uploaded']) is site-local if current_time('mysql') was used
                                echo esc_html(human_time_diff(strtotime($feed['last_uploaded']), current_time('timestamp')) . ' ago');
                            } else {
                                echo '<span class="hp-gmc-not-uploaded">' . esc_html__('Never', 'hp-gmc-manager') . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if (!empty($feed['last_crawl_time'])) {
                                // last_crawl_time is stored from GMC API (ISO 8601) which is UTC
                                // But FeedManager.php converted it using date('Y-m-d H:i:s', strtotime($lastFetchTime))
                                // which uses the server's default timezone (usually UTC).
                                echo esc_html(human_time_diff(strtotime($feed['last_crawl_time']), time()) . ' ago');
                            } else {
                                echo '<span class="hp-gmc-not-crawled">' . esc_html__('Never', 'hp-gmc-manager') . '</span>';
                            }
                            ?>
                        </td>
                        <td class="hp-gmc-feed-actions-cell">
                            <a href="<?php echo esc_url(add_query_arg('feed_id', $feed['id']) . '#feeds'); ?>" class="button button-small" title="<?php esc_attr_e('View details', 'hp-gmc-manager'); ?>">
                                <?php esc_html_e('View', 'hp-gmc-manager'); ?>
                            </a>
                            <?php if ((int)$feed['product_count'] > 0): ?>
                                <?php if (!$feed['file_url'] || !$feed['gmc_feed_id']): ?>
                                <button type="button" class="button button-small button-primary hp-gmc-feed-publish" 
                                        data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                        title="<?php esc_attr_e('Generate file and upload to GMC', 'hp-gmc-manager'); ?>">
                                    <?php esc_html_e('Publish', 'hp-gmc-manager'); ?>
                                </button>
                                <?php else: ?>
                                <button type="button" class="button button-small hp-gmc-feed-upload" 
                                        data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                        title="<?php esc_attr_e('Re-upload to GMC', 'hp-gmc-manager'); ?>">
                                    <?php esc_html_e('Upload', 'hp-gmc-manager'); ?>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($feed['gmc_feed_id']): ?>
                            <button type="button" class="button button-small hp-gmc-feed-check-status" 
                                    data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                    title="<?php esc_attr_e('Refresh GMC status', 'hp-gmc-manager'); ?>">
                                <span class="dashicons dashicons-update-alt"></span>
                            </button>
                            <button type="button" class="button button-small hp-gmc-feed-debug" 
                                    data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                    title="<?php esc_attr_e('View raw GMC response', 'hp-gmc-manager'); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <button type="button" class="button button-small hp-gmc-feed-remove-gmc" 
                                    data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                    data-feed-name="<?php echo esc_attr($feed['name']); ?>"
                                    title="<?php esc_attr_e('Remove from GMC (keep local)', 'hp-gmc-manager'); ?>">
                                <span class="dashicons dashicons-cloud-upload" style="transform: rotate(180deg);"></span>
                            </button>
                            <?php endif; ?>
                            <?php if ($feed['file_url']): ?>
                            <a href="<?php echo esc_url($feed['file_url']); ?>" class="button button-small" download title="<?php esc_attr_e('Download feed file', 'hp-gmc-manager'); ?>">
                                <span class="dashicons dashicons-download"></span>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php endif; ?>
            
            <!-- MCP Tools Reference -->
            <div class="hp-gmc-feeds-tools">
                <h3><?php esc_html_e('Feed Management via MCP', 'hp-gmc-manager'); ?></h3>
                <div class="hp-gmc-mcp-commands">
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-create-policy-feeds</code>
                        <span><?php esc_html_e('Create standard exclusion feeds (personalization, pharma, otc)', 'hp-gmc-manager'); ?></span>
                    </div>
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-auto-populate-feed</code>
                        <span><?php esc_html_e('Auto-add products matching issue patterns to a feed', 'hp-gmc-manager'); ?></span>
                    </div>
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-feed-statistics</code>
                        <span><?php esc_html_e('Get statistics for a feed (issues covered, pending products)', 'hp-gmc-manager'); ?></span>
                    </div>
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-feed-generate</code>
                        <span><?php esc_html_e('Generate TSV/CSV file for a feed', 'hp-gmc-manager'); ?></span>
                    </div>
                    <div class="hp-gmc-mcp-command">
                        <code>gmc-feed-upload</code>
                        <span><?php esc_html_e('Upload feed to Google Merchant Center', 'hp-gmc-manager'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Create Feed Modal -->
        <div id="hp-gmc-create-feed-modal" class="hp-gmc-modal" style="display:none;">
            <div class="hp-gmc-modal-content">
                <div class="hp-gmc-modal-header">
                    <h3><?php esc_html_e('Create New Feed', 'hp-gmc-manager'); ?></h3>
                    <button type="button" class="hp-gmc-modal-close">&times;</button>
                </div>
                <div class="hp-gmc-modal-body">
                    <p>
                        <label for="hp-gmc-new-feed-name"><?php esc_html_e('Feed Name:', 'hp-gmc-manager'); ?></label>
                        <input type="text" id="hp-gmc-new-feed-name" class="regular-text" placeholder="hp-exclusions-personalization">
                    </p>
                    <p>
                        <label for="hp-gmc-new-feed-type"><?php esc_html_e('Feed Type:', 'hp-gmc-manager'); ?></label>
                        <select id="hp-gmc-new-feed-type">
                            <option value="exclusion"><?php esc_html_e('Exclusion (excluded_destination)', 'hp-gmc-manager'); ?></option>
                            <option value="redirect"><?php esc_html_e('Redirect (ads_redirect)', 'hp-gmc-manager'); ?></option>
                            <option value="custom"><?php esc_html_e('Custom', 'hp-gmc-manager'); ?></option>
                        </select>
                    </p>
                    <p>
                        <label for="hp-gmc-new-feed-category"><?php esc_html_e('Category (optional):', 'hp-gmc-manager'); ?></label>
                        <input type="text" id="hp-gmc-new-feed-category" class="regular-text" placeholder="personalization">
                    </p>
                </div>
                <div class="hp-gmc-modal-footer">
                    <button type="button" class="button hp-gmc-modal-close"><?php esc_html_e('Cancel', 'hp-gmc-manager'); ?></button>
                    <button type="button" class="button button-primary" id="hp-gmc-create-feed-submit"><?php esc_html_e('Create Feed', 'hp-gmc-manager'); ?></button>
                </div>
            </div>
        </div>
        
        <!-- Pending Products Modal -->
        <div id="hp-gmc-pending-products-modal" class="hp-gmc-modal hp-gmc-modal-lg" style="display:none;">
            <div class="hp-gmc-modal-content">
                <div class="hp-gmc-modal-header">
                    <h3><?php esc_html_e('Pending Products', 'hp-gmc-manager'); ?> - <span id="hp-gmc-pending-feed-name"></span></h3>
                    <button type="button" class="hp-gmc-modal-close">&times;</button>
                </div>
                <div class="hp-gmc-modal-body">
                    <p class="hp-gmc-pending-info">
                        <?php esc_html_e('These products have issues matching this feed\'s category but are not yet included in the feed.', 'hp-gmc-manager'); ?>
                    </p>
                    <div id="hp-gmc-pending-products-loading" style="display:none;">
                        <span class="spinner is-active"></span> <?php esc_html_e('Loading...', 'hp-gmc-manager'); ?>
                    </div>
                    <div id="hp-gmc-pending-products-list"></div>
                </div>
                <div class="hp-gmc-modal-footer">
                    <button type="button" class="button hp-gmc-modal-close"><?php esc_html_e('Close', 'hp-gmc-manager'); ?></button>
                    <button type="button" class="button button-primary" id="hp-gmc-add-all-pending" data-feed-id="">
                        <?php esc_html_e('Add All to Feed', 'hp-gmc-manager'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Feed Debug Modal -->
        <div id="hp-gmc-feed-debug-modal" class="hp-gmc-modal hp-gmc-modal-lg" style="display:none;">
            <div class="hp-gmc-modal-content">
                <div class="hp-gmc-modal-header">
                    <h3><?php esc_html_e('GMC Feed Debug', 'hp-gmc-manager'); ?></h3>
                    <button type="button" class="hp-gmc-modal-close">&times;</button>
                </div>
                <div class="hp-gmc-modal-body">
                    <pre id="hp-gmc-feed-debug-content" style="background: #f0f0f1; padding: 10px; border-radius: 4px; overflow: auto; max-height: 500px;"></pre>
                </div>
                <div class="hp-gmc-modal-footer">
                    <button type="button" class="button hp-gmc-modal-close"><?php esc_html_e('Close', 'hp-gmc-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
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
            'feed' => __('Feeds', 'hp-gmc-manager'),
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
