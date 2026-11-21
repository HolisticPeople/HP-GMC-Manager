#!/usr/bin/env bash
# Existing GMC staging deployment guard. No activation, option writes or feeds.
set -euo pipefail
[[ $# == 7 ]] || { echo 'Invalid staging guard arguments' >&2; exit 2; }
phase=$1
plugins_base=$2
expected_root=$3
expected_home=$4
expected_version=$5
header_sha=$6
policy_sha=$7
[[ "$phase" == preflight || "$phase" == installed ]] || exit 2
[[ "$expected_home" == https://env-holisticpeoplecom-hpdevplus.kinsta.cloud ]] || exit 2
[[ -f "$expected_root/wp-load.php" && -f "$expected_root/wp-settings.php" ]] || exit 2
root=$(realpath -e "$expected_root")
base=$(realpath -e "$plugins_base")
[[ "$root" == "$expected_root" && "$base" == "$root/wp-content/plugins" ]] || exit 2
plugin="$base/hp-gmc-manager"
[[ -d "$plugin" && ! -L "$plugin" && "$(realpath -e "$plugin")" == "$plugin" ]] || exit 2
[[ -f "$plugin/composer.lock" && ! -L "$plugin/composer.lock" ]] || { echo 'Existing dependency lock required' >&2; exit 2; }

# Skip ordinary plugins/themes: in particular do not invoke GMC's legacy init
# migration while establishing the destination. MU/drop-ins remain WP bootstrap.
facts=$(wp --path="$root" --skip-plugins --skip-themes eval '
echo wp_json_encode([
    "home" => home_url(), "site" => site_url(),
    "environment" => wp_get_environment_type(),
    "php" => PHP_VERSION_ID, "public" => (string) get_option("blog_public"),
    "mode" => get_option("hp_gmc_mode", "auto"),
    "override" => get_option("hp_gmc_environment", ""),
    "policyMigration" => get_option("hp_gmc_storefront_policy_content_version", "")
]);')
php -r '
$f = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$expected = $argv[2];
if (!is_array($f) || rtrim($f["home"] ?? "", "/") !== $expected
    || rtrim($f["site"] ?? "", "/") !== $expected
    || ($f["environment"] ?? null) !== "staging"
    || !is_int($f["php"] ?? null) || $f["php"] < 80500
    || ($f["public"] ?? null) !== "0"
    || !in_array($f["mode"] ?? null, ["auto", "dry_run", "mock"], true)
    || !in_array($f["override"] ?? null, ["", "staging", "local"], true)
    || ($f["policyMigration"] ?? null) !== "3.4.9") {
    fwrite(STDERR, "Staging identity, silent mode or migration precondition refused\n"); exit(2);
}
echo json_encode(["stagingIdentity" => $f, "qualified" => true]), "\n";
' "$facts" "$expected_home"

if [[ "$phase" == installed ]]; then
    [[ "$expected_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ && "$header_sha" =~ ^[a-f0-9]{64}$ && "$policy_sha" =~ ^[a-f0-9]{64}$ ]] || exit 2
    printf '%s  %s\n' "$header_sha" "$plugin/hp-gmc-manager.php" "$policy_sha" "$plugin/includes/Services/MerchantReturnPolicySchemaService.php" | sha256sum --check --strict
    grep -Fqx " * Version: $expected_version" "$plugin/hp-gmc-manager.php"
    # Preserve the existing required object-cache effect, at the validated root.
    # Failures propagate; no global page-cache/OPcache reset is introduced.
    wp --path="$root" --skip-plugins --skip-themes cache flush
    echo 'GMC selected installed bytes and required object-cache clear verified'
fi
