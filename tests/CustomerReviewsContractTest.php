<?php
/** Standalone synthetic tests; no WordPress or network. */
define('ABSPATH', '/');
define('HP_GMC_URL', '/plugin/');
define('HP_GMC_VERSION', 'test');
$_SERVER['HTTP_HOST']='holisticpeople.com';
function wp_get_environment_type() { return $GLOBALS['wpEnvironment'] ?? 'production'; }
$options=[]; $host='https://holisticpeople.com/'; $override=null; $context=[]; $calls=0; $validNonce=false;
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function home_url($path='') { return $GLOBALS['host']; }
function is_ssl() { return true; }
function apply_filters($tag,$value) { return $GLOBALS['override'] ?? $value; }
function is_email($email) { return filter_var($email,FILTER_VALIDATE_EMAIL); }
function wp_create_nonce($action) { return 'nonce-'.hash('sha256',$action); }
function wp_verify_nonce($nonce,$action) { return hash_equals(wp_create_nonce($action),$nonce); }
function wp_unslash($value) { return $value; }
function esc_html__($v,$domain) { return htmlspecialchars($v,ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars($v,ENT_QUOTES); }
function esc_url($v) { return $v; }
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
function hp_checkout_render_review_confirmation_auth_fields_v1() { if (!empty($GLOBALS['authUnavailable'])) { echo 'PARTIAL-AUTH'; throw new RuntimeException('unavailable'); } }
function hp_checkout_get_review_confirmation_context_v1() { $GLOBALS['calls']++; return $GLOBALS['context']; }
function get_post_meta($id,$key,$single=true) { return ''; }
function wc_get_product($id) { return isset($GLOBALS['products'][$id]) ? new WC_Product($id,$GLOBALS['products'][$id]) : false; }
class WC_Product {
    public function __construct(private int $id,private string $gtin) {}
    public function get_id() { return $this->id; }
    public function get_global_unique_id() { return $this->gtin; }
}
require dirname(__DIR__).'/includes/Services/CustomerReviewsEnvironment.php';
require dirname(__DIR__).'/includes/Services/ProductIdentifiers.php';
require dirname(__DIR__).'/includes/Services/CustomerReviews.php';
use HP_GMC\Services\CustomerReviews as GCR;
use HP_GMC\Services\CustomerReviewsEnvironment as Environment;
$n=0;
function check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['n']++; echo "ok $message\n"; }
check(GCR::getOptin()['reason']==='disabled' && $calls===0,'default off does not request order');
$options=['hp_gmc_customer_reviews_enabled'=>'enabled','hp_gmc_merchant_id'=>'5298746911','hp_gmc_environment'=>'production'];
$host='https://unknown.example/';check(Environment::resolve()==='unknown' && GCR::getOptin()['reason']==='outward_silent' && $calls===0,'unknown host silent despite legacy DB override');
$host='https://staging-hp.kinsta.cloud/';check(GCR::getOptin()['reason']==='outward_silent' && $calls===0,'staging silent despite enabled copied option');
$override='production';check(Environment::resolve()==='staging' && GCR::getOptin()['reason']==='outward_silent' && $calls===0,'forced production cannot upgrade staging or request order');
$host='https://unknown.example/';check(Environment::resolve()==='unknown','forced production cannot upgrade unknown host');
$host='https://holisticpeople.com/';$_SERVER['HTTP_HOST']='staging-hp.kinsta.cloud';check(GCR::getOptin()['reason']==='outward_silent' && $calls===0,'actual request host blocks copied production home URL');
$_SERVER['HTTP_HOST']='holisticpeople.com';$wpEnvironment='staging';check(GCR::getOptin()['reason']==='outward_silent' && $calls===0,'WordPress staging identity blocks production host');$wpEnvironment='production';
$override='bad';check(Environment::resolve()==='unknown','invalid override fails closed');
$override=null;$host='https://holisticpeople.com/';
$context=['version'=>1,'status'=>'unavailable','reason'=>'missing_delivery'];check(GCR::getOptin()['reason']==='context_unavailable','missing delivery has no payload');
$ready=['order_reference'=>'gcr_abcdefghijklmnop','email'=>'synthetic@example.invalid','delivery_country'=>'US','estimated_delivery_date'=>'2026-09-15','product_ids'=>[1,2,3,4], 'order_key'=>'NEVER-SHARE','delivery_provenance'=>['private'=>true]];
$context=['version'=>1,'status'=>'ready','context'=>$ready];
$_SERVER['REQUEST_METHOD']='GET';$prompt=GCR::getOptin();check(array_keys($prompt)===['version','status','nonce'] && $prompt['status']==='prompt','GET prompt excludes all customer fields');
$_SERVER['REQUEST_METHOD']='POST';$_POST=['hp_gmc_gcr_consent'=>'yes','hp_gmc_gcr_nonce'=>'bad'];check(GCR::getOptin()['status']==='prompt','invalid nonce never emits payload');
$_POST['hp_gmc_gcr_nonce']=wp_create_nonce('hp_gmc_gcr_other-order');check(GCR::getOptin()['status']==='prompt','cross-order nonce rejected');
$_POST['hp_gmc_gcr_nonce']=$prompt['nonce'];
$products=[1=>'679372000057',2=>'679372000057',3=>'bad',4=>'123456789013'];
$result=GCR::getOptin();check($result['status']==='ready','valid bound consent emits ready');
check(array_keys($result['payload'])===['merchant_id','order_id','email','delivery_country','estimated_delivery_date','products'],'strict Google allowlist excludes access key and internal provenance');
check($result['payload']['products']===[['gtin'=>'679372000057']],'distinct checksum-valid GTIN only; invalid IDs omitted');
$products=[];check(!isset(GCR::getOptin()['payload']['products']),'no GTIN retains store survey without products');
$context['context']['estimated_delivery_date']='2026-02-30';check(GCR::getOptin()['reason']==='context_invalid','impossible date rejected');
$context['context']=$ready;$context['context']['email']='bad';check(GCR::getOptin()['reason']==='context_invalid','invalid email rejected');
$context['context']=$ready;$context['context']['delivery_country']='ZZ';check(GCR::getOptin()['reason']==='context_invalid','unknown delivery country rejected');
$context['context']=$ready;$context['version']=2;check(GCR::getOptin()['reason']==='context_unavailable','unknown provider version rejected');
$context=['version'=>1,'status'=>'ready','context'=>$ready];$options['hp_gmc_merchant_id']='123';check(GCR::getOptin()['reason']==='merchant_mismatch','wrong merchant rejected');
$options['hp_gmc_merchant_id']='5298746911';$_SERVER['REQUEST_METHOD']='GET';
$authUnavailable=true;ob_start();GCR::render();check(ob_get_clean()==='','failed auth-field helper emits no partial or looping consent form');$authUnavailable=false;
ob_start();GCR::render();$html=ob_get_clean();
check(str_contains($html,'hp_gmc_gcr_nonce') && !str_contains($html,$ready['email']) && !str_contains($html,$ready['order_reference']) && !str_contains($html,'apis.google.com') && !str_contains($html,'customer-reviews.js'),'rendered GET is local consent only with no buyer payload or loader');
ob_start();GCR::render();check(ob_get_clean()==='','second renderer call emits nothing');
echo "$n assertions passed; no external requests made.\n";
