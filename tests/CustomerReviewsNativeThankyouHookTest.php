<?php
/** Standalone provider-hook contract; no WordPress, HTTP, or external services. */
declare(strict_types=1);
define('ABSPATH', '/');
define('HP_GMC_URL', '/plugin/');
define('HP_GMC_VERSION', 'test');
$_SERVER['HTTP_HOST'] = 'holisticpeople.com';
$GLOBALS['actions'] = [];
$GLOBALS['options'] = ['hp_gmc_customer_reviews_enabled' => 'enabled', 'hp_gmc_merchant_id' => '5298746911'];
$GLOBALS['host'] = 'https://holisticpeople.com/';
$GLOBALS['wpEnvironment'] = 'production';
$GLOBALS['context'] = ['version' => 1, 'status' => 'unavailable', 'reason' => 'unpaid'];
$GLOBALS['checkout_calls'] = 0;

function add_action(string $tag, $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['actions'][$tag][] = [$callback, $priority, $acceptedArgs]; }
function do_action(string $tag, ...$args): void { foreach ($GLOBALS['actions'][$tag] ?? [] as [$callback, , $acceptedArgs]) { $callback(...array_slice($args, 0, $acceptedArgs)); } }
function get_option(string $key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function home_url(string $path = ''): string { return $GLOBALS['host']; }
function wp_get_environment_type(): string { return $GLOBALS['wpEnvironment']; }
function is_ssl(): bool { return true; }
function apply_filters(string $tag, $value) { return $value; }
function is_email(string $email): bool { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
function wp_create_nonce(string $action): string { return 'nonce-' . hash('sha256', $action); }
function wp_verify_nonce(string $nonce, string $action): bool { return hash_equals(wp_create_nonce($action), $nonce); }
function wp_unslash(string $value): string { return $value; }
function esc_html__(string $value, string $domain = ''): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url(string $value): string { return $value; }
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags); }
function get_post_meta(int $id, string $key, bool $single = true): string { return ''; }
function wc_get_product(int $id) { return false; }
function hp_checkout_get_review_confirmation_context_v1(): array {
    $GLOBALS['checkout_calls']++;
    return $GLOBALS['context'];
}
function hp_checkout_render_review_confirmation_auth_fields_v1(): void { echo '<input name="billing_email" value="">'; }
function hp_zen_theme_order_surfaces_complete(): bool { return true; }
function get_template_part(...$ignored): void {}
function esc_html_e(string $value, string $domain = ''): void { echo htmlspecialchars($value, ENT_QUOTES); }
function is_user_logged_in(): bool { return false; }
function wc_get_page_permalink(string $page): string { return '/my-account/'; }

class WC_Product {}
class WC_Order {
    public function __construct(private int $id, private string $status = 'processing') {}
    public function get_id(): int { return $this->id; }
    public function has_status(string|array $status): bool { return in_array($this->status, (array) $status, true); }
    public function get_checkout_payment_url(): string { return '/pay/'; }
    public function get_payment_method(): string { return 'test'; }
}

require dirname(__DIR__) . '/includes/Services/CustomerReviewsEnvironment.php';
require dirname(__DIR__) . '/includes/Services/ProductIdentifiers.php';
require dirname(__DIR__) . '/includes/Services/CustomerReviews.php';
use HP_GMC\Services\CustomerReviews as GCR;

function check(bool $condition, string $label): void { if (!$condition) { throw new RuntimeException($label); } echo "ok $label\n"; }

GCR::register();
check(($GLOBALS['actions']['woocommerce_thankyou'][0][0] ?? null) === [GCR::class, 'renderOnThankyou'], 'registers native Woo thank-you hook');
check(($GLOBALS['actions']['woocommerce_thankyou'][0][1] ?? null) === 20 && ($GLOBALS['actions']['woocommerce_thankyou'][0][2] ?? null) === 0, 'runs without accepting a Woo order ID');

foreach (['unrelated', 'failed', 'unpaid', 'wrong_owner'] as $reason) {
    $GLOBALS['context'] = ['version' => 1, 'status' => 'unavailable', 'reason' => $reason];
    ob_start(); GCR::renderOnThankyou(); check(ob_get_clean() === '', "$reason context renders no output");
}
$GLOBALS['wpEnvironment'] = 'staging';
$GLOBALS['context'] = ['version' => 1, 'status' => 'ready', 'context' => []];
ob_start(); GCR::renderOnThankyou(); check(ob_get_clean() === '', 'staging renders no output');
$GLOBALS['wpEnvironment'] = 'production';

$GLOBALS['context'] = ['version' => 1, 'status' => 'ready', 'context' => [
    'order_reference' => 'gcr_abcdefghijklmnop', 'email' => 'synthetic@example.invalid',
    'delivery_country' => 'US', 'estimated_delivery_date' => '2026-09-15', 'product_ids' => [],
]];
$_SERVER['REQUEST_METHOD'] = 'GET';
$order = new WC_Order(77);
ob_start(); require __DIR__ . '/fixtures/production-zen-thankyou-6b329f.php'; $html = ob_get_clean();
check(hash_file('sha256', __DIR__ . '/fixtures/production-zen-thankyou-6b329f.php') === 'e5690b388645752baa7da7433f58cd4e52fe6347f58a8e5ceeb22d05c871bd0c', 'fixture equals verified production Zen template');
check((bool) preg_match('~hp-zen-order-surface__native">.*hp-gmc-customer-reviews.*</div>~s', $html), 'hooked renderer is inside native Zen container');
check(!str_contains($html, 'synthetic@example.invalid') && !str_contains($html, 'gcr_abcdefghijklmnop') && !str_contains($html, 'apis.google.com'), 'pre-consent hook exposes no private payload or Google loader');
check($GLOBALS['checkout_calls'] > 0, 'Checkout authorization uses its current-request public contract');
ob_start(); GCR::render(); check(ob_get_clean() === '', 'future Zen explicit mount remains deduplicated');
echo "native thank-you hook assertions passed; no external requests made.\n";
