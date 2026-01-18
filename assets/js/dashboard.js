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
        }
    };

    $(document).ready(function() {
        GMCDashboard.init();
    });

})(jQuery);
