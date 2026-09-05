<?php
define('ABSPATH', '/');
$GLOBALS['options'] = []; $GLOBALS['writes'] = 0;
class WP_Error { public function __construct(public string $code, public string $message) {} public function get_error_message() { return $this->message; } }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['writes']++; $GLOBALS['options'][$key] = $value; return true; }
function verify($value, $message) { if (!$value) { throw new RuntimeException($message); } echo "ok $message\n"; }
require dirname(__DIR__) . '/includes/Services/GoogleSubmissionObservation.php';
use HP_GMC\Services\GoogleSubmissionObservation as O;
$base = ['version'=>1, 'section'=>'countries', 'source'=>O::definitions()['countries']['source'], 'environment'=>'production', 'state'=>'submitted', 'observed_at'=>gmdate('Y-m-d\TH:i:s\Z', time()-100), 'rows'=>['us'=>['country'=>'US','approved_products'=>512,'total_products'=>512], 'il'=>['country'=>'IL','approved_products'=>0,'total_products'=>0]]];
verify(O::import($base) === true, 'bounded observed country rows import');
verify(O::current('countries')['rows']['il']['approved_products'] === 0 && O::current('countries')['rows']['il']['shipping_covered'] === null, 'unknown is distinct from observed zero');
$next = $base; $next['observed_at'] = gmdate('Y-m-d\TH:i:s\Z',time()-80); $next['rows']['il']['shipping_covered'] = true;
verify(O::import($next) === true && O::changedRows('countries') === ['il'], 'real coverage change detected by stable row identity');
$again = $next; $again['observed_at'] = gmdate('Y-m-d\TH:i:s\Z',time()-60); $again['rows'] = array_reverse($again['rows'], true);
verify(O::import($again) === true && O::changedRows('countries') === [], 'timestamp and row ordering alone are not changes');
verify(O::import($base) instanceof WP_Error && O::current('countries')['observed_at'] === $again['observed_at'], 'old observation cannot replace last good');
foreach ([
    ['source'=>'https://example.com'], ['observed_at'=>'2026-02-30T00:00:00Z'], ['observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)], ['environment'=>'unknown'], ['state'=>'enabled'], ['customer_email'=>'private@example.com'],
] as $changes) { verify(O::import(array_replace($base, $changes)) instanceof WP_Error, 'reject invalid envelope/source/time'); }
foreach ([['country'=>'USA'], ['approved_products'=>-1], ['approved_products'=>513,'total_products'=>512], ['action'=>'email=private@example.com'], ['action'=>'<script>'], ['order_id'=>1234], ['shipping_covered'=>'yes']] as $row) {
    $bad=$base; $bad['rows']=['bad'=>$row]; verify(O::import($bad) instanceof WP_Error, 'reject invalid or private field');
}
$shipping=['version'=>1,'section'=>'shipping','source'=>O::definitions()['shipping']['source'],'environment'=>'production','state'=>'submitted','observed_at'=>$base['observed_at'],'rows'=>['ups'=>['countries'=>['IL','AU'],'handling_min'=>0,'handling_max'=>1,'transit_min'=>0,'transit_max'=>0]]];
verify(O::import($shipping)===true && O::current('shipping')['rows']['ups']['transit_max']===0,'faithfully record incorrect zero-day submitted policy rather than silently correct it');
$bad=$shipping; $bad['rows']['ups']['transit_min']=5;
verify(O::import($bad) instanceof WP_Error,'reject internally reversed range');
O::recordFailure('authentication_required','shipping');
verify(O::health('shipping',$shipping['observed_at'])['last_error']==='authentication_required' && O::current('shipping')!==null,'source failure preserves last good observation');
verify(O::health('payments',null)['last_error']===null,'source failures do not contaminate other sections');
O::trackImportResult('widget',new WP_Error('legacy_error','Widget observation storage failed.'));
verify(O::health('widget',null)['last_error']==='storage_failed','legacy storage errors retain their actual cause');
$recovery=$shipping; $recovery['observed_at']=$again['observed_at'];
verify(O::import($recovery)===true && O::health('shipping',$recovery['observed_at'])['last_error']===null,'successful re-observation clears only own failure');
verify(O::health('payments',gmdate('Y-m-d\TH:i:s\Z',time()-2*86400))['freshness']==='stale','daily source stales after36hours');
verify(O::health('countries',gmdate('Y-m-d\TH:i:s\Z',time()-2*86400))['freshness']==='fresh','weekly country source has independent freshness');
verify(O::health('countries',gmdate('Y-m-d\TH:i:s\Z',time()-9*86400))['freshness']==='stale','weekly country source becomes stale');
$writes=$GLOBALS['writes']; O::current('shipping'); O::history('shipping'); O::changedRows('shipping'); O::health('shipping',$recovery['observed_at']);
verify($GLOBALS['writes']===$writes,'report reads have no writes');
$GLOBALS['options'][O::HISTORY]['shipping']=array_fill(0,35,$shipping);
verify(count(O::history('shipping'))===30,'history bounded to30');
$GLOBALS['options'][O::OPTION]='invalid'; $GLOBALS['options'][O::ERRORS]='invalid';
verify(O::current('shipping')===null && O::health('shipping',null)['freshness']==='missing','malformed stored data is fail-soft');
