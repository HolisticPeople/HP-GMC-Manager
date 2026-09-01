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
$sync = file_get_contents($root . '/includes/Services/ProductSync.php');
$identifiers = file_get_contents($root . '/includes/Services/ProductIdentifiers.php');

function method_body(string $source, string $method): string
{
    $pattern = '/private static function ' . preg_quote($method, '/') . '\s*\([^)]*\)\s*:\s*string\s*\{/';
    if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $start = $match[0][1] + strlen($match[0][0]) - 1;
    $depth = 0;
    $len = strlen($source);
    for ($i = $start; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

// --- Version coupling: header, constant, and README changelog must agree ---
preg_match('/^\s*\*\s*Version:\s*([\d.]+)/m', $main, $mHeader);
preg_match("/define\('HP_GMC_VERSION',\s*'([\d.]+)'\)/", $main, $mConst);
$headerVersion = $mHeader[1] ?? '';
$constVersion = $mConst[1] ?? '';
check($headerVersion !== '' && $headerVersion === $constVersion,
    "plugin header Version ($headerVersion) matches HP_GMC_VERSION ($constVersion)");
check($constVersion === '3.4.6',
    'current version is pinned exactly to 3.4.6');
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
require $root . '/includes/Services/ProductIdentifiers.php';
require $root . '/includes/Services/ProductDataFeed.php';

class WC_Product {}

class FakeProduct extends WC_Product
{
    public function __construct(private int $id, private string $gtin) {}
    public function get_id(): int { return $this->id; }
    public function get_global_unique_id(): string { return $this->gtin; }
}

$rm = new ReflectionMethod(\HP_GMC\Services\ProductIdentifiers::class, 'getGtin');

// Native WC field wins and separators are stripped (ISBN-13 with dashes).
check($rm->invoke(null, new FakeProduct(1, '978-1947925229')) === '9781947925229',
    'getGtin normalizes the native WC GTIN field (dashes stripped, 13 digits)');

// Invalid length fails CLOSED (empty), never emitted as-is.
check($rm->invoke(null, new FakeProduct(2, '12345')) === '',
    'getGtin emits empty for invalid-length values (fail closed)');

// Invalid GS1 check digit also fails closed.
check($rm->invoke(null, new FakeProduct(5, '679372000058')) === '',
    'getGtin emits empty for an invalid GS1 check digit');

// Falls back to _global_unique_id meta when the native getter is empty.
$meta[3] = ['_global_unique_id' => '0123456789012'];
check($rm->invoke(null, new FakeProduct(3, '')) === '0123456789012',
    'getGtin falls back to _global_unique_id meta');

// Legacy meta keys still honored.
$meta[4] = ['_wpm_gtin_code' => '4006381333931'];
check($rm->invoke(null, new FakeProduct(4, '')) === '4006381333931',
    'getGtin honors legacy GTIN meta keys');

// --- 3.4.6: MPN and identifier_exists are explicit reviewed data, never SKU inference.
$rmHeaderCheck = strpos($feed, "'identifier_exists',");
check($rmHeaderCheck !== false, 'feed header includes identifier_exists column');
check(strpos($feed, 'ProductIdentifiers::getMpn($product)') !== false,
    'feed MPN comes from the reviewed identifier provider');
check(strpos($feed, 'ProductIdentifiers::getIdentifierExists($product, $gtin, $mpn, $brand)') !== false,
    'identifier_exists comes from the explicit reviewed-absence provider');
check(strpos($feed, '$mpn = trim((string) $product->get_sku())') === false,
    'feed never copies the internal SKU into MPN');
check(strpos($sync, 'mapProductToGmc') === false && strpos($sync, 'pushProduct') === false,
    'obsolete direct product mapper/request path is disabled instead of claiming Merchant API v1 parity');
check(strpos($identifiers, "'_hp_gmc_mpn_verified'") !== false
    && strpos($identifiers, "'_hp_gmc_mpn_source'") !== false,
    'MPN provider requires explicit review and provenance metadata');
check((bool) preg_match("/'gender',\R\s*'identifier_exists',\R\s*\/\/ UCP checkout-compliance columns \(3\.4\.0\)\./", $feed),
    'identifier_exists remains at the end of the 3.3.0 UCP block');

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
    'shipping_length', 'shipping_width', 'shipping_height',
] as $col) {
    check(strpos($feed, "'$col',") !== false, "feed header includes $col column");
}
// --- 3.3.1: product_highlights REMOVED (claims risk — surfaced un-reviewed
// disease/claim language from description bullets into the feed). Guard so it
// cannot silently return without a compliance guard + full claims audit.
check(strpos($feed, "'product_highlights'") === false, 'product_highlights column is NOT in the feed header (removed 3.3.1, claims risk)');
check(strpos($feed, 'getProductHighlights') === false, 'getProductHighlights builder is fully removed (no dead call site)');
// --- 3.3.2: google_product_category default comment corrected. 469 is the
// TOP-LEVEL "Health & Beauty" node, NOT the supplement leaf (525). Guard that
// the wrong comment can't return, the default value stays 469, and the comment
// still documents the real 525 leaf so the lesson isn't lost.
check(strpos($feed, '469 = Health & Beauty > Health Care > Vitamins & Supplements') === false,
    'the WRONG "469 = ...Vitamins & Supplements" comment is gone (469 is the top-level node)');
