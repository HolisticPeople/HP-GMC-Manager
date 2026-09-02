<?php
declare(strict_types=1);

define('ABSPATH', '/');
require dirname(__DIR__) . '/includes/Services/StorefrontPolicyContentMigration.php';

use HP_GMC\Services\StorefrontPolicyContentMigration;

$return = StorefrontPolicyContentMigration::returnPolicyContent();
foreach ([
    'within 30 days of delivery',
    'There is no restocking fee',
    'actual cost of that label is the customer’s responsibility',
    'prepaid downloadable return label at no cost to you',
    'issue the refund to the original payment method within 10 days',
    'bank or payment provider may need additional time',
    'We do not offer ordinary exchanges',
] as $needle) {
    if (!str_contains($return, $needle)) {
        fwrite(STDERR, "Return policy content missing: {$needle}\n");
        exit(1);
    }
}

$legacyTerms = '<h2>Refunds and No Returns Policy&nbsp;</h2><p>No returns after shipment.</p>'
    . '<h2>Summary</h2><ol><li>No Returns Policy - once items are shipped out, we do not accept returns to ensure the highest quality.</li></ol>';
$terms = StorefrontPolicyContentMigration::termsContent($legacyTerms);
if (str_contains($terms, 'No Returns Policy') || substr_count($terms, '/return-policy/') !== 2) {
    fwrite(STDERR, "Terms migration did not remove both legacy no-return claims.\n");
    exit(1);
}

echo "storefront policy content migration contract passed\n";
