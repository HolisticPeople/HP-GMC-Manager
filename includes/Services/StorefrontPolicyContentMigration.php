<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies the storefront copy that corresponds to the Merchant Center policy.
 * Original page bodies are retained in an option for operational rollback.
 */
final class StorefrontPolicyContentMigration
{
    private const VERSION = '3.4.9';
    private const VERSION_OPTION = 'hp_gmc_storefront_policy_content_version';
    private const BACKUP_OPTION = 'hp_gmc_storefront_policy_backup_3_4_8';

    public static function init(): void
    {
        add_action('init', [self::class, 'run'], 30);
    }

    public static function run(): void
    {
        if ((string) get_option(self::VERSION_OPTION, '') === self::VERSION) {
            return;
        }

        $returnPage = get_page_by_path('return-policy', OBJECT, 'page');
        $termsPage = get_page_by_path('terms-service-holisticpeople', OBJECT, 'page');
        if (!$returnPage instanceof \WP_Post || !$termsPage instanceof \WP_Post) {
            return;
        }

        if (get_option(self::BACKUP_OPTION, null) === null) {
            add_option(self::BACKUP_OPTION, [
                'created_at' => gmdate('c'),
                'return_policy' => [
                    'id' => (int) $returnPage->ID,
                    'content' => (string) $returnPage->post_content,
                ],
                'terms' => [
                    'id' => (int) $termsPage->ID,
                    'content' => (string) $termsPage->post_content,
                ],
            ], '', false);
        }

        $returnResult = wp_update_post([
            'ID' => (int) $returnPage->ID,
            'post_content' => self::returnPolicyContent(),
        ], true);
        $termsResult = wp_update_post([
            'ID' => (int) $termsPage->ID,
            'post_content' => self::termsContent((string) $termsPage->post_content),
        ], true);

        if (!is_wp_error($returnResult) && !is_wp_error($termsResult)) {
            update_option(self::VERSION_OPTION, self::VERSION, false);
            clean_post_cache((int) $returnPage->ID);
            clean_post_cache((int) $termsPage->ID);
        }
    }

    public static function returnPolicyContent(): string
    {
        return <<<'HTML'
<h2>Returns at a Glance</h2>
<p>You may return <strong>unopened, unused products in their original packaging within 30 days of delivery</strong> for a full refund. There is no restocking fee.</p>
<h2>What can be returned</h2>
<ul>
<li><strong>Unopened products</strong> in original packaging — returnable within 30 days of delivery.</li>
<li><strong>Damaged, defective, or incorrect items</strong> — contact us within 7 days of delivery. We will resolve the issue with a replacement or full refund. You do not need to return the item unless we ask you to.</li>
</ul>
<h2>What cannot be returned</h2>
<ul>
<li><strong>Opened or used supplements and foods</strong> — for health and safety reasons, consumable products cannot be restocked once opened.</li>
<li><strong>Frozen and perishable products</strong> (for example, fresh-frozen algae) — these cannot survive return shipping.</li>
<li>Gift cards and special-order items.</li>
</ul>
<h2>How to start a return</h2>
<p>Email <a href="mailto:office@holisticpeople.com">office@holisticpeople.com</a> or call +1 603-557-7635 with your order number and the item(s) you wish to return. We will confirm eligibility and email the return instructions.</p>
<h2>Return shipping and labels</h2>
<p>For a standard eligible return where the product is not defective, we can email you a downloadable return label. The actual cost of that label is the customer’s responsibility.</p>
<p>If an item is damaged, defective, or incorrect and we require it to be returned, we will email a prepaid downloadable return label at no cost to you.</p>
<h2>Refund timing</h2>
<p>After we receive and inspect an eligible return, we issue the refund to the original payment method within 10 days. Your bank or payment provider may need additional time to post the credit to your account.</p>
<h2>Replacements and exchanges</h2>
<p>We do not offer ordinary exchanges for preference changes. A replacement sent for a damaged, defective, or incorrect item is issue resolution and is not a general exchange program.</p>
<p><em>Questions? We are happy to help: office@holisticpeople.com</em></p>
HTML;
    }

    public static function termsContent(string $content): string
    {
        $policySection = '<h2>Returns and Refunds</h2>'
            . '<p>Our return and refund rules are described in the <a href="/return-policy/">Return &amp; Refund Policy</a>, which is part of these Terms of Service. The policy includes a 30-day return window for eligible unopened, unused products and free resolution of damaged, defective, or incorrect items.</p>';

        $content = (string) preg_replace(
            '#<h2>Refunds and No Returns Policy(?:\s|&nbsp;| )*</h2>\s*<p>.*?</p>#si',
            $policySection,
            $content,
            1
        );

        return (string) preg_replace(
            '#<li>No Returns Policy\s*-\s*once items are shipped out, we do not accept returns to ensure the highest quality\.</li>#i',
            '<li>Returns and refunds are governed by our <a href="/return-policy/">Return &amp; Refund Policy</a>.</li>',
            $content,
            1
        );
    }
}
