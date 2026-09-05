<?php
/** Exercise actual reporting services and feed status without WordPress or remote requests. */
define('ABSPATH', '/');
$GLOBALS['options'] = []; $GLOBALS['writes'] = 0; $GLOBALS['http'] = 0; $GLOBALS['role'] = 'administrator';
class WP_Error { public function __construct(public string $code, public string $message) {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['writes']++; $GLOBALS['options'][$name] = $value; return true; }
function delete_option($name) { $GLOBALS['writes']++; unset($GLOBALS['options'][$name]); return true; }
function current_user_can($cap) { return $cap === 'manage_woocommerce' && in_array($GLOBALS['role'], ['administrator','shop_manager'], true); }
function wp_die($message) { throw new RuntimeException('role_denied'); }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($url) { return esc_html($url); }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return esc_html($text); }
function home_url($path = '') { return 'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud' . $path; }
function rest_url($path = '') { return home_url('/wp-json/' . $path); }
function wp_get_environment_type() { return 'staging'; }
function is_ssl() { return true; }
function wp_remote_get(...$args) { $GLOBALS['http']++; throw new RuntimeException('unexpected_http'); }
function wp_remote_post(...$args) { $GLOBALS['http']++; throw new RuntimeException('unexpected_http'); }
function wp_remote_request(...$args) { $GLOBALS['http']++; throw new RuntimeException('unexpected_http'); }
function hp_ss_get_google_submit_data_v1() {
    if ($GLOBALS['ship_throw'] ?? false) { throw new RuntimeException('unavailable_provider'); }
    return null;
}
foreach (['ProductDataFeed','StoreQualitySnapshot','GoogleSubmittedSettings','GoogleSubmissionObservation','CustomerReviewsEnvironment'] as $service) {
    require dirname(__DIR__) . '/includes/Services/' . $service . '.php';
}
require dirname(__DIR__) . '/includes/Admin/GoogleSubmitDataPage.php';
use HP_GMC\Admin\GoogleSubmitDataPage as Page;
use HP_GMC\Services\GoogleSubmissionObservation as Observation;
use HP_GMC\Services\StoreQualitySnapshot as Quality;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } echo "ok $message\n"; }
function renderPage(): string {
    $writes = $GLOBALS['writes']; $http = $GLOBALS['http'];
    ob_start();
    try { Page::render(); $html = ob_get_contents(); } finally { ob_end_clean(); }
    check($GLOBALS['writes'] === $writes && $GLOBALS['http'] === $http, 'render performs zero option writes and HTTP requests');
    check(!preg_match('/<(?:form|input|textarea|select|button)\b/i', $html), 'report contains no edit or submit controls');
    return $html;
}
foreach (['administrator','shop_manager'] as $role) {
    $GLOBALS['role'] = $role; $html = renderPage();
    check(str_contains($html, '<h1>Google Submit Data</h1>'), $role . ' can view actual report');
}
foreach (['subscriber','customer','anonymous'] as $role) {
    $GLOBALS['role'] = $role; $denied = false;
    ob_start(); try { Page::render(); } catch (RuntimeException $e) { $denied = $e->getMessage() === 'role_denied'; } finally { $output = ob_get_clean(); }
    check($denied && $output === '', $role . ' denied before any report output');
}
$GLOBALS['role'] = 'shop_manager';
$GLOBALS['ship_throw'] = true; $html = renderPage();
check(str_contains($html, 'Unavailable') && str_contains($html, 'Outward silent'), 'absent or throwing optional provider fails softly and staging gate is visible');
foreach (Observation::definitions() as $definition) {
    check(str_contains($html, esc_html($definition['title'] . ' · owner ' . $definition['owner'])), 'section and repository owner are displayed');
}
check(str_contains($html, 'Configuration does not prove Google receipt'), 'missing external observations explicitly remain unknown');
check(str_contains($html, 'No imported Google return observation') && str_contains($html, 'No imported Google loyalty observation'), 'legacy disclosure fallback remains when both typed observations are missing');
check(str_contains($html, '/privacy-policy-holisticpeople/') && str_contains($html, '/terms-service-holisticpeople/'), 'existing canonical disclosure URLs are reported');
check(preg_match('~Local merchant feed rows</th><td><code>No data</code>~', $html), 'ungenerated feed count stays unknown');
$GLOBALS['options']['hp_gmc_primary_feed_last_generated'] = gmdate('Y-m-d H:i:s');
$GLOBALS['options']['hp_gmc_primary_feed_product_count'] = 0;
$html = renderPage();
check(preg_match('~Local merchant feed rows</th><td><code>0</code>~', $html), 'actual generated empty feed retains zero');
check(str_contains($html, 'Local Google Customer Reviews invitation timing. These rules do not submit shipping speeds to Merchant Center.'), 'local invitation estimates are distinguished from Google shipping submission');
$base = ['version'=>1,'section'=>'countries','source'=>Observation::definitions()['countries']['source'],'environment'=>'production','state'=>'submitted','observed_at'=>gmdate('Y-m-d\TH:i:s\Z', time()-120),'rows'=>['us'=>['country'=>'US','approved_products'=>0,'total_products'=>512,'shipping_covered'=>null]]];
check(Observation::import($base) === true, 'valid observation fixture uses actual importer');
$html = renderPage();
check(str_contains($html, 'Changed rows</th><td><code>No previous observation</code>') && !str_contains($html, 'None compared with previous observation'), 'initial observation is a baseline rather than an unchanged comparison');
$next = $base; $next['observed_at'] = gmdate('Y-m-d\TH:i:s\Z', time()-60); $next['rows']['us']['shipping_covered'] = true;
check(Observation::import($next) === true, 'second observation retains history');
Observation::recordFailure('authentication_required', 'countries');
$html = renderPage();
check(str_contains($html, '<td>0</td>') && str_contains($html, '<td>No data</td>') && str_contains($html, '<td>Yes</td>'), 'observed zero unknown and true are rendered distinctly');
check(str_contains($html, 'authentication_required') && str_contains($html, $base['observed_at']) && str_contains($html, '<code>us</code>'), 'source error previous observation and changed row remain visible');
check(Observation::health('payments', null)['last_error'] === null, 'one source failure does not contaminate other sources');
$same = $next; $same['observed_at'] = gmdate('Y-m-d\TH:i:s\Z', time()-30);
check(Observation::import($same) === true, 'identical newer observation imports');
$html = renderPage(); check(str_contains($html, 'None compared with previous observation'), 'unchanged rows have a real prior comparison');
foreach (['returns'=>['standard'=>['policy_id'=>9298149193,'status'=>'verified','window_days'=>30]], 'loyalty'=>['rewards'=>['program'=>'Rewards','status'=>'not_submitted']]] as $scope=>$rows) {
    $observation = $base; $observation['section']=$scope; $observation['source']=Observation::definitions()[$scope]['source']; $observation['rows']=$rows;
    check(Observation::import($observation) === true, $scope . ' typed disclosure fixture imports');
    $html = renderPage();
    check(!str_contains($html, 'No imported Google return observation'), 'typed returns replace contradictory missing legacy returns');
    if ($scope === 'returns') { check(str_contains($html, 'No imported Google loyalty observation'), 'missing typed loyalty keeps its independent legacy fallback'); }
}
check(!str_contains($html, 'Returns and loyalty disclosures') && !str_contains($html, 'No imported Google loyalty observation'), 'both typed disclosures suppress the redundant legacy section');
unset($GLOBALS['options'][Observation::OPTION]['returns']);
$html = renderPage(); check(str_contains($html, 'No imported Google return observation') && !str_contains($html, 'No imported Google loyalty observation'), 'missing typed returns retain their fallback without contradicting typed loyalty');
$metrics=[];
foreach (['overall_quality','delivery','shipping_cost','return_window','return_cost','promotions_rejection','ewallet','high_resolution_images','images_per_offer','store_rating'] as $name) { $metrics[$name]=['value'=>null,'rating'=>'incomplete']; }
$metrics['overall_quality']['rating']='great';
$metrics['ewallet']['denominator']=4;
$snapshot=['version'=>1,'observed_at'=>$base['observed_at'],'source'=>['url'=>'https://merchants.google.com/mc/quality?a=5298746911&region=US','country'=>'US','window'=>'trailing_30_days','scope'=>'all_stores'],'metrics'=>$metrics,'errors'=>[]];
check(Quality::import($snapshot) === true, 'rating-only Store Quality fixture imports');
$html=renderPage();
check(str_contains($html, 'overall_quality</th><td><code>Great</code>') && !str_contains($html, 'No data · great'), 'overall quality displays its rating without a nonexistent numeric score');
check(str_contains($html, 'delivery</th><td><code>No data days · incomplete</code>') && str_contains($html, 'Changed metrics</th><td><code>No previous observation</code>'), 'numeric unknowns remain unknown and quality baseline has no previous observation');
$snapshot['observed_at']=$next['observed_at'];$snapshot['metrics']['overall_quality']['rating']='exceptional';
check(Quality::import($snapshot) === true, 'second rating-only Store Quality fixture imports');
$html=renderPage();
check(str_contains($html, 'Changed overall_quality</th><td><code>Great → Exceptional</code>') && !str_contains($html, 'No data · great'), 'overall rating history and change use the same human display');
$bad = $next; $bad['rows']['us']['customer_email'] = 'private@example.test';
check(Observation::import($bad) instanceof WP_Error, 'unexpected customer field is rejected');
$html = renderPage(); check(!str_contains($html, 'private@example.test'), 'rejected private data never reaches report');
$GLOBALS['options'][Observation::OPTION]['countries'] = ['malformed'=>'<script>not-trusted</script>'];
$html = renderPage(); check(!str_contains($html, 'not-trusted') && str_contains($html, 'No imported observation.'), 'malformed stored observation is suppressed');
echo "Google Submit Data actual-service render checks passed.\n";
