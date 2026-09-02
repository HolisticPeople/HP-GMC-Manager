<?php
/**
 * HP-GMC reviewed identifier migration tool (schema v1).
 *
 * Run through WP-CLI so WordPress and WooCommerce are loaded:
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php export gla_123:123,...
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php preflight /path/manifest.json SHA256
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php apply /path/manifest.json SHA256
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php rollback /path/manifest.json SHA256
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php regenerate
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php production-preflight /path/manifest.json MANIFEST_SHA /path/authorization.json AUTH_SHA
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php production-apply /path/manifest.json MANIFEST_SHA /path/authorization.json AUTH_SHA
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php production-rollback /path/manifest.json MANIFEST_SHA /path/authorization.json AUTH_SHA
 *   wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php production-regenerate /path/manifest.json MANIFEST_SHA /path/authorization.json AUTH_SHA
 *
 * Production mutations use separate `production-*` operations and require an
 * immutable, operation-specific authorization packet in addition to the
 * immutable manifest. Existing staging operations remain staging-only.
 */

declare(strict_types=1);

const HP_GMC_IDENTIFIER_MANIFEST_SCHEMA = 'hp-gmc-identifier-migration/v1';
const HP_GMC_IDENTIFIER_PRODUCTION_AUTHORIZATION_SCHEMA = 'hp-gmc-identifier-production-authorization/v1';
const HP_GMC_IDENTIFIER_STAGING_HOST = 'env-holisticpeoplecom-hpdevplus.kinsta.cloud';
const HP_GMC_IDENTIFIER_PRODUCTION_HOST = 'holisticpeople.com';

function hp_gmc_identifier_is_staging(string $url, ?string $environmentType = null): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $environmentType ??= function_exists('wp_get_environment_type')
        ? (string) wp_get_environment_type()
        : '';

    return $environmentType === 'staging'
        && hash_equals(HP_GMC_IDENTIFIER_STAGING_HOST, $host);
}

function hp_gmc_identifier_is_production(string $url, ?string $environmentType = null): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $environmentType ??= function_exists('wp_get_environment_type')
        ? (string) wp_get_environment_type()
        : '';

    return $environmentType === 'production'
        && hash_equals(HP_GMC_IDENTIFIER_PRODUCTION_HOST, $host);
}

function hp_gmc_identifier_normalize_url(string $url): string
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $port = parse_url($url, PHP_URL_PORT);
    if ($scheme === '' || $host === '') {
        return '';
    }

    return $scheme . '://' . $host . ($port ? ':' . $port : '') . '/';
}

function hp_gmc_identifier_json(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Manifest is not a readable file: ' . $path);
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Manifest root must be an object.');
    }

    return $decoded;
}

function hp_gmc_identifier_checksum(string $path, string $expected): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) {
        throw new RuntimeException('Expected SHA-256 must be 64 lowercase hexadecimal characters.');
    }

    $actual = hash_file('sha256', $path);
    if (!hash_equals($expected, $actual)) {
        throw new RuntimeException('Manifest checksum mismatch.');
    }
}

function hp_gmc_identifier_current(int $productId): array
{
    return [
        '_sku' => (string) get_post_meta($productId, '_sku', true),
        '_global_unique_id' => (string) get_post_meta($productId, '_global_unique_id', true),
        'sku_mfr' => (string) get_post_meta($productId, 'sku_mfr', true),
        '_hp_gmc_mpn' => (string) get_post_meta($productId, '_hp_gmc_mpn', true),
        '_hp_gmc_mpn_verified' => (string) get_post_meta($productId, '_hp_gmc_mpn_verified', true),
        '_hp_gmc_mpn_source' => (string) get_post_meta($productId, '_hp_gmc_mpn_source', true),
    ];
}

function hp_gmc_identifier_brand(int $productId): array
{
    $result = [
        'manufacturer_acf' => '',
        'manufacturer' => '',
        'product_brand' => [],
        'yith_product_brand' => [],
    ];

    if (function_exists('get_field')) {
        foreach (['manufacturer_acf', 'manufacturer'] as $field) {
            $value = get_field($field, $productId);
            if (is_object($value)) {
                $value = $value->name ?? '';
            } elseif (is_array($value)) {
                $value = $value['name'] ?? '';
            }
            $result[$field] = trim((string) $value);
        }
    }

    foreach (['product_brand', 'yith_product_brand'] as $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }
        $terms = wp_get_post_terms($productId, $taxonomy, ['fields' => 'names']);
        if (!is_wp_error($terms)) {
            $result[$taxonomy] = array_values(array_map('strval', $terms));
        }
    }

    return $result;
}

