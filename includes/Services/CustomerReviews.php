<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Single GCR handoff. Never used by feeds, analytics, or order history. */
final class CustomerReviews
{
    public const MERCHANT_ID = 5298746911;

    private static function unavailable(string $reason): array
    {
        return ['version' => 1, 'status' => 'unavailable', 'reason' => $reason];
    }

    public static function getOptin(): array
    {
        if (get_option('hp_gmc_customer_reviews_enabled', 'disabled') !== 'enabled') {
            return self::unavailable('disabled');
        }
        if (CustomerReviewsEnvironment::isOutwardSilent() || !is_ssl()) {
            return self::unavailable('outward_silent');
        }
        if ((string) get_option('hp_gmc_merchant_id', '') !== (string) self::MERCHANT_ID) {
            return self::unavailable('merchant_mismatch');
        }
        if (!function_exists('hp_checkout_get_review_confirmation_context_v1')) {
            return self::unavailable('provider_missing');
        }
        try {
            $result = hp_checkout_get_review_confirmation_context_v1();
            if (!is_array($result) || ($result['version'] ?? null) !== 1 || ($result['status'] ?? '') !== 'ready' || !is_array($result['context'] ?? null)) {
                return self::unavailable('context_unavailable');
            }
            $context = $result['context'];
            foreach (['order_reference', 'email', 'delivery_country', 'estimated_delivery_date'] as $key) {
                if (!is_string($context[$key] ?? null) || $context[$key] === '') {
                    return self::unavailable('context_invalid');
                }
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $context['estimated_delivery_date']);
            if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $context['order_reference'])
                || !is_email($context['email'])
                || !preg_match('/^[A-Z]{2}$/D', $context['delivery_country']) || $context['delivery_country'] === 'ZZ'
                || !$date || $date->format('Y-m-d') !== $context['estimated_delivery_date']
                || !is_array($context['product_ids'] ?? null)) {
                return self::unavailable('context_invalid');
            }
            $nonceAction = 'hp_gmc_gcr_' . $context['order_reference'];
            $consent = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && ($_POST['hp_gmc_gcr_consent'] ?? '') === 'yes'
                && is_string($_POST['hp_gmc_gcr_nonce'] ?? null)
                && wp_verify_nonce(wp_unslash($_POST['hp_gmc_gcr_nonce']), $nonceAction);
            if (!$consent) {
                // No customer fields, product identifiers or adapter URL before consent.
                return ['version' => 1, 'status' => 'prompt', 'nonce' => wp_create_nonce($nonceAction)];
            }
            $payload = [
                'merchant_id' => self::MERCHANT_ID,
                'order_id' => $context['order_reference'],
                'email' => $context['email'],
                'delivery_country' => $context['delivery_country'],
                'estimated_delivery_date' => $context['estimated_delivery_date'],
            ];
            $gtins = [];
            foreach (array_unique($context['product_ids']) as $id) {
                if (!is_int($id) || $id < 1) { continue; }
                $product = wc_get_product($id);
                if (!$product instanceof \WC_Product) { continue; }
                $gtin = ProductIdentifiers::getGtin($product);
                if ($gtin !== '') { $gtins[$gtin] = ['gtin' => $gtin]; }
            }
            if ($gtins) { $payload['products'] = array_values($gtins); }
            return ['version' => 1, 'status' => 'ready', 'payload' => $payload];
        } catch (\Throwable $error) {
            // Fail-soft without logging private order/provider data.
            return self::unavailable('provider_error');
        }
    }

    public static function render(): void
    {
        static $rendered = false;
        if ($rendered) { return; }
        $result = self::getOptin();
        if (!in_array($result['status'], ['prompt', 'ready'], true)) { return; }
        $rendered = true;
        echo '<section class="hp-gmc-customer-reviews" aria-labelledby="hp-gmc-gcr-title">';
        echo '<h2 id="hp-gmc-gcr-title">' . esc_html__('Google Customer Reviews', 'hp-gmc-manager') . '</h2>';
        if ($result['status'] === 'prompt') {
            echo '<p>' . esc_html__('Choose whether to share your order reference, email, delivery country, delivery estimate and eligible product barcodes with Google to load its optional store and product review invitation. Google may also receive browser and device information and use cookies. This is separate from choosing to receive a survey email.', 'hp-gmc-manager') . '</p>';
            echo '<details><summary>' . esc_html__('What we share with Google', 'hp-gmc-manager') . '</summary><p>';
            echo esc_html__('On our order confirmation page, we offer Google’s optional store and product review survey. To display this option and operate the survey service, we share your order reference, email address, delivery country, estimated delivery date, and the product barcodes (GTINs) of eligible items with Google. Loading Google’s service may also allow Google to receive browser and device information and use cookies or similar technologies. Google sends a review survey only if you choose to receive it in Google’s invitation. Participation is voluntary and does not affect your purchase, support, refunds, or rewards.', 'hp-gmc-manager');
            echo ' <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">' . esc_html__('Google Privacy Policy', 'hp-gmc-manager') . '</a>. ';
            echo esc_html__('You can manage cookies using your browser’s settings and any privacy choices provided on our site.', 'hp-gmc-manager') . '</p></details>';
            echo '<p>' . esc_html__('We do not award points, discounts, or other incentives for Google store or product reviews. Leaving a review, choosing not to review, or giving any rating does not affect your rewards or support.', 'hp-gmc-manager') . '</p>';
            echo '<form method="post"><input type="hidden" name="hp_gmc_gcr_nonce" value="' . esc_attr($result['nonce']) . '">';
            echo '<button type="submit" name="hp_gmc_gcr_consent" value="yes">' . esc_html__('Allow sharing and load Google invitation', 'hp-gmc-manager') . '</button></form>';
            echo '<p>' . esc_html__('You can leave this page without loading Google. Your order is already complete.', 'hp-gmc-manager') . '</p>';
        } else {
            echo '<p data-hp-gcr-status role="status">' . esc_html__('Loading Google’s optional invitation…', 'hp-gmc-manager') . '</p>';
            echo '<button type="button" data-hp-gcr-retry hidden>' . esc_html__('Try loading again', 'hp-gmc-manager') . '</button>';
            echo '<script type="application/json" data-hp-gcr-payload>' . wp_json_encode($result['payload'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
            echo '<script defer src="' . esc_url(HP_GMC_URL . 'assets/js/customer-reviews.js?ver=' . HP_GMC_VERSION) . '"></script>';
        }
        echo '</section>';
    }
}
