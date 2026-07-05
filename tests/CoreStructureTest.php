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

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
