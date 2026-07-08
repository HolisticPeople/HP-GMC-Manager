<?php
/**
 * Core structure / regression-lessons ledger for HP GMC Manager.
 * Standalone: run with `php tests/CoreStructureTest.php` (no WordPress).
 *
 * Every shipped fix adds an assertion here so the bug can't silently return.
 */

error_reporting(E_ALL);
$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "  ok  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label\n";
    }
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/hp-gmc-manager.php');
$readme = file_get_contents($root . '/README.md');
$client = file_get_contents($root . '/includes/Services/MerchantApiClient.php');
$feed = file_get_contents($root . '/includes/Services/ProductDataFeed.php');

// --- Version coupling: header, constant, and README changelog must agree ---
preg_match('/^\s*\*\s*Version:\s*([\d.]+)/m', $main, $mHeader);
preg_match("/define\('HP_GMC_VERSION',\s*'([\d.]+)'\)/", $main, $mConst);
$headerVersion = $mHeader[1] ?? '';
$constVersion = $mConst[1] ?? '';
check($headerVersion !== '' && $headerVersion === $constVersion,
    "plugin header Version ($headerVersion) matches HP_GMC_VERSION ($constVersion)");
check(strpos($readme, "### $constVersion") !== false,
    "README changelog has an entry for $constVersion");

// --- 3.1.0 lesson: Merchant API v1beta was discontinued 2026-02-28 (HTTP 409).
// No merchantapi.googleapis.com call may target v1beta again.
check(!preg_match('#merchantapi\.googleapis\.com/[a-z]+/v1beta#', $client),
    'MerchantApiClient has no merchantapi.googleapis.com v1beta base URLs');
check(substr_count($client, 'merchantapi.googleapis.com') >= 3
    && preg_match_all('#merchantapi\.googleapis\.com/(datasources|products|accounts)/v1/#', $client) === 3,
    'MerchantApiClient targets Merchant API v1 for datasources, products, and accounts');

// --- 3.1.0: GTIN sourcing behavior (run the real normalization + lookup path) ---
// Execute ProductDataFeed with stubs so getGtin's actual logic is exercised,
// not just string-scanned.
$meta = [];
function get_post_meta($id, $key, $single) { global $meta; return $meta[$id][$key] ?? ''; }
if (!defined('ABSPATH')) define('ABSPATH', '/');
require $root . '/includes/Services/ProductDataFeed.php';

class WC_Product {}

class FakeProduct extends WC_Product
{
    public function __construct(private int $id, private string $gtin) {}
    public function get_id(): int { return $this->id; }
    public function get_global_unique_id(): string { return $this->gtin; }
}

$rm = new ReflectionMethod(\HP_GMC\Services\ProductDataFeed::class, 'getGtin');

// Native WC field wins and separators are stripped (ISBN-13 with dashes).
check($rm->invoke(null, new FakeProduct(1, '978-1947925229')) === '9781947925229',
    'getGtin normalizes the native WC GTIN field (dashes stripped, 13 digits)');

// Invalid length fails CLOSED (empty), never emitted as-is.
check($rm->invoke(null, new FakeProduct(2, '12345')) === '',
    'getGtin emits empty for invalid-length values (fail closed)');

// Falls back to _global_unique_id meta when the native getter is empty.
$meta[3] = ['_global_unique_id' => '0123456789012'];
check($rm->invoke(null, new FakeProduct(3, '')) === '0123456789012',
    'getGtin falls back to _global_unique_id meta');

// Legacy meta keys still honored.
$meta[4] = ['_wpm_gtin_code' => '4006381333931'];
check($rm->invoke(null, new FakeProduct(4, '')) === '4006381333931',
    'getGtin honors legacy GTIN meta keys');

