<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Hard GCR gate. Configuration can mute, never upgrade an unsafe host. */
final class CustomerReviewsEnvironment
{
    public static function resolve(): string
    {
        $allowed = ['holisticpeople.com', 'www.holisticpeople.com'];
        $homeHost = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        $requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        $wpEnvironment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';
        if (!is_ssl() || !in_array($homeHost, $allowed, true) || !in_array($requestHost, $allowed, true)
            || $wpEnvironment !== 'production') {
            return ($wpEnvironment === 'staging' || str_contains($homeHost, 'staging') || str_ends_with($homeHost, '.kinsta.cloud'))
                ? 'staging' : 'unknown';
        }
        $environment = defined('HP_GMC_GCR_ENV') ? HP_GMC_GCR_ENV : 'production';
        $environment = apply_filters('hp_gmc_gcr_environment', $environment);
        return in_array($environment, ['production', 'staging', 'unknown'], true) ? $environment : 'unknown';
    }

    public static function isOutwardSilent(): bool
    {
        return self::resolve() !== 'production';
    }
}
