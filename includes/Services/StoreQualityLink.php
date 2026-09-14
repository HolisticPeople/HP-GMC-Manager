<?php
namespace HP_GMC\Services;

/** Static public destination. No Google request, rating claim, or review-form promise. */
final class StoreQualityLink
{
    public static function get(): ?array
    {
        $host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        if (!is_ssl() || !in_array($host, [
            'holisticpeople.com', 'www.holisticpeople.com',
            'env-holisticpeoplecom-hpdevplus.kinsta.cloud',
        ], true) || (string) get_option('hp_gmc_merchant_id', '') !== '5298746911') {
            return null;
        }
        return [
            'url' => 'https://www.google.com/storepages?q=holisticpeople.com&c=US',
            'label' => __('View store quality on Google', 'hp-gmc-manager'),
        ];
    }
}
