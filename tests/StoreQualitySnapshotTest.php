<?php
define('ABSPATH', '/');
$options = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function wp_json_encode($value) { return json_encode($value); }
class WP_Error { public function __construct(public string $code, public string $message) {} public function get_error_message() { return $this->message; } }
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

$bad = $valid; $bad['observed_at'] = 'tomorrow';
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'relative observation time is rejected');
$bad = $valid; $bad['observed_at'] = '2025-02-30T12:00:00Z';
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'overflow observation date is rejected');
$bad = $valid; $bad['observed_at'] = gmdate('Y-m-d\\TH:i:s\\Z', time() + 120);
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'future observation time is rejected');
$bad = $valid;
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'nonmonotonic observation time is rejected');
$bad = $valid; $bad['metrics']['delivery']['value'] = INF;
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'non-finite metric is rejected');
$bad = $valid; $bad['metrics']['overall_quality']['value'] = 1;
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'overall quality numeric value is rejected');
$bad = $valid; $bad['metrics']['delivery']['unit'] = 'USD';
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'per-metric unit is enforced');
$bad = $valid; $bad['metrics']['ewallet']['providers'] = ['Stripe'];
check(is_wp_error(StoreQualitySnapshot::import($bad)), 'wallet provider allowlist is enforced');
$GLOBALS['options'][StoreQualitySnapshot::HISTORY_OPTION] = 'malformed';
check(StoreQualitySnapshot::history() === [], 'scalar history never throws or leaks');
$lastGood = StoreQualitySnapshot::current();
check(StoreQualitySnapshot::recordFailure('source_unavailable') === true, 'fixed refresh failure code records');
check(StoreQualitySnapshot::current() === $lastGood && StoreQualitySnapshot::freshness()['error'] === 'source_unavailable', 'failure preserves last good and exposes code');
check(is_wp_error(StoreQualitySnapshot::recordFailure('network_failure')), 'unknown refresh failure code is rejected');
check(is_wp_error(StoreQualitySnapshot::importWidgetObservation(['version'=>1,'observed_at'=>'2025-09-04T13:11:00Z','route'=>'/shop/?x=1','status'=>'widget_visible','variant'=>'store_quality'])), 'widget query route is rejected');
check(is_wp_error(StoreQualitySnapshot::importWidgetObservation(['version'=>1,'observed_at'=>'2025-09-04T13:11:00Z','route'=>'/shop/','status'=>'script_loaded','variant'=>'store_quality'])), 'widget variant needs visibility');
$reviews = ['version'=>1,'observed_at'=>'2025-09-04T13:20:00Z','source'=>['gcr_url'=>StoreQualitySnapshot::GCR_URL,'product_reviews_url'=>StoreQualitySnapshot::PRODUCT_REVIEWS_URL,'country'=>'all','window'=>'panel_default'],'gcr_status'=>'no_data','product_reviews_status'=>'active','survey_optins'=>null,'surveys_offered'=>0,'survey_responses'=>null,'matched_gtins'=>3,'product_survey_responses'=>null];
check(StoreQualitySnapshot::importReviewsObservation($reviews) === true, 'reviews preserve observed time/source and null counters');
check(StoreQualitySnapshot::reviewsObservation()['survey_optins'] === null && StoreQualitySnapshot::reviewsObservation()['matched_gtins'] === 3, 'null is distinct from zero and product counter persists');
$reviews['observed_at'] = '2025-02-30T13:20:00Z';
check(is_wp_error(StoreQualitySnapshot::importReviewsObservation($reviews)), 'invalid review timestamp is rejected');
