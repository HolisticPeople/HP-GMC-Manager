<?php

declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__);

    function get_transient(string $key): mixed { return false; }
    function set_transient(string $key, mixed $value, int $duration): bool { return true; }
    function update_option(string $key, mixed $value): bool { return true; }
    function get_option(string $key, mixed $default = false): mixed { return $default; }
    function delete_transient(string $key): bool { return true; }
    function current_time(string $type): string { return '2026-08-31 12:00:00'; }
    function rest_url(string $path): string { return 'https://example.com/wp-json/' . ltrim($path, '/'); }
}

namespace HP_Funnels\Services {
    final class FunnelGmcService
    {
        public static function getAllGmcEnabledOffers(): array
        {
            return [
                [
                    'funnel_id' => 125516,
                    'feed_id' => 'funnel_125516',
                    'title' => 'ILLUMODINE - Starter size',
                    'description' => 'Starter bottle.',
                    'link' => 'https://example.com/express-shop/illumodine/?offer=offer-starter#offers',
                    'image_link' => 'https://example.com/starter.png',
                    'additional_image_link' => '',
                    'price' => 29,
                    'price_formatted' => '29.00 USD',
                    'availability' => 'in_stock',
                    'brand' => 'Illumodine',
                    'condition' => 'new',
                    'is_bundle' => 'no',
                    'shipping_weight_formatted' => '0.20 lb',
                    'google_product_category' => 469,
                    'custom_label_0' => '',
                    'custom_label_1' => '',
                    'custom_label_2' => '',
                    'custom_label_3' => '',
                    'custom_label_4' => '',
                    'item_group_id' => '',
                ],
                [
                    'funnel_id' => 125516,
                    'feed_id' => 'funnel_125516_offer-value-pack',
                    'title' => 'ILLUMODINE - Value pack',
                    'description' => 'Buy 2 oz and get 0.5 oz free.',
                    'link' => 'https://example.com/express-shop/illumodine/?offer=offer-value-pack#offers',
                    'image_link' => 'https://example.com/value.png',
                    'additional_image_link' => 'https://example.com/free-bottle.png',
                    'price' => 114,
                    'price_formatted' => '114.00 USD',
                    'availability' => 'in_stock',
                    'brand' => 'Illumodine',
                    'condition' => 'new',
                    'is_bundle' => 'yes',
                    'shipping_weight_formatted' => '0.60 lb',
                    'google_product_category' => 469,
                    'custom_label_0' => '',
                    'custom_label_1' => '',
                    'custom_label_2' => '',
                    'custom_label_3' => '',
                    'custom_label_4' => '',
                    'item_group_id' => '',
                ],
            ];
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/Services/FunnelDataFeed.php';

    $feed = \HP_GMC\Services\FunnelDataFeed::generateFeed('tsv', true);
    $lines = explode("\n", $feed);
    if (count($lines) !== 3) {
        throw new RuntimeException('Expected a header and two offer rows.');
    }
    if (!str_contains($lines[0], "additional_image_link") || !str_contains($lines[0], "is_bundle")) {
        throw new RuntimeException('Offer-level feed headers are incomplete.');
    }
    if (!str_starts_with($lines[2], "funnel_125516_offer-value-pack\t")) {
        throw new RuntimeException('The manager must use HP-Funnels offer-level feed IDs.');
    }
    if (!str_contains($lines[2], "\tyes\t")) {
        throw new RuntimeException('The value pack must be marked as a bundle.');
    }
    if (!str_contains($lines[2], 'free-bottle.png')) {
        throw new RuntimeException('Additional offer imagery must reach the GMC feed.');
    }

    echo "Funnel offer feed contract passed.\n";
}
