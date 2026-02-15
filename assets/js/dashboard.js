/**
 * HP GMC Manager Dashboard JavaScript
 */
(function($) {
    'use strict';

    const GMCDashboard = {
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.showEnvironmentWarning();
            this.initSubTabsClientSide(); // Initialize client-side sub-tab handling
        },

        initTabs: function() {
            // Handle browser back/forward
            $(window).on('hashchange', this.handleHashChange.bind(this));
            
            // Check initial hash
            const hash = window.location.hash.replace('#', '') || 'overview';
            this.switchTab(hash);
        },

        handleHashChange: function() {
            const hash = window.location.hash.replace('#', '') || 'overview';
            this.switchTab(hash);
        },

        switchTab: function(tabId) {
            // Update nav tabs
            $('.hp-gmc-tabs .nav-tab').removeClass('nav-tab-active');
            $('.hp-gmc-tabs .nav-tab[data-tab="' + tabId + '"]').addClass('nav-tab-active');
            
            // Update panels
            $('.hp-gmc-tab-panel').removeClass('active');
            $('#tab-' + tabId).addClass('active');
        },

        bindEvents: function() {
            // Tab clicks
            $('.hp-gmc-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();
                const tabId = $(this).data('tab');
                window.location.hash = tabId;
            });

            // Sync now button
            $('#hp-gmc-sync-now').on('click', this.syncNow.bind(this));
            
            // Primary feed regenerate button (Feeds tab only)
            $('#hp-gmc-regenerate-primary-feed-feeds-tab').on('click', this.regeneratePrimaryFeed.bind(this));

            // Funnel feed regenerate button
            $('#hp-gmc-regenerate-funnel-feed').on('click', this.regenerateFunnelFeed.bind(this));

            // Tool toggles
            $(document).on('change', '.hp-gmc-tool-toggle', this.toggleTool.bind(this));

            // Enable/Disable all
            $('#hp-gmc-enable-all').on('click', () => this.setAllTools(true));
            $('#hp-gmc-disable-all').on('click', () => this.setAllTools(false));

            // Presets
            $('#hp-gmc-preset').on('change', this.applyPreset.bind(this));

            // Clear log
            $('#hp-gmc-clear-log').on('click', this.clearLog.bind(this));

            // Export log
            $('#hp-gmc-export-log').on('click', this.exportLog.bind(this));

            // Test connection
            $('#hp-gmc-test-connection').on('click', this.testConnection.bind(this));

            // Refresh buttons
            $('#hp-gmc-refresh-shipping').on('click', this.refreshShipping.bind(this));
            $('#hp-gmc-refresh-issues').on('click', () => location.reload());
            $('#hp-gmc-refresh-log').on('click', () => location.reload());
            
            // Issues filters
            $('#hp-gmc-filter-status, #hp-gmc-filter-brand, #hp-gmc-filter-issue').on('change', this.filterIssues.bind(this));
            $('#hp-gmc-clear-filters').on('click', this.clearFilters.bind(this));
        },
        
        filterIssues: function() {
            const statusFilter = $('#hp-gmc-filter-status').val();
            const brandFilter = $('#hp-gmc-filter-brand').val();
            const issueFilter = $('#hp-gmc-filter-issue').val();
            
            let visibleCount = 0;
            
            $('#hp-gmc-issues-table tbody tr').each(function() {
                const $row = $(this);
                const rowStatus = $row.data('status');
                const rowBrand = $row.data('brand');
                const rowIssues = ($row.data('issues') || '').toString();
                
                let show = true;
                
                // Filter by status
                if (statusFilter && rowStatus !== statusFilter) {
                    show = false;
                }
                
                // Filter by brand
                if (brandFilter && rowBrand !== brandFilter) {
                    show = false;
                }
                
                // Filter by issue type
                if (issueFilter && rowIssues.indexOf(issueFilter) === -1) {
                    show = false;
                }
                
                if (show) {
                    $row.removeClass('hp-gmc-hidden');
                    visibleCount++;
                } else {
                    $row.addClass('hp-gmc-hidden');
                }
            });
            
            // Update count
            $('.hp-gmc-filter-count').text('Showing ' + visibleCount + ' products');
        },
        
        clearFilters: function() {
            $('#hp-gmc-filter-status, #hp-gmc-filter-brand, #hp-gmc-filter-issue').val('');
            $('#hp-gmc-issues-table tbody tr').removeClass('hp-gmc-hidden');
            
            const totalCount = $('#hp-gmc-issues-table tbody tr').length;
            $('.hp-gmc-filter-count').text('Showing ' + totalCount + ' products');
        },

        showEnvironmentWarning: function() {
            if (hpGmcData.isDryRun) {
                console.log('HP GMC Manager: Running in DRY RUN mode. Actions will be logged but not executed.');
            }
        },
        
        // Client-side sub-tab handling to prevent page refresh
        initSubTabsClientSide: function() {
            const self = this;
            
            // Cache for sub-tab content to avoid redundant AJAX calls
            this.subtabCache = {};
            
            // Handle sub-tab clicks with JavaScript instead of page navigation
            $(document).on('click', '.hp-gmc-subtab', function(e) {
                e.preventDefault();
                
                const $tab = $(this);
                const subtab = $tab.data('subtab') || 'fixable';
                
                // Update sub-tab UI immediately (no waiting for AJAX)
                $('.hp-gmc-subtab').removeClass('active');
                $tab.addClass('active');
                
                // Ensure we're on the issues tab
                if (window.location.hash !== '#issues') {
                    window.location.hash = 'issues';
                }
                
                // Check cache first
                if (self.subtabCache[subtab]) {
                    const $content = $('.hp-gmc-issues-content');
                    $content.html(self.subtabCache[subtab]);
                    $content.css('opacity', '1'); // Ensure opacity is reset
                    if (window.GMCDashboard && typeof window.GMCDashboard.initIssuesEvents === 'function') {
                        window.GMCDashboard.initIssuesEvents();
                    }
                    return;
                }
                
                // Load sub-tab content via AJAX
                self.loadSubTabContent(subtab);
            });
            
            // Cache current sub-tab content on initial load
            const currentSubtab = $('.hp-gmc-subtab.active').data('subtab');
            if (currentSubtab) {
                this.subtabCache[currentSubtab] = $('.hp-gmc-issues-content').html();
            }
        },
        
        // Load sub-tab content via AJAX to avoid page refresh
        loadSubTabContent: function(subtab) {
            const self = this;
            const $content = $('.hp-gmc-issues-content');
            
            // Show loading state
            $content.css('opacity', '0.5');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_get_issues_subtab',
                    nonce: hpGmcData.nonce,
                    subtab: subtab
                },
                    success: function(response) {
                    if (response.success && response.data.html) {
                        $content.html(response.data.html);
                        $content.css('opacity', '1'); // Reset opacity immediately after loading
                        // Cache the content for fast switching
                        self.subtabCache = self.subtabCache || {};
                        self.subtabCache[subtab] = response.data.html;
                        // Re-initialize any event handlers for the new content
                        if (window.GMCDashboard && typeof window.GMCDashboard.initIssuesEvents === 'function') {
                            window.GMCDashboard.initIssuesEvents();
                        }
                    } else {
                        // Fallback: reload the page with the subtab parameter (with hash preserved)
                        const url = new URL(window.location.href);
                        url.searchParams.set('issues_tab', subtab);
                        url.hash = 'issues';
                        window.location.href = url.toString();
                    }
                },
                error: function() {
                    // Fallback: reload with proper hash preserved
                    const url = new URL(window.location.href);
                    url.searchParams.set('issues_tab', subtab);
                    url.hash = 'issues';
                    window.location.href = url.toString();
                },
                complete: function() {
                    $content.css('opacity', '1');
                }
            });
        },

        syncNow: function(e) {
            const $btn = $(e.target);
            const originalText = $btn.text();

            $btn.prop('disabled', true).text(hpGmcData.strings.syncing);

            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_sync_now',
                    nonce: hpGmcData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(hpGmcData.strings.error + '\n' + (response.data?.message || 'Unknown error'));
                    }
                },
                error: function() {
                    alert(hpGmcData.strings.error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        regeneratePrimaryFeed: function(e) {
            const $btn = $(e.target);
            const originalText = $btn.text();

            $btn.prop('disabled', true).text('Regenerating...');

            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_regenerate_primary_feed',
                    nonce: hpGmcData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || 'Feed regenerated successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data?.message || 'Failed to regenerate feed'));
                    }
                },
                error: function() {
                    alert('Network error. Please try again.');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        regenerateFunnelFeed: function(e) {
            const $btn = $(e.target);
            const originalText = $btn.text();

            $btn.prop('disabled', true).text('Regenerating...');

            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_regenerate_funnel_feed',
                    nonce: hpGmcData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || 'Funnel feed regenerated successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data?.message || 'Failed to regenerate funnel feed'));
                    }
                },
                error: function() {
                    alert('Network error. Please try again.');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        toggleTool: function(e) {
            const $toggle = $(e.target);
            const toolId = $toggle.data('tool-id');
            const enabled = $toggle.is(':checked');

            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_toggle_tool',
                    nonce: hpGmcData.nonce,
                    tool_id: toolId,
                    enabled: enabled ? 1 : 0
                },
                success: function(response) {
                    if (!response.success) {
                        // Revert on failure
                        $toggle.prop('checked', !enabled);
                        alert(response.data?.message || 'Failed to toggle tool');
                    }
                },
                error: function() {
                    $toggle.prop('checked', !enabled);
                    alert('Network error');
                }
            });
        },

        setAllTools: function(enabled) {
            $('.hp-gmc-tool-toggle').each(function() {
                const $toggle = $(this);
                if ($toggle.is(':checked') !== enabled) {
                    $toggle.prop('checked', enabled).trigger('change');
                }
            });
        },

        applyPreset: function(e) {
            const preset = $(e.target).val();
            if (!preset) return;

            const presets = {
                minimal: ['gmc-dashboard-summary', 'gmc-test-hello'],
                product: ['gmc-dashboard-summary', 'gmc-list-issues', 'gmc-get-product-status', 'gmc-set-exclusion', 'gmc-test-hello'],
                full: null // Enable all
            };

            const enabledTools = presets[preset];

            $('.hp-gmc-tool-toggle').each(function() {
                const $toggle = $(this);
                const toolId = $toggle.data('tool-id');
                const shouldEnable = enabledTools === null || enabledTools.includes(toolId);
                
                if ($toggle.is(':checked') !== shouldEnable) {
                    $toggle.prop('checked', shouldEnable).trigger('change');
                }
            });

            // Reset select
            $(e.target).val('');
        },

        clearLog: function() {
            if (!confirm('Clear all dry run log entries?')) {
                return;
            }

            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_clear_dry_run_log',
                    nonce: hpGmcData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data?.message || 'Failed to clear log');
                    }
                }
            });
        },

        exportLog: function() {
            // Collect log data from table
            const logs = [];
            $('.hp-gmc-dry-run-log table tbody tr').each(function() {
                const $row = $(this);
                logs.push({
                    time: $row.find('td:eq(0)').text(),
                    action: $row.find('td:eq(1)').text(),
                    endpoint: $row.find('td:eq(2)').text(),
                    params: $row.find('pre').text()
                });
            });

            // Download as JSON
            const blob = new Blob([JSON.stringify(logs, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'gmc-dry-run-log-' + new Date().toISOString().slice(0, 10) + '.json';
            a.click();
            URL.revokeObjectURL(url);
        },

        testConnection: function(e) {
            const $btn = $(e.target);
            const $status = $('#hp-gmc-connection-status');
            const originalText = $btn.text();

            $btn.prop('disabled', true).text('Testing...');
            $status.text('');

            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_test_connection',
                    nonce: hpGmcData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                    } else {
                        $status.html('<span style="color: red;">✗ ' + (response.data?.message || 'Connection failed') + '</span>');
                    }
                },
                error: function() {
                    $status.html('<span style="color: red;">✗ Network error</span>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        refreshShipping: function(e) {
            const $btn = $(e.target);
            const $container = $('#hp-gmc-shipping-data');
            const originalText = $btn.text();

            $btn.prop('disabled', true).text('Loading...');

            // This would call the API in a real implementation
            // For now, show a placeholder
            setTimeout(function() {
                if (hpGmcData.isDryRun) {
                    $container.html(
                        '<div class="notice notice-info inline"><p>Mock shipping data (Dry Run mode)</p></div>' +
                        '<table class="wp-list-table widefat fixed striped">' +
                        '<thead><tr><th>Service</th><th>Countries</th><th>Status</th></tr></thead>' +
                        '<tbody>' +
                        '<tr><td>Standard Shipping</td><td>US</td><td><span class="hp-gmc-status hp-gmc-status-approved">Active</span></td></tr>' +
                        '<tr><td>Express Shipping</td><td>US</td><td><span class="hp-gmc-status hp-gmc-status-approved">Active</span></td></tr>' +
                        '</tbody></table>'
                    );
                } else {
                    $container.html('<p>Configure API credentials in Settings to load shipping data.</p>');
                }
                $btn.prop('disabled', false).text(originalText);
            }, 500);
        },

        // ========================================
        // FEED MANAGEMENT
        // ========================================

        initFeedEvents: function() {
            // Create feed modal
            $('#hp-gmc-create-feed').on('click', () => {
                $('#hp-gmc-create-feed-modal').show();
            });
            
            $('.hp-gmc-modal-close').on('click', function() {
                $(this).closest('.hp-gmc-modal').hide();
            });
            
            // Close modal on backdrop click
            $('.hp-gmc-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                    if (this.id === 'hp-gmc-supplemental-url-modal') {
                        location.reload();
                    }
                }
            });
            
            // Create feed submit
            $('#hp-gmc-create-feed-submit').on('click', this.createFeed.bind(this));
            
            // Feed actions
            $(document).on('click', '.hp-gmc-feed-generate', this.generateFeed.bind(this));
            $(document).on('click', '.hp-gmc-feed-upload', this.uploadFeed.bind(this));
            $(document).on('click', '.hp-gmc-feed-check-status', this.checkFeedStatus.bind(this));
            $(document).on('click', '.hp-gmc-feed-debug', this.showFeedDebug.bind(this));
            $(document).on('click', '.hp-gmc-feed-remove-gmc', this.removeFromGMC.bind(this));
            $(document).on('click', '.hp-gmc-feed-delete', this.deleteFeed.bind(this));
            $(document).on('click', '.hp-gmc-remove-product', this.removeProductFromFeed.bind(this));
            $(document).on('click', '.hp-gmc-feed-publish', this.publishFeed.bind(this));
            $(document).on('click', '.hp-gmc-view-pending', this.viewPendingProducts.bind(this));
            $('#hp-gmc-supplemental-url-copy').on('click', this.copySupplementalUrl.bind(this));
            $('#hp-gmc-supplemental-open-gmc').on('click', function() { /* open in new tab via href */ });
            $('.hp-gmc-supplemental-url-close, #hp-gmc-supplemental-url-modal .hp-gmc-modal-close').on('click', function() {
                $('#hp-gmc-supplemental-url-modal').hide();
                location.reload();
            });
            $('#hp-gmc-add-all-pending').on('click', this.addAllPendingProducts.bind(this));
            
            // Bulk actions
            $('#hp-gmc-refresh-all-feeds').on('click', this.refreshAllFeedStatuses.bind(this));
            $('#hp-gmc-select-all-feeds').on('change', this.toggleSelectAllFeeds.bind(this));
            $('#hp-gmc-apply-bulk-action').on('click', this.applyBulkFeedAction.bind(this));
            $('#hp-gmc-download-json-template').on('click', this.downloadJsonTemplate.bind(this));
            $('#hp-gmc-import-feeds-json').on('click', function() { $('#hp-gmc-import-json-file').trigger('click'); });
            $('#hp-gmc-import-json-file').on('change', this.importFeedsFromFile.bind(this));
            
            // Add product
            $('#hp-gmc-add-product-btn').on('click', this.addProductToFeed.bind(this));
            
            // Product search autocomplete
            this.initProductSearch();
        },
        
        initProductSearch: function() {
            const $search = $('#hp-gmc-product-search');
            if (!$search.length) return;
            
            let searchTimeout;
            const self = this;
            
            $search.on('input', function() {
                clearTimeout(searchTimeout);
                const term = $(this).val();
                
                if (term.length < 2) {
                    $('#hp-gmc-search-results').remove();
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    self.searchProducts(term);
                }, 300);
            });
            
            // Hide results on click outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.hp-gmc-add-product-fields').length) {
                    $('#hp-gmc-search-results').remove();
                }
            });
        },
        
        searchProducts: function(term) {
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_search_products',
                    nonce: hpGmcData.nonce,
                    term: term
                },
                success: function(response) {
                    if (response.success && response.data.products.length) {
                        let html = '<div id="hp-gmc-search-results" class="hp-gmc-search-dropdown">';
                        response.data.products.forEach(p => {
                            html += '<div class="hp-gmc-search-item" data-id="' + p.id + '" data-sku="' + p.sku + '">' +
                                    '<strong>' + p.sku + '</strong> - ' + p.name +
                                    '</div>';
                        });
                        html += '</div>';
                        
                        $('#hp-gmc-search-results').remove();
                        $('#hp-gmc-product-search').after(html);
                        
                        // Click handler for results
                        $('.hp-gmc-search-item').on('click', function() {
                            $('#hp-gmc-product-search').val($(this).data('sku'));
                            $('#hp-gmc-product-search').data('product-id', $(this).data('id'));
                            $('#hp-gmc-search-results').remove();
                        });
                    }
                }
            });
        },
        
        createFeed: function() {
            const name = $('#hp-gmc-new-feed-name').val().trim();
            const type = $('#hp-gmc-new-feed-type').val();
            const category = $('#hp-gmc-new-feed-category').val().trim();
            
            if (!name) {
                alert('Please enter a feed name');
                return;
            }
            
            const $btn = $('#hp-gmc-create-feed-submit');
            $btn.prop('disabled', true).text('Creating...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_create_feed',
                    nonce: hpGmcData.nonce,
                    name: name,
                    type: type,
                    category: category
                },
                success: function(response) {
                    if (response.success) {
                        // Redirect to the new feed
                        window.location.href = window.location.pathname + 
                            window.location.search + 
                            '&feed_id=' + response.data.feed_id + 
                            '#feeds';
                    } else {
                        alert(response.data?.message || 'Failed to create feed');
                    }
                },
                error: function() {
                    alert('Network error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Create Feed');
                }
            });
        },
        
        generateFeed: function(e) {
            const feedId = $(e.target).data('feed-id');
            const $btn = $(e.target);
            const originalText = $btn.text();
            
            $btn.prop('disabled', true).text('Generating...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_generate_feed',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId,
                    format: 'tsv'
                },
                success: function(response) {
                    if (response.success) {
                        alert('Feed file generated successfully!\nProducts: ' + response.data.product_count);
                        location.reload();
                    } else {
                        alert(response.data?.error || 'Failed to generate feed');
                    }
                },
                error: function() {
                    alert('Network error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },
        
        uploadFeed: function(e) {
            const feedId = $(e.target).closest('button').data('feed-id') || $(e.target).data('feed-id');
            const $btn = $(e.target).closest('button');
            const originalText = $btn.text();
            const that = this;
            
            $btn.prop('disabled', true).text('Uploading...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_upload_feed',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data?.supplemental_only && response.data?.supplemental_url) {
                            that.showSupplementalUrlModal(response.data.supplemental_url);
                        } else {
                            alert('Feed uploaded to GMC!\n\n' + (response.data?.note || response.data?.message || 'Success'));
                            location.reload();
                        }
                    } else {
                        alert(response.data?.error || 'Failed to upload feed');
                    }
                },
                error: function() {
                    alert('Network error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },
        
        checkFeedStatus: function(e) {
            const feedId = $(e.target).closest('button').data('feed-id') || $(e.target).data('feed-id');
            const $btn = $(e.target).closest('button');
            const originalHtml = $btn.html();
            
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_check_feed_status',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    if (response.success) {
                        // Show status summary before reload
                        const summary = response.data?.status_summary;
                        const rawData = response.data?.data;
                        
                        let msg = 'GMC Status Updated\n\n';
                        
                        if (summary) {
                            msg += 'Processing Status: ' + (summary.processing_status || 'unknown') + '\n';
                            msg += 'Items Total: ' + (summary.items_total || 0) + '\n';
                            msg += 'Items Valid: ' + (summary.items_valid || 0) + '\n';
                            msg += 'Errors: ' + (summary.error_count || 0) + '\n';
                            msg += 'Warnings: ' + (summary.warning_count || 0) + '\n';
                            
                            if (summary.errors && summary.errors.length > 0) {
                                msg += '\nFirst Errors:\n';
                                summary.errors.forEach(function(err, i) {
                                    msg += (i+1) + '. ' + (err.message || JSON.stringify(err)) + '\n';
                                });
                            }
                        } else if (rawData) {
                            msg += 'Raw Response:\n' + JSON.stringify(rawData, null, 2);
                        } else {
                            msg += 'Raw Response:\n' + JSON.stringify(response.data, null, 2);
                        }
                        
                        alert(msg);
                        location.reload();
                    } else {
                        alert('Failed to check status:\n\n' + JSON.stringify(response.data, null, 2));
                    }
                },
                error: function(xhr, status, error) {
                    alert('Network error: ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },

        showFeedDebug: function(e) {
            const feedId = $(e.target).closest('button').data('feed-id') || $(e.target).data('feed-id');
            const $btn = $(e.target).closest('button');
            const originalHtml = $btn.html();
            
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_check_feed_status',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    const rawData = response.data?.data || response.data || response;
                    $('#hp-gmc-feed-debug-content').text(JSON.stringify(rawData, null, 4));
                    $('#hp-gmc-feed-debug-modal').show();
                },
                error: function(xhr, status, error) {
                    alert('Network error: ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },
        
        removeFromGMC: function(e) {
            const $btn = $(e.target).closest('button');
            const feedId = $btn.data('feed-id');
            const feedName = $btn.data('feed-name') || 'this feed';
            
            if (!confirm('Remove "' + feedName + '" from Google Merchant Center?\n\nThe feed will be kept locally and can be re-uploaded later.')) {
                return;
            }
            
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_remove_from_gmc',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data?.message || 'Failed to remove from GMC');
                    }
                },
                error: function() {
                    alert('Network error');
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },
        
        publishFeed: function(e) {
            const feedId = $(e.target).closest('button').data('feed-id') || $(e.target).data('feed-id');
            const $btn = $(e.target).closest('button');
            const originalText = $btn.text();
            const that = this;
            
            $btn.prop('disabled', true).text('Publishing...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_publish_feed',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data?.supplemental_url) {
                            that.showSupplementalUrlModal(response.data.supplemental_url);
                        } else {
                            location.reload();
                        }
                    } else {
                        alert(response.data?.message || 'Failed to publish feed');
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('Network error');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },
        
        showSupplementalUrlModal: function(url) {
            var $modal = $('#hp-gmc-supplemental-url-modal');
            var $input = $('#hp-gmc-supplemental-url-input');
            $input.val(url);
            $modal.show();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    $('#hp-gmc-supplemental-url-copy').text('Copied!');
                    setTimeout(function() {
                        $('#hp-gmc-supplemental-url-copy').text('Copy URL');
                    }, 2000);
                });
            }
        },
        
        copySupplementalUrl: function() {
            var url = $('#hp-gmc-supplemental-url-input').val();
            if (url && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    $('#hp-gmc-supplemental-url-copy').text('Copied!');
                    setTimeout(function() {
                        $('#hp-gmc-supplemental-url-copy').text('Copy URL');
                    }, 2000);
                });
            }
        },
        
        refreshAllFeedStatuses: function() {
            const $btn = $('#hp-gmc-refresh-all-feeds');
            const originalHtml = $btn.html();
            
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Refreshing...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_refresh_all_feed_statuses',
                    nonce: hpGmcData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Failed to refresh statuses');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    alert('Network error');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },
        
        toggleSelectAllFeeds: function(e) {
            const isChecked = $(e.target).prop('checked');
            $('.hp-gmc-feed-checkbox').prop('checked', isChecked);
        },
        
        applyBulkFeedAction: function() {
            const action = $('#hp-gmc-bulk-action').val();
            const feedIds = $('.hp-gmc-feed-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (!action) {
                alert('Please select a bulk action');
                return;
            }
            
            if (feedIds.length === 0) {
                alert('Please select at least one feed');
                return;
            }
            
            if (action === 'export') {
                this.exportFeedsAndDownload(feedIds);
                return;
            }
            
            if (action === 'delete' && !confirm('Are you sure you want to delete ' + feedIds.length + ' feed(s)?')) {
                return;
            }
            
            const $btn = $('#hp-gmc-apply-bulk-action');
            const originalText = $btn.text();
            
            $btn.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_bulk_feed_action',
                    nonce: hpGmcData.nonce,
                    bulk_action: action,
                    feed_ids: feedIds
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data?.message || 'Bulk action failed');
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('Network error');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },
        
        downloadJsonFile: function(filename, jsonString) {
            const blob = new Blob([jsonString], { type: 'application/json' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        },
        
        exportFeedsAndDownload: function(feedIds) {
            const $btn = $('#hp-gmc-apply-bulk-action');
            const originalText = $btn.text();
            $btn.prop('disabled', true).text('Exporting...');
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_export_feeds_json',
                    nonce: hpGmcData.nonce,
                    feed_ids: feedIds
                },
                success: function(response) {
                    $btn.prop('disabled', false).text(originalText);
                    if (response.success && response.data.json && response.data.filename) {
                        GMCDashboard.downloadJsonFile(response.data.filename, response.data.json);
                    } else {
                        alert(response.data?.message || 'Export failed');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text(originalText);
                    alert('Network error');
                }
            });
        },
        
        downloadJsonTemplate: function() {
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_download_feed_json_template',
                    nonce: hpGmcData.nonce
                },
                success: function(response) {
                    if (response.success && response.data.json && response.data.filename) {
                        GMCDashboard.downloadJsonFile(response.data.filename, response.data.json);
                    } else {
                        alert(response.data?.message || 'Failed to get template');
                    }
                },
                error: function() {
                    alert('Network error');
                }
            });
        },
        
        importFeedsFromFile: function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            e.target.value = '';
            const reader = new FileReader();
            reader.onload = function(ev) {
                const raw = ev.target && ev.target.result;
                if (!raw) return;
                const formData = new FormData();
                formData.append('action', 'hp_gmc_import_feeds_json');
                formData.append('nonce', hpGmcData.nonce);
                formData.append('json', raw);
                $.ajax({
                    url: hpGmcData.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success && response.data) {
                            const d = response.data;
                            let msg = 'Import complete. Created ' + (d.created && d.created.length ? d.created.length : 0) + ' feed(s).';
                            if (d.skipped_products && d.skipped_products.length) {
                                msg += ' Skipped ' + d.skipped_products.length + ' product row(s) (missing SKU or product not found).';
                            }
                            if (d.errors && d.errors.length) {
                                msg += ' Errors: ' + d.errors.length;
                            }
                            alert(msg);
                            location.reload();
                        } else {
                            alert(response.data?.message || 'Import failed');
                        }
                    },
                    error: function(xhr) {
                        const msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                            ? xhr.responseJSON.data.message : 'Network error';
                        alert(msg);
                    }
                });
            };
            reader.readAsText(file);
        },
        
        viewPendingProducts: function(e) {
            e.preventDefault();
            const feedId = $(e.target).data('feed-id');
            const feedName = $(e.target).data('feed-name');
            
            $('#hp-gmc-pending-feed-name').text(feedName);
            $('#hp-gmc-add-all-pending').data('feed-id', feedId);
            $('#hp-gmc-pending-products-loading').show();
            $('#hp-gmc-pending-products-list').html('');
            $('#hp-gmc-pending-products-modal').show();
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_get_pending_products',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    $('#hp-gmc-pending-products-loading').hide();
                    
                    if (response.success && response.data.products.length > 0) {
                        let html = '<table class="wp-list-table widefat fixed striped">';
                        html += '<thead><tr><th>SKU</th><th>Product</th><th>Matched Issue</th></tr></thead><tbody>';
                        
                        response.data.products.forEach(function(product) {
                            html += '<tr>';
                            html += '<td><code>' + product.sku + '</code></td>';
                            html += '<td>' + product.name + '</td>';
                            html += '<td><small>' + product.matched_issue + '</small></td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody></table>';
                        $('#hp-gmc-pending-products-list').html(html);
                        $('#hp-gmc-add-all-pending').show();
                    } else {
                        $('#hp-gmc-pending-products-list').html('<p>No pending products found.</p>');
                        $('#hp-gmc-add-all-pending').hide();
                    }
                },
                error: function() {
                    $('#hp-gmc-pending-products-loading').hide();
                    $('#hp-gmc-pending-products-list').html('<p class="error">Failed to load pending products.</p>');
                }
            });
        },
        
        addAllPendingProducts: function() {
            const feedId = $('#hp-gmc-add-all-pending').data('feed-id');
            const $btn = $('#hp-gmc-add-all-pending');
            const originalText = $btn.text();
            
            $btn.prop('disabled', true).text('Adding...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_add_pending_products',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    if (response.success) {
                        $('#hp-gmc-pending-products-modal').hide();
                        location.reload();
                    } else {
                        alert(response.data?.error || 'Failed to add products');
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('Network error');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },
        
        deleteFeed: function(e) {
            const feedId = $(e.target).data('feed-id');
            
            if (!confirm('Are you sure you want to delete this feed?')) {
                return;
            }
            
            const $btn = $(e.target);
            $btn.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_delete_feed',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove feed_id from URL and reload
                        const url = new URL(window.location);
                        url.searchParams.delete('feed_id');
                        url.hash = 'feeds';
                        window.location.href = url.toString();
                    } else {
                        alert(response.data?.message || 'Failed to delete feed');
                        $btn.prop('disabled', false).text('Delete');
                    }
                },
                error: function() {
                    alert('Network error');
                    $btn.prop('disabled', false).text('Delete');
                }
            });
        },
        
        addProductToFeed: function() {
            const feedId = $('#hp-gmc-add-product-btn').data('feed-id');
            const productId = $('#hp-gmc-product-search').data('product-id');
            const sku = $('#hp-gmc-product-search').val().trim();
            const value = $('#hp-gmc-product-value').val().trim();
            const reason = $('#hp-gmc-product-reason').val().trim();
            
            if (!productId && !sku) {
                alert('Please search and select a product');
                return;
            }
            
            if (!value) {
                alert('Please enter a value');
                return;
            }
            
            // If we have SKU but not productId, we need to look it up
            let finalProductId = productId;
            
            if (!finalProductId) {
                // Try to use SKU directly - backend will handle lookup
                alert('Please select a product from the search results');
                return;
            }
            
            const $btn = $('#hp-gmc-add-product-btn');
            $btn.prop('disabled', true).text('Adding...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_add_product_to_feed',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId,
                    product_id: finalProductId,
                    value: value,
                    reason: reason
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data?.message || 'Failed to add product');
                    }
                },
                error: function() {
                    alert('Network error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Add');
                }
            });
        },
        
        removeProductFromFeed: function(e) {
            const $btn = $(e.target);
            const feedId = $btn.data('feed-id');
            const productId = $btn.data('product-id');
            
            if (!confirm('Remove this product from the feed?')) {
                return;
            }
            
            $btn.prop('disabled', true).text('...');
            
            $.ajax({
                url: hpGmcData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_gmc_remove_product_from_feed',
                    nonce: hpGmcData.nonce,
                    feed_id: feedId,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(() => $(this).remove());
                    } else {
                        alert(response.data?.message || 'Failed to remove product');
                        $btn.prop('disabled', false).text('Remove');
                    }
                },
                error: function() {
                    alert('Network error');
                    $btn.prop('disabled', false).text('Remove');
                }
            });
        }
    };

    // Expose so second script block can extend with initIssuesEvents
    window.GMCDashboard = GMCDashboard;

    $(document).ready(function() {
        const Dashboard = window.GMCDashboard;
        Dashboard.init();
        Dashboard.initFeedEvents();
        if (typeof Dashboard.initIssuesEvents === 'function') {
            Dashboard.initIssuesEvents();
        }
    });

})(jQuery);

