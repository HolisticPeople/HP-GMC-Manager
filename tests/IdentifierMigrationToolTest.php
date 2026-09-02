<?php
/** Standalone contract checks for the deployed identifier operator. */

declare(strict_types=1);

define('HP_GMC_IDENTIFIER_MIGRATION_LIBRARY_ONLY', true);
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

$base = [
    'schema' => HP_GMC_IDENTIFIER_MANIFEST_SCHEMA,
    'target_environment' => 'staging',
    'rows' => [[
        'product_id' => 10,
        'review_status' => 'accepted',
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

$bad = $base;
$bad['rows'][0]['proposed_mpn'] = 'INVENTED';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'accepted MPN cannot differ from reviewed legacy value');

$bad = $base;
$bad['rows'][0]['manufacturer_provenance']['type'] = 'internal_sku';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'internal SKU provenance is rejected');

$bad = $base;
$bad['rows'][0]['manufacturer_provenance']['evidence'] = '';
$check(hp_gmc_identifier_validate_manifest($bad) !== [], 'accepted row requires provenance evidence');

$deferred = $base;
$deferred['rows'][0] = ['product_id' => 10, 'review_status' => 'deferred'];
$check(hp_gmc_identifier_validate_manifest($deferred) === [], 'deferred row needs no fabricated identifier');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