check((bool) preg_match('/525 = "Health & Beauty > Health Care > Fitness & Nutrition > Vitamins & Supplements"/', $feed),
    'default comment documents the real supplement leaf (525)');
check((bool) preg_match('/Default for products with no per-product mapping.*?\R(?:.*\R)*?\s*return \'469\';/', $feed),
    'getGoogleProductCategory still defaults to 469 for unmapped products');
// identifier_exists must remain the last 3.3.0 UCP column before the 3.4.0
// checkout-compliance append.
check((bool) preg_match("/'identifier_exists',\R\s*\/\/ UCP checkout-compliance columns \(3\.4\.0\)\./", $feed),
    'identifier_exists is still the final 3.3.0 UCP header before 3.4.0 additions');
// price must now source the regular price so sale_price can carry the sale amount.
check(strpos($feed, 'self::getRegularPrice($product)') !== false,
    'price column sources the regular (non-sale) price');

// --- 3.4.0: UCP checkout-compliance transaction-layer columns for Copilot
// Checkout / Google UCP. Eligibility is inert by default; notices are opt-in
// and fail closed; merchant_item_id always maps to the numeric WC product ID.
$nativeCol = "'native_commerce(checkout_eligibility)',";
$noticeCol = "'consumer_notice(notice_type:notice_message)',";
$merchantCol = "'merchant_item_id',";
foreach ([$nativeCol, $noticeCol, $merchantCol] as $col) {
    check(strpos($feed, $col) !== false, "feed header includes " . trim($col, "',"));
}
$posIdentifier = strpos($feed, "'identifier_exists',");
$posNative = strpos($feed, $nativeCol);
$posNotice = strpos($feed, $noticeCol);
$posMerchant = strpos($feed, $merchantCol);
check($posIdentifier !== false && $posNative !== false && $posNotice !== false && $posMerchant !== false
    && $posIdentifier < $posNative && $posNative < $posNotice && $posNotice < $posMerchant,
    '3.4.0 checkout-compliance columns appear in documented order after the 3.3.0 UCP block');

$checkoutBody = method_body($feed, 'checkoutEligibility');
check($checkoutBody !== '' && strpos($checkoutBody, "get_option('hp_gmc_ucp_checkout_eligibility', 'disabled') !== 'enabled'") !== false,
    'checkoutEligibility gates emission on hp_gmc_ucp_checkout_eligibility inside the helper body');

$noticeBody = method_body($feed, 'consumerNotice');
check($noticeBody !== ''
    && strpos($noticeBody, "'legal_disclaimer'") !== false
    && strpos($noticeBody, "'safety_warning'") !== false
    && strpos($noticeBody, "'prop_65'") !== false,
    'consumerNotice enforces the documented notice_type enum');
