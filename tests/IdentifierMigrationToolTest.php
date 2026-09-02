<?php
/** Standalone contract checks for the deployed identifier operator. */

declare(strict_types=1);

define('HP_GMC_IDENTIFIER_MIGRATION_LIBRARY_ONLY', true);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
$GLOBALS['hp_gmc_test_consumed_options'] = [];
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = true): bool
{
    if (array_key_exists($key, $GLOBALS['hp_gmc_test_consumed_options'])) {
        return false;
    }
    $GLOBALS['hp_gmc_test_consumed_options'][$key] = $value;
    return true;
}
require dirname(__DIR__) . '/includes/Operations/IdentifierMigrationOperator.php';

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "  ok  {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";
    }
};

$check(hp_gmc_identifier_is_staging(
    'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/',
    'staging'
), 'exact approved host plus authoritative staging type is accepted');
$check(!hp_gmc_identifier_is_staging(
    'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/',
    'production'
), 'approved host is rejected when WordPress environment type is production');
$check(!hp_gmc_identifier_is_staging(
    'https://production-store.kinsta.cloud/',
    'staging'
), 'production-like Kinsta host is rejected');
$check(!hp_gmc_identifier_is_staging(
    'https://notstaging.example.com/',
    'staging'
), 'staging substring trap is rejected');
$check(!hp_gmc_identifier_is_staging(
    'https://hpdevelopment.example.com/',
    'staging'
), 'hpdev substring trap is rejected');
$check(!hp_gmc_identifier_is_staging('https://holisticpeople.com/', 'production'), 'production host is rejected');
$check(hp_gmc_identifier_is_production(
    'https://holisticpeople.com/',
    'production'
), 'exact production host plus authoritative production type is accepted');
$check(!hp_gmc_identifier_is_production(
    'https://holisticpeople.com/',
    'staging'
), 'production host is rejected when WordPress environment type is staging');
$check(!hp_gmc_identifier_is_production(
    'https://www.holisticpeople.com/',
    'production'
), 'production host alias is rejected');
$check(!hp_gmc_identifier_is_production(
    'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/',
    'production'
), 'staging host is rejected for production operations');

$base = [
    'schema' => HP_GMC_IDENTIFIER_MANIFEST_SCHEMA,
    'target_environment' => 'staging',
    'rows' => [[
        'product_id' => 10,
        'gmc_offer_id' => 'gla_10',
        'review_status' => 'accepted',
        'woo_sku' => 'STORE-10',
        'gtin' => '',
        'raw_sku_mfr' => 'MFR-10',
        'proposed_mpn' => 'MFR-10',
        'manufacturer_provenance' => [
            'type' => 'manufacturer_catalog',
            'evidence' => 'Manufacturer catalog page and dated reviewer packet.',
        ],
        'reviewer' => 'independent-reviewer',
        'review_date' => '2026-09-02',
    ]],
];
$check(hp_gmc_identifier_validate_manifest($base) === [], 'accepted source-backed row validates');

$production = $base;
$production['target_environment'] = 'production';
$check(
    hp_gmc_identifier_validate_manifest($production, 'production') === [],
    'production manifest validates only when production is explicitly expected'
);
$check(
    hp_gmc_identifier_validate_manifest($base, 'production') !== [],
    'staging manifest cannot authorize a production operation'
);

$bad = $base;
$bad['rows'][0]['proposed_mpn'] = 'INVENTED';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'accepted MPN cannot differ from reviewed legacy value');

$bad = $base;
$bad['rows'][0]['manufacturer_provenance']['type'] = 'internal_sku';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'internal SKU provenance is rejected');

$bad = $base;
$bad['rows'][0]['manufacturer_provenance']['evidence'] = '';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'accepted row requires provenance evidence');

$bad = $base;
$bad['rows'][0]['woo_sku'] = 'MFR-10';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'accepted MPN cannot equal the Woo SKU');

$bad = $base;
$bad['rows'][0]['gtin'] = '012345678905';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'GTIN-bearing row is rejected from this MPN migration');

$bad = $base;
$duplicate = $bad['rows'][0];
$duplicate['product_id'] = 11;
$duplicate['gmc_offer_id'] = 'gla_11';
$duplicate['gtin'] = '';
$bad['rows'][0]['gtin'] = '';
$bad['rows'][] = $duplicate;
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'duplicate accepted MPN is rejected');

$deferred = $base;
$deferred['rows'][0] = [
    'product_id' => 10,
    'gmc_offer_id' => 'gla_10',
    'review_status' => 'deferred',
];
$check(hp_gmc_identifier_validate_manifest($deferred) === [], 'deferred row needs no fabricated identifier');

