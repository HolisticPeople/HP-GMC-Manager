<?php
/**
 * Behavioral row-level parity checks for the canonical TSV pull feed.
 * Standalone: run with `php tests/ProductFeedIdentifierParityTest.php`.
 */

error_reporting(E_ALL);
$failures = 0;

function check_feed(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "  ok  $label\n";
        return;
    }

    $failures++;
    echo "FAIL  $label\n";
}

$meta = [];
$brands = [];
$products = [];

function get_post_meta($id, $key, $single = true) {
    global $meta;
    return $meta[$id][$key] ?? '';
}
function wp_get_post_terms($id, $taxonomy, $args = []) {
    global $brands;
    return isset($brands[$id]) ? [$brands[$id]] : [];
}
function is_wp_error($value) { return false; }
function taxonomy_exists($taxonomy) { return false; }
function get_the_terms($id, $taxonomy) { return false; }
function get_term($id, $taxonomy) { return false; }
function get_transient($key) { return false; }
function set_transient($key, $value, $duration) { return true; }
function update_option($key, $value) { return true; }
function current_time($type) { return '2026-09-01 12:00:00'; }
function get_woocommerce_currency() { return 'USD'; }
function wc_get_product($id) { global $products; return $products[$id] ?? false; }
function wp_strip_all_tags($value) { return trim(strip_tags((string) $value)); }
function wp_get_attachment_url($id) { return 'https://img.example/' . (int) $id . '.jpg'; }
function wc_placeholder_img_src($size) { return 'https://img.example/placeholder.jpg'; }
function get_attached_media($type, $id) { return []; }
function get_post_mime_type($id) { return 'image/jpeg'; }
function wp_get_attachment_metadata($id) { return ['width' => 1000, 'height' => 1000]; }
function get_option($key, $default = false) {
    return match ($key) {
        'woocommerce_weight_unit' => 'lbs',
        'woocommerce_dimension_unit' => 'in',
        default => $default,
    };
}

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

class FeedWpdb
{
    public string $posts = 'wp_posts';
    public function get_col($query): array { global $products; return array_keys($products); }
}
$wpdb = new FeedWpdb();

class WC_Product
{
    public function __construct(
        private int $id,
        private string $sku,
        private string $gtin = ''
    ) {}

    public function get_id(): int { return $this->id; }
    public function get_sku(): string { return $this->sku; }
    public function get_global_unique_id(): string { return $this->gtin; }
    public function get_name(): string { return 'Feed Product ' . $this->id; }
    public function get_description(): string { return 'Truthful product description.'; }
    public function get_short_description(): string { return ''; }
    public function get_permalink(): string { return 'https://example.com/product/' . $this->id; }
    public function get_image_id(): int { return 10 + $this->id; }
    public function get_gallery_image_ids(): array { return []; }
    public function get_price(): string { return '10.00'; }
    public function get_regular_price(): string { return '10.00'; }
    public function get_sale_price(): string { return ''; }
    public function get_date_on_sale_from() { return null; }
    public function get_date_on_sale_to() { return null; }
    public function get_weight(): string { return '0.1'; }
    public function get_length(): string { return ''; }
    public function get_width(): string { return ''; }
    public function get_height(): string { return ''; }
    public function get_catalog_visibility(): string { return 'visible'; }
    public function is_purchasable(): bool { return true; }
    public function is_in_stock(): bool { return true; }
    public function is_on_sale(): bool { return false; }
    public function is_type(string $type): bool { return $type === 'simple'; }
    public function get_available_variations(): array { return []; }
    public function get_attribute(string $name): string { return ''; }
}

$products = [
    101 => new WC_Product(101, 'STORE-SKU-101', '679-372000057'),
    102 => new WC_Product(102, 'STORE-SKU-102'),
    103 => new WC_Product(103, 'STORE-SKU-103'),
    104 => new WC_Product(104, 'STORE-SKU-104'),
    105 => new WC_Product(105, 'STORE-SKU-105', '679A372000057'),
];
$brands = [
    101 => 'Dragon Herbs',
    102 => 'Example Manufacturer',
    104 => 'Known Brand',
];
$meta = [
    101 => ['_hp_gmc_identifier_exists' => 'no'],
    102 => [
        '_hp_gmc_mpn' => 'MANUFACTURER-102',
        '_hp_gmc_mpn_verified' => 'yes',
        '_hp_gmc_mpn_source' => 'manufacturer_catalog',
    ],
    103 => ['_hp_gmc_identifier_exists' => 'no'],
    104 => ['_hp_gmc_identifier_exists' => 'no'],
];

$root = dirname(__DIR__);
require $root . '/includes/Services/ProductIdentifiers.php';
require $root . '/includes/Services/ProductDataFeed.php';

$feed = \HP_GMC\Services\ProductDataFeed::generateFeed('tsv', true);
$lines = explode("\n", trim($feed));
$headers = str_getcsv(array_shift($lines), "\t", '"', '\\');
$rows = [];
foreach ($lines as $line) {
    $values = str_getcsv($line, "\t", '"', '\\');
    $row = array_combine($headers, $values);
    $rows[$row['id']] = $row;
}

check_feed(count($rows) === 5, 'behavioral feed fixture emits all five rows');

$gtinRow = $rows['gla_101'];
check_feed($gtinRow['gtin'] === '679372000057', 'GTIN row preserves normalized provider-valid GTIN');
check_feed($gtinRow['brand'] === 'Dragon Herbs', 'GTIN row preserves source-backed brand');
check_feed($gtinRow['mpn'] === '', 'GTIN row does not relabel SKU as MPN');
check_feed($gtinRow['identifier_exists'] === '', 'GTIN row suppresses contradictory reviewed absence');

$mpnRow = $rows['gla_102'];
check_feed($mpnRow['brand'] === 'Example Manufacturer', 'MPN row emits its source-backed brand');
check_feed($mpnRow['mpn'] === 'MANUFACTURER-102', 'MPN row emits exact reviewed manufacturer MPN');
check_feed($mpnRow['gtin'] === '', 'MPN-only row leaves GTIN blank');
check_feed($mpnRow['identifier_exists'] === '', 'brand plus MPN defaults identifier existence to yes');

$absentRow = $rows['gla_103'];
check_feed($absentRow['brand'] === '', 'reviewed absence row does not fabricate HolisticPeople brand');
check_feed($absentRow['mpn'] === '' && $absentRow['gtin'] === '', 'reviewed absence row has no fabricated UPI');
check_feed($absentRow['identifier_exists'] === 'no', 'reviewed UPI absence emits identifier_exists=no');

$brandRow = $rows['gla_104'];
check_feed($brandRow['brand'] === 'Known Brand', 'brand-only row preserves known brand');
check_feed($brandRow['identifier_exists'] === 'no',
    'brand-only row retains reviewed no when GTIN and MPN are absent');

$malformedRow = $rows['gla_105'];
check_feed($malformedRow['gtin'] === '', 'malformed alphanumeric GTIN is omitted, not sanitized');
check_feed($malformedRow['identifier_exists'] === '', 'malformed unresolved GTIN does not fabricate absence');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
