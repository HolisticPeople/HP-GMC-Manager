<?php
/** Standalone contract check for merchant-feed status ownership. */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/Services/ProductDataFeed.php';

$method = new ReflectionMethod(HP_GMC\Services\ProductDataFeed::class, 'updatesMerchantStatus');
$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "  ok  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
    }
};

$check($method->invoke(null, 'merchant') === true, 'merchant generation owns merchant status');
$check($method->invoke(null, 'agent') === false, 'agent generation cannot overwrite merchant status');
$check($method->invoke(null, 'openai') === false, 'OpenAI generation cannot overwrite merchant status');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