// --- 3.2.0: identifier_exists doctrine (user 2026-07-04) — no-GTIN rows declare
// identifier_exists=no; GTIN rows leave it blank. Assert column placement AND logic.
$rmHeaderCheck = strpos($feed, "'identifier_exists',");
check($rmHeaderCheck !== false, 'feed header includes identifier_exists column');
check(strpos($feed, "\$gtin === '' ? 'no' : ''") !== false,
    'identifier_exists emits no exactly when gtin is empty');
check((bool) preg_match("/'gender',\R\s*'identifier_exists',\R\s*\];/", $feed),
    'identifier_exists is the LAST header column (matches row order)');

// --- Availability doctrine (backorder business model, user 2026-07-03):
// the feed must use WC is_in_stock(), NOT HP-Inventory sellable QOH — HP sells
// on backorder and shows in_stock unless a known supplier issue.
check(strpos($feed, "is_in_stock() ? 'in_stock' : 'out_of_stock'") !== false,
    'feed availability comes from WC is_in_stock (backorder model)');
check(strpos($feed, 'hp_inventory_sellable_qoh') === false,
    'feed availability does NOT consult sellable QOH (intentional)');

// --- 3.2.1: supplemental feed must serve RAW text (header+echo+exit), never
// through WP_REST_Response - the JSON-encoded blob was unparseable by GMC and
// silently disabled every override/exclusion since the feeds were linked.
$supp = (string) file_get_contents($root . '/includes/Rest/SupplementalFeedEndpoint.php');
$serve_pos = strpos($supp, 'public static function serveFeed');
$echo_pos = strpos($supp, 'echo $content;', (int) $serve_pos);
$exit_pos = strpos($supp, 'exit;', (int) $echo_pos);
check($serve_pos !== false && $echo_pos !== false && $exit_pos !== false,
    'supplemental serveFeed emits raw content via echo+exit');
check(!preg_match('/new WP_REST_Response\(\$content/', $supp),
    'supplemental serveFeed never wraps feed content in WP_REST_Response');
check(strpos($supp, "header('Content-Type: '", (int) $serve_pos) !== false,
    'supplemental serveFeed sends the content type via header()');

// --- 3.3.0: UCP / agentic-commerce enhancement columns. Assert header presence,
// the price/sale_price pairing, and — crucially — the omit-when-empty behavior
// (a product without the data yields a BLANK cell, never a fabricated value).

// Header presence for every new column.
foreach ([
    'additional_image_link', 'sale_price', 'sale_price_effective_date',
    'product_highlights', 'shipping_length', 'shipping_width', 'shipping_height',
] as $col) {
    check(strpos($feed, "'$col',") !== false, "feed header includes $col column");
}
// identifier_exists must remain the LAST column even after the additions.
check((bool) preg_match("/'gender',\R\s*'identifier_exists',\R\s*\];/", $feed),
    'identifier_exists is STILL the last header column after 3.3.0 additions');
// price must now source the regular price so sale_price can carry the sale amount.
check(strpos($feed, 'self::getRegularPrice($product)') !== false,
    'price column sources the regular (non-sale) price');

// WP stubs needed to exercise the new builders.
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return $key === 'woocommerce_dimension_unit' ? 'in' : $default;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s, $b = false) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($id) { return "https://img.example/$id.png"; }
}

class SaleDate { public function __construct(private string $c) {} public function date($f) { return $this->c; } }

class FakeFeedProduct extends WC_Product
{
    public array $gallery = [];
    public int $mainImage = 0;
    public string $len = '', $wid = '', $hei = '';
    public bool $onSale = false;
    public string $sale = '', $regular = '', $price = '';
    public ?SaleDate $from = null, $to = null;
    public string $short = '', $long = '';
    public function get_gallery_image_ids() { return $this->gallery; }
    public function get_image_id() { return $this->mainImage; }
    public function get_length() { return $this->len; }
    public function get_width() { return $this->wid; }
    public function get_height() { return $this->hei; }
    public function is_on_sale() { return $this->onSale; }
    public function get_sale_price() { return $this->sale; }
    public function get_regular_price() { return $this->regular; }
    public function get_price() { return $this->price; }
    public function get_date_on_sale_from() { return $this->from; }
    public function get_date_on_sale_to() { return $this->to; }
    public function get_short_description() { return $this->short; }
    public function get_description() { return $this->long; }
}

