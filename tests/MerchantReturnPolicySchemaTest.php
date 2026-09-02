<?php
declare(strict_types=1);

define('ABSPATH', '/');
$hooks = [];
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1): void {
    global $hooks;
    $hooks[] = compact('tag', 'callback', 'priority', 'accepted_args');
}
function home_url(string $path = ''): string {
    return 'https://example.test' . $path;
}

$root = dirname(__DIR__);
require $root . '/includes/Services/MerchantReturnPolicySchemaService.php';

use HP_GMC\Services\MerchantReturnPolicySchemaService;

MerchantReturnPolicySchemaService::init();
if (($hooks[0]['tag'] ?? '') !== 'wpseo_schema_graph' || ($hooks[0]['priority'] ?? 0) !== 30) {
    fwrite(STDERR, "Merchant return policy must register after ordinary Yoast graph filters.\n");
    exit(1);
}

$graph = [
    ['@type' => 'WebSite', '@id' => 'site'],
    [
        '@type' => ['Organization', 'OnlineStore'],
        '@id' => 'organization',
        'hasMerchantReturnPolicy' => ['merchantReturnLink' => 'https://example.test/terms/'],
    ],
];
$result = MerchantReturnPolicySchemaService::enrichYoastGraph($graph);
$policy = $result[1]['hasMerchantReturnPolicy'] ?? [];

$expected = [
    'merchantReturnLink' => 'https://example.test/return-policy/',
    'applicableCountry' => 'US',
    'returnPolicyCountry' => 'US',
    'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
    'merchantReturnDays' => 30,
    'returnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
    'customerRemorseReturnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
    'itemDefectReturnFees' => 'https://schema.org/FreeReturn',
    'returnLabelSource' => 'https://schema.org/ReturnLabelDownloadAndPrint',
    'refundType' => 'https://schema.org/FullRefund',
    'restockingFee' => 0,
];

foreach ($expected as $key => $value) {
    if (($policy[$key] ?? null) !== $value) {
        fwrite(STDERR, "Unexpected {$key} in merchant return policy.\n");
        exit(1);
    }
}

if (($policy['itemCondition'] ?? []) !== [
    'https://schema.org/NewCondition',
    'https://schema.org/DamagedCondition',
]) {
    fwrite(STDERR, "Return policy must accept new and damaged conditions.\n");
    exit(1);
}

echo "merchant return policy schema contract passed\n";