function hp_gmc_identifier_export(string $pairs): array
{
    if ($pairs === '') {
        throw new RuntimeException('Export requires comma-separated offer_id:product_id pairs.');
    }

    $rows = [];
    $seen = [];
    foreach (explode(',', $pairs) as $pair) {
        if (!preg_match('/^([^:,]+):(\d+)$/D', $pair, $match)) {
            throw new RuntimeException('Invalid export pair: ' . $pair);
        }
        $offerId = $match[1];
        $productId = (int) $match[2];
        if ($productId <= 0 || isset($seen[$productId])) {
            throw new RuntimeException('Duplicate or invalid product ID: ' . $productId);
        }
        $seen[$productId] = true;

        $product = wc_get_product($productId);
        if (!$product) {
            throw new RuntimeException('Product does not exist: ' . $productId);
        }

        $rows[] = [
            'product_id' => $productId,
            'gmc_offer_id' => $offerId,
            'title' => (string) $product->get_name(),
            'brand_sources' => hp_gmc_identifier_brand($productId),
            'woo_sku' => (string) $product->get_sku('edit'),
            'raw_global_unique_id' => method_exists($product, 'get_global_unique_id')
                ? (string) $product->get_global_unique_id('edit')
                : (string) get_post_meta($productId, '_global_unique_id', true),
            'raw_sku_mfr' => (string) get_post_meta($productId, 'sku_mfr', true),
            'legacy_source_field' => [
                'name' => 'sku_mfr',
                'acf_field_key' => (string) get_post_meta($productId, '_sku_mfr', true),
            ],
            'canonical_before' => [
                '_hp_gmc_mpn' => (string) get_post_meta($productId, '_hp_gmc_mpn', true),
                '_hp_gmc_mpn_verified' => (string) get_post_meta($productId, '_hp_gmc_mpn_verified', true),
                '_hp_gmc_mpn_source' => (string) get_post_meta($productId, '_hp_gmc_mpn_source', true),
            ],
            'protected_fingerprint' => [
                '_sku' => (string) get_post_meta($productId, '_sku', true),
                '_global_unique_id' => (string) get_post_meta($productId, '_global_unique_id', true),
                'sku_mfr' => (string) get_post_meta($productId, 'sku_mfr', true),
            ],
        ];
    }

    return [
        'schema' => 'hp-gmc-identifier-candidate-export/v1',
        'captured_at_utc' => gmdate('c'),
        'site_url' => home_url('/'),
        'plugin_version' => defined('HP_GMC_VERSION') ? HP_GMC_VERSION : null,
        'row_count' => count($rows),
        'rows' => $rows,
    ];
}