$cls = \HP_GMC\Services\ProductDataFeed::class;
$call = fn(string $m, array $args) => (new ReflectionMethod($cls, $m))->invokeArgs(null, $args);

// additional_image_link: gallery URLs comma-joined, primary image excluded.
$p = new FakeFeedProduct(); $p->gallery = [10, 11, 12]; $p->mainImage = 10;
check($call('getAdditionalImageLink', [$p]) === 'https://img.example/11.png,https://img.example/12.png',
    'getAdditionalImageLink joins gallery URLs and drops the primary image');
$p = new FakeFeedProduct(); // empty gallery
check($call('getAdditionalImageLink', [$p]) === '',
    'getAdditionalImageLink is blank when there are no gallery images (omit-when-empty)');

// sale_price: only a genuine discount, correctly formatted; blank otherwise.
$p = new FakeFeedProduct(); $p->onSale = true; $p->sale = '8'; $p->regular = '10';
check($call('getSalePrice', [$p, 'USD']) === '8.00 USD', 'getSalePrice emits the formatted sale amount');
$p = new FakeFeedProduct(); $p->onSale = false; $p->sale = '8'; $p->regular = '10';
check($call('getSalePrice', [$p, 'USD']) === '', 'getSalePrice is blank when not on sale (omit-when-empty)');
$p = new FakeFeedProduct(); $p->onSale = true; $p->sale = '10'; $p->regular = '10';
check($call('getSalePrice', [$p, 'USD']) === '', 'getSalePrice is blank when sale is not below regular');

// getRegularPrice: prefers the regular price for the price column.
$p = new FakeFeedProduct(); $p->regular = '10'; $p->price = '8';
check($call('getRegularPrice', [$p]) === '10', 'getRegularPrice returns the regular price for the price column');

// sale_price_effective_date: only when BOTH bounds are set.
$p = new FakeFeedProduct(); $p->onSale = true; $p->from = new SaleDate('2026-07-01T00:00:00+00:00'); $p->to = new SaleDate('2026-07-31T00:00:00+00:00');
check($call('getSalePriceEffectiveDate', [$p]) === '2026-07-01T00:00:00+00:00/2026-07-31T00:00:00+00:00',
    'getSalePriceEffectiveDate emits an ISO-8601 start/end range');
$p = new FakeFeedProduct(); $p->onSale = true; $p->from = new SaleDate('2026-07-01T00:00:00+00:00');
check($call('getSalePriceEffectiveDate', [$p]) === '',
    'getSalePriceEffectiveDate is blank when an end date is missing (omit-when-empty)');

// shipping dimensions: value + GMC-valid unit; blank when unset.
$p = new FakeFeedProduct(); $p->len = '3';
check($call('getShippingDimension', [$p, 'length']) === '3 in', 'getShippingDimension emits value + unit');
$p = new FakeFeedProduct();
check($call('getShippingDimension', [$p, 'height']) === '', 'getShippingDimension is blank when the dimension is unset (omit-when-empty)');

// product_highlights: only from genuine <li> bullets, never from paragraphs.
$p = new FakeFeedProduct(); $p->short = '<ul><li>Vegan</li><li>Non-GMO</li></ul>';
check($call('getProductHighlights', [$p]) === 'Vegan,Non-GMO', 'getProductHighlights extracts bullet-list items');
$p = new FakeFeedProduct(); $p->short = '<p>A nice paragraph of prose.</p>';
check($call('getProductHighlights', [$p]) === '', 'getProductHighlights is blank for prose (no fabricated bullets)');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
