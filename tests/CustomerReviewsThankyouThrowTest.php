<?php
/** The native hook must discard partial optional markup when its renderer throws. */
declare(strict_types=1);
define('ABSPATH', '/');
define('HP_GMC_URL', '/plugin/');
define('HP_GMC_VERSION', 'test');
$_SERVER['HTTP_HOST'] = 'holisticpeople.com';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['options'] = ['hp_gmc_customer_reviews_enabled' => 'enabled', 'hp_gmc_merchant_id' => '5298746911'];
$GLOBALS['context'] = ['version' => 1, 'status' => 'ready', 'context' => [
    'order_reference' => 'gcr_abcdefghijklmnop', 'email' => 'synthetic@example.invalid',
    'delivery_country' => 'US', 'estimated_delivery_date' => '2026-09-15', 'product_ids' => [],
]];
function get_option(string $key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function home_url(string $path = ''): string { return 'https://holisticpeople.com/'; }
function wp_get_environment_type(): string { return 'production'; }
function is_ssl(): bool { return true; }
function apply_filters(string $tag, $value) { return $value; }
function is_email(string $email): bool { return true; }
function wp_create_nonce(string $action): string { return 'nonce'; }
function wp_verify_nonce(string $nonce, string $action): bool { return false; }
function wp_unslash(string $value): string { return $value; }
function esc_html__(string $value, string $domain = ''): string { echo 'PARTIAL'; throw new RuntimeException('synthetic renderer throw'); }
function esc_attr(string $value): string { return $value; }
function esc_url(string $value): string { return $value; }
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags); }
function hp_checkout_get_review_confirmation_context_v1(): array { return $GLOBALS['context']; }
function hp_checkout_render_review_confirmation_auth_fields_v1(): void {}
require dirname(__DIR__) . '/includes/Services/CustomerReviewsEnvironment.php';
require dirname(__DIR__) . '/includes/Services/CustomerReviews.php';
ob_start();
\HP_GMC\Services\CustomerReviews::renderOnThankyou();
if (ob_get_clean() !== '') { throw new RuntimeException('Partial GCR markup escaped the native hook buffer.'); }
echo "throwing optional renderer discarded without external request.\n";