$manifestSha = str_repeat('a', 64);
$feedSnapshot = [
    'sha256' => str_repeat('b', 64),
    'product_count' => 530,
];
$authorization = [
    'schema' => HP_GMC_IDENTIFIER_PRODUCTION_AUTHORIZATION_SCHEMA,
    'target_environment' => 'production',
    'target_host' => HP_GMC_IDENTIFIER_PRODUCTION_HOST,
    'target_site_url' => 'https://holisticpeople.com/',
    'target_merchant_id_sha256' => hash('sha256', 'merchant-123'),
    'manifest_sha256' => $manifestSha,
    'operation' => 'apply',
    'expected_canonical_phase' => 'rolled_back',
    'authorization_id' => '123e4567-e89b-42d3-a456-426614174000',
    'confirmation' => hp_gmc_identifier_production_confirmation('apply', $manifestSha),
    'expected_manifest_rows' => 1,
    'expected_accepted_rows' => 1,
    'expected_feed_before_sha256' => $feedSnapshot['sha256'],
    'expected_feed_product_count' => 530,
    'approved_by' => 'publish-manager',
    'approval_reference' => 'publish-ready-frozen:sha256',
    'approved_at_utc' => '2026-09-02T10:00:00Z',
    'expires_at_utc' => '2026-09-02T14:00:00Z',
];
$authorizationErrors = hp_gmc_identifier_validate_production_authorization(
    $authorization,
    'apply',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
);
$check($authorizationErrors === [], 'fully bound production authorization validates');

$badAuthorization = $authorization;
$badAuthorization['target_merchant_id_sha256'] = hash('sha256', 'wrong-account');
$check(hp_gmc_identifier_validate_production_authorization(
    $badAuthorization,
    'apply',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
) !== [], 'merchant-account fingerprint mismatch is rejected');

$badAuthorization = $authorization;
$badAuthorization['expected_feed_product_count'] = 529;
$check(hp_gmc_identifier_validate_production_authorization(
    $badAuthorization,
    'apply',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
) !== [], 'served merchant-feed count mismatch is rejected');

$badAuthorization = $authorization;
$badAuthorization['expires_at_utc'] = '2026-09-02T11:59:59Z';
$check(hp_gmc_identifier_validate_production_authorization(
    $badAuthorization,
    'apply',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
) !== [], 'expired production authorization is rejected');

$badAuthorization = $authorization;
$badAuthorization['confirmation'] = 'GO';
$check(hp_gmc_identifier_validate_production_authorization(
    $badAuthorization,
    'apply',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
) !== [], 'generic confirmation string is rejected');

$badAuthorization = $authorization;
$badAuthorization['expires_at_utc'] = '2026-09-02T15:00:01Z';
$check(hp_gmc_identifier_validate_production_authorization(
    $badAuthorization,
    'apply',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
) !== [], 'authorization window longer than four hours is rejected');

$badAuthorization = $authorization;
$badAuthorization['expected_canonical_phase'] = 'applied';
$check(hp_gmc_identifier_validate_production_authorization(
    $badAuthorization,
    'apply',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
) !== [], 'apply authorization cannot target an already-applied canonical phase');

$regenerateAuthorization = $authorization;
$regenerateAuthorization['operation'] = 'regenerate';
$regenerateAuthorization['expected_canonical_phase'] = 'applied';
$regenerateAuthorization['confirmation'] = hp_gmc_identifier_production_confirmation('regenerate', $manifestSha);
$check(hp_gmc_identifier_validate_production_authorization(
    $regenerateAuthorization,
    'regenerate',
    $manifestSha,
    $production,
    'https://holisticpeople.com/',
    'merchant-123',
    $feedSnapshot,
    strtotime('2026-09-02T12:00:00Z')
) === [], 'regeneration authorization accepts the explicit applied phase');

$verifiedPath = tempnam(sys_get_temp_dir(), 'hp-gmc-json-');
$verifiedDocument = ['schema' => 'test', 'value' => 1];
$verifiedBytes = json_encode($verifiedDocument, JSON_THROW_ON_ERROR);
file_put_contents($verifiedPath, $verifiedBytes);
$check(
    hp_gmc_identifier_verified_json($verifiedPath, hash('sha256', $verifiedBytes)) === $verifiedDocument,
    'verified JSON hashes and decodes the same captured bytes'
);
try {
    hp_gmc_identifier_verified_json($verifiedPath, str_repeat('0', 64));
    $check(false, 'verified JSON rejects a mismatched checksum');
} catch (RuntimeException) {
    $check(true, 'verified JSON rejects a mismatched checksum');
}
unlink($verifiedPath);

$consumed = hp_gmc_identifier_consume_production_authorization(
    $authorization,
    str_repeat('c', 64),
    $manifestSha,
    'apply'
);
$check(
    ($consumed['record']['authorization_sha256'] ?? '') === str_repeat('c', 64),
    'mutating authorization consumption records the exact authorization checksum'
);
try {
    hp_gmc_identifier_consume_production_authorization(
        $authorization,
        str_repeat('c', 64),
        $manifestSha,
        'apply'
    );
    $check(false, 'mutating authorization cannot be replayed');
} catch (RuntimeException) {
    $check(true, 'mutating authorization cannot be replayed');
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
