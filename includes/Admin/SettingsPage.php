<?php
namespace HP_GMC\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings page for HP GMC Manager.
 */
class SettingsPage
{
    /**
     * Render the settings page.
     */
    public static function render(): void
    {
        $merchant_id = get_option('hp_gmc_merchant_id', '');
        $service_account_path = get_option('hp_gmc_service_account_path', '');
        $mode = get_option('hp_gmc_mode', 'auto');
        $sync_frequency = get_option('hp_gmc_sync_frequency', 'hourly');
        $environment = get_option('hp_gmc_environment', '');
        $detected_environment = hp_gmc_get_environment();
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e('GMC Manager Settings', 'hp-gmc-manager'); ?>
                <span class="hp-gmc-version">v<?php echo esc_html(HP_GMC_VERSION); ?></span>
            </h1>
            <?php
            if (isset($_GET['hp_gmc_oauth']) && $_GET['hp_gmc_oauth'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Google Ads OAuth: Connected successfully. Upload to Google Ads will use your account.', 'hp-gmc-manager') . '</p></div>';
            }
            if (!empty($_GET['hp_gmc_oauth_error'])) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Google Ads OAuth error:', 'hp-gmc-manager') . ' ' . esc_html(urldecode(sanitize_text_field(wp_unslash($_GET['hp_gmc_oauth_error'])))) . '</p></div>';
            }
            ?>
            <form method="post" action="options.php">
                <?php settings_fields('hp_gmc_settings'); ?>

                <h2><?php esc_html_e('API Configuration', 'hp-gmc-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_merchant_id"><?php esc_html_e('Merchant ID', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="hp_gmc_merchant_id" 
                                   name="hp_gmc_merchant_id" 
                                   value="<?php echo esc_attr($merchant_id); ?>" 
                                   class="regular-text"
                                   placeholder="5298746911">
                            <p class="description">
                                <?php esc_html_e('Your Google Merchant Center account ID.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_service_account_path"><?php esc_html_e('Service Account JSON Path', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="hp_gmc_service_account_path" 
                                   name="hp_gmc_service_account_path" 
                                   value="<?php echo esc_attr($service_account_path); ?>" 
                                   class="large-text"
                                   placeholder="/path/to/service-account.json">
                            <p class="description">
                                <?php esc_html_e('Absolute path to your Google service account JSON key file.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Environment & Mode', 'hp-gmc-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_environment"><?php esc_html_e('Environment', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <select id="hp_gmc_environment" name="hp_gmc_environment">
                                <option value="" <?php selected($environment, ''); ?>>
                                    <?php printf(
                                        esc_html__('Auto-detect (%s)', 'hp-gmc-manager'),
                                        esc_html($detected_environment)
                                    ); ?>
                                </option>
                                <option value="production" <?php selected($environment, 'production'); ?>>
                                    <?php esc_html_e('Production', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="staging" <?php selected($environment, 'staging'); ?>>
                                    <?php esc_html_e('Staging', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="local" <?php selected($environment, 'local'); ?>>
                                    <?php esc_html_e('Local', 'hp-gmc-manager'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Override automatic environment detection if needed.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_mode"><?php esc_html_e('Operating Mode', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <select id="hp_gmc_mode" name="hp_gmc_mode">
                                <option value="auto" <?php selected($mode, 'auto'); ?>>
                                    <?php esc_html_e('Auto (Live on production, Dry Run on staging/local)', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="live" <?php selected($mode, 'live'); ?>>
                                    <?php esc_html_e('Live - Real API calls', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="dry_run" <?php selected($mode, 'dry_run'); ?>>
                                    <?php esc_html_e('Dry Run - Log actions only', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="mock" <?php selected($mode, 'mock'); ?>>
                                    <?php esc_html_e('Mock - Use simulated data', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="passthrough" <?php selected($mode, 'passthrough'); ?>>
                                    <?php esc_html_e('Passthrough - Staging to Production GMC (CAUTION!)', 'hp-gmc-manager'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Controls how the plugin interacts with Google Merchant Center.', 'hp-gmc-manager'); ?>
                            </p>
                            <?php if ($mode === 'passthrough'): ?>
                            <p class="notice notice-error inline">
                                <strong><?php esc_html_e('Warning:', 'hp-gmc-manager'); ?></strong>
                                <?php esc_html_e('Passthrough mode will make real changes to your production GMC account from this environment!', 'hp-gmc-manager'); ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Sync Settings', 'hp-gmc-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_sync_frequency"><?php esc_html_e('Sync Frequency', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <select id="hp_gmc_sync_frequency" name="hp_gmc_sync_frequency">
                                <option value="hourly" <?php selected($sync_frequency, 'hourly'); ?>>
                                    <?php esc_html_e('Hourly', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="twicedaily" <?php selected($sync_frequency, 'twicedaily'); ?>>
                                    <?php esc_html_e('Twice Daily', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="daily" <?php selected($sync_frequency, 'daily'); ?>>
                                    <?php esc_html_e('Daily', 'hp-gmc-manager'); ?>
                                </option>
                                <option value="manual" <?php selected($sync_frequency, 'manual'); ?>>
                                    <?php esc_html_e('Manual Only', 'hp-gmc-manager'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('How often to sync product statuses from Google Merchant Center.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('GA4 Analytics', 'hp-gmc-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_ga4_property_id"><?php esc_html_e('GA4 Property ID', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="hp_gmc_ga4_property_id"
                                   name="hp_gmc_ga4_property_id"
                                   value="<?php echo esc_attr(get_option('hp_gmc_ga4_property_id', '')); ?>"
                                   class="regular-text"
                                   placeholder="123456789">
                            <p class="description">
                                <?php esc_html_e('Your GA4 property ID (numeric). Found in GA4 Admin > Property Settings.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Google Ads', 'hp-gmc-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_ads_upload_auth"><?php esc_html_e('Audience upload authentication', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <select id="hp_gmc_ads_upload_auth" name="hp_gmc_ads_upload_auth">
                                <option value="oauth" <?php selected(get_option('hp_gmc_ads_upload_auth', 'oauth'), 'oauth'); ?>><?php esc_html_e('OAuth (when connected)', 'hp-gmc-manager'); ?></option>
                                <option value="service_account" <?php selected(get_option('hp_gmc_ads_upload_auth', 'oauth'), 'service_account'); ?>><?php esc_html_e('Service account', 'hp-gmc-manager'); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Which credentials to use for Audiences → Upload to Google Ads. OAuth uses your connected Google account; Service account uses the JSON key file below.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_ads_customer_id"><?php esc_html_e('Customer ID', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="hp_gmc_ads_customer_id"
                                   name="hp_gmc_ads_customer_id"
                                   value="<?php echo esc_attr(get_option('hp_gmc_ads_customer_id', '')); ?>"
                                   class="regular-text"
                                   placeholder="123-456-7890">
                            <p class="description">
                                <?php esc_html_e('Your Google Ads account ID (with or without dashes).', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_ads_manager_id"><?php esc_html_e('Manager Account ID', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="hp_gmc_ads_manager_id"
                                   name="hp_gmc_ads_manager_id"
                                   value="<?php echo esc_attr(get_option('hp_gmc_ads_manager_id', '')); ?>"
                                   class="regular-text"
                                   placeholder="123-456-7890">
                            <p class="description">
                                <?php esc_html_e('MCC (manager) account ID, if accessing via a manager account. Leave blank for direct access.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_ads_developer_token"><?php esc_html_e('Developer Token', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   id="hp_gmc_ads_developer_token"
                                   name="hp_gmc_ads_developer_token"
                                   value="<?php echo esc_attr(get_option('hp_gmc_ads_developer_token', '')); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Google Ads API developer token. Found in Google Ads > Tools > API Center. For Customer Match (Audiences upload) the token must be approved for production, not Test account.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <?php
                    $oauth_available = class_exists(\HP_GMC\Services\GoogleAdsOAuth::class);
                    $oauth_connected = $oauth_available && \HP_GMC\Services\GoogleAdsOAuth::isConnected();
                    $oauth_email = $oauth_connected ? \HP_GMC\Services\GoogleAdsOAuth::getStoredEmail() : null;
                    ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Upload to Google Ads (OAuth)', 'hp-gmc-manager'); ?></th>
                        <td>
                            <?php if (!$oauth_available) : ?>
                                <p class="description"><?php esc_html_e('OAuth module not loaded. Ensure all plugin files are deployed.', 'hp-gmc-manager'); ?></p>
                            <?php else : ?>
                                <p class="description">
                                    <?php esc_html_e('Use your Google account for "Upload to Google Ads" when the service account gets 403. Create OAuth 2.0 credentials (Web application) in Google Cloud, add the redirect URI below to Authorized redirect URIs, then connect.', 'hp-gmc-manager'); ?>
                                </p>
                                <p><strong><?php esc_html_e('Redirect URI:', 'hp-gmc-manager'); ?></strong> <code><?php echo esc_html(rest_url('hp-gmc/v1/oauth-callback')); ?></code></p>
                                <input type="text" id="hp_gmc_ads_oauth_client_id" name="hp_gmc_ads_oauth_client_id" value="<?php echo esc_attr(get_option('hp_gmc_ads_oauth_client_id', '')); ?>" class="regular-text" placeholder="Client ID">
                                <input type="password" id="hp_gmc_ads_oauth_client_secret" name="hp_gmc_ads_oauth_client_secret" value="<?php echo esc_attr(get_option('hp_gmc_ads_oauth_client_secret', '')); ?>" class="regular-text" placeholder="Client Secret" style="margin-top:6px;">
                                <p class="description" style="margin-top:8px;"><?php esc_html_e('Save your changes (click Save Changes at the bottom of this page) before clicking Connect with Google.', 'hp-gmc-manager'); ?></p>
                                <?php if ($oauth_connected && $oauth_email) : ?>
                                    <p style="margin-top:10px;"><span class="dashicons dashicons-yes-alt" style="color:green;"></span> <?php echo esc_html(sprintf(__('Connected as %s', 'hp-gmc-manager'), $oauth_email)); ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=hp-gmc-settings&hp_gmc_oauth_disconnect=1'), 'hp_gmc_oauth_disconnect')); ?>" class="button button-small" style="margin-left:10px;"><?php esc_html_e('Disconnect', 'hp-gmc-manager'); ?></a>
                                    </p>
                                <?php elseif ($oauth_connected) : ?>
                                    <p style="margin-top:10px;"><span class="dashicons dashicons-yes-alt" style="color:green;"></span> <?php esc_html_e('Connected (user email not stored).', 'hp-gmc-manager'); ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=hp-gmc-settings&hp_gmc_oauth_disconnect=1'), 'hp_gmc_oauth_disconnect')); ?>" class="button button-small" style="margin-left:10px;"><?php esc_html_e('Disconnect', 'hp-gmc-manager'); ?></a>
                                    </p>
                                <?php else : ?>
                                    <p style="margin-top:10px;"><a href="<?php echo esc_url(admin_url('admin.php?page=hp-gmc-settings&hp_gmc_oauth_start=1')); ?>" class="button"><?php esc_html_e('Connect with Google', 'hp-gmc-manager'); ?></a></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Schema & Audiences', 'hp-gmc-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_schema_upgrade_on_load"><?php esc_html_e('Run schema upgrade on plugin load', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="hp_gmc_schema_upgrade_on_load" value="0">
                            <input type="checkbox"
                                   id="hp_gmc_schema_upgrade_on_load"
                                   name="hp_gmc_schema_upgrade_on_load"
                                   value="1"
                                   <?php checked(get_option('hp_gmc_schema_upgrade_on_load', true)); ?>>
                            <p class="description">
                                <?php esc_html_e('When enabled, database tables are created/updated on plugin load when the schema version changes. Disable to run upgrades only on plugin activation.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_audience_sync_run_cap"><?php esc_html_e('Audience sync run cap', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="hp_gmc_audience_sync_run_cap"
                                   name="hp_gmc_audience_sync_run_cap"
                                   value="<?php echo esc_attr(get_option('hp_gmc_audience_sync_run_cap', 5000)); ?>"
                                   min="1"
                                   class="small-text">
                            <p class="description">
                                <?php esc_html_e('Segment runs that would exceed this many rows use background processing. Default: 5000.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_audience_force_background_over_cap"><?php esc_html_e('Force runs over cap to background', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="hp_gmc_audience_force_background_over_cap" value="0">
                            <input type="checkbox"
                                   id="hp_gmc_audience_force_background_over_cap"
                                   name="hp_gmc_audience_force_background_over_cap"
                                   value="1"
                                   <?php checked(get_option('hp_gmc_audience_force_background_over_cap', false)); ?>>
                            <p class="description">
                                <?php esc_html_e('When enabled, segment runs over the cap always use background job. Default: No.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_audience_upload_disabled"><?php esc_html_e('Disable Upload to Google Ads', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="hp_gmc_audience_upload_disabled" value="0">
                            <input type="checkbox"
                                   id="hp_gmc_audience_upload_disabled"
                                   name="hp_gmc_audience_upload_disabled"
                                   value="1"
                                   <?php checked(get_option('hp_gmc_audience_upload_disabled', false)); ?>>
                            <p class="description">
                                <?php esc_html_e('Site-wide kill switch: hide or disable "Upload to Google Ads" in the Audiences tab. Use for quick rollback.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_audience_batches_per_chunk"><?php esc_html_e('Batches per chunk', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="hp_gmc_audience_batches_per_chunk"
                                   name="hp_gmc_audience_batches_per_chunk"
                                   value="<?php echo esc_attr(get_option('hp_gmc_audience_batches_per_chunk', 100)); ?>"
                                   min="25"
                                   max="250"
                                   class="small-text">
                            <p class="description">
                                <?php esc_html_e('How many batches each run-continue request runs. Smaller = more HTTP requests, shorter each; larger = fewer requests, longer each. Default: 100. Range: 25–250.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_audience_order_batch_size"><?php esc_html_e('Order batch size', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="hp_gmc_audience_order_batch_size"
                                   name="hp_gmc_audience_order_batch_size"
                                   value="<?php echo esc_attr(get_option('hp_gmc_audience_order_batch_size', 50)); ?>"
                                   min="10"
                                   max="200"
                                   class="small-text">
                            <p class="description">
                                <?php esc_html_e('Order IDs fetched per batch inside the engine. Lower = less memory per batch, more DB round-trips. Default: 50. Range: 10–200.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hp_gmc_audience_max_orders"><?php esc_html_e('Max orders to scan', 'hp-gmc-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="hp_gmc_audience_max_orders"
                                   name="hp_gmc_audience_max_orders"
                                   value="<?php echo esc_attr(get_option('hp_gmc_audience_max_orders', 25000)); ?>"
                                   min="1000"
                                   max="100000"
                                   class="small-text">
                            <p class="description">
                                <?php esc_html_e('Total orders considered per segment run. Total batches = ceil(max orders / order batch size). Default: 25000. Range: 1000–100000.', 'hp-gmc-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Connection Test', 'hp-gmc-manager'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('API Connection', 'hp-gmc-manager'); ?></th>
                        <td>
                            <button type="button" class="button" id="hp-gmc-test-connection">
                                <?php esc_html_e('Test Connection', 'hp-gmc-manager'); ?>
                            </button>
                            <span id="hp-gmc-connection-status"></span>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Setup Instructions', 'hp-gmc-manager'); ?></h2>
            <ol>
                <li><?php esc_html_e('Create a Google Cloud project and enable the Merchant API', 'hp-gmc-manager'); ?></li>
                <li><?php esc_html_e('Create a service account and download the JSON key file', 'hp-gmc-manager'); ?></li>
                <li><?php esc_html_e('Add the service account email to your Merchant Center as an Admin user', 'hp-gmc-manager'); ?></li>
                <li><?php esc_html_e('Upload the JSON key file to your server (outside web root for security)', 'hp-gmc-manager'); ?></li>
                <li><?php esc_html_e('Enter the path and Merchant ID above', 'hp-gmc-manager'); ?></li>
                <li><?php esc_html_e('Click "Test Connection" to verify', 'hp-gmc-manager'); ?></li>
            </ol>
        </div>
        <?php
    }
}
