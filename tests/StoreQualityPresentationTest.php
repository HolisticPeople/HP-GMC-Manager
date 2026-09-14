<?php
/** Exercise the actual production gate without fetching any external script. */
define('ABSPATH', '/'); define('HP_GMC_URL', 'https://holisticpeople.com/plugin/'); define('HP_GMC_VERSION', 'test');
$state = [];
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES); }
function get_option($name, $default = false) { return $GLOBALS['state']['options'][$name] ?? $default; }
function home_url($path = '') { return $GLOBALS['state']['home'] . $path; }
function wp_get_environment_type() { return $GLOBALS['state']['environment']; }
function apply_filters($name, $value) { return $GLOBALS['state']['override'] ?? $value; }
function is_admin() { return $GLOBALS['state']['admin'] ?? false; }
function is_ssl() { return $GLOBALS['state']['ssl'] ?? true; }
function is_preview() { return $GLOBALS['state']['preview'] ?? false; }
function is_checkout() { return $GLOBALS['state']['checkout'] ?? false; }
function is_account_page() { return $GLOBALS['state']['account'] ?? false; }
function is_cart() { return $GLOBALS['state']['cart'] ?? false; }
function is_front_page() { return $GLOBALS['state']['front'] ?? false; }
function is_shop() { return $GLOBALS['state']['shop'] ?? false; }
function is_product() { return $GLOBALS['state']['product'] ?? false; }
function is_product_category() { return false; }
function is_page($slug) { return in_array($GLOBALS['state']['page'] ?? '', (array) $slug, true); }
function is_wc_endpoint_url() { return $GLOBALS['state']['endpoint'] ?? false; }
function is_singular() { return $GLOBALS['state']['singular'] ?? false; }
function get_post_status() { return $GLOBALS['state']['post_status'] ?? 'publish'; }
function post_password_required() { return $GLOBALS['state']['password'] ?? false; }
function wp_enqueue_script(...$args) { $GLOBALS['state']['enqueued'][] = $args; }
function wp_add_inline_script(...$args) { $GLOBALS['state']['inline'][] = $args; }
require dirname(__DIR__) . '/includes/Services/CustomerReviewsEnvironment.php';
require dirname(__DIR__) . '/includes/Services/StoreQualityLink.php';
require dirname(__DIR__) . '/includes/Plugin.php';

function is_user_logged_in() { return $GLOBALS['state']['logged'] ?? true; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['state']['caps'] ?? ['manage_options'], true); }
function wp_json_encode($value) { return json_encode($value); }
require dirname(__DIR__) . '/includes/Services/StoreQualityPresentation.php';
function previewCase(array $changes, array $query, bool $expected, string $label): void {
    $GLOBALS['state'] = array_replace(['home'=>'https://holisticpeople.com','environment'=>'production','product'=>true,'singular'=>true,'options'=>['hp_gmc_merchant_id'=>'5298746911']], $changes);
    $_SERVER['HTTP_HOST'] = $GLOBALS['state']['host'] ?? 'holisticpeople.com';
    $_SERVER['REQUEST_URI'] = $GLOBALS['state']['path'] ?? '/product/example/'; $_GET = $query;
    $value = HP_GMC\Services\StoreQualityPresentation::get();
    if (($value !== null) !== $expected) { throw new RuntimeException($label); }
    HP_GMC\Services\StoreQualityPresentation::enqueue();
    if (!empty($GLOBALS['state']['enqueued']) !== $expected) { throw new RuntimeException($label . ' enqueue'); }
    if ($expected && ($value['preview'] !== true || $value['interactive'] !== true || $value['url'] !== 'https://www.google.com/storepages?q=holisticpeople.com&c=US')) { throw new RuntimeException('contract'); }
    echo "ok $label\n";
}
$q = ['hp_google_quality_preview'=>'1'];
previewCase([], [], false, 'ordinary public baseline');
previewCase([], $q, true, 'administrator preview');
previewCase(['caps'=>['manage_woocommerce']], $q, true, 'shop manager preview');
previewCase(['logged'=>false], $q, false, 'anonymous denied');
previewCase(['caps'=>['read']], $q, false, 'subscriber denied');
previewCase(['caps'=>[]], $q, false, 'customer denied');
previewCase([], ['hp_google_quality_preview'=>['1']], false, 'malformed flag denied');
foreach (['key', 'order_id', 'email', 'nonce'] as $key) { previewCase([], $q + [$key=>'private'], false, $key . ' denied'); }
previewCase([], $q + ['utm_source'=>['bad']], false, 'nested tracking denied');
previewCase(['password'=>true], $q, false, 'password page denied');
previewCase(['post_status'=>'draft'], $q, false, 'draft denied');
previewCase(['preview'=>true], $q, false, 'WordPress preview denied');
previewCase(['account'=>true], $q, false, 'account denied');
previewCase(['cart'=>true], $q, false, 'cart denied');
previewCase(['host'=>'foreign.example'], $q, false, 'foreign request denied');
previewCase(['ssl'=>false], $q, false, 'insecure denied');
previewCase(['options'=>['hp_gmc_merchant_id'=>'123']], $q, false, 'wrong merchant denied');
previewCase(['home'=>'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud','host'=>'env-holisticpeoplecom-hpdevplus.kinsta.cloud','environment'=>'staging','override'=>'production'], $q, true, 'staging layout allowed');
if (!str_contains($GLOBALS['state']['inline'][0][1], '"allowGoogle":false')) { throw new RuntimeException('staging cannot load Google'); }
previewCase([], $q, true, 'production outbound click permitted');
if (!str_contains($GLOBALS['state']['inline'][0][1], '"allowGoogle":true')) { throw new RuntimeException('production config'); }
previewCase(['path'=>'/hp-checkout/','product'=>false], $q + ['hp_source'=>'sidecart'], true, 'custom sidecart checkout');
previewCase(['path'=>'/hp-checkout/order-pay/1/'], $q, false, 'private checkout endpoint');
previewCase(['path'=>'/hp-checkout/%6frder-received/1/'], $q, false, 'encoded private checkout endpoint');
previewCase(['checkout'=>true,'path'=>'/checkout/'], $q, false, 'native checkout denied');
foreach (['reviews', 'return-policy', 'terms-service-holisticpeople', 'privacy-policy-holisticpeople'] as $page) {
    previewCase(['product'=>false,'page'=>$page,'path'=>'/' . $page . '/'], $q, true, $page . ' preview');
}
previewCase(['product'=>false,'page'=>'unrelated'], $q, false, 'unrelated route denied');
echo "Store quality preview gates passed.\n";
