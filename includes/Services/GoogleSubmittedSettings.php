<?php
namespace HP_GMC\Services;
if (!defined('ABSPATH')) { exit; }
/** Fixed Merchant Center support/returns/loyalty observations; no live Google read. */
final class GoogleSubmittedSettings {
    public const OPTION = 'hp_gmc_submitted_settings_v1';
    private const SUPPORT = 'https://merchants.google.com/mc/merchantprofile/businessinfo?a=5298746911';
    private const RETURNS = 'https://merchants.google.com/mc/returnpolicies/adsorganic/standard/edit?a=5298746911&policyId=9298149193';
    public static function import(array $v) {
        if (($v['version']??null)!==1 || !self::time($v['observed_at']??null) || ($v['support']['source']??'')!==self::SUPPORT || ($v['returns']['source']??'')!==self::RETURNS || !in_array($v['loyalty']['status']??'', ['not_observed','configured'],true)) return new \WP_Error('hp_gmc_submitted_settings_invalid','Invalid submitted settings observation.');
        if (!is_string($v['support']['url']??null) || !preg_match('~^https://holisticpeople\.com/[a-z0-9/-]+/$~D',$v['support']['url']) || !filter_var($v['support']['email']??'',FILTER_VALIDATE_EMAIL) || !preg_match('/^\+[1-9][0-9 -]{6,20}$/D',$v['support']['phone']??'')) return new \WP_Error('hp_gmc_submitted_settings_invalid','Invalid support value.');
        if (($v['returns']['policy_id']??null)!==9298149193 || ($v['returns']['status']??'')!=='verified' || !is_int($v['returns']['days']??null) || $v['returns']['days']<0 || $v['returns']['days']>3650) return new \WP_Error('hp_gmc_submitted_settings_invalid','Invalid return policy value.');
        $cost=$v['returns']['cost']??'unknown'; if(!in_array($cost,['customer_responsibility','free','unknown'],true)) return new \WP_Error('hp_gmc_submitted_settings_invalid','Invalid return cost.');
        $clean=['version'=>1,'observed_at'=>$v['observed_at'],'support'=>['source'=>self::SUPPORT,'url'=>$v['support']['url'],'email'=>$v['support']['email'],'phone'=>$v['support']['phone']],'returns'=>['source'=>self::RETURNS,'policy_id'=>9298149193,'status'=>'verified','days'=>$v['returns']['days'],'cost'=>$cost,'products'=>is_int($v['returns']['products']??null)?$v['returns']['products']:null],'loyalty'=>['status'=>$v['loyalty']['status']]];
        update_option(self::OPTION,$clean,false); return get_option(self::OPTION)===$clean?true:new \WP_Error('hp_gmc_submitted_settings_storage','Storage failed.');
    }
    public static function current(): ?array { $v=get_option(self::OPTION,null); return is_array($v)?$v:null; }
    private static function time($v): bool { return is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$v); }
}