function hp_gmc_identifier_validate_manifest(array $manifest, string $targetEnvironment = 'staging'): array
{
    $errors = [];
    $allowedSources = ['manufacturer', 'manufacturer_catalog', 'authorized_distributor', 'product_label'];
    if (($manifest['schema'] ?? '') !== HP_GMC_IDENTIFIER_MANIFEST_SCHEMA) {
        $errors[] = 'Unsupported manifest schema.';
    }
    if (!in_array($targetEnvironment, ['staging', 'production'], true)) {
        $errors[] = 'Unsupported expected manifest environment.';
    } elseif (($manifest['target_environment'] ?? '') !== $targetEnvironment) {
        $errors[] = 'Manifest target_environment must be ' . $targetEnvironment . '.';
    }
    if (!isset($manifest['rows']) || !is_array($manifest['rows'])) {
        return array_merge($errors, ['Manifest rows must be an array.']);
    }

    $seen = [];
    $seenOffers = [];
    $seenAcceptedMpn = [];
    foreach ($manifest['rows'] as $index => $row) {
        $prefix = 'rows[' . $index . ']';
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId <= 0 || isset($seen[$productId])) {
            $errors[] = $prefix . ' has an invalid or duplicate product_id.';
            continue;
        }
        $seen[$productId] = true;
        $offerId = trim((string) ($row['gmc_offer_id'] ?? ''));
        if ($offerId === '' || isset($seenOffers[$offerId])) {
            $errors[] = $prefix . ' has an invalid or duplicate gmc_offer_id.';
        } else {
            $seenOffers[$offerId] = true;
        }

        $status = (string) ($row['review_status'] ?? '');
        if (!in_array($status, ['accepted', 'rejected', 'ambiguous', 'deferred'], true)) {
            $errors[] = $prefix . ' has an invalid review_status.';
        }
        if ($status !== 'accepted') {
            continue;
        }

        $mpn = (string) ($row['proposed_mpn'] ?? '');
        if ($mpn === '' || trim($mpn) !== $mpn || strlen($mpn) > 70 || strip_tags($mpn) !== $mpn) {
            $errors[] = $prefix . ' accepted proposed_mpn is invalid.';
        }
        if ($mpn !== (string) ($row['raw_sku_mfr'] ?? '')) {
            $errors[] = $prefix . ' proposed_mpn must exactly preserve reviewed raw_sku_mfr.';
        }
        if ($mpn !== '' && hash_equals($mpn, (string) ($row['woo_sku'] ?? ''))) {
            $errors[] = $prefix . ' accepted proposed_mpn cannot equal the Woo SKU.';
        }
        if (trim((string) ($row['gtin'] ?? '')) !== '') {
            $errors[] = $prefix . ' accepted target must not already have a GTIN.';
        }
        $normalizedMpn = strtolower($mpn);
        if ($normalizedMpn !== '' && isset($seenAcceptedMpn[$normalizedMpn])) {
            $errors[] = $prefix . ' accepted proposed_mpn duplicates another accepted row.';
        } elseif ($normalizedMpn !== '') {
            $seenAcceptedMpn[$normalizedMpn] = true;
        }
        $source = (string) ($row['manufacturer_provenance']['type'] ?? '');
        if (!in_array($source, $allowedSources, true)) {
            $errors[] = $prefix . ' accepted provenance type is not approved.';
        }
        if (trim((string) ($row['manufacturer_provenance']['evidence'] ?? '')) === '') {
            $errors[] = $prefix . ' accepted provenance evidence is required.';
        }
        if (trim((string) ($row['reviewer'] ?? '')) === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($row['review_date'] ?? ''))) {
            $errors[] = $prefix . ' accepted reviewer and ISO review_date are required.';
        }
    }

    return $errors;
}

function hp_gmc_identifier_preflight(array $manifest, bool $rollback = false, string $targetEnvironment = 'staging'): array
{
    $errors = hp_gmc_identifier_validate_manifest($manifest, $targetEnvironment);
    $accepted = 0;
    foreach (($manifest['rows'] ?? []) as $index => $row) {
        if (($row['review_status'] ?? '') !== 'accepted') {
            continue;
        }
        $accepted++;
        $productId = (int) $row['product_id'];
        if (!wc_get_product($productId)) {
            $errors[] = 'rows[' . $index . '] product does not exist.';
            continue;
        }
        $current = hp_gmc_identifier_current($productId);
        foreach (['_sku', '_global_unique_id', 'sku_mfr'] as $key) {
            $expected = (string) ($row['protected_fingerprint'][$key] ?? '');
            if (!hash_equals($expected, $current[$key])) {
                $errors[] = 'rows[' . $index . '] protected field drift: ' . $key;
            }
        }

        $expectedCanonical = $rollback
            ? [
                '_hp_gmc_mpn' => (string) ($row['proposed_mpn'] ?? ''),
                '_hp_gmc_mpn_verified' => 'yes',
                '_hp_gmc_mpn_source' => (string) ($row['manufacturer_provenance']['type'] ?? ''),
            ]
            : (array) ($row['rollback_value'] ?? []);
        foreach (['_hp_gmc_mpn', '_hp_gmc_mpn_verified', '_hp_gmc_mpn_source'] as $key) {
            if (!array_key_exists($key, $expectedCanonical) || !hash_equals((string) $expectedCanonical[$key], $current[$key])) {
                $errors[] = 'rows[' . $index . '] canonical field drift: ' . $key;
            }
        }
    }

    return [
        'ok' => $errors === [],
        'accepted_rows' => $accepted,
        'errors' => $errors,
    ];
}

function hp_gmc_identifier_store(int $productId, string $key, string $value): void
{
    if ($value === '') {
        delete_post_meta($productId, $key);
    } else {
        update_post_meta($productId, $key, $value);
    }
    if (!hash_equals($value, (string) get_post_meta($productId, $key, true))) {
        throw new RuntimeException('Write verification failed for product ' . $productId . ' field ' . $key);
    }
}

