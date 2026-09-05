/** Optional Google Merchant Center store widget. It never handles order or survey data. */
(function () {
    'use strict';
    var safeQuery = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','gclid','gbraid','wbraid'];
    var query = new URLSearchParams(window.location.search);
    for (var pair of query.entries()) { if (!safeQuery.includes(pair[0]) || pair[1].length > 160) { return; } }
    var path;
    try { path = decodeURIComponent(window.location.pathname || '/'); } catch (error) { return; }
    if (/(?:^|\/)(?:order-pay|order-received|my-account|hp-account|cart|wp-admin)(?:\/|$)/i.test(path) ||
        (/^\/(?:hp-checkout|checkout)(?:\/|$)/i.test(path) && !['/hp-checkout', '/hp-checkout/'].includes(path))) { return; }
    var config = window.HPGMCStoreWidgetConfig || {};
    if (window.HPGMCStoreWidget || window.location.protocol !== 'https:' ||
        !['holisticpeople.com', 'www.holisticpeople.com'].includes(window.location.hostname) ||
        config.merchantId !== 5298746911) { return; }

    window.HPGMCStoreWidget = { status: 'not_started' };
    var existing = document.getElementById('merchantWidgetScript');
    if (existing || document.querySelector('script[src*="merchantwidget.js"]')) {
        window.HPGMCStoreWidget.status = 'foreign_or_existing_loader';
        return;
    }
    var script = document.createElement('script');
    script.id = 'merchantWidgetScript';
    script.src = 'https://www.gstatic.com/shopping/merchant/merchantwidget.js';
    script.defer = true;
    script.referrerPolicy = 'strict-origin-when-cross-origin';
    script.addEventListener('load', function () {
        window.HPGMCStoreWidget.status = 'script_loaded';
        if (!window.merchantwidget || typeof window.merchantwidget.start !== 'function') {
            window.HPGMCStoreWidget.status = 'start_unavailable';
            return;
        }
        try {
            window.HPGMCStoreWidget.status = 'start_attempted';
            window.merchantwidget.start({ merchant_id: config.merchantId, position: config.position || 'LEFT_BOTTOM', region: config.region || 'US', sideMargin: config.sideMargin || 21, bottomMargin: config.bottomMargin || 33, mobileSideMargin: config.mobileSideMargin || 11, mobileBottomMargin: config.mobileBottomMargin || 96 });
        } catch (error) {
            window.HPGMCStoreWidget.status = 'start_failed';
        }
    });
    script.addEventListener('error', function () { window.HPGMCStoreWidget.status = 'script_load_failed'; });
    document.head.appendChild(script);
}());
