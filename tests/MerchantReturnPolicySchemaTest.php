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
        '@id' => 'https://example.test/#organization',
        'hasMerchantReturnPolicy' => ['merchantReturnLink' => 'https://example.test/terms/'],
    ],
    ['@type' => 'Organization', '@id' => 'https://example.test/pro/doterra/#profile', 'name' => 'doTERRA'],
    [
        '@type' => 'Corporation',
        '@id' => 'https://example.test/pro/premier-research-labs/#profile',
        'name' => 'Premier Research Labs',
        'hasMerchantReturnPolicy' => ['merchantReturnLink' => 'https://manufacturer.test/returns/'],
    ],
    ['@type' => 'Person', '@id' => 'https://example.test/pro/paul-stamets/#profile'],
    ['@type' => 'Brand', '@id' => 'https://example.test/supplements-brand/doterra/#brand'],
    ['@type' => 'Organization', 'name' => 'Unidentified organization'],
    ['@type' => 'OnlineStore', '@id' => ['not-a-string']],
    [
        '@type' => 'Offer',
        'seller' => ['@id' => 'https://example.test/#organization'],
        'hasMerchantReturnPolicy' => ['merchantReturnDays' => 14],
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

foreach ($graph as $index => $node) {
    if ($index !== 1 && $result[$index] !== $node) {
        fwrite(STDERR, "Only the identified HP retailer may receive HP's return policy; foreign identities and offers must be preserved.\n");
        exit(1);
    }
}

if (MerchantReturnPolicySchemaService::enrichYoastGraph(null) !== []) {
    fwrite(STDERR, "Malformed graph input must retain the existing fail-soft response.\n");
    exit(1);
}

echo "merchant return policy schema contract passed\n";