function hp_gmc_identifier_apply(array $manifest, bool $rollback = false, string $targetEnvironment = 'staging'): array
{
    $preflight = hp_gmc_identifier_preflight($manifest, $rollback, $targetEnvironment);
    if (!$preflight['ok']) {
        throw new RuntimeException('Preflight failed: ' . implode(' | ', $preflight['errors']));
    }

    $changed = [];
    $restores = [];
    try {
        foreach ($manifest['rows'] as $row) {
            if (($row['review_status'] ?? '') !== 'accepted') {
                continue;
            }
            $productId = (int) $row['product_id'];
            $before = hp_gmc_identifier_current($productId);
            $beforeProtected = array_intersect_key($before, array_flip(['_sku', '_global_unique_id', 'sku_mfr']));
            foreach ($beforeProtected as $key => $currentValue) {
                $expectedValue = (string) ($row['protected_fingerprint'][$key] ?? '');
                if (!hash_equals($expectedValue, $currentValue)) {
                    throw new RuntimeException('Immediate protected-field drift for product ' . $productId . ': ' . $key);
                }
            }
            $expectedCanonical = $rollback
                ? [
                    '_hp_gmc_mpn' => (string) ($row['proposed_mpn'] ?? ''),
                    '_hp_gmc_mpn_verified' => 'yes',
                    '_hp_gmc_mpn_source' => (string) ($row['manufacturer_provenance']['type'] ?? ''),
                ]
                : (array) ($row['rollback_value'] ?? []);
            foreach (['_hp_gmc_mpn', '_hp_gmc_mpn_verified', '_hp_gmc_mpn_source'] as $key) {
                if (!array_key_exists($key, $expectedCanonical)
                    || !hash_equals((string) $expectedCanonical[$key], $before[$key])) {
                    throw new RuntimeException('Immediate canonical-field drift for product ' . $productId . ': ' . $key);
                }
            }
            $restores[$productId] = array_intersect_key($before, array_flip([
                '_hp_gmc_mpn',
                '_hp_gmc_mpn_verified',
                '_hp_gmc_mpn_source',
            ]));
            $values = $rollback
                ? (array) $row['rollback_value']
                : [
                    '_hp_gmc_mpn' => (string) $row['proposed_mpn'],
                    '_hp_gmc_mpn_verified' => 'yes',
                    '_hp_gmc_mpn_source' => (string) $row['manufacturer_provenance']['type'],
                ];
            foreach (['_hp_gmc_mpn', '_hp_gmc_mpn_verified', '_hp_gmc_mpn_source'] as $key) {
                hp_gmc_identifier_store($productId, $key, (string) ($values[$key] ?? ''));
            }
            $afterProtected = array_intersect_key(hp_gmc_identifier_current($productId), array_flip(['_sku', '_global_unique_id', 'sku_mfr']));
            if ($beforeProtected !== $afterProtected) {
                throw new RuntimeException('Protected field changed for product ' . $productId);
            }
            $changed[] = $productId;
        }
    } catch (Throwable $error) {
        foreach (array_reverse(array_keys($restores)) as $productId) {
            foreach ($restores[$productId] as $key => $value) {
                hp_gmc_identifier_store((int) $productId, (string) $key, (string) $value);
            }
        }
        throw new RuntimeException('Apply failed and automatic restore ran: ' . $error->getMessage(), 0, $error);
    }

    return [
        'ok' => true,
        'operation' => $rollback ? 'rollback' : 'apply',
        'changed_count' => count($changed),
        'changed_product_ids' => $changed,
    ];
}

function hp_gmc_identifier_feed_snapshot(): array
{
    $content = get_transient('hp_gmc_primary_feed_content_tsv_merchant');
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Merchant TSV feed cache is unavailable; capture/freeze it before authorization.');
    }

    return [
        'sha256' => hash('sha256', $content),
        'product_count' => substr_count($content, "\n"),
    ];
}

function hp_gmc_identifier_production_confirmation(string $operation, string $manifestSha): string
{
    return 'HP-GMC-PRODUCTION-' . strtoupper($operation) . '-' . substr($manifestSha, 0, 12);
}

