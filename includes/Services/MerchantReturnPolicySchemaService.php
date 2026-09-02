<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publishes the canonical storefront return policy in Yoast's Organization
 * graph so Google receives the same policy configured in Merchant Center.
 */
final class MerchantReturnPolicySchemaService
{
    public static function init(): void
    {
        add_filter('wpseo_schema_graph', [self::class, 'enrichYoastGraph'], 30, 2);
    }

    /**
     * @param mixed $graph
     * @param mixed $context
     * @return array<int, mixed>
     */
    public static function enrichYoastGraph($graph, $context = null): array
    {
        unset($context);

        if (!is_array($graph)) {
            return [];
        }

        foreach ($graph as $index => $node) {
            if (!is_array($node) || !self::isOrganizationNode($node)) {
                continue;
            }

            $graph[$index]['hasMerchantReturnPolicy'] = self::policy();
        }

        return $graph;
    }

    /**
     * @return array<string, mixed>
     */
    public static function policy(): array
    {
        return [
            '@type' => 'MerchantReturnPolicy',
            'merchantReturnLink' => home_url('/return-policy/'),
            'applicableCountry' => 'US',
            'returnPolicyCountry' => 'US',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 30,
            'itemCondition' => [
                'https://schema.org/NewCondition',
                'https://schema.org/DamagedCondition',
            ],
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
            'returnLabelSource' => 'https://schema.org/ReturnLabelDownloadAndPrint',
            'customerRemorseReturnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
            'customerRemorseReturnLabelSource' => 'https://schema.org/ReturnLabelDownloadAndPrint',
            'itemDefectReturnFees' => 'https://schema.org/FreeReturn',
            'itemDefectReturnLabelSource' => 'https://schema.org/ReturnLabelDownloadAndPrint',
            'refundType' => 'https://schema.org/FullRefund',
            'restockingFee' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function isOrganizationNode(array $node): bool
    {
        $types = $node['@type'] ?? [];
        $types = is_array($types) ? $types : [$types];

        foreach ($types as $type) {
            if (in_array((string) $type, ['Organization', 'OnlineStore', 'Corporation'], true)) {
                return true;
            }
        }

        return false;
    }
}
