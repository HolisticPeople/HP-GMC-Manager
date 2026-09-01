<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical, fail-closed product identifier projection for Google feeds.
 *
 * Store-local SKUs are deliberately not accepted as MPNs. A manufacturer
 * part number is emitted only after a product-level review records both the
 * value and an approved source type. Variations are resolved by their own
 * WooCommerce product ID; parent identifiers are never inherited.
 */
final class ProductIdentifiers
{
    private const MPN_META_KEY = '_hp_gmc_mpn';
    private const MPN_VERIFIED_META_KEY = '_hp_gmc_mpn_verified';
    private const MPN_SOURCE_META_KEY = '_hp_gmc_mpn_source';
    private const IDENTIFIER_EXISTS_META_KEY = '_hp_gmc_identifier_exists';

    /** @var string[] Sources capable of proving a manufacturer-issued MPN. */
    private const MPN_SOURCE_TYPES = [
        'manufacturer',
        'manufacturer_catalog',
        'authorized_distributor',
        'product_label',
    ];

    /**
     * Return a provider-valid GTIN without guessing or reshaping identifiers.
     */
    public static function getGtin(\WC_Product $product): string
    {
        $productId = (int) $product->get_id();

        if (method_exists($product, 'get_global_unique_id')) {
            $gtin = self::normalizeGtin((string) $product->get_global_unique_id());
            if ($gtin !== '') {
                return $gtin;
            }
        }

        $gtinKeys = ['_global_unique_id', '_gtin', '_ean', '_upc', 'gtin', 'ean', 'upc', '_wpm_gtin_code'];

        foreach ($gtinKeys as $key) {
            $gtin = self::normalizeGtin((string) get_post_meta($productId, $key, true));
            if ($gtin !== '') {
                return $gtin;
            }
        }

        return '';
    }

    /**
     * Return a reviewed manufacturer-issued MPN, or an empty string.
     *
     * Required product meta:
     * - _hp_gmc_mpn: exact manufacturer part number;
     * - _hp_gmc_mpn_verified: "yes" after human/source review;
     * - _hp_gmc_mpn_source: one of the approved provenance types above.
     */
    public static function getMpn(\WC_Product $product): string
    {
        $productId = (int) $product->get_id();
        if ($productId <= 0) {
            return '';
        }

        $verified = strtolower(trim((string) get_post_meta($productId, self::MPN_VERIFIED_META_KEY, true)));
        $source = strtolower(trim((string) get_post_meta($productId, self::MPN_SOURCE_META_KEY, true)));

        if ($verified !== 'yes' || !in_array($source, self::MPN_SOURCE_TYPES, true)) {
            return '';
        }

        return self::normalizeMpn((string) get_post_meta($productId, self::MPN_META_KEY, true));
    }

    /**
     * Return the text-feed identifier_exists value.
     *
     * A missing local identifier is an unresolved data gap, not proof that the
     * manufacturer assigned none. Therefore "no" is emitted only when the
     * product has no projected GTIN/MPN and a product-level review explicitly
     * records _hp_gmc_identifier_exists=no.
     */
    public static function getIdentifierExists(\WC_Product $product, string $gtin, string $mpn): string
    {
        if ($gtin !== '' || $mpn !== '') {
            return '';
        }

        $reviewedValue = strtolower(trim((string) get_post_meta(
            (int) $product->get_id(),
            self::IDENTIFIER_EXISTS_META_KEY,
            true
        )));

        return $reviewedValue === 'no' ? 'no' : '';
    }

    /**
     * Normalize GTIN separators and require a valid GS1 check digit.
     */
    private static function normalizeGtin(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (!in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return '';
        }

        $length = strlen($digits);
        $sum = 0;
        $position = 0;
        for ($index = $length - 2; $index >= 0; $index--, $position++) {
            $sum += (int) $digits[$index] * ($position % 2 === 0 ? 3 : 1);
        }

        $expectedCheckDigit = (10 - ($sum % 10)) % 10;
        if ($expectedCheckDigit !== (int) $digits[$length - 1]) {
            return '';
        }

        return $digits;
    }

    /**
     * Keep the exact reviewed MPN within Google's 70-character feed limit.
     */
    private static function normalizeMpn(string $raw): string
    {
        $value = strip_tags($raw);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 70) : substr($value, 0, 70);
    }
}