function hp_gmc_identifier_validate_production_authorization(
    array $authorization,
    string $operation,
    string $manifestSha,
    array $manifest,
    string $siteUrl,
    string $merchantId,
    array $feedSnapshot,
    ?int $now = null
): array {
    $errors = hp_gmc_identifier_validate_manifest($manifest, 'production');
    $allowedOperations = ['preflight', 'apply', 'rollback', 'regenerate'];
    if (!in_array($operation, $allowedOperations, true)) {
        $errors[] = 'Unsupported production operation.';
    }
    if (($authorization['schema'] ?? '') !== HP_GMC_IDENTIFIER_PRODUCTION_AUTHORIZATION_SCHEMA) {
        $errors[] = 'Unsupported production authorization schema.';
    }
    if (($authorization['target_environment'] ?? '') !== 'production') {
        $errors[] = 'Production authorization target_environment must be production.';
    }
    if (($authorization['target_host'] ?? '') !== HP_GMC_IDENTIFIER_PRODUCTION_HOST) {
        $errors[] = 'Production authorization target_host mismatch.';
    }
    $normalizedSiteUrl = hp_gmc_identifier_normalize_url($siteUrl);
    if ($normalizedSiteUrl !== 'https://' . HP_GMC_IDENTIFIER_PRODUCTION_HOST . '/') {
        $errors[] = 'Runtime production site URL mismatch.';
    }
    if ((string) ($authorization['target_site_url'] ?? '') !== $normalizedSiteUrl) {
        $errors[] = 'Production authorization target_site_url mismatch.';
    }
    if ($merchantId === '') {
        $errors[] = 'Runtime merchant account ID is empty.';
    } elseif (!hash_equals(
        hash('sha256', $merchantId),
        (string) ($authorization['target_merchant_id_sha256'] ?? '')
    )) {
        $errors[] = 'Production authorization merchant account fingerprint mismatch.';
    }
    if (!hash_equals($manifestSha, (string) ($authorization['manifest_sha256'] ?? ''))) {
        $errors[] = 'Production authorization manifest checksum mismatch.';
    }
    if (($authorization['operation'] ?? '') !== $operation) {
        $errors[] = 'Production authorization operation mismatch.';
    }
    if (($authorization['confirmation'] ?? '') !== hp_gmc_identifier_production_confirmation($operation, $manifestSha)) {
        $errors[] = 'Production authorization confirmation mismatch.';
    }
    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
        (string) ($authorization['authorization_id'] ?? '')
    )) {
        $errors[] = 'Production authorization authorization_id must be a lowercase UUID.';
    }
    if ((int) ($authorization['expected_manifest_rows'] ?? -1) !== count($manifest['rows'] ?? [])) {
        $errors[] = 'Production authorization manifest row count mismatch.';
    }
    $acceptedRows = count(array_filter(
        $manifest['rows'] ?? [],
        static fn(array $row): bool => ($row['review_status'] ?? '') === 'accepted'
    ));
    if ((int) ($authorization['expected_accepted_rows'] ?? -1) !== $acceptedRows) {
        $errors[] = 'Production authorization accepted row count mismatch.';
    }
    if (!hash_equals(
        (string) ($feedSnapshot['sha256'] ?? ''),
        (string) ($authorization['expected_feed_before_sha256'] ?? '')
    )) {
        $errors[] = 'Production authorization feed checksum mismatch.';
    }
    if ((int) ($authorization['expected_feed_product_count'] ?? -1) !== (int) ($feedSnapshot['product_count'] ?? -2)) {
        $errors[] = 'Production authorization feed product count mismatch.';
    }
    if (trim((string) ($authorization['approved_by'] ?? '')) === '') {
        $errors[] = 'Production authorization approved_by is required.';
    }
    if (trim((string) ($authorization['approval_reference'] ?? '')) === '') {
        $errors[] = 'Production authorization approval_reference is required.';
    }

    $now ??= time();
    $approvedAt = strtotime((string) ($authorization['approved_at_utc'] ?? ''));
    $expiresAt = strtotime((string) ($authorization['expires_at_utc'] ?? ''));
    if ($approvedAt === false || $approvedAt > $now) {
        $errors[] = 'Production authorization approved_at_utc is invalid or in the future.';
    }
    if ($expiresAt === false || $expiresAt <= $now || $expiresAt <= (int) $approvedAt) {
        $errors[] = 'Production authorization is expired or has an invalid expiry.';
    } elseif ($approvedAt !== false && $expiresAt - $approvedAt > 4 * HOUR_IN_SECONDS) {
        $errors[] = 'Production authorization validity window exceeds four hours.';
    }

    return $errors;
}