/**
 * Issues Tab - 3-Tier Classification Handlers
 */
(function($) {
    'use strict';
    
    // Extend GMCDashboard with issues functionality
    window.GMCDashboard = window.GMCDashboard || {};
    
    GMCDashboard.initIssuesEvents = function() {
        // Analyze triggers button
        $(document).on('click', '.hp-gmc-analyze-triggers', this.analyzeTriggers.bind(this));
        
        // Mark as restriction button
        $(document).on('click', '.hp-gmc-mark-restriction', this.markAsRestriction.bind(this));
        
        // Add to feed (single product)
        $(document).on('click', '.hp-gmc-add-to-feed', this.addSingleToFeed.bind(this));
        
        // Bulk add to feed button
        $('#hp-gmc-bulk-add-to-feed').on('click', () => {
            const selected = $('.hp-gmc-restriction-checkbox:checked').length;
            if (selected === 0) {
                alert('Please select at least one product');
                return;
            }
            $('#hp-gmc-bulk-selected-count').text(selected + ' product(s) selected');
            $('#hp-gmc-bulk-feed-modal').show();
        });
        
        // Bulk add confirm
        $('#hp-gmc-bulk-add-confirm').on('click', this.bulkAddToFeed.bind(this));
        
        // Export fixable CSV
        $('#hp-gmc-export-fixable').on('click', this.exportFixableCSV.bind(this));
        
        // Select all checkboxes
        $('#hp-gmc-select-all-fixable').on('change', function() {
            $('.hp-gmc-fixable-checkbox').prop('checked', $(this).is(':checked'));
        });
        
        $('#hp-gmc-select-all-restriction').on('change', function() {
            $('.hp-gmc-restriction-checkbox').prop('checked', $(this).is(':checked'));
        });
    };
    
    GMCDashboard.analyzeTriggers = function(e) {
        const $btn = $(e.target);
        const productId = $btn.data('product-id');
        const issue = $btn.data('issue');
        
        $btn.prop('disabled', true).text('...');
        
        $.ajax({
            url: hpGmcData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hp_gmc_analyze_triggers',
                nonce: hpGmcData.nonce,
                product_id: productId,
                issue: issue
            },
            success: function(response) {
                if (response.success) {
                    GMCDashboard.showTriggerModal(response.data);
                } else {
                    alert(response.data?.error || 'Failed to analyze triggers');
                }
            },
            error: function() {
                alert('Network error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Analyze');
            }
        });
    };
    
    GMCDashboard.showTriggerModal = function(data) {
        let html = '<h4>' + (data.product_name || 'Product') + '</h4>';
        
        if (data.likely_cause) {
            html += '<p><strong>Likely Cause:</strong> ' + data.likely_cause + '</p>';
        }
        
        if (data.fix_strategy) {
            html += '<p><strong>Fix Strategy:</strong> ' + data.fix_strategy + '</p>';
        }
        
        if (data.triggers_found && data.triggers_found.length > 0) {
            html += '<h4>Trigger Keywords Found (' + data.triggers_found.length + ')</h4>';
            html += '<div class="hp-gmc-trigger-list">';
            
            data.triggers_found.forEach(function(trigger) {
                html += '<div class="hp-gmc-trigger-item">';
                html += '<span class="hp-gmc-trigger-keyword">' + trigger.keyword + '</span>';
                html += ' found in <strong>' + trigger.location + '</strong>';
                html += '<span class="hp-gmc-trigger-context">' + trigger.context + '</span>';
                html += '</div>';
            });
            
            html += '</div>';
        } else {
            html += '<p><em>No specific trigger keywords found. The classification may be based on overall product context.</em></p>';
        }
        
        if (data.suggestions && data.suggestions.length > 0) {
            html += '<h4>Suggested Fixes</h4>';
            data.suggestions.forEach(function(s) {
                html += '<div class="hp-gmc-trigger-suggestion">';
                html += '<strong>Replace:</strong> "' + s.keyword + '" <strong>→</strong> "' + s.suggestion + '"';
                html += '</div>';
            });
        }
        
        html += '<div style="margin-top:20px;">';
        html += '<a href="' + (hpGmcData.adminUrl || '/wp-admin/') + 'post.php?post=' + data.product_id + '&action=edit" class="button button-primary" target="_blank">Edit Product</a>';
        html += '</div>';
        
        $('#hp-gmc-trigger-modal-body').html(html);
        $('#hp-gmc-trigger-modal').show();
    };
    
    GMCDashboard.markAsRestriction = function(e) {
        const $btn = $(e.target);
        const productId = $btn.data('product-id');
        
        if (!confirm('Mark this product as a TRUE policy restriction?\n\nThis will move it to Tier 3 for exclusion feed assignment.')) {
            return;
        }
        
        $btn.prop('disabled', true).text('...');
        
        $.ajax({
            url: hpGmcData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hp_gmc_mark_as_restriction',
                nonce: hpGmcData.nonce,
                product_id: productId,
                reason: 'Manually marked by admin'
            },
            success: function(response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data?.error || 'Failed to mark as restriction');
                    $btn.prop('disabled', false).text('→ Tier 3');
                }
            },
            error: function() {
                alert('Network error');
                $btn.prop('disabled', false).text('→ Tier 3');
            }
        });
    };
    
    GMCDashboard.addSingleToFeed = function(e) {
        const $btn = $(e.target);
        const productId = $btn.data('product-id');
        const exclusions = $btn.data('exclusions');
        const $select = $btn.siblings('.hp-gmc-feed-select');
        const feedId = $select.val();
        
        if (!feedId) {
            alert('Please select a feed');
            return;
        }
        
        $btn.prop('disabled', true).text('...');
        
        $.ajax({
            url: hpGmcData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hp_gmc_add_product_to_feed',
                nonce: hpGmcData.nonce,
                feed_id: feedId,
                product_id: productId,
                value: exclusions,
                reason: 'Policy restriction'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data?.message || 'Failed to add to feed');
                    $btn.prop('disabled', false).text('Add');
                }
            },
            error: function() {
                alert('Network error');
                $btn.prop('disabled', false).text('Add');
            }
        });
    };
    
    GMCDashboard.bulkAddToFeed = function() {
        const feedId = $('#hp-gmc-bulk-feed-select').val();
        const exclusions = $('#hp-gmc-bulk-exclusions').val();
        
        if (!feedId) {
            alert('Please select a feed');
            return;
        }
        
        const productIds = [];
        $('.hp-gmc-restriction-checkbox:checked').each(function() {
            productIds.push($(this).val());
        });
        
        if (productIds.length === 0) {
            alert('No products selected');
            return;
        }
        
        const $btn = $('#hp-gmc-bulk-add-confirm');
        $btn.prop('disabled', true).text('Adding...');
        
        $.ajax({
            url: hpGmcData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hp_gmc_bulk_add_to_feed',
                nonce: hpGmcData.nonce,
                feed_id: feedId,
                product_ids: productIds,
                value: exclusions,
                reason: 'Bulk add - policy restriction'
            },
            success: function(response) {
                if (response.success) {
                    alert('Added ' + response.data.added + ' products to feed');
                    location.reload();
                } else {
                    alert(response.data?.message || 'Failed to add products');
                }
            },
            error: function() {
                alert('Network error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Add to Feed');
                $('#hp-gmc-bulk-feed-modal').hide();
            }
        });
    };
    
    GMCDashboard.exportFixableCSV = function() {
        const rows = [['Product ID', 'SKU', 'Product Name', 'Issue', 'Fix Type', 'Suggested Fix']];
        
        $('.hp-gmc-fixable-table tbody tr').each(function() {
            const $row = $(this);
            const productId = $row.data('product-id');
            const sku = $row.find('td:eq(2) code').text();
            const name = $row.find('td:eq(1) a').text() || $row.find('td:eq(1)').text();
            const issue = $row.find('td:eq(3)').text().trim();
            const fixType = $row.find('.hp-gmc-fix-type').text().trim();
            const suggestedFix = $row.find('.hp-gmc-suggested-fix').text().trim();
            
            rows.push([productId, sku, name, issue, fixType, suggestedFix]);
        });
        
        // Convert to CSV
        const csv = rows.map(row => row.map(cell => '"' + (cell || '').replace(/"/g, '""') + '"').join(',')).join('\n');
        
        // Download
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'gmc-fixable-issues-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
    };
    
})(jQuery);
