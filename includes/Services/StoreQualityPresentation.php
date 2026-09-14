<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Read-only staff preview. It cannot enable the public widget or submit data. */
final class StoreQualityPresentation
{
    public static function register(): void
    {
        add_action('init', [self::class, 'protectCache'], 0);
        add_action('send_headers', [self::class, 'protectCache']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function protectCache(): void
    {
        if (!isset($_GET['hp_google_quality_preview'])) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        }
    }

    public static function get(): ?array
    {
        if (($_GET['hp_google_quality_preview'] ?? null) !== '1'
            || !is_user_logged_in() || !(current_user_can('manage_options') || current_user_can('manage_woocommerce'))
            || is_admin() || !is_ssl() || is_preview()) { return null; }
        $rawQuery = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        $previewKeys = array_filter(explode('&', $rawQuery), static fn($pair) => rawurldecode(explode('=', $pair, 2)[0]) === 'hp_google_quality_preview');
        if (count($previewKeys) !== 1) { return null; }
        $link = StoreQualityLink::get();
        $host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        $requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if (!$link || $requestHost !== $host) { return null; }
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $checkout = in_array($path, ['/hp-checkout', '/hp-checkout/'], true);
        $safeQuery = ['hp_google_quality_preview', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'gclid', 'gbraid', 'wbraid'];
        foreach ($_GET as $key => $value) {
            if ($key === 'hp_source' && $checkout && $value === 'sidecart') { continue; }
            if (!in_array($key, $safeQuery, true) || !is_scalar($value) || strlen((string) $value) > 160) { return null; }
        }
        foreach (['is_account_page', 'is_cart', 'is_checkout', 'is_shop', 'is_product', 'is_product_category', 'is_wc_endpoint_url'] as $helper) {
            if (!function_exists($helper)) { return null; }
        }
        if (preg_match('~(?:^|/)(?:order-pay|order-received|my-account|hp-account|cart|wp-admin)(?:/|$)~i', $path)
            || is_account_page() || is_cart() || is_wc_endpoint_url() || (is_checkout() && !$checkout)
            || (is_singular() && (post_password_required() || get_post_status() !== 'publish'))) { return null; }
        if (!$checkout && !is_front_page() && !is_shop() && !is_product() && !is_product_category()
            && !is_page(['reviews', 'privacy-policy-holisticpeople', 'terms-service-holisticpeople', 'return-policy'])) { return null; }
        return [
            'url' => $link['url'],
            'label' => __('Store quality on Google', 'hp-gmc-manager'),
            'description' => __('Shipping, returns and shopping experience.', 'hp-gmc-manager'),
            'preview' => true,
            'interactive' => true,
        ];
    }

    public static function enqueue(): void
    {
        if (self::get() === null) { return; }
        wp_enqueue_script('hp-gmc-store-quality-control', HP_GMC_URL . 'assets/js/store-quality-control.js', [], HP_GMC_VERSION, true);
        wp_add_inline_script('hp-gmc-store-quality-control', 'window.HPGMCStoreQualityConfig = ' . wp_json_encode([
            'allowGoogle' => !CustomerReviewsEnvironment::isOutwardSilent(),
            'merchantId' => 5298746911,
            'region' => 'US',
        ]) . ';', 'before');
    }
}