function hp_gmc_identifier_production_context(
    string $operation,
    string $manifestPath,
    string $manifestSha,
    string $authorizationPath,
    string $authorizationSha
): array {
    if (!hp_gmc_identifier_is_production(home_url('/'))) {
        throw new RuntimeException('production-' . $operation . ' is permitted only on the exact production site.');
    }
    hp_gmc_identifier_checksum($manifestPath, $manifestSha);
    hp_gmc_identifier_checksum($authorizationPath, $authorizationSha);
    $manifest = hp_gmc_identifier_json($manifestPath);
    $authorization = hp_gmc_identifier_json($authorizationPath);
    $feedSnapshot = hp_gmc_identifier_feed_snapshot();
    $errors = hp_gmc_identifier_validate_production_authorization(
        $authorization,
        $operation,
        $manifestSha,
        $manifest,
        home_url('/'),
        trim((string) get_option('hp_gmc_merchant_id', '')),
        $feedSnapshot
    );
    if ($errors !== []) {
        throw new RuntimeException('Production authorization failed: ' . implode(' | ', $errors));
    }

    return [
        'manifest' => $manifest,
        'authorization' => $authorization,
        'feed_snapshot' => $feedSnapshot,
    ];
}

function hp_gmc_identifier_regenerate(): array
{
    if (!class_exists('HP_GMC\\Services\\ProductDataFeed')) {
        throw new RuntimeException('HP-GMC ProductDataFeed service is unavailable.');
    }
    \HP_GMC\Services\ProductDataFeed::clearCache();
    $feed = \HP_GMC\Services\ProductDataFeed::generateFeed('tsv', true);

    return [
        'ok' => true,
        'operation' => 'regenerate',
        'sha256' => hash('sha256', $feed),
        'logical_lines' => substr_count($feed, "\n") + 1,
        'status' => \HP_GMC\Services\ProductDataFeed::getStatus(),
    ];
}

if (defined('HP_GMC_IDENTIFIER_MIGRATION_LIBRARY_ONLY') && HP_GMC_IDENTIFIER_MIGRATION_LIBRARY_ONLY) {
    return;
}

if (!defined('ABSPATH') || !defined('WP_CLI')) {
    fwrite(STDERR, "Run this tool with wp eval-file.\n");
    exit(2);
}

$commandArgs = isset($args) && is_array($args) ? $args : [];
$operation = (string) ($commandArgs[0] ?? '');

try {
    if ($operation === 'export') {
        $result = hp_gmc_identifier_export((string) ($commandArgs[1] ?? ''));
    } elseif (in_array($operation, ['preflight', 'apply', 'rollback'], true)) {
        if (!hp_gmc_identifier_is_staging(home_url('/'))) {
            throw new RuntimeException($operation . ' is permitted only on staging.');
        }
        $path = (string) ($commandArgs[1] ?? '');
        hp_gmc_identifier_checksum($path, (string) ($commandArgs[2] ?? ''));
        $manifest = hp_gmc_identifier_json($path);
        $result = $operation === 'preflight'
            ? hp_gmc_identifier_preflight($manifest, false)
            : hp_gmc_identifier_apply($manifest, $operation === 'rollback');
    } elseif ($operation === 'regenerate') {
        if (!hp_gmc_identifier_is_staging(home_url('/'))) {
            throw new RuntimeException('Feed regeneration is permitted only on staging.');
        }
        $result = hp_gmc_identifier_regenerate();
    } elseif (preg_match('/^production-(preflight|apply|rollback|regenerate)$/D', $operation, $match)) {
        $productionOperation = $match[1];
        $context = hp_gmc_identifier_production_context(
            $productionOperation,
            (string) ($commandArgs[1] ?? ''),
            (string) ($commandArgs[2] ?? ''),
            (string) ($commandArgs[3] ?? ''),
            (string) ($commandArgs[4] ?? '')
        );
        if ($productionOperation === 'preflight') {
            $result = hp_gmc_identifier_preflight($context['manifest'], false, 'production');
            $result['operation'] = 'production-preflight';
            $result['feed_snapshot'] = $context['feed_snapshot'];
        } elseif ($productionOperation === 'apply') {
            $result = hp_gmc_identifier_apply($context['manifest'], false, 'production');
            $result['operation'] = 'production-apply';
        } elseif ($productionOperation === 'rollback') {
            $result = hp_gmc_identifier_apply($context['manifest'], true, 'production');
            $result['operation'] = 'production-rollback';
        } else {
            $result = hp_gmc_identifier_regenerate();
            $result['operation'] = 'production-regenerate';
        }
    } else {
        throw new RuntimeException('Unknown operation.');
    }

    echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (in_array($operation, ['preflight', 'production-preflight'], true) && !($result['ok'] ?? false)) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, wp_json_encode([
        'ok' => false,
        'operation' => $operation,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
