<?php
/**
 * Test exclusion on DH224
 */
require_once '/www/holisticpeoplecom_349/public/wp-load.php';

$result = HP_GMC\Abilities\ProductAbilities::setExclusion([
    'sku' => 'DH224',
    'destinations' => ['Shopping_ads']
]);

echo json_encode($result, JSON_PRETTY_PRINT);