check($noticeBody !== '' && strpos($noticeBody, '1000') !== false,
    'consumerNotice hard-truncates messages to 1000 chars');

$merchantBody = method_body($feed, 'merchantItemId');
check($merchantBody !== '' && strpos($merchantBody, '(string) max(0, $productId)') !== false,
    'merchantItemId returns the numeric product id as a string');
check(strpos($feed, 'self::escapeField(self::merchantItemId($productId), $format)') !== false,
    'merchant_item_id helper is wired into row emission');

// WP stubs needed to exercise the new builders.
$options = [];
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        global $options;
        if (is_array($options) && array_key_exists($key, $options)) {
            return $options[$key];
        }
        return $key === 'woocommerce_dimension_unit' ? 'in' : $default;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s, $b = false) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($id) {
        global $attachmentUrls;
        return array_key_exists($id, $attachmentUrls) ? $attachmentUrls[$id] : "https://img.example/$id.png";
    }
}
if (!function_exists('get_attached_media')) {
    function get_attached_media($type, $postId) {
        global $attachedMedia;
        return $attachedMedia[$postId] ?? [];
    }
}
if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type($id) {
        global $attachmentMimes;
        return $attachmentMimes[$id] ?? 'image/jpeg';
    }
}
if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata($id) {
        global $attachmentMetadata;
        return $attachmentMetadata[$id] ?? ['width' => 1000, 'height' => 1000];
    }
}

$attachmentUrls = [];
$attachedMedia = [];
$attachmentMimes = [];
$attachmentMetadata = [];

class SaleDate { public function __construct(private string $c) {} public function date($f) { return $this->c; } }

class FakeFeedProduct extends WC_Product
{
    public int $id = 123;
    public array $gallery = [];
    public int $mainImage = 0;
    public string $len = '', $wid = '', $hei = '';
    public bool $onSale = false;
    public string $sale = '', $regular = '', $price = '';
    public ?SaleDate $from = null, $to = null;
    public string $short = '', $long = '';
    public string $name = 'Fallback Product';
    public string $sku = '';
    public function get_id() { return $this->id; }
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
    public function get_name() { return $this->name; }
    public function get_sku() { return $this->sku; }
    public function is_type($type) { return $type === 'simple'; }
    public function is_purchasable() { return true; }
    public function is_in_stock() { return true; }
}

$cls = \HP_GMC\Services\ProductDataFeed::class;
$call = fn(string $m, array $args) => (new ReflectionMethod($cls, $m))->invokeArgs(null, $args);

// additional_image_link: gallery URLs comma-joined, primary image excluded.
$p = new FakeFeedProduct(); $p->gallery = [10, 11, 12]; $p->mainImage = 10;
check($call('getAdditionalImageLink', [$p]) === 'https://img.example/11.png,https://img.example/12.png',
    'getAdditionalImageLink joins gallery URLs and drops the primary image');
$p = new FakeFeedProduct(); $p->mainImage = 20;
$attachedMedia[123] = array_map(fn($id) => (object) ['ID' => $id], [20, 21, 22, 23, 24, 21]);
$attachmentMetadata[21] = ['width' => 1200, 'height' => 800];
$attachmentMetadata[22] = ['width' => 499, 'height' => 800];
$attachmentMetadata[23] = ['width' => 800, 'height' => 800];
$attachmentMimes[23] = 'image/svg+xml';
$attachmentMetadata[24] = ['width' => 800, 'height' => 800];
$attachmentUrls[24] = false;
check($call('getAdditionalImageLink', [$p]) === 'https://img.example/21.png',
    'getAdditionalImageLink safely falls back to distinct 500x500+ product-owned raster images');
$attachedMedia[123] = [(object) ['ID' => 25], (object) ['ID' => 26]];
$attachmentMetadata[25] = $attachmentMetadata[26] = ['width' => 800, 'height' => 800];
$attachmentUrls[25] = $attachmentUrls[26] = 'https://img.example/shared.png';
check($call('getAdditionalImageLink', [$p]) === 'https://img.example/shared.png',
    'getAdditionalImageLink does not emit a duplicate attachment URL');
