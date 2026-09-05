<?php
/**
 * Native HP-Zen Order Received surface.
 *
 * @package HP-Zen
 * @version 8.1.0
 * @var WC_Order|false $order
 */

defined('ABSPATH') || exit;

if (!function_exists('hp_zen_theme_order_surfaces_complete') || !hp_zen_theme_order_surfaces_complete()) {
    $core = function_exists('WC') ? WC()->plugin_path() . '/templates/checkout/thankyou.php' : '';
    if ($core !== '' && is_readable($core)) {
        include $core;
    }
    return;
}

if (!$order instanceof WC_Order) {
    get_template_part('template-parts/order-surface/unavailable', null, ['surface' => 'unavailable']);
    return;
}
?>
<div class="woocommerce-order hp-zen-order-surface" data-hp-zen-template="order-surface" data-hp-zen-order-surface="thank-you" data-hp-zen-order-surface-version="1" data-hp-zen-tone="carbon">
    <?php do_action( 'woocommerce_before_thankyou', $order->get_id() ); ?>

    <?php get_template_part('template-parts/order-surface/summary', null, ['order' => $order, 'surface' => 'thank-you']); ?>

    <?php if ($order->has_status('failed')) : ?>
        <div class="hp-zen-order-surface__card woocommerce-thankyou-order-failed-actions">
            <a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="button pay"><?php esc_html_e('Pay', 'woocommerce'); ?></a>
            <?php if (is_user_logged_in()) : ?>
                <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="button pay"><?php esc_html_e('My account', 'woocommerce'); ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="hp-zen-order-surface__native">
        <?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
        <?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>
    </div>
</div>
