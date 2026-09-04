<?php
/** Exercise the actual production gate without fetching any external script. */
define('ABSPATH', '/'); define('HP_GMC_URL', 'https://holisticpeople.com/plugin/'); define('HP_GMC_VERSION', 'test');
$state = [];
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
function is_page($slug) { return $GLOBALS['state']['reviews'] ?? false; }
function is_singular() { return $GLOBALS['state']['singular'] ?? false; }
function get_post_status() { return $GLOBALS['state']['post_status'] ?? 'publish'; }
function post_password_required() { return $GLOBALS['state']['password'] ?? false; }
function wp_enqueue_script(...$args) { $GLOBALS['state']['enqueued'][] = $args; }
function wp_add_inline_script(...$args) { $GLOBALS['state']['inline'][] = $args; }
require dirname(__DIR__) . '/includes/Services/CustomerReviewsEnvironment.php';
require dirname(__DIR__) . '/includes/Plugin.php';
function verifyWidget(array $changes, array $query, bool $expected, string $label): void {
    $GLOBALS['state'] = array_replace(['home'=>'https://holisticpeople.com','environment'=>'production','product'=>true,'singular'=>true,'options'=>['hp_gmc_store_widget_enabled'=>'enabled','hp_gmc_merchant_id'=>'5298746911']], $changes);
    $_SERVER['HTTP_HOST'] = 'holisticpeople.com'; $_GET = $query;
    HP_GMC\Plugin::enqueue_store_widget();
    if (!empty($GLOBALS['state']['enqueued']) !== $expected) { throw new RuntimeException($label); }
    echo "ok $label\n";
}
verifyWidget([], [], true, 'published public product eligible');
verifyWidget([], ['utm_source'=>'google','gclid'=>'test-public-click'], true, 'normal Google campaign eligible');
verifyWidget([], ['key'=>'private-order-key'], false, 'order key suppressed');
verifyWidget([], ['utm_source'=>['nested']], false, 'non-scalar query suppressed');
verifyWidget(['preview'=>true], [], false, 'preview suppressed');
verifyWidget(['post_status'=>'private'], [], false, 'private product suppressed');
verifyWidget(['password'=>true], [], false, 'password-protected product suppressed');
verifyWidget(['checkout'=>true], [], false, 'checkout suppressed');
verifyWidget(['account'=>true], [], false, 'account suppressed');
verifyWidget(['environment'=>'staging','override'=>'production'], [], false, 'staging cannot be upgraded');
verifyWidget(['home'=>'https://untrusted.example'], [], false, 'foreign home suppressed');
verifyWidget(['ssl'=>false], [], false, 'HTTP suppressed');
verifyWidget(['options'=>['hp_gmc_merchant_id'=>'5298746911']], [], false, 'default disabled');
echo "Store widget environment checks passed.\n";
