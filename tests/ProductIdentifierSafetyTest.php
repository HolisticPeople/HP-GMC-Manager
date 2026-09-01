<?php
/**
 * Focused identifier-safety checks for the feed and direct API projection.
 * Standalone: run with `php tests/ProductIdentifierSafetyTest.php`.
 */

error_reporting(E_ALL);
$failures = 0;

function check_identifier(bool $ok, string $label): void
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

function get_post_meta($id, $key, $single = true) {
    global $meta;
    return $meta[$id][$key] ?? '';
}
function wp_strip_all_tags($value) { return trim(strip_tags((string) $value)); }
function wp_get_attachment_url($id) { return 'https://img.example/' . (int) $id . '.jpg'; }
function get_woocommerce_currency() { return 'USD'; }
function get_locale() { return 'en_US'; }
function wc_get_base_location() { return ['country' => 'US']; }
function get_option($key, $default = false) { return $default; }
function wp_get_post_terms($id, $taxonomy, $args = []) {
    global $brands;
    return isset($brands[$id]) ? [$brands[$id]] : [];
}
function is_wp_error($value) { return false; }
function taxonomy_exists($taxonomy) { return false; }
function get_the_terms($id, $taxonomy) { return false; }
function get_bloginfo($field) { return 'HolisticPeople'; }

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

class WC_Product
{
    public function __construct(
        private int $id,
        private string $sku = '',
        private string $gtin = '',
        private string $type = 'simple'
    ) {}

    public function get_id(): int { return $this->id; }
    public function get_sku(): string { return $this->sku; }
    public function get_global_unique_id(): string { return $this->gtin; }
    public function get_name(): string { return 'Identifier Test Product'; }
    public function get_description(): string { return 'A product used for identifier tests.'; }
    public function get_permalink(): string { return 'https://example.com/product/' . $this->id; }
    public function get_image_id(): int { return 10; }
    public function is_in_stock(): bool { return true; }
    public function get_price(): string { return '10.00'; }
    public function get_weight(): string { return ''; }
    public function get_length(): string { return ''; }
    public function get_width(): string { return ''; }
    public function get_height(): string { return ''; }
    public function is_type(string $type): bool { return $this->type === $type; }
}

$root = dirname(__DIR__);
require $root . '/includes/Services/ProductIdentifiers.php';
require $root . '/includes/Services/ProductSync.php';

$syncReflection = new ReflectionClass(\HP_GMC\Services\ProductSync::class);
$sync = $syncReflection->newInstanceWithoutConstructor();

// GTIN + brand: preserve Woo's native GTIN provider and deterministic brand.
$brands[101] = 'Dragon Herbs';
$product = new WC_Product(101, 'DH005', '679-372000057');
$mapped = $sync->mapProductToGmc($product);
check_identifier(($mapped['gtin'] ?? '') === '679372000057',
    'native GTIN is normalized and emitted');
check_identifier(($mapped['brand'] ?? '') === 'Dragon Herbs',
    'brand projection remains intact alongside GTIN');
check_identifier(!array_key_exists('mpn', $mapped),
    'GTIN product does not fabricate MPN from SKU');
check_identifier(!array_key_exists('identifierExists', $mapped),
    'identifierExists defaults to Google yes when a GTIN is present');
$meta[101]['_hp_gmc_identifier_exists'] = 'no';
$mapped = $sync->mapProductToGmc($product);
check_identifier(!array_key_exists('identifierExists', $mapped),
    'a present GTIN suppresses a contradictory identifierExists=false flag');

// Genuine manufacturer-issued MPN: all review/provenance gates are required.
$meta[102] = [
    '_hp_gmc_mpn' => 'ABC-42-GRN',
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'manufacturer_catalog',
];
$brands[102] = 'Example Manufacturer';
$product = new WC_Product(102, 'INTERNAL-102');
$mapped = $sync->mapProductToGmc($product);
check_identifier(($mapped['mpn'] ?? '') === 'ABC-42-GRN',
    'reviewed manufacturer-catalog MPN is emitted');
check_identifier(($mapped['brand'] ?? '') === 'Example Manufacturer',
    'genuine MPN remains paired with the product brand');
check_identifier(($mapped['mpn'] ?? '') !== $product->get_sku(),
    'internal SKU remains distinct from the manufacturer MPN');

// SKU-only is unresolved: omit identifiers and do not claim they do not exist.
$product = new WC_Product(103, 'STORE-SKU-103');
$mapped = $sync->mapProductToGmc($product);
check_identifier(!array_key_exists('mpn', $mapped),
    'SKU-only product emits no MPN');
check_identifier(!array_key_exists('gtin', $mapped),
    'product without a provider-valid GTIN emits no GTIN');
check_identifier(!array_key_exists('identifierExists', $mapped),
    'unresolved identifiers do not fabricate identifierExists=false');
$invalidGtin = new WC_Product(106, 'STORE-SKU-106', '679372000058');
$mapped = $sync->mapProductToGmc($invalidGtin);
check_identifier(!array_key_exists('gtin', $mapped),
    'GTIN with an invalid GS1 check digit fails closed');

// Explicitly reviewed identifier absence maps to Merchant API false.
$meta[104] = ['_hp_gmc_identifier_exists' => 'no'];
$product = new WC_Product(104, 'STORE-SKU-104');
$mapped = $sync->mapProductToGmc($product);
check_identifier(array_key_exists('identifierExists', $mapped) && $mapped['identifierExists'] === false,
    'reviewed no-identifiers product emits Merchant API identifierExists=false');

// Unverified or unsupported MPN provenance fails closed.
$meta[105] = [
    '_hp_gmc_mpn' => 'UNREVIEWED-105',
    '_hp_gmc_mpn_verified' => 'no',
    '_hp_gmc_mpn_source' => 'manufacturer',
];
$product = new WC_Product(105, 'STORE-SKU-105');
check_identifier(\HP_GMC\Services\ProductIdentifiers::getMpn($product) === '',
    'unverified MPN fails closed');
$meta[105]['_hp_gmc_mpn_verified'] = 'yes';
$meta[105]['_hp_gmc_mpn_source'] = 'internal_sku';
check_identifier(\HP_GMC\Services\ProductIdentifiers::getMpn($product) === '',
    'unsupported internal-SKU provenance fails closed');

// Variations must carry their own identifier; never inherit a parent value.
$meta[200] = [
    '_hp_gmc_mpn' => 'PARENT-MPN',
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'manufacturer',
];
$variation = new WC_Product(201, 'VARIATION-SKU', '', 'variation');
check_identifier(\HP_GMC\Services\ProductIdentifiers::getMpn($variation) === '',
    'variation without its own reviewed MPN does not inherit the parent MPN');
$meta[201] = [
    '_hp_gmc_mpn' => 'VARIANT-BLUE-MPN',
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'product_label',
];
check_identifier(\HP_GMC\Services\ProductIdentifiers::getMpn($variation) === 'VARIANT-BLUE-MPN',
    'variation emits its own reviewed manufacturer identifier');

// Static guards keep both output paths off the historical SKU-as-MPN shortcut.
$feedSource = (string) file_get_contents($root . '/includes/Services/ProductDataFeed.php');
$syncSource = (string) file_get_contents($root . '/includes/Services/ProductSync.php');
check_identifier(strpos($feedSource, '$mpn = trim((string) $product->get_sku())') === false,
    'pull feed no longer copies SKU into MPN');
check_identifier(strpos($syncSource, "'mpn' => \$product->get_sku()") === false,
    'direct API mapper no longer copies SKU into MPN');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
