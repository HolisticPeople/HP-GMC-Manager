<?php
define('ABSPATH', '/');
$options = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function wp_json_encode($value) { return json_encode($value); }
class WP_Error { public function __construct(public string $code, public string $message) {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/includes/Services/StoreQualitySnapshot.php';
use HP_GMC\Services\StoreQualitySnapshot;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } echo "ok $message\n"; }
$metrics = [];
foreach (['overall_quality','delivery','shipping_cost','return_window','return_cost','promotions_rejection','ewallet','high_resolution_images','images_per_offer','store_rating'] as $name) {
    $metrics[$name] = ['value' => null, 'rating' => 'incomplete'];
}
$metrics['overall_quality'] = ['value' => null, 'rating' => 'great'];
$metrics['ewallet'] = ['value' => 1, 'denominator' => 4, 'providers' => ['PayPal'], 'rating' => 'fair'];
$valid = ['version' => 1, 'observed_at' => '2025-09-04T12:00:00Z', 'source' => ['url' => 'https://merchants.google.com/mc/quality?a=5298746911&region=US', 'country' => 'US', 'window' => 'trailing_30_days', 'scope' => 'all_stores'], 'metrics' => $metrics, 'errors' => []];
check(StoreQualitySnapshot::import($valid) === true, 'allowlisted snapshot imports');
check(StoreQualitySnapshot::current()['metrics']['overall_quality']['rating'] === 'great', 'current snapshot is local and typed');
$invalid = $valid; $invalid['source']['country'] = 'CA';
check(is_wp_error(StoreQualitySnapshot::import($invalid)), 'foreign scope is rejected');
StoreQualitySnapshot::import(array_merge($valid, ['observed_at' => '2025-09-04T13:00:00Z']));
check(count(StoreQualitySnapshot::history()) === 1, 'bounded history retains prior observation');
check(StoreQualitySnapshot::importWidgetObservation(['version' => 1, 'observed_at' => '2025-09-04T13:10:00Z', 'route' => '/shop/', 'status' => 'script_loaded']) === true, 'safe widget observation imports');
check(StoreQualitySnapshot::widgetObservation()['google_receipt'] === 'not_observed', 'widget load is never a receipt');
