<?php
/**
 * Focused identifier-safety checks for the canonical pull-feed projection.
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
$acfBrands = [];

function get_post_meta($id, $key, $single = true) {
    global $meta;
    return $meta[$id][$key] ?? '';
}
function get_field($field, $id) {
    global $acfBrands;
    return $acfBrands[$id][$field] ?? '';
}
function wp_get_post_terms($id, $taxonomy, $args = []) {
    global $brands;
    return isset($brands[$id]) ? [$brands[$id]] : [];
}
function is_wp_error($value) { return false; }
function taxonomy_exists($taxonomy) { return false; }
function get_the_terms($id, $taxonomy) { return false; }

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

class WC_Product
{
    public function __construct(
        private int $id,
        private string $sku = '',
        private string $gtin = '',
        private string $type = 'simple',
        private int $parentId = 0
    ) {}

    public function get_id(): int { return $this->id; }
    public function get_sku(): string { return $this->sku; }
    public function get_global_unique_id(): string { return $this->gtin; }
    public function is_type(string $type): bool { return $this->type === $type; }
    public function get_parent_id(): int { return $this->parentId; }
}

$root = dirname(__DIR__);
require $root . '/includes/Services/ProductIdentifiers.php';

use HP_GMC\Services\ProductIdentifiers;

// GTIN + brand: preserve native Woo GTIN and deterministic source brand.
$brands[101] = 'Dragon Herbs';
$product = new WC_Product(101, 'DH005', '679-372000057');
$gtin = ProductIdentifiers::getGtin($product);
$brand = ProductIdentifiers::getBrand($product);
check_identifier($gtin === '679372000057', 'native GTIN accepts only allowed hyphen separators');
check_identifier($brand === 'Dragon Herbs', 'source-backed product brand is preserved');
check_identifier(ProductIdentifiers::getMpn($product) === '', 'GTIN product does not fabricate MPN from SKU');
$meta[101]['_hp_gmc_identifier_exists'] = 'no';
check_identifier(ProductIdentifiers::getIdentifierExists($product, $gtin, '', $brand) === '',
    'GTIN and brand suppress a contradictory identifier_exists=no');
$acfBrands[109]['manufacturer'] = (object) ['name' => 'ACF Term Brand'];
check_identifier(ProductIdentifiers::getBrand(new WC_Product(109)) === 'ACF Term Brand',
    'ACF term-object brand is projected without object coercion');

// Malformed/restricted/checksum-invalid GTINs all fail closed.
foreach ([
    '679A372000057' => 'letters are rejected rather than stripped',
    '679/372000057' => 'non-space/non-hyphen separators are rejected',
    "679\t372000057" => 'control whitespace is rejected',
    '679372000058' => 'invalid GS1 check digit is rejected',
    '0200000000008' => 'restricted 02 prefix is rejected',
    '0400000000006' => 'restricted 04 prefix is rejected',
    '2000000000008' => 'restricted 2 prefix is rejected',
] as $candidate => $label) {
    check_identifier(ProductIdentifiers::getGtin(new WC_Product(110, '', $candidate)) === '', $label);
}

// Genuine manufacturer-issued MPN requires value, review, and provenance.
$meta[102] = [
    '_hp_gmc_mpn' => 'ABC-42-GRN',
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'manufacturer_catalog',
];
$brands[102] = 'Example Manufacturer';
$product = new WC_Product(102, 'INTERNAL-102');
$mpn = ProductIdentifiers::getMpn($product);
$brand = ProductIdentifiers::getBrand($product);
check_identifier($mpn === 'ABC-42-GRN', 'reviewed manufacturer-catalog MPN is emitted exactly');
check_identifier($brand === 'Example Manufacturer', 'genuine MPN remains paired with product brand');
check_identifier($mpn !== $product->get_sku(), 'internal SKU remains distinct from manufacturer MPN');
check_identifier(ProductIdentifiers::getIdentifierExists($product, '', $mpn, $brand) === '',
    'brand plus genuine MPN keeps identifier_exists at its default yes');

// MPN identity is never truncated or normalized into a different value.
$meta[120] = [
    '_hp_gmc_mpn' => str_repeat('A', 70),
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'manufacturer',
];
check_identifier(ProductIdentifiers::getMpn(new WC_Product(120)) === str_repeat('A', 70),
    '70-character reviewed MPN is accepted unchanged');
$meta[121] = [
    '_hp_gmc_mpn' => str_repeat('B', 71),
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'manufacturer',
];
check_identifier(ProductIdentifiers::getMpn(new WC_Product(121)) === '',
    '71-character reviewed MPN fails closed instead of truncating');
$meta[122] = [
    '_hp_gmc_mpn' => ' PADDED-MPN ',
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'manufacturer',
];
check_identifier(ProductIdentifiers::getMpn(new WC_Product(122)) === '',
    'MPN requiring whitespace alteration fails closed');

// SKU-only is unresolved: no inferred MPN and no false absence declaration.
$product = new WC_Product(103, 'STORE-SKU-103');
check_identifier(ProductIdentifiers::getMpn($product) === '', 'SKU-only product emits no MPN');
check_identifier(ProductIdentifiers::getGtin($product) === '', 'product without provider-valid GTIN emits no GTIN');
check_identifier(ProductIdentifiers::getBrand($product) === '',
    'missing brand stays blank instead of falling back to HolisticPeople');
check_identifier(ProductIdentifiers::getIdentifierExists($product, '', '', '') === '',
    'unresolved identifier state does not fabricate identifier_exists=no');

// Explicit reviewed absence is valid only when GTIN, MPN, and brand are blank.
$meta[104] = ['_hp_gmc_identifier_exists' => 'no'];
$product = new WC_Product(104, 'STORE-SKU-104');
check_identifier(ProductIdentifiers::getIdentifierExists($product, '', '', '') === 'no',
    'reviewed no-identifiers product emits identifier_exists=no');
$brands[104] = 'Known Brand';
$brand = ProductIdentifiers::getBrand($product);
check_identifier(ProductIdentifiers::getIdentifierExists($product, '', '', $brand) === '',
    'known brand suppresses identifier_exists=no');
check_identifier(ProductIdentifiers::getIdentifierExists($product, '', 'KNOWN-MPN', $brand) === '',
    'brand plus MPN suppresses identifier_exists=no');

// Unverified or unsupported provenance fails closed.
$meta[105] = [
    '_hp_gmc_mpn' => 'UNREVIEWED-105',
    '_hp_gmc_mpn_verified' => 'no',
    '_hp_gmc_mpn_source' => 'manufacturer',
];
$product = new WC_Product(105, 'STORE-SKU-105');
check_identifier(ProductIdentifiers::getMpn($product) === '', 'unverified MPN fails closed');
$meta[105]['_hp_gmc_mpn_verified'] = 'yes';
$meta[105]['_hp_gmc_mpn_source'] = 'internal_sku';
check_identifier(ProductIdentifiers::getMpn($product) === '', 'internal-SKU provenance fails closed');

// Variations inherit brand only; unique identifiers stay variation-specific.
$brands[200] = 'Parent Brand';
$meta[200] = [
    '_hp_gmc_mpn' => 'PARENT-MPN',
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'manufacturer',
];
$variation = new WC_Product(201, 'VARIATION-SKU', '', 'variation', 200);
check_identifier(ProductIdentifiers::getBrand($variation) === 'Parent Brand',
    'variation inherits the source-backed parent brand');
check_identifier(ProductIdentifiers::getMpn($variation) === '',
    'variation never inherits the parent MPN');
$meta[201] = [
    '_hp_gmc_mpn' => 'VARIANT-BLUE-MPN',
    '_hp_gmc_mpn_verified' => 'yes',
    '_hp_gmc_mpn_source' => 'product_label',
];
check_identifier(ProductIdentifiers::getMpn($variation) === 'VARIANT-BLUE-MPN',
    'variation emits its own reviewed manufacturer identifier');

// The obsolete flat direct-push path is removed rather than claimed as v1 parity.
$feedSource = (string) file_get_contents($root . '/includes/Services/ProductDataFeed.php');
$syncSource = (string) file_get_contents($root . '/includes/Services/ProductSync.php');
check_identifier(strpos($feedSource, '$mpn = trim((string) $product->get_sku())') === false,
    'pull feed no longer copies SKU into MPN');
check_identifier(strpos($syncSource, 'mapProductToGmc') === false && strpos($syncSource, 'pushProduct') === false,
    'obsolete non-v1 direct product mapper and request path are disabled');
check_identifier(strpos($syncSource, 'accounts/{$merchantId}/products') === false,
    'obsolete flat products POST request envelope cannot execute');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
