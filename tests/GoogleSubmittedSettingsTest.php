<?php
define('ABSPATH','/'); $options=[];
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;} function update_option($k,$v,$a=null){$GLOBALS['options'][$k]=$v;return true;}
class WP_Error { public function __construct(public $code,public $message){} } function is_wp_error($v){return $v instanceof WP_Error;}
require dirname(__DIR__).'/includes/Services/GoogleSubmittedSettings.php'; use HP_GMC\Services\GoogleSubmittedSettings;
function check($v,$m){if(!$v)throw new RuntimeException($m);echo "ok $m\n";}
$v=['version'=>1,'observed_at'=>'2026-09-04T06:10:10Z','support'=>['source'=>GoogleSubmittedSettings::SUPPORT,'url'=>'https://holisticpeople.com/return-policy/','email'=>'office@holisticpeople.com','phone'=>'+16035577635'],'returns'=>['source'=>GoogleSubmittedSettings::RETURNS,'policy_id'=>9298149193,'status'=>'verified','days'=>30,'cost'=>'customer_responsibility','products'=>512],'loyalty'=>['status'=>'not_observed']];
check(GoogleSubmittedSettings::import($v)===true,'valid E164 observed settings import');
check(GoogleSubmittedSettings::current()['support']['phone']==='+16035577635','phone remains normalized E164');
foreach(['tomorrow','2026-02-30T06:10:10Z',gmdate('Y-m-d\\TH:i:s\\Z',time()+120)] as $time){$bad=$v;$bad['observed_at']=$time;check(is_wp_error(GoogleSubmittedSettings::import($bad)),'invalid relative overflow or future UTC time rejected');}
$bad=$v;$bad['support']['source']='https://merchants.google.com/mc/merchantprofile/businessinfo?a=1';check(is_wp_error(GoogleSubmittedSettings::import($bad)),'foreign source rejected');
$bad=$v;$bad['support']['url']='https://holisticpeople.com/contact/?x=1';check(is_wp_error(GoogleSubmittedSettings::import($bad)),'query URL rejected');
$bad=$v;$bad['returns']['products']=-1;check(is_wp_error(GoogleSubmittedSettings::import($bad)),'negative real count rejected');
$next=$v;$next['observed_at']='2026-09-04T06:11:10Z';$next['returns']['status']='pending';$next['returns']['days']=null;$next['returns']['products']=0;check(GoogleSubmittedSettings::import($next)===true,'verified can become pending on later observation');
check(GoogleSubmittedSettings::current()['returns']['days']===null&&GoogleSubmittedSettings::current()['returns']['products']===0,'null is distinct from zero');
$reordered=['returns'=>$next['returns'],'support'=>$next['support'],'loyalty'=>$next['loyalty'],'version'=>1,'observed_at'=>'2026-09-04T06:12:10Z'];check(GoogleSubmittedSettings::import($reordered)===true,'reordered envelope keys remain valid');
check(is_wp_error(GoogleSubmittedSettings::import($v))&&GoogleSubmittedSettings::current()['returns']['status']==='pending','nonmonotonic import preserves last good');
$GLOBALS['options'][GoogleSubmittedSettings::OPTION]='malformed';$GLOBALS['options'][GoogleSubmittedSettings::HISTORY_OPTION]=['bad',$v];check(GoogleSubmittedSettings::current()===null&&count(GoogleSubmittedSettings::history())===1,'malformed current/history are safe');
$GLOBALS['options'][GoogleSubmittedSettings::HISTORY_OPTION]=array_fill(0,35,$v);check(count(GoogleSubmittedSettings::history())===30,'history is bounded');