$attachedMedia[123] = array_map(fn($id) => (object) ['ID' => $id], range(30, 44));
$attachmentMetadata = [];
$attachmentMimes = [];
$attachmentUrls = [];
check(count(explode(',', $call('getAdditionalImageLink', [$p]))) === 10,
    'getAdditionalImageLink caps attachment fallback at 10 URLs');

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

// checkout eligibility: inert by default.
$options = ['hp_gmc_ucp_checkout_eligibility' => 'disabled'];
$p = new FakeFeedProduct();
check($call('checkoutEligibility', [$p]) === '',
    'checkoutEligibility is blank while the global option is disabled');

// consumer_notice: enum + message required, message is sanitized/truncated.
$meta[123] = [
    '_hp_gmc_notice_type' => 'safety_warning',
    '_hp_gmc_notice_message' => "Use as directed:\tline\n<script>alert(1)</script><b>bold</b>",
];
check($call('consumerNotice', [123]) === 'safety_warning:Use as directed: line alert(1)<b>bold</b>',
    'consumerNotice emits type:message with TSV-safe sanitized message');
$meta[124] = [
    '_hp_gmc_notice_type' => 'unsupported',
    '_hp_gmc_notice_message' => 'message',
];
check($call('consumerNotice', [124]) === '',
    'consumerNotice is blank for unsupported notice types');

check($call('merchantItemId', [123]) === '123',
    'merchantItemId emits the numeric WooCommerce product id');

// 3.4.1 feed readiness: required descriptions and a claim-safe agent profile.
$p = new FakeFeedProduct();
check($call('getDescription', [$p]) === 'Fallback Product',
    'blank source descriptions fall back to the truthful product name');
$p->long = 'This formula treats infection and prevents disease.';
$agentDescription = $call('getAgentDescription', [$p, $call('getDescription', [$p])]);
check(strpos($agentDescription, 'treats infection') === false && strpos($agentDescription, 'Fallback Product') === 0,
    'unreviewed high-risk claims are replaced with neutral agent catalog copy');
$meta[123]['_hp_agent_feed_description'] = 'Reviewed agent-safe product copy.';
check($call('getAgentDescription', [$p, $call('getDescription', [$p])]) === 'Reviewed agent-safe product copy.',
    'reviewed agent description meta takes precedence');
$p->name = 'A Cure for Diabetes';
check($call('getAgentTitle', [$p, $p->name]) === '',
    'unreviewed claim-bearing titles are excluded from the agent feed');
$meta[123]['_hp_agent_feed_title'] = 'Reviewed Wellness Book';
check($call('getAgentTitle', [$p, $p->name]) === 'Reviewed Wellness Book',
    'reviewed claim-safe agent title meta restores an excluded item');

$endpoint = (string) file_get_contents($root . '/includes/Rest/ProductFeedEndpoint.php');
check(strpos($endpoint, "'profile' =>") !== false && strpos($endpoint, 'generateAgentFeed') !== false,
    'public product-feed endpoint exposes the agent profile without changing the merchant default');

// 3.4.3 OpenAI feed: separate, explicit allowlist; born empty/fail-closed.
check(strpos($endpoint, "'openai'") !== false && strpos($endpoint, 'generateOpenAiFeed') !== false,
    'public product-feed endpoint exposes a distinct OpenAI profile');
check($call('isOpenAiEligible', [123]) === false,
    'OpenAI feed excludes products without an explicit policy-review flag');
$meta[123]['_hp_openai_feed_eligible'] = 'yes';
check($call('isOpenAiEligible', [123]) === true,
    'OpenAI feed includes only explicitly reviewed products');
check(strpos($feed, "foreach (['merchant', 'agent', 'openai'] as \$profile)") !== false,
    'cache clearing covers the OpenAI profile');

// product_highlights builder removed in 3.3.1 (claims risk) — no test needed;
// the header + no-call-site assertions above are the regression guard.

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
