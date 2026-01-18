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
                }
            });
            
            // Create feed submit
            $('#hp-gmc-create-feed-submit').on('click', this.createFeed.bind(this));
            
            // Feed actions
            $(document).on('click', '.hp-gmc-feed-generate', this.generateFeed.bind(this));
            $(document).on('click', '.hp-gmc-feed-upload', this.uploadFeed.bind(this));
            $(document).on('click', '.hp-gmc-feed-check-status', this.checkFeedStatus.bind(this));
            $(document).on('click', '.hp-gmc-feed-delete', this.deleteFeed.bind(this));
            $(document).on('click', '.hp-gmc-remove-product', this.removeProductFromFeed.bind(this));
            
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
            const feedId = $(e.target).data('feed-id');
            const $btn = $(e.target);
            const originalText = $btn.text();
            
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
                        alert('Feed uploaded to GMC!\n\n' + (response.data?.note || response.data?.message || 'Success'));
                        location.reload();
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
            const feedId = $(e.target).data('feed-id');
            const $btn = $(e.target);
            const originalText = $btn.text();
            
            $btn.prop('disabled', true).text('Checking...');
            
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
                        alert('GMC Status: ' + JSON.stringify(response.data.data, null, 2));
                        location.reload();
                    } else {
                        alert(response.data?.error || 'Failed to check status');
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

    $(document).ready(function() {
        GMCDashboard.init();
        GMCDashboard.initFeedEvents();
    });

})(jQuery);
