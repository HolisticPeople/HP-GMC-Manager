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
                <a href="#funnels" class="nav-tab" data-tab="funnels">
                    <?php esc_html_e('Funnels', 'hp-gmc-manager'); ?>
                </a>
                <a href="#shipping" class="nav-tab" data-tab="shipping">
                    <?php esc_html_e('Shipping', 'hp-gmc-manager'); ?>
                </a>
                <a href="#audiences" class="nav-tab" data-tab="audiences">
                    <?php esc_html_e('Audiences', 'hp-gmc-manager'); ?>
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
                <div id="tab-funnels" class="hp-gmc-tab-panel">
                    <?php self::render_funnels_tab(); ?>
                </div>
                <div id="tab-shipping" class="hp-gmc-tab-panel">
                    <?php self::render_shipping_tab(); ?>
                </div>
                <div id="tab-audiences" class="hp-gmc-tab-panel">
                    <?php self::render_audiences_tab(); ?>
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
            
            <div class="hp-gmc-supplemental-feed-help" style="margin-bottom: 15px; padding: 10px; background: #f0f6fc; border-left: 4px solid #2271b1;">
                <strong><?php esc_html_e('Add feeds to GMC Supplemental:', 'hp-gmc-manager'); ?></strong>
                <p style="margin: 5px 0 0 0;"><?php esc_html_e('Click "Upload to GMC" on a feed. The feed URL is copied to your clipboard and a modal opens with an "Open GMC → Data sources" button. In GMC, add the URL under Supplemental sources (not Primary), then paste and save.', 'hp-gmc-manager'); ?></p>
            </div>
            
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
                        <strong><?php esc_html_e('Last generated:', 'hp-gmc-manager'); ?></strong> <?php echo esc_html($selectedFeed['last_uploaded']); ?>
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
                        <option value="export"><?php esc_html_e('Export to JSON', 'hp-gmc-manager'); ?></option>
                        <option value="delete"><?php esc_html_e('Delete', 'hp-gmc-manager'); ?></option>
                    </select>
                    <button type="button" class="button" id="hp-gmc-apply-bulk-action"><?php esc_html_e('Apply', 'hp-gmc-manager'); ?></button>
                </div>
                <div class="hp-gmc-feeds-toolbar-right">
                    <button type="button" class="button" id="hp-gmc-download-json-template" title="<?php esc_attr_e('Download a JSON template for importing feeds', 'hp-gmc-manager'); ?>">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e('Download JSON template', 'hp-gmc-manager'); ?>
                    </button>
                    <button type="button" class="button" id="hp-gmc-import-feeds-json" title="<?php esc_attr_e('Import feeds from a JSON file', 'hp-gmc-manager'); ?>">
                        <span class="dashicons dashicons-upload"></span> <?php esc_html_e('Import from JSON', 'hp-gmc-manager'); ?>
                    </button>
                    <input type="file" id="hp-gmc-import-json-file" accept=".json,application/json" style="display:none;">
                    <button type="button" class="button" id="hp-gmc-refresh-all-feeds" title="<?php esc_attr_e('Refresh GMC status for all uploaded feeds', 'hp-gmc-manager'); ?>">
                        <span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh All', 'hp-gmc-manager'); ?>
                    </button>
                </div>
            </div>

            <div id="hp-gmc-last-response" class="hp-gmc-last-response" style="display:none; margin:1em 0; padding:1em; background:#f0f0f1; border:1px solid #c3c4c7; border-radius:4px;">
                <strong><?php esc_html_e('Last response (Refresh All / Force crawl / Check status)', 'hp-gmc-manager'); ?></strong>
                <pre id="hp-gmc-last-response-content" style="margin:0.5em 0 0; padding:0.75em; background:#fff; border:1px solid #dcdcde; overflow:auto; max-height:320px; white-space:pre-wrap; word-break:break-all; font-size:12px;"></pre>
                <p style="margin:0.5em 0 0;">
                    <button type="button" class="button button-small" id="hp-gmc-last-response-close"><?php esc_html_e('Close', 'hp-gmc-manager'); ?></button>
                </p>
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
                        <th style="width:10%" title="<?php esc_attr_e('When the feed file was last generated (or when you last used Upload to GMC). Not when GMC fetched the URL.', 'hp-gmc-manager'); ?>"><?php esc_html_e('Last generated', 'hp-gmc-manager'); ?></th>
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
                        
                        // Determine health indicator (linked = supplemental feed connected in GMC)
                        $healthClass = 'neutral';
                        $healthTitle = __('Not uploaded', 'hp-gmc-manager');
                        $gmcLive = in_array($feed['gmc_status'] ?? '', ['success', 'active', 'completed', 'linked']);
                        if ($feed['status'] === 'error' || $feed['gmc_status'] === 'failed' || $feed['gmc_status'] === 'error') {
                            $healthClass = 'error';
                            $healthTitle = __('Feed has errors', 'hp-gmc-manager');
                        } elseif (($feed['status'] === 'active' || $gmcLive) && $gmcLive) {
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
                            <?php elseif ($feed['file_url']): ?>
                            <span class="hp-gmc-gmc-status hp-gmc-gmc-status-unknown" title="<?php esc_attr_e('GMC status unknown. Use Refresh All to try linking this feed if you added it in GMC.', 'hp-gmc-manager'); ?>"><?php esc_html_e('Unknown', 'hp-gmc-manager'); ?></span>
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
                        <td class="hp-gmc-feed-actions-cell">
                            <a href="<?php echo esc_url(add_query_arg('feed_id', $feed['id']) . '#feeds'); ?>" class="button button-small" title="<?php esc_attr_e('View details', 'hp-gmc-manager'); ?>">
                                <?php esc_html_e('View', 'hp-gmc-manager'); ?>
                            </a>
                            <?php if ((int)$feed['product_count'] > 0): ?>
                                <button type="button" class="button button-small button-primary hp-gmc-feed-upload" 
                                        data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                        title="<?php esc_attr_e('Generate feed and get URL for GMC Supplemental sources', 'hp-gmc-manager'); ?>">
                                    <?php esc_html_e('Upload to GMC', 'hp-gmc-manager'); ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($feed['gmc_feed_id']): ?>
                            <button type="button" class="button button-small hp-gmc-feed-check-status" 
                                    data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                    title="<?php esc_attr_e('Refresh GMC status', 'hp-gmc-manager'); ?>">
                                <span class="dashicons dashicons-update-alt"></span>
                            </button>
                            <button type="button" class="button button-small hp-gmc-feed-force-crawl" 
                                    data-feed-id="<?php echo esc_attr($feed['id']); ?>" 
                                    title="<?php esc_attr_e('Force crawl: tell GMC to fetch this feed now', 'hp-gmc-manager'); ?>">
                                <span class="dashicons dashicons-controls-play"></span>
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
        
        <!-- Supplemental feed URL modal: copy URL and open GMC -->
        <div id="hp-gmc-supplemental-url-modal" class="hp-gmc-modal" style="display:none;">
            <div class="hp-gmc-modal-content">
                <div class="hp-gmc-modal-header">
                    <h3><?php esc_html_e('Add this feed to GMC as Supplemental', 'hp-gmc-manager'); ?></h3>
                    <button type="button" class="hp-gmc-modal-close">&times;</button>
                </div>
                <div class="hp-gmc-modal-body">
                    <p><?php esc_html_e('Google does not allow adding supplemental sources via API. Paste the URL below in GMC:', 'hp-gmc-manager'); ?></p>
                    <p><strong><?php esc_html_e('Settings → Data sources → Supplemental sources → Add supplemental product data', 'hp-gmc-manager'); ?></strong></p>
                    <p class="description" style="margin: 8px 0;"><?php esc_html_e('After adding the URL, click Edit next to the feed name in GMC and set Column delimiter to Tab (so GMC parses the file correctly).', 'hp-gmc-manager'); ?></p>
                    <p style="margin-bottom: 8px;">
                        <input type="text" id="hp-gmc-supplemental-url-input" class="large-text" readonly style="width:100%;">
                    </p>
                    <p>
                        <button type="button" class="button" id="hp-gmc-supplemental-url-copy"><?php esc_html_e('Copy URL', 'hp-gmc-manager'); ?></button>
                        <a href="https://merchants.google.com/mc/settings/data-sources" target="_blank" rel="noopener" class="button button-primary" id="hp-gmc-supplemental-open-gmc"><?php esc_html_e('Open GMC → Data sources', 'hp-gmc-manager'); ?></a>
                    </p>
                </div>
                <div class="hp-gmc-modal-footer">
                    <button type="button" class="button button-primary hp-gmc-supplemental-url-close"><?php esc_html_e('Done', 'hp-gmc-manager'); ?></button>
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
     * Render the Audiences tab (saved segments, segment builder, templates, export, upload).
     */
    private static function render_audiences_tab(): void
    {
        $repo = new \HP_GMC\Services\SavedSegmentsRepository();
        $segments = $repo->list_all();
        $upload_disabled = (bool) get_option('hp_gmc_audience_upload_disabled', false);
        $rest_base = rest_url('hp-gmc/v1/audiences/segments');
        $nonce = wp_create_nonce('wp_rest');
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $edit_segment = $edit_id ? $repo->get($edit_id) : null;
        ?>
        <div class="hp-gmc-audiences">
            <h2><?php esc_html_e('Audiences', 'hp-gmc-manager'); ?></h2>
            <p class="description">
                <?php esc_html_e('Build segments from your WooCommerce data and export them as CSV for Google Ads Customer Match, or upload via API (production).', 'hp-gmc-manager'); ?>
            </p>

            <?php if ($upload_disabled): ?>
            <p class="notice notice-warning inline">
                <?php esc_html_e('Upload to Google Ads is currently disabled in Settings (Schema & Audiences).', 'hp-gmc-manager'); ?>
            </p>
            <?php endif; ?>

            <div class="hp-gmc-audiences-help" style="margin: 1em 0; padding: 0.75em; background: #f0f0f1; border-left: 4px solid #2271b1;">
                <strong><?php esc_html_e('Segment', 'hp-gmc-manager'); ?></strong>
                <?php esc_html_e('Users who match the conditions below (e.g. billing/shipping address, purchase history, funnel).', 'hp-gmc-manager'); ?>
                <br>
                <strong><?php esc_html_e('Append vs Replace', 'hp-gmc-manager'); ?></strong>
                <?php esc_html_e('When uploading to Google Ads: Append adds the current run to the existing list; Replace clears the list and adds only the current run.', 'hp-gmc-manager'); ?>
                <br>
                <strong><?php esc_html_e('Upload to Google Ads', 'hp-gmc-manager'); ?></strong>
                <?php
                $upload_auth = get_option('hp_gmc_ads_upload_auth', 'oauth');
                if ($upload_auth === 'service_account') {
                    esc_html_e('Uses the service account (Settings > Audience upload authentication). Add that service account email as a user in Google Ads (Admin > Access and security) with access to your customer/manager account.', 'hp-gmc-manager');
                } else {
                    esc_html_e('Uses OAuth when connected (Settings > Audience upload authentication). If you manage the client via a manager (MCC) account, set Manager Account ID in Settings.', 'hp-gmc-manager');
                }
                ?>
            </div>
            <?php
            $audience_countries = [];
            if (function_exists('WC') && WC()->countries) {
                $audience_countries = WC()->countries->get_countries();
            }
            if (empty($audience_countries)) {
                $audience_countries = ['US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia'];
            }
            $audience_funnel_slugs = [];
            $funnel_posts = get_posts([
                'post_type'      => 'hp-funnel',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
            foreach ($funnel_posts as $fp) {
                $slug = get_post_meta($fp->ID, 'funnel_slug', true) ?: $fp->post_name;
                $audience_funnel_slugs[] = ['slug' => $slug, 'title' => $fp->post_title];
            }
            ?>

            <h3><?php esc_html_e('Saved segments', 'hp-gmc-manager'); ?></h3>
            <div id="hp-gmc-audience-upload-message" class="hp-gmc-audience-upload-message" style="display:none; margin: 0.5em 0; padding: 0.75em; border-left: 4px solid #d63638; background: #fcf0f1; color: #1d2327;"></div>
            <div id="hp-gmc-audience-bulk-actions" class="hp-gmc-audience-bulk-actions" style="display:none; margin-bottom: 8px; padding: 8px 12px; background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px;">
                <span class="hp-gmc-audience-bulk-label"><?php esc_html_e('With selected:', 'hp-gmc-manager'); ?></span>
                <button type="button" class="button button-small hp-gmc-audience-bulk-delete" style="color:#b32d2e;"><?php esc_html_e('Delete', 'hp-gmc-manager'); ?></button>
                <button type="button" class="button button-small hp-gmc-audience-bulk-rerun"><?php esc_html_e('Re-run', 'hp-gmc-manager'); ?></button>
                <button type="button" class="button button-small hp-gmc-audience-bulk-export"><?php esc_html_e('Export', 'hp-gmc-manager'); ?></button>
            </div>
            <table class="wp-list-table widefat fixed striped hp-gmc-audiences-table">
                <thead>
                    <tr>
                        <th class="check-column" style="width: 2.2em;">
                            <input type="checkbox" id="hp-gmc-audience-select-all" title="<?php esc_attr_e('Select / deselect all', 'hp-gmc-manager'); ?>">
                        </th>
                        <th><?php esc_html_e('Name', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Last run', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Count', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Last upload', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Actions', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($segments)): ?>
                    <tr><td colspan="6"><?php esc_html_e('No saved segments yet. Use the builder below and "Save as".', 'hp-gmc-manager'); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($segments as $seg): ?>
                    <tr data-segment-id="<?php echo esc_attr($seg['id']); ?>">
                        <th scope="row" class="check-column"><input type="checkbox" class="hp-gmc-audience-row-cb" value="<?php echo esc_attr($seg['id']); ?>"></th>
                        <td><strong><?php echo esc_html($seg['name']); ?></strong></td>
                        <td><?php echo $seg['last_run_at'] ? esc_html($seg['last_run_at']) : '—'; ?></td>
                        <td><?php echo $seg['last_run_count'] !== null ? (int) $seg['last_run_count'] : '—'; ?></td>
                        <td class="hp-gmc-audience-upload-status" data-segment-id="<?php echo esc_attr($seg['id']); ?>">
                            <?php
                            if (!empty($seg['last_upload_status'])):
                                echo esc_html(ucfirst($seg['last_upload_status']));
                                if (!empty($seg['last_upload_job_id'])):
                                    echo ' <span class="hp-gmc-job-id" title="' . esc_attr($seg['last_upload_job_id']) . '">(' . esc_html(__('job', 'hp-gmc-manager')) . ')</span>';
                                endif;
                                if (!empty($seg['last_upload_at'])):
                                    echo ' ' . esc_html($seg['last_upload_at']);
                                endif;
                            else:
                                echo '—';
                            endif;
                            ?>
                        </td>
                        <td>
                            <span class="hp-gmc-audience-run-wrap" style="display:inline-flex;align-items:center;gap:6px;">
                                <button type="button" class="button button-small hp-gmc-audience-run" data-id="<?php echo esc_attr($seg['id']); ?>"><?php esc_html_e('Run', 'hp-gmc-manager'); ?></button>
                                <button type="button" class="button button-small hp-gmc-audience-abort" style="display:none;"><?php esc_html_e('Abort', 'hp-gmc-manager'); ?></button>
                                <span class="hp-gmc-audience-run-status" style="display:none;" aria-live="polite"><span class="spinner is-active" style="float:none;display:inline-block;vertical-align:middle;margin:0 4px 0 0;"></span><span class="hp-gmc-audience-run-status-text"><?php esc_html_e('Running…', 'hp-gmc-manager'); ?></span><span class="hp-gmc-audience-run-progress-bar" style="display:none;margin-left:8px;width:80px;height:6px;background:#ddd;border-radius:3px;overflow:hidden;vertical-align:middle;"><span style="display:block;height:100%;width:0%;background:#2271b1;border-radius:3px;"></span></span></span>
                            </span>
                            <button type="button" class="button button-small hp-gmc-audience-duplicate" data-id="<?php echo esc_attr($seg['id']); ?>"><?php esc_html_e('Duplicate', 'hp-gmc-manager'); ?></button>
                            <button type="button" class="button button-small hp-gmc-audience-edit" data-id="<?php echo esc_attr($seg['id']); ?>"><?php esc_html_e('Edit', 'hp-gmc-manager'); ?></button>
                            <button type="button" class="button button-small hp-gmc-audience-export" data-id="<?php echo esc_attr($seg['id']); ?>"><?php esc_html_e('Export CSV', 'hp-gmc-manager'); ?></button>
                            <button type="button" class="button button-small hp-gmc-audience-delete" data-id="<?php echo esc_attr($seg['id']); ?>" style="color:#b32d2e;"><?php esc_html_e('Delete', 'hp-gmc-manager'); ?></button>
                            <?php if (!$upload_disabled): ?>
                            <button type="button" class="button button-small button-secondary hp-gmc-audience-upload" data-id="<?php echo esc_attr($seg['id']); ?>" data-count="<?php echo esc_attr($seg['last_run_count'] !== null ? (int) $seg['last_run_count'] : ''); ?>"><?php esc_html_e('Upload to Google Ads', 'hp-gmc-manager'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top: 0.5em;"><?php esc_html_e('Count and Last run are from the last successful run.', 'hp-gmc-manager'); ?></p>

            <h3><?php esc_html_e('Segment builder', 'hp-gmc-manager'); ?></h3>
            <p style="display:flex;align-items:center;gap:1em;flex-wrap:wrap;">
                <span><?php esc_html_e('Start by choosing a template below or click Add condition to build from scratch.', 'hp-gmc-manager'); ?></span>
                <button type="button" class="button button-secondary" id="hp-gmc-audience-create-new"><?php esc_html_e('Create new segment', 'hp-gmc-manager'); ?></button>
            </p>
            <p><?php esc_html_e('Prebuilt templates:', 'hp-gmc-manager'); ?></p>
            <p>
                <button type="button" class="button hp-gmc-audience-template" data-template="past_90"><?php esc_html_e('Past 90-day buyers', 'hp-gmc-manager'); ?></button>
                <button type="button" class="button hp-gmc-audience-template" data-template="high_ltv"><?php esc_html_e('High LTV (≥ $100)', 'hp-gmc-manager'); ?></button>
                <button type="button" class="button hp-gmc-audience-template" data-template="lapsed"><?php esc_html_e('Lapsed (no order in 90 days)', 'hp-gmc-manager'); ?></button>
                <button type="button" class="button hp-gmc-audience-template" data-template="last_7_days"><?php esc_html_e('Buyers in last 7 days', 'hp-gmc-manager'); ?></button>
            </p>
            <div class="hp-gmc-audience-builder" style="margin-top: 1em;">
                <p class="description" style="margin-bottom: 0.5em;"><?php esc_html_e('First row is the base; each following row combines with the previous result using AND or OR.', 'hp-gmc-manager'); ?></p>
                <table class="wp-list-table widefat fixed striped hp-gmc-audience-conditions-table" style="margin-top: 0.5em;">
                    <thead>
                        <tr>
                            <th class="check-column" style="width: 2em;"><span class="screen-reader-text"><?php esc_html_e('Select for deletion', 'hp-gmc-manager'); ?></span></th>
                            <th style="width: 8%;"><?php esc_html_e('With previous', 'hp-gmc-manager'); ?></th>
                            <th style="width: 16%;"><?php esc_html_e('Condition type', 'hp-gmc-manager'); ?></th>
                            <th style="width: 48%;"><?php esc_html_e('Parameters', 'hp-gmc-manager'); ?></th>
                            <th style="width: 10%;"><?php esc_html_e('Include / Exclude', 'hp-gmc-manager'); ?></th>
                            <th style="width: 6em;"><?php esc_html_e('Remove', 'hp-gmc-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="hp-gmc-audience-conditions"></tbody>
                </table>
                <p style="margin-top: 0.5em;">
                    <button type="button" class="button" id="hp-gmc-audience-add-condition"><?php esc_html_e('Add condition', 'hp-gmc-manager'); ?></button>
                    <button type="button" class="button button-secondary" id="hp-gmc-audience-delete-selected"><?php esc_html_e('Delete selected', 'hp-gmc-manager'); ?></button>
                </p>
                <p>
                    <label><?php esc_html_e('Save as (name):', 'hp-gmc-manager'); ?></label>
                    <input type="text" id="hp-gmc-audience-save-name" placeholder="<?php esc_attr_e('Segment name', 'hp-gmc-manager'); ?>" style="width: 240px;" value="<?php echo $edit_segment ? esc_attr($edit_segment['name']) : ''; ?>">
                    <button type="button" class="button" id="hp-gmc-audience-save-as"><?php echo $edit_segment ? esc_html__('Update', 'hp-gmc-manager') : esc_html__('Save as', 'hp-gmc-manager'); ?></button>
                    <?php if ($edit_segment): ?>
                    <input type="hidden" id="hp-gmc-audience-edit-id" value="<?php echo esc_attr($edit_segment['id']); ?>">
                    <?php endif; ?>
                </p>
            </div>
            <script>
            (function() {
                var restBase = <?php echo wp_json_encode($rest_base); ?>;
                var nonce = <?php echo wp_json_encode($nonce); ?>;
                var productSearchAjaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var productSearchNonce = <?php echo wp_json_encode(wp_create_nonce('hp_gmc_admin')); ?>;
                var editDefinition = <?php echo $edit_segment && !empty($edit_segment['filter_definition']) ? wp_json_encode(json_decode($edit_segment['filter_definition'], true)) : 'null'; ?>;
                var editId = <?php echo $edit_segment ? (int) $edit_segment['id'] : '0'; ?>;
                var currentEditId = editId;
                var audienceCountries = <?php echo wp_json_encode($audience_countries); ?>;
                var audienceFunnelSlugs = <?php echo wp_json_encode($audience_funnel_slugs); ?>;
                var conditionTypeLabels = {
                    billing_address: 'Billing address',
                    shipping_address: 'Shipping address',
                    country_related: 'Country related',
                    purchase_product: 'Purchase history: Bought product',
                    purchase_spend: 'Purchase history: Total spend',
                    purchase_orders: 'Purchase history: Number of orders',
                    purchase_last_order: 'Purchase history: Last order',
                    funnel: 'Funnel'
                };
                var templates = {
                    past_90: { logic: 'and', conditions: [{ type: 'purchase_orders', order_count_min: 1, last_days: 90, include: true }] },
                    high_ltv: { logic: 'and', conditions: [{ type: 'purchase_spend', ltv_min: 100, include: true }] },
                    lapsed: { logic: 'and', conditions: [{ type: 'purchase_last_order', when: 'before', date: new Date(Date.now() - 90*24*60*60*1000).toISOString().slice(0,10), include: true }] },
                    last_7_days: { logic: 'and', conditions: [{ type: 'purchase_orders', order_count_min: 1, last_days: 7, include: true }] }
                };
                function buildConditionFields(type, c) {
                    c = c || {};
                    var esc = function(v) { return String(v === null || v === undefined ? '' : v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };
                    if (type === 'billing_address' || type === 'shipping_address') {
                        var countryOpts = Object.keys(audienceCountries).map(function(code) {
                            var sel = (c.country === code) ? ' selected' : '';
                            return '<option value="' + esc(code) + '"' + sel + '>' + esc(audienceCountries[code]) + '</option>';
                        }).join('');
                        return '<select class="cond-country" data-param="country" style="width:140px;max-width:100%;"><option value="">—</option>' + countryOpts + '</select> <input type="text" class="cond-zip" data-param="zip" placeholder="Zip (optional)" value="' + esc(c.zip) + '" style="width:100px">';
                    }
                    if (type === 'country_related') {
                        var countryOpts = Object.keys(audienceCountries).map(function(code) {
                            var sel = (c.country === code) ? ' selected' : '';
                            return '<option value="' + esc(code) + '"' + sel + '>' + esc(audienceCountries[code]) + '</option>';
                        }).join('');
                        return '<select class="cond-country" data-param="country" style="width:140px;max-width:100%;"><option value="">—</option>' + countryOpts + '</select> <span class="description">(billing or shipping or phone country)</span>';
                    }
                    if (type === 'purchase_product') {
                        var skuList = Array.isArray(c.skus) ? c.skus : (c.sku ? [c.sku] : []);
                        var chipsHtml = skuList.map(function(s) {
                            return '<span class="cond-sku-chip" data-sku="' + esc(s) + '"><span class="hp-gmc-chip-content"><span class="hp-gmc-chip-sku">' + esc(s) + '</span></span> <button type="button" class="cond-sku-remove" aria-label="Remove">&times;</button></span>';
                        }).join('');
                        return 'SKUs <span class="hp-gmc-sku-wrap" style="position:relative;display:inline-block;vertical-align:middle;">' +
                            '<span class="hp-gmc-sku-chips" style="display:inline-flex;flex-wrap:wrap;gap:4px;align-items:center;min-height:28px;">' + chipsHtml + '</span>' +
                            '<input type="text" class="cond-sku" data-param="sku" placeholder="Add SKU or search…" value="" style="width:160px;margin-left:2px;" autocomplete="off">' +
                            '<div class="hp-gmc-sku-suggestions" style="position:absolute;left:0;top:100%;min-width:220px;max-height:200px;overflow:auto;background:#fff;border:1px solid #ccc;border-radius:4px;box-shadow:0 4px 8px rgba(0,0,0,0.15);display:none;z-index:100;"></div>' +
                            '</span> ' +
                            'At least <input type="number" class="cond-min-quantity" data-param="min_quantity" min="1" value="' + (c.min_quantity || 1) + '" style="width:50px"> units ' +
                            'In the last <input type="number" class="cond-last-days" data-param="last_days" min="1" placeholder="all time" value="' + esc(c.last_days) + '" style="width:60px"> days';
                    }
                    if (type === 'purchase_spend') {
                        return 'At least $<input type="number" class="cond-ltv-min" data-param="ltv_min" min="0" step="0.01" value="' + esc(c.ltv_min) + '" style="width:80px"> ' +
                            'In the last <input type="number" class="cond-last-days" data-param="last_days" min="1" placeholder="all time" value="' + esc(c.last_days) + '" style="width:60px"> days';
                    }
                    if (type === 'purchase_orders') {
                        return 'At least <input type="number" class="cond-order-count-min" data-param="order_count_min" min="0" value="' + (c.order_count_min !== undefined ? c.order_count_min : '') + '" style="width:50px"> orders ' +
                            'In the last <input type="number" class="cond-last-days" data-param="last_days" min="1" placeholder="all time" value="' + esc(c.last_days) + '" style="width:60px"> days';
                    }
                    if (type === 'purchase_last_order') {
                        var beforeChecked = (c.when !== 'after') ? ' checked' : '';
                        var afterChecked = (c.when === 'after') ? ' checked' : '';
                        return '<label><input type="radio" class="cond-when" data-param="when" name="when_' + Math.random().toString(36).slice(2) + '" value="before"' + beforeChecked + '> Before</label> ' +
                            '<label><input type="radio" class="cond-when" data-param="when" value="after"' + afterChecked + '> After</label> ' +
                            ' <input type="date" class="cond-date" data-param="date" value="' + esc(c.date) + '">';
                    }
                    if (type === 'funnel') {
                        var funnelOpts = '<option value="">— ' + (audienceFunnelSlugs.length ? 'Select funnel' : 'No funnels') + ' —</option>';
                        audienceFunnelSlugs.forEach(function(f) {
                            var sel = (c.funnel_slug === f.slug) ? ' selected' : '';
                            funnelOpts += '<option value="' + esc(f.slug) + '"' + sel + '>' + esc(f.title || f.slug) + '</option>';
                        });
                        return 'Funnel <select class="cond-funnel-slug" data-param="funnel_slug" style="min-width:160px">' + funnelOpts + '</select> ' +
                            'In the last <input type="number" class="cond-last-days" data-param="last_days" min="1" placeholder="all time" value="' + esc(c.last_days) + '" style="width:60px" title="Leave empty for all time"> days';
                    }
                    return '';
                }
                function initSkuAutocomplete(row) {
                    var input = row ? row.querySelector('.cond-sku') : null;
                    var wrap = input ? input.closest('.hp-gmc-sku-wrap') : null;
                    var list = wrap ? wrap.querySelector('.hp-gmc-sku-suggestions') : null;
                    var chipsContainer = wrap ? wrap.querySelector('.hp-gmc-sku-chips') : null;
                    if (!input || !list || !chipsContainer) return;
                    var debounce = null;
                    function hideList() { list.style.display = 'none'; list.innerHTML = ''; }
                    function getExistingSkus() {
                        var set = {};
                        chipsContainer.querySelectorAll('.cond-sku-chip').forEach(function(el) {
                            var s = (el.getAttribute('data-sku') || '').toUpperCase();
                            if (s) set[s] = true;
                        });
                        return set;
                    }
                    if (typeof window.hpGmcSkuProductCache === 'undefined') window.hpGmcSkuProductCache = {};
                    var productCache = window.hpGmcSkuProductCache;
                    function renderChipContent(chip) {
                        var sku = chip.getAttribute('data-sku') || '';
                        var name = chip.getAttribute('data-name') || '';
                        var imageUrl = chip.getAttribute('data-image') || '';
                        var content = document.createElement('span');
                        content.className = 'hp-gmc-chip-content';
                        if (imageUrl) {
                            var img = document.createElement('img');
                            img.className = 'hp-gmc-chip-thumb';
                            img.src = imageUrl;
                            img.alt = '';
                            content.appendChild(img);
                        }
                        var skuSpan = document.createElement('span');
                        skuSpan.className = 'hp-gmc-chip-sku';
                        skuSpan.textContent = sku;
                        content.appendChild(skuSpan);
                        if (name) {
                            var nameSpan = document.createElement('span');
                            nameSpan.className = 'hp-gmc-chip-name';
                            nameSpan.textContent = name;
                            content.appendChild(nameSpan);
                        }
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'cond-sku-remove';
                        btn.setAttribute('aria-label', 'Remove');
                        btn.innerHTML = '&times;';
                        chip.innerHTML = '';
                        chip.appendChild(content);
                        chip.appendChild(btn);
                    }
                    function ensureChipProduct(chip) {
                        var sku = chip.getAttribute('data-sku') || '';
                        var skuKey = sku.toUpperCase();
                        if (chip.getAttribute('data-name')) return;
                        if (productCache[skuKey]) {
                            chip.setAttribute('data-name', productCache[skuKey].name || '');
                            chip.setAttribute('data-image', productCache[skuKey].image_url || '');
                            renderChipContent(chip);
                            return;
                        }
                        var form = new FormData();
                        form.append('action', 'hp_gmc_get_product_by_sku');
                        form.append('nonce', productSearchNonce);
                        form.append('sku', sku);
                        fetch(productSearchAjaxUrl, { method: 'POST', body: form })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                var ok = data && data.success;
                                var d = (data && data.data) ? data.data : {};
                                var returnedSku = (d && d.sku) ? String(d.sku).trim() : '';
                                if (returnedSku && returnedSku.toUpperCase() !== skuKey) { return; }
                                var n = (d && d.name) ? d.name : '';
                                var img = (d && d.image_url) ? d.image_url : '';
                                productCache[skuKey] = { name: n || '<?php echo esc_js(__('Product not found', 'hp-gmc-manager')); ?>', image_url: img };
                                chip.setAttribute('data-name', n || '');
                                chip.setAttribute('data-image', img || '');
                                renderChipContent(chip);
                            })
                            .catch(function() {
                                productCache[skuKey] = { name: '<?php echo esc_js(__('Product not found', 'hp-gmc-manager')); ?>', image_url: '' };
                                chip.setAttribute('data-name', '');
                                chip.setAttribute('data-image', '');
                                renderChipContent(chip);
                            });
                    }
                    function addChip(sku, name, imageUrl) {
                        sku = String(sku || '').trim();
                        if (!sku) return;
                        var existing = chipsContainer.querySelectorAll('.cond-sku-chip');
                        for (var i = 0; i < existing.length; i++) { if ((existing[i].getAttribute('data-sku') || '').toUpperCase() === sku.toUpperCase()) return; }
                        var span = document.createElement('span');
                        span.className = 'cond-sku-chip';
                        span.setAttribute('data-sku', sku);
                        span.setAttribute('data-name', name || '');
                        span.setAttribute('data-image', imageUrl || '');
                        renderChipContent(span);
                        chipsContainer.appendChild(span);
                    }
                    wrap.addEventListener('click', function(e) {
                        if (e.target && e.target.classList.contains('cond-sku-remove')) {
                            var chip = e.target.closest('.cond-sku-chip');
                            if (chip) chip.remove();
                        }
                    });
                    chipsContainer.querySelectorAll('.cond-sku-chip').forEach(function(chip) {
                        if (!chip.getAttribute('data-name')) ensureChipProduct(chip);
                        else renderChipContent(chip);
                    });
                    function showList(items) {
                        var existingSkus = getExistingSkus();
                        items = items.filter(function(p) { return !existingSkus[(String(p.sku || '').toUpperCase())]; });
                        list.innerHTML = items.map(function(p) {
                            var sku = String(p.sku || '').replace(/"/g, '&quot;').replace(/</g, '&lt;');
                            var name = String(p.name || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/&/g, '&amp;');
                            var img = String(p.image_url || '').replace(/"/g, '&quot;');
                            var imgHtml = img ? '<img src="' + img + '" alt="" class="hp-gmc-sku-item-thumb">' : '<span class="hp-gmc-sku-item-thumb hp-gmc-sku-item-no-thumb"></span>';
                            return '<div class="hp-gmc-sku-item" data-sku="' + sku + '" data-name="' + name + '" data-image="' + img + '">' + imgHtml + '<span class="hp-gmc-sku-item-text"><span class="hp-gmc-sku-item-sku">' + sku + '</span><span class="hp-gmc-sku-item-name">' + name + '</span></span></div>';
                        }).join('');
                        list.style.display = items.length ? 'block' : 'none';
                        list.querySelectorAll('.hp-gmc-sku-item').forEach(function(el) {
                            el.addEventListener('click', function() {
                                addChip(el.getAttribute('data-sku') || '', el.getAttribute('data-name') || '', el.getAttribute('data-image') || '');
                                input.value = '';
                                hideList();
                                input.focus();
                            });
                        });
                    }
                    list.addEventListener('mousedown', function(e) { e.preventDefault(); });
                    input.addEventListener('input', function() {
                        clearTimeout(debounce);
                        var term = input.value.trim();
                        if (term.length < 2) { hideList(); return; }
                        debounce = setTimeout(function() {
                            var form = new FormData();
                            form.append('action', 'hp_gmc_search_products');
                            form.append('nonce', productSearchNonce);
                            form.append('term', term);
                            fetch(productSearchAjaxUrl, { method: 'POST', body: form })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    var products = (data && data.data && data.data.products) ? data.data.products : [];
                                    showList(products);
                                })
                                .catch(function() { hideList(); });
                        }, 250);
                    });
                    input.addEventListener('focus', function() {
                        var term = input.value.trim();
                        if (term.length >= 2 && list.innerHTML === '') {
                            var form = new FormData();
                            form.append('action', 'hp_gmc_search_products');
                            form.append('nonce', productSearchNonce);
                            form.append('term', term);
                            fetch(productSearchAjaxUrl, { method: 'POST', body: form })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    var products = (data && data.data && data.data.products) ? data.data.products : [];
                                    showList(products);
                                })
                                .catch(function() {});
                        }
                    });
                    input.addEventListener('blur', function() {
                        setTimeout(hideList, 200);
                    });
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            addChip(input.value);
                            input.value = '';
                            hideList();
                        }
                    });
                }
                function getConditionFromRow(row) {
                    var typeEl = row.querySelector('.cond-type');
                    var includeEl = row.querySelector('.cond-include-exclude');
                    if (!typeEl || !includeEl) return null;
                    var type = typeEl.value;
                    if (!type) return null;
                    var cond = { type: type, include: includeEl.value !== 'exclude' };
                    var fields = row.querySelectorAll('.cond-fields [data-param]');
                    fields.forEach(function(el) {
                        var param = el.getAttribute('data-param');
                        var val;
                        if (el.type === 'number') {
                            if (el.value === '') val = null;
                            else val = (param === 'min_quantity' || param === 'order_count_min' || param === 'order_count_max' || param === 'last_days') ? parseInt(el.value, 10) : parseFloat(el.value);
                        } else if (el.type === 'radio') {
                            if (el.checked) val = el.value;
                            else return;
                        } else {
                            val = el.value.trim();
                        }
                        if (val !== '' && val !== null && val !== undefined) cond[param] = val;
                    });
                    if ((type === 'billing_address' || type === 'shipping_address' || type === 'country_related') && cond.country) return cond;
                    if (type === 'purchase_product') {
                        var chipSkus = [];
                        row.querySelectorAll('.cond-sku-chip').forEach(function(el) { var s = (el.getAttribute('data-sku') || '').trim(); if (s) chipSkus.push(s); });
                        var inputSku = (row.querySelector('.cond-sku') && row.querySelector('.cond-sku').value || '').trim();
                        if (inputSku) chipSkus.push(inputSku);
                        cond.skus = chipSkus.filter(function(s, i, a) { return a.indexOf(s) === i; });
                        if (cond.skus.length) return cond;
                        return null;
                    }
                    if (type === 'purchase_spend' && (cond.ltv_min != null || cond.ltv_max != null)) return cond;
                    if (type === 'purchase_orders' && (cond.order_count_min != null || cond.order_count_max != null)) return cond;
                    if (type === 'purchase_last_order' && cond.date) { if (!cond.when) cond.when = row.querySelector('.cond-when:checked') ? row.querySelector('.cond-when:checked').value : 'before'; return cond; }
                    if (type === 'funnel' && cond.funnel_slug) return cond;
                    return null;
                }
                function getDefinition() {
                    var conditions = [];
                    var rows = document.querySelectorAll('#hp-gmc-audience-conditions .hp-gmc-condition');
                    rows.forEach(function(row, idx) {
                        var c = getConditionFromRow(row);
                        if (c) {
                            if (idx > 0) {
                                var sel = row.querySelector('.cond-combine');
                                c.combine_with_previous = (sel && sel.value) ? sel.value : 'and';
                            }
                            conditions.push(c);
                        }
                    });
                    return { conditions: conditions };
                }
                function setDefinition(def) {
                    var cont = document.getElementById('hp-gmc-audience-conditions');
                    cont.innerHTML = '';
                    var defaultLogic = (def.logic === 'or' || def.logic === 'and') ? def.logic : 'and';
                    (def.conditions || []).forEach(function(c, i) {
                        if (i > 0 && (c.combine_with_previous === undefined || c.combine_with_previous === null)) c.combine_with_previous = defaultLogic;
                        addConditionRow(c);
                    });
                }
                function addConditionRow(c) {
                    c = c || {};
                    var type = c.type || 'billing_address';
                    var includeVal = (c.include === false) ? 'exclude' : 'include';
                    var tbody = document.getElementById('hp-gmc-audience-conditions');
                    var isFirst = tbody.children.length === 0;
                    var combineVal = (c.combine_with_previous === 'or') ? 'or' : 'and';
                    var combineCell = isFirst
                        ? '<td class="cond-combine-cell" style="vertical-align:middle;"><span class="description">—</span></td>'
                        : '<td class="cond-combine-cell"><select class="cond-combine" aria-label="Combine with previous"><option value="and"' + (combineVal === 'and' ? ' selected' : '') + '>AND</option><option value="or"' + (combineVal === 'or' ? ' selected' : '') + '>OR</option></select></td>';
                    var row = document.createElement('tr');
                    row.className = 'hp-gmc-condition';
                    var typeOpts = Object.keys(conditionTypeLabels).map(function(t) {
                        return '<option value="' + t + '"' + (t === type ? ' selected' : '') + '>' + conditionTypeLabels[t] + '</option>';
                    }).join('');
                    row.innerHTML =
                        '<td class="check-column"><input type="checkbox" class="cond-delete" aria-label="Select for deletion"></td>' +
                        combineCell +
                        '<td><select class="cond-type">' + typeOpts + '</select></td>' +
                        '<td><span class="cond-fields">' + buildConditionFields(type, c) + '</span></td>' +
                        '<td><select class="cond-include-exclude"><option value="include"' + (includeVal === 'include' ? ' selected' : '') + '>Include</option><option value="exclude"' + (includeVal === 'exclude' ? ' selected' : '') + '>Exclude</option></select></td>' +
                        '<td><button type="button" class="button button-small cond-remove">Remove</button></td>';
                    var tbody = document.getElementById('hp-gmc-audience-conditions');
                    tbody.appendChild(row);
                    if (type === 'purchase_product') initSkuAutocomplete(row);
                    row.querySelector('.cond-type').addEventListener('change', function() {
                        var newType = this.value;
                        row.querySelector('.cond-fields').innerHTML = buildConditionFields(newType, {});
                        if (newType === 'purchase_product') initSkuAutocomplete(row);
                    });
                    row.querySelector('.cond-remove').addEventListener('click', function() {
                        row.remove();
                        updateCombineColumn();
                    });
                }
                function updateCombineColumn() {
                    var rows = document.querySelectorAll('#hp-gmc-audience-conditions .hp-gmc-condition');
                    rows.forEach(function(r, idx) {
                        var cell = r.querySelector('.cond-combine-cell');
                        if (!cell) return;
                        var isFirst = (idx === 0);
                        var currentSelect = cell.querySelector('.cond-combine');
                        var currentSpan = cell.querySelector('.description');
                        if (isFirst) {
                            if (currentSelect) { currentSelect.remove(); cell.appendChild(document.createElement('span')).className = 'description'; cell.querySelector('.description').textContent = '—'; }
                            else if (currentSpan) currentSpan.textContent = '—';
                        } else {
                            if (currentSpan) currentSpan.remove();
                            if (!currentSelect) {
                                var sel = document.createElement('select');
                                sel.className = 'cond-combine';
                                sel.setAttribute('aria-label', 'Combine with previous');
                                sel.innerHTML = '<option value="and">AND</option><option value="or">OR</option>';
                                cell.appendChild(sel);
                            }
                        }
                    });
                }
                document.getElementById('hp-gmc-audience-add-condition').addEventListener('click', function() { addConditionRow({}); });
                document.getElementById('hp-gmc-audience-delete-selected').addEventListener('click', function() {
                    document.querySelectorAll('#hp-gmc-audience-conditions .cond-delete:checked').forEach(function(cb) {
                        var tr = cb.closest('tr');
                        if (tr) tr.remove();
                    });
                    updateCombineColumn();
                });
                function applySegmentToForm(seg) {
                    if (!seg) return;
                    currentEditId = seg.id ? parseInt(seg.id, 10) : 0;
                    var nameEl = document.getElementById('hp-gmc-audience-save-name');
                    if (nameEl) nameEl.value = seg.name || '';
                    var editIdInput = document.getElementById('hp-gmc-audience-edit-id');
                    if (currentEditId && editIdInput) editIdInput.value = currentEditId;
                    if (currentEditId && !editIdInput) {
                        var p = document.getElementById('hp-gmc-audience-save-name');
                        if (p && p.parentNode) {
                            var hid = document.createElement('input');
                            hid.type = 'hidden';
                            hid.id = 'hp-gmc-audience-edit-id';
                            hid.value = currentEditId;
                            p.parentNode.appendChild(hid);
                        }
                    }
                    if (!currentEditId && editIdInput) editIdInput.remove();
                    var saveBtn = document.getElementById('hp-gmc-audience-save-as');
                    if (saveBtn) saveBtn.textContent = currentEditId ? '<?php echo esc_js(__('Update', 'hp-gmc-manager')); ?>' : '<?php echo esc_js(__('Save as', 'hp-gmc-manager')); ?>';
                }
                (function applyInitialDefinition() {
                    var match = location.search.match(/[?&]edit=(\d+)/);
                    var urlEditId = match ? parseInt(match[1], 10) : 0;

                    if (urlEditId && editId === urlEditId && editDefinition) {
                        setDefinition(editDefinition);
                        currentEditId = editId;
                        var nameEl = document.getElementById('hp-gmc-audience-save-name');
                        applySegmentToForm({ id: editId, name: nameEl ? nameEl.value : '' });
                    } else if (urlEditId) {
                        addConditionRow({});
                        var fetchDone = false;
                        var editFetchTimeout = setTimeout(function() { fetchDone = true; }, 5000);
                        fetch(restBase + '/' + urlEditId, { headers: { 'X-WP-Nonce': nonce } })
                            .then(function(r) {
                                if (fetchDone) return null;
                                if (!r.ok) { fetchDone = true; clearTimeout(editFetchTimeout); return null; }
                                return r.json();
                            })
                            .then(function(seg) {
                                if (fetchDone) return;
                                fetchDone = true;
                                clearTimeout(editFetchTimeout);
                                if (!seg || !seg.filter_definition) { return; }
                                if (seg.code && seg.message) { return; }
                                var def;
                                try { def = typeof seg.filter_definition === 'string' ? JSON.parse(seg.filter_definition) : seg.filter_definition; } catch (e) { return; }
                                if (def && (def.conditions || def.logic)) { setDefinition(def); applySegmentToForm(seg); }
                            })
                            .catch(function() {
                                if (!fetchDone) { fetchDone = true; clearTimeout(editFetchTimeout); }
                            });
                    } else if (editDefinition) {
                        setDefinition(editDefinition);
                    } else {
                        addConditionRow({});
                    }
                })();
                document.querySelector('.hp-gmc-audiences').addEventListener('click', function(e) {
                    var editBtn = e.target && e.target.closest && e.target.closest('.hp-gmc-audience-edit');
                    if (!editBtn) return;
                    e.preventDefault();
                    var id = editBtn.getAttribute('data-id');
                    if (!id) return;
                    editBtn.disabled = true;
                    fetch(restBase + '/' + id, { headers: { 'X-WP-Nonce': nonce } })
                        .then(function(r) { return r.ok ? r.json() : Promise.reject(new Error('Failed to load')); })
                        .then(function(seg) {
                            if (!seg || !seg.filter_definition) { editBtn.disabled = false; return; }
                            var def;
                            try { def = typeof seg.filter_definition === 'string' ? JSON.parse(seg.filter_definition) : seg.filter_definition; } catch (err) { editBtn.disabled = false; return; }
                            if (def && (def.conditions || def.logic)) {
                                setDefinition(def);
                                applySegmentToForm(seg);
                                var url = new URL(location.href);
                                url.searchParams.set('edit', id);
                                url.hash = 'audiences';
                                if (typeof history !== 'undefined' && history.replaceState) history.replaceState(null, '', url.pathname + url.search + (url.hash || ''));
                            }
                            editBtn.disabled = false;
                        })
                        .catch(function() { editBtn.disabled = false; });
                });
                document.querySelectorAll('.hp-gmc-audience-template').forEach(function(btn) {
                    btn.addEventListener('click', function() { setDefinition(templates[this.dataset.template] || {}); });
                });
                function startProgressPolling(progressKey, updateFn) {
                    function poll() {
                        fetch(restBase + '/run-progress', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                            body: JSON.stringify({ progress_key: progressKey }),
                            cache: 'no-store'
                        })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d && (d.total > 0 || d.current > 0)) {
                                    if (typeof console !== 'undefined' && console.log) console.log('hp_gmc_progress', d.current, d.total);
                                    updateFn(d.current || 0, d.total || 0);
                                }
                            })
                            .catch(function(err) { if (typeof console !== 'undefined' && console.warn) console.warn('hp_gmc_progress poll failed', err); });
                    }
                    var firstId = setTimeout(poll, 400);
                    var t = setInterval(poll, 1500);
                    return function() { clearTimeout(firstId); clearInterval(t); };
                }
                function renderProgress(container, current, total) {
                    if (!container) return;
                    var textEl = container.querySelector && container.querySelector('.hp-gmc-audience-run-status-text');
                    var barWrap = container.querySelector && container.querySelector('.hp-gmc-audience-run-progress-bar');
                    var barInner = barWrap && barWrap.querySelector('span');
                    if (total > 0 && textEl) {
                        textEl.textContent = '<?php echo esc_js(__('Processing', 'hp-gmc-manager')); ?> ' + current + ' <?php echo esc_js(__('of', 'hp-gmc-manager')); ?> ' + total;
                        if (barWrap) { barWrap.style.display = 'inline-block'; }
                        if (barInner) { barInner.style.width = (total ? Math.min(100, (current / total) * 100) : 0) + '%'; }
                    }
                }
                document.getElementById('hp-gmc-audience-save-as').addEventListener('click', function() {
                    var name = document.getElementById('hp-gmc-audience-save-name').value.trim();
                    if (!name) { alert('Enter a segment name'); return; }
                    var payload = { name: name, filter_definition: JSON.stringify(getDefinition()) };
                    var url = restBase, method = 'POST';
                    if (currentEditId) { url = restBase + '/' + currentEditId; method = 'PUT'; }
                    var btn = this;
                    btn.disabled = true;
                    fetch(url, { method: method, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify(payload) })
                        .then(function(r) {
                            return r.text().then(function(text) {
                                var data;
                                try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { message: text || 'Invalid response' }; }
                                if (!r.ok) return Promise.reject(new Error(data.message || data.code || 'Save failed'));
                                return data;
                            });
                        })
                        .then(function(data) {
                            if (data.id || data.name) {
                                var notice = document.createElement('div');
                                notice.className = 'notice notice-success is-dismissible';
                                notice.style.marginTop = '8px';
                                notice.innerHTML = '<p><?php echo esc_js(__('Segment saved. Refreshing list…', 'hp-gmc-manager')); ?></p>';
                                var wrap = document.querySelector('.hp-gmc-audiences');
                                if (wrap) wrap.insertBefore(notice, wrap.firstChild);
                                location.reload();
                            } else {
                                btn.disabled = false;
                                alert(data.message || 'Failed');
                            }
                        })
                        .catch(function(e) {
                            btn.disabled = false;
                            alert(e.message || 'Failed');
                        });
                });
                document.getElementById('hp-gmc-audience-create-new').addEventListener('click', function() {
                    currentEditId = 0;
                    var editIdInput = document.getElementById('hp-gmc-audience-edit-id');
                    if (editIdInput) editIdInput.remove();
                    var nameEl = document.getElementById('hp-gmc-audience-save-name');
                    if (nameEl) nameEl.value = '';
                    var saveBtn = document.getElementById('hp-gmc-audience-save-as');
                    if (saveBtn) saveBtn.textContent = '<?php echo esc_js(__('Save as', 'hp-gmc-manager')); ?>';
                    setDefinition({ logic: 'and', conditions: [] });
                    addConditionRow({});
                    if (typeof history !== 'undefined' && history.replaceState) {
                        var q = new URLSearchParams(location.search);
                        q.delete('edit');
                        var newSearch = q.toString();
                        history.replaceState(null, '', location.pathname + (newSearch ? '?' + newSearch : ''));
                    }
                });
                function resetRunUI(wrap) {
                    if (!wrap) return;
                    var runBtn = wrap.querySelector('.hp-gmc-audience-run');
                    var abortBtn = wrap.querySelector('.hp-gmc-audience-abort');
                    var statusEl = wrap.querySelector('.hp-gmc-audience-run-status');
                    if (runBtn) { runBtn.disabled = false; runBtn.style.display = ''; }
                    if (abortBtn) abortBtn.style.display = 'none';
                    if (statusEl) statusEl.style.display = 'none';
                    wrap._stopPolling = null;
                    wrap._progressKey = null;
                }
                document.addEventListener('click', function(e) {
                    var abortBtn = e.target && e.target.classList && e.target.classList.contains('hp-gmc-audience-abort') ? e.target : null;
                    if (!abortBtn) return;
                    var wrap = abortBtn.closest('.hp-gmc-audience-run-wrap');
                    var stopPolling = wrap && wrap._stopPolling;
                    var progressKey = wrap && wrap._progressKey;
                    if (wrap && wrap._bulkResolve) { wrap._bulkResolve(); wrap._bulkResolve = null; }
                    if (stopPolling) stopPolling();
                    if (progressKey) {
                        fetch(restBase + '/run-abort', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify({ progress_key: progressKey }) }).catch(function() {});
                    }
                    resetRunUI(wrap);
                });
                document.querySelectorAll('.hp-gmc-audience-run').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.dataset.id;
                        var runBtn = this;
                        var wrap = runBtn.closest('.hp-gmc-audience-run-wrap');
                        var statusEl = wrap ? wrap.querySelector('.hp-gmc-audience-run-status') : null;
                        var abortBtn = wrap ? wrap.querySelector('.hp-gmc-audience-abort') : null;
                        runBtn.disabled = true;
                        runBtn.style.display = 'none';
                        if (abortBtn) abortBtn.style.display = 'inline-block';
                        if (statusEl) statusEl.style.display = 'inline';
                        var progressKey = 'run_' + Date.now();
                        var batchesPerChunk = 100;
                        var nextChunkIndex = 1;
                        var stopPolling = startProgressPolling(progressKey, function(cur, tot) {
                            renderProgress(statusEl, cur, tot);
                            if (tot > 0 && cur >= tot) {
                                stopPolling();
                                wrap._stopPolling = null;
                                wrap._progressKey = null;
                                setTimeout(function() { location.reload(); }, 2000);
                                return;
                            }
                            var threshold = nextChunkIndex * batchesPerChunk;
                            if (tot > 0 && cur >= threshold) {
                                var idx = nextChunkIndex++;
                                fetch(restBase + '/run-continue', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify({ segment_id: id, progress_key: progressKey, chunk_index: idx }) })
                                    .then(function(r) { return r.json(); })
                                    .then(function(data) {
                                        if (data.done) {
                                            stopPolling();
                                            wrap._stopPolling = null;
                                            wrap._progressKey = null;
                                            setTimeout(function() { location.reload(); }, 500);
                                        }
                                    })
                                    .catch(function() {});
                            }
                        });
                        wrap._stopPolling = stopPolling;
                        wrap._progressKey = progressKey;
                        var runPayload = { progress_key: progressKey };
                        fetch(restBase + '/run-start', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify({ progress_key: progressKey }) })
                            .then(function(r) { var p = r.ok ? r.json() : Promise.resolve({}); return p.then(function(d) { if (statusEl && d && d.total > 0) renderProgress(statusEl, 0, d.total); if (d && d.batches_per_chunk > 0) batchesPerChunk = d.batches_per_chunk; return fetch(restBase + '/' + id + '/run', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify(runPayload) }); }); })
                            .then(function(r) {
                                return r.text().then(function(text) {
                                    var data;
                                    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { message: text || 'Invalid response' }; }
                                    if (r.status === 202) return data;
                                    if (!r.ok) return Promise.reject(new Error(data.message || data.code || 'Run failed'));
                                    return data;
                                });
                            })
                            .then(function(data) {
                                if (data.status === 'accepted') return;
                                stopPolling();
                                wrap._stopPolling = null;
                                wrap._progressKey = null;
                                if (data.count !== undefined) {
                                    location.reload();
                                } else {
                                    resetRunUI(wrap);
                                    alert(data.message || 'Error');
                                }
                            })
                            .catch(function(e) {
                                stopPolling();
                                resetRunUI(wrap);
                                alert(e.message || 'Error');
                            });
                    });
                });
                document.querySelectorAll('.hp-gmc-audience-duplicate').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        fetch(restBase + '/' + this.dataset.id + '/duplicate', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify({}) })
                            .then(function(r) { return r.json(); })
                            .then(function(data) { if (data.id) location.reload(); else alert(data.message || 'Failed'); })
                                .catch(function() { alert('Failed'); });
                    });
                });
                document.querySelectorAll('.hp-gmc-audience-export').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.dataset.id;
                        fetch(restBase + '/' + id + '/export-csv', { headers: { 'X-WP-Nonce': nonce } })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.csv !== undefined) {
                                    var a = document.createElement('a');
                                    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(data.csv);
                                    a.download = data.filename || 'segment.csv';
                                    a.click();
                                } else { alert(data.message || 'Failed'); }
                            })
                            .catch(function() { alert('Error'); });
                    });
                });
                document.querySelectorAll('.hp-gmc-audience-delete').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.dataset.id;
                        if (!confirm('Delete this segment? This cannot be undone.')) return;
                        fetch(restBase + '/' + id, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.deleted) location.reload();
                                else alert(data.message || 'Delete failed');
                            })
                            .catch(function() { alert('Delete failed'); });
                    });
                });
                (function() {
                    var bulkBar = document.getElementById('hp-gmc-audience-bulk-actions');
                    var selectAll = document.getElementById('hp-gmc-audience-select-all');
                    var rowCbs = document.querySelectorAll('.hp-gmc-audience-row-cb');
                    function getSelectedIds() {
                        return Array.prototype.map.call(document.querySelectorAll('.hp-gmc-audience-row-cb:checked'), function(cb) { return cb.value; });
                    }
                    function updateBulkBar() {
                        var ids = getSelectedIds();
                        if (bulkBar) bulkBar.style.display = ids.length ? 'block' : 'none';
                        if (selectAll) {
                            selectAll.checked = rowCbs.length > 0 && ids.length === rowCbs.length;
                            selectAll.indeterminate = ids.length > 0 && ids.length < rowCbs.length;
                        }
                    }
                    if (selectAll) {
                        selectAll.addEventListener('change', function() {
                            rowCbs.forEach(function(cb) { cb.checked = selectAll.checked; });
                            updateBulkBar();
                        });
                    }
                    rowCbs.forEach(function(cb) {
                        cb.addEventListener('change', updateBulkBar);
                    });
                    if (bulkBar) {
                        bulkBar.querySelector('.hp-gmc-audience-bulk-delete').addEventListener('click', function() {
                            var ids = getSelectedIds();
                            if (!ids.length) return;
                            if (!confirm('Delete ' + ids.length + ' segment(s)? This cannot be undone.')) return;
                            var done = 0;
                            ids.forEach(function(id) {
                                fetch(restBase + '/' + id, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } })
                                    .then(function(r) { return r.json(); })
                                    .then(function(data) { if (data.deleted) { done++; if (done === ids.length) location.reload(); } })
                                    .catch(function() { done++; if (done === ids.length) location.reload(); });
                            });
                        });
                        bulkBar.querySelector('.hp-gmc-audience-bulk-rerun').addEventListener('click', function() {
                            var ids = getSelectedIds();
                            if (!ids.length) return;
                            var btn = this;
                            var table = document.querySelector('.hp-gmc-audiences-table');
                            if (!table) return;
                            btn.disabled = true;
                            function runOneSegment(id) {
                                return new Promise(function(resolveSegment) {
                                    var row = table.querySelector('tr[data-segment-id="' + id + '"]');
                                    if (!row) { resolveSegment(); return; }
                                    var wrap = row.querySelector('.hp-gmc-audience-run-wrap');
                                    var runBtn = wrap ? wrap.querySelector('.hp-gmc-audience-run') : null;
                                    var statusEl = wrap ? wrap.querySelector('.hp-gmc-audience-run-status') : null;
                                    var abortBtn = wrap ? wrap.querySelector('.hp-gmc-audience-abort') : null;
                                    if (!wrap || !runBtn) { resolveSegment(); return; }
                                    runBtn.disabled = true;
                                    runBtn.style.display = 'none';
                                    if (abortBtn) abortBtn.style.display = 'inline-block';
                                    if (statusEl) statusEl.style.display = 'inline';
                                    var progressKey = 'bulk_' + Date.now() + '_' + id;
                                    var batchesPerChunk = 100;
                                    var nextChunkIndex = 1;
                                    function done() {
                                        if (wrap._stopPolling) wrap._stopPolling();
                                        wrap._stopPolling = null;
                                        wrap._progressKey = null;
                                        wrap._bulkResolve = null;
                                        resetRunUI(wrap);
                                        resolveSegment();
                                    }
                                    wrap._bulkResolve = resolveSegment;
                                    var stopPolling = startProgressPolling(progressKey, function(cur, tot) {
                                        renderProgress(statusEl, cur, tot);
                                        if (tot > 0 && cur >= tot) { done(); return; }
                                        var threshold = nextChunkIndex * batchesPerChunk;
                                        if (tot > 0 && cur >= threshold) {
                                            var idx = nextChunkIndex++;
                                            fetch(restBase + '/run-continue', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify({ segment_id: id, progress_key: progressKey, chunk_index: idx }) })
                                                .then(function(r) { return r.json(); })
                                                .then(function(data) { if (data && data.done) done(); })
                                                .catch(function() {});
                                        }
                                    });
                                    wrap._stopPolling = stopPolling;
                                    wrap._progressKey = progressKey;
                                    var runPayload = { progress_key: progressKey };
                                    fetch(restBase + '/run-start', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify({ progress_key: progressKey }) })
                                        .then(function(r) { var p = r.ok ? r.json() : Promise.resolve({}); return p.then(function(d) { if (statusEl && d && d.total > 0) renderProgress(statusEl, 0, d.total); if (d && d.batches_per_chunk > 0) batchesPerChunk = d.batches_per_chunk; return fetch(restBase + '/' + id + '/run', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify(runPayload) }); }); })
                                        .then(function(r) {
                                            return r.text().then(function(text) {
                                                var data;
                                                try { data = text ? JSON.parse(text) : {}; } catch (e) { data = {}; }
                                                if (r.status === 202) return data;
                                                if (!r.ok) return Promise.reject(new Error(data.message || data.code || 'Run failed'));
                                                return data;
                                            });
                                        })
                                        .then(function(data) {
                                            if (data && data.status === 'accepted') return;
                                            done();
                                            if (data && data.count !== undefined) return;
                                            alert(data && data.message ? data.message : 'Error');
                                        })
                                        .catch(function(e) { done(); alert(e.message || 'Error'); });
                                });
                            }
                            function runNext(idx) {
                                if (idx >= ids.length) { btn.disabled = false; setTimeout(function() { location.reload(); }, 800); return; }
                                runOneSegment(ids[idx]).then(function() { runNext(idx + 1); });
                            }
                            runNext(0);
                        });
                        bulkBar.querySelector('.hp-gmc-audience-bulk-export').addEventListener('click', function() {
                            var ids = getSelectedIds();
                            if (!ids.length) return;
                            var delay = 0;
                            ids.forEach(function(id) {
                                setTimeout(function() {
                                    fetch(restBase + '/' + id + '/export-csv', { headers: { 'X-WP-Nonce': nonce } })
                                        .then(function(r) { return r.json(); })
                                        .then(function(data) {
                                            if (data.csv !== undefined) {
                                                var a = document.createElement('a');
                                                a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(data.csv);
                                                a.download = data.filename || 'segment-' + id + '.csv';
                                                a.click();
                                            }
                                        })
                                        .catch(function() {});
                                }, delay);
                                delay += 400;
                            });
                        });
                    }
                })();
                document.querySelectorAll('.hp-gmc-audience-upload').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.dataset.id;
                        var count = parseInt(this.dataset.count, 10) || 0;
                        if (count > 0 && count < 1000 && !confirm('This segment has fewer than 1000 users. Google typically recommends at least 1000 for Customer Match. Continue?')) {
                            return;
                        }
                        var msgEl = document.getElementById('hp-gmc-audience-upload-message');
                        if (msgEl) { msgEl.style.display = 'none'; msgEl.textContent = ''; }
                        btn.disabled = true;
                        fetch(restBase + '/' + id + '/upload', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                            body: JSON.stringify({ append: false })
                        })
                            .then(function(r) {
                                return r.text().then(function(text) {
                                    var data;
                                    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { message: text || 'No response' }; }
                                    return { ok: r.ok, status: r.status, data: data };
                                });
                            })
                            .then(function(res) {
                                if (res.ok && res.data.success) {
                                    var statusCell = document.querySelector('.hp-gmc-audience-upload-status[data-segment-id="' + id + '"]');
                                    if (statusCell) {
                                        statusCell.textContent = 'Pending (job submitted). Reload page to see status.';
                                    }
                                    if (msgEl) { msgEl.style.display = 'none'; msgEl.textContent = ''; }
                                } else {
                                    var msg = res.data.message || res.data.error || res.data.code || 'Upload failed';
                                    if (res.status === 404) {
                                        msg = 'Endpoint not found (404). Ensure GMC Manager is updated and REST API is available.';
                                    } else if (res.status === 403) {
                                        msg = msg + ' (Upload may be disabled in Settings > Schema & Audiences.)';
                                    } else {
                                        msg = 'HTTP ' + res.status + ': ' + msg;
                                    }
                                    if (msgEl) {
                                        msgEl.textContent = msg;
                                        msgEl.style.display = 'block';
                                        msgEl.style.borderLeftColor = '#d63638';
                                        msgEl.style.background = '#fcf0f1';
                                    }
                                }
                            })
                            .catch(function(err) {
                                if (msgEl) {
                                    msgEl.textContent = 'Network or server error. Check the Network tab for the request to ' + restBase + '/' + id + '/upload (e.g. 500 = server error).';
                                    msgEl.style.display = 'block';
                                    msgEl.style.borderLeftColor = '#d63638';
                                    msgEl.style.background = '#fcf0f1';
                                }
                            })
                            .finally(function() { btn.disabled = false; });
                    });
                });
            })();
            </script>
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
     * Render the funnels tab.
     */
    private static function render_funnels_tab(): void
    {
        $funnelFeedAvailable = \HP_GMC\Services\FunnelDataFeed::isAvailable();
        $feedStatus = \HP_GMC\Services\FunnelDataFeed::getStatus();
        $funnels = $funnelFeedAvailable ? \HP_GMC\Services\FunnelDataFeed::getAllFunnels() : [];
        ?>
        <div class="hp-gmc-funnels">
            <div class="hp-gmc-section-header">
                <h2><?php esc_html_e('Funnel GMC Integration', 'hp-gmc-manager'); ?></h2>
            </div>

            <?php if (!$funnelFeedAvailable): ?>
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php esc_html_e('HP-Funnels Plugin Required', 'hp-gmc-manager'); ?></strong><br>
                    <?php esc_html_e('The Funnel GMC integration requires the HP-Funnels plugin (or legacy HP-React-Widgets) to be installed and activated.', 'hp-gmc-manager'); ?>
                </p>
            </div>
            <?php else: ?>

            <!-- Summary Cards -->
            <div class="hp-gmc-cards" style="margin-bottom: 20px;">
                <div class="hp-gmc-card">
                    <div class="hp-gmc-card-header">
                        <span class="dashicons dashicons-megaphone"></span>
                        <?php esc_html_e('GMC-Enabled Funnels', 'hp-gmc-manager'); ?>
                    </div>
                    <div class="hp-gmc-card-body">
                        <div class="hp-gmc-stat-value"><?php echo esc_html(count($funnels)); ?></div>
                        <div class="hp-gmc-stat-label"><?php esc_html_e('Active for advertising', 'hp-gmc-manager'); ?></div>
                    </div>
                </div>

                <div class="hp-gmc-card">
                    <div class="hp-gmc-card-header">
                        <span class="dashicons dashicons-clock"></span>
                        <?php esc_html_e('Last Generated', 'hp-gmc-manager'); ?>
                    </div>
                    <div class="hp-gmc-card-body">
                        <div class="hp-gmc-stat-value">
                            <?php 
                            if ($feedStatus['last_generated']) {
                                echo esc_html(human_time_diff(strtotime($feedStatus['last_generated']), current_time('timestamp')) . ' ago');
                            } else {
                                esc_html_e('Never', 'hp-gmc-manager');
                            }
                            ?>
                        </div>
                        <div class="hp-gmc-stat-label"><?php esc_html_e('Feed regeneration', 'hp-gmc-manager'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Feed URLs -->
            <div class="hp-gmc-primary-feed-card" style="margin-bottom: 20px;">
                <div class="hp-gmc-card-header">
                    <span class="dashicons dashicons-rss"></span>
                    <?php esc_html_e('Funnel Data Source', 'hp-gmc-manager'); ?>
                </div>
                <div class="hp-gmc-card-body">
                    <p><?php esc_html_e('Register this feed in Google Merchant Center as a supplemental or primary data source for funnel products.', 'hp-gmc-manager'); ?></p>
                    
                    <table class="form-table" style="margin-bottom: 10px;">
                        <tr>
                            <th scope="row"><?php esc_html_e('TSV Feed URL', 'hp-gmc-manager'); ?></th>
                            <td>
                                <code id="hp-gmc-funnel-feed-tsv-url"><?php echo esc_url($feedStatus['feed_urls']['tsv'] ?? ''); ?></code>
                                <button type="button" class="button button-small hp-gmc-copy-url" data-target="hp-gmc-funnel-feed-tsv-url">
                                    <?php esc_html_e('Copy', 'hp-gmc-manager'); ?>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('CSV Feed URL', 'hp-gmc-manager'); ?></th>
                            <td>
                                <code id="hp-gmc-funnel-feed-csv-url"><?php echo esc_url($feedStatus['feed_urls']['csv'] ?? ''); ?></code>
                                <button type="button" class="button button-small hp-gmc-copy-url" data-target="hp-gmc-funnel-feed-csv-url">
                                    <?php esc_html_e('Copy', 'hp-gmc-manager'); ?>
                                </button>
                            </td>
                        </tr>
                    </table>

                    <button type="button" class="button button-primary" id="hp-gmc-regenerate-funnel-feed">
                        <?php esc_html_e('Regenerate Funnel Feed', 'hp-gmc-manager'); ?>
                    </button>
                </div>
            </div>

            <!-- Funnels List -->
            <h3><?php esc_html_e('GMC-Enabled Funnels', 'hp-gmc-manager'); ?></h3>
            
            <?php if (empty($funnels)): ?>
            <p><?php esc_html_e('No funnels are enabled for GMC sync. Enable GMC sync in the funnel edit screen.', 'hp-gmc-manager'); ?></p>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Funnel', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Price', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Brand', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Availability', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Category', 'hp-gmc-manager'); ?></th>
                        <th><?php esc_html_e('Actions', 'hp-gmc-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($funnels as $funnel): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($funnel['title']); ?></strong>
                            <br>
                            <small><code><?php echo esc_html($funnel['slug']); ?></code></small>
                        </td>
                        <td>$<?php echo esc_html(number_format($funnel['price'], 2)); ?></td>
                        <td><?php echo esc_html($funnel['brand']); ?></td>
                        <td>
                            <?php
                            $availColors = [
                                'in_stock' => '#00a32a',
                                'out_of_stock' => '#d63638',
                                'preorder' => '#dba617',
                            ];
                            $avail = $funnel['availability'];
                            $color = $availColors[$avail] ?? '#666';
                            ?>
                            <span style="color: <?php echo esc_attr($color); ?>;">
                                <?php echo esc_html(ucwords(str_replace('_', ' ', $avail))); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($funnel['google_product_category']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($funnel['funnel_id'])); ?>" class="button button-small">
                                <?php esc_html_e('Edit', 'hp-gmc-manager'); ?>
                            </a>
                            <a href="<?php echo esc_url($funnel['link']); ?>" class="button button-small" target="_blank">
                                <?php esc_html_e('View', 'hp-gmc-manager'); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php endif; ?>
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
