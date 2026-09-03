<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Push-proof GCR gate. Deliberately does not use the legacy DB environment setting. */
final class CustomerReviewsEnvironment
{
    public static function resolve(): string
    {
        if (defined('HP_GMC_GCR_ENV')) {
            $environment = HP_GMC_GCR_ENV;
        } else {
            $host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
            $environment = in_array($host, ['holisticpeople.com', 'www.holisticpeople.com'], true)
                ? 'production'
                : ((str_contains($host, 'staging') || str_ends_with($host, '.kinsta.cloud') || str_ends_with($host, '.local') || str_contains($host, 'localhost')) ? 'staging' : 'unknown');
        }
        $environment = apply_filters('hp_gmc_gcr_environment', $environment);
        return in_array($environment, ['production', 'staging', 'unknown'], true) ? $environment : 'unknown';
    }

    public static function isOutwardSilent(): bool
    {
        return self::resolve() !== 'production';
    }
}
