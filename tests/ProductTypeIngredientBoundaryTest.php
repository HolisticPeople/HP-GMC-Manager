<?php
/** Commercial product_type must not be chosen from ingredient annotations. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/Services/ProductDataFeed.php';
$GLOBALS['terms'] = [];
$GLOBALS['assigned'] = [];
function get_term($id, $taxonomy) { return $GLOBALS['terms'][$id] ?? false; }
function get_the_terms($id, $taxonomy) { return array_map(fn($n)=>get_term($n,$taxonomy),$GLOBALS['assigned']); }
function is_wp_error($value) { return false; }
class WC_Product { public function get_id() { return 100; } }
function term($id,$parent,$slug,$name): void { $GLOBALS['terms'][$id]=(object)['term_id'=>$id,'parent'=>$parent,'slug'=>$slug,'name'=>$name]; }
term(1,0,'dietary-supplements','Dietary Supplements');term(2,1,'ingredients','Ingredients');term(3,2,'mushrooms','Mushrooms');term(4,3,'ganoderma','Ganoderma');term(5,4,'reishi','Reishi');term(6,1,'herbal-supplements','Herbal Supplements');term(7,6,'mushroom-supplements','Mushroom Supplements');term(8,0,'other','Other');term(9,8,'ingredients','Ingredients Outside Scoped Root');
$m=new ReflectionMethod(HP_GMC\Services\ProductDataFeed::class,'getProductType');
function check($ids,$want,$label): void { global $m;$GLOBALS['assigned']=$ids;$got=$m->invoke(null,new WC_Product());if($want!==$got)throw new RuntimeException($label.': '.$got); }
check([5,7],'Dietary Supplements > Herbal Supplements > Mushroom Supplements','nested ingredient cannot beat real aisle');
check([2,3,4,5,6],'Dietary Supplements > Herbal Supplements','root and all ingredient descendants excluded');
check([2,5],'','only ingredient categories produces blank, not ingredient fallback');
check([9],'Other > Ingredients Outside Scoped Root','unrelated same slug is preserved');
check([7,5,6],'Dietary Supplements > Herbal Supplements > Mushroom Supplements','non-ingredient specificity preserved');
check([],'','no categories stays blank');
echo "PASS commercial taxonomy excludes Ingredient root and descendants, preserves other category paths and blank fallback.\n";
