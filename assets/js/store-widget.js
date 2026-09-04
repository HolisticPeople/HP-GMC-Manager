/** Optional Google Merchant Center store widget. It never handles order or survey data. */
(function () {
    'use strict';
    if (window.HPGMCStoreWidget || window.location.protocol !== 'https:' ||
        !['holisticpeople.com', 'www.holisticpeople.com'].includes(window.location.hostname)) { return; }

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
            var config = window.HPGMCStoreWidgetConfig || {};
            if (config.merchantId !== 5298746911) { window.HPGMCStoreWidget.status = 'merchant_mismatch'; return; }
            window.merchantwidget.start({ merchant_id: config.merchantId, position: config.position || 'RIGHT_BOTTOM', region: config.region || 'US' });
        } catch (error) {
            window.HPGMCStoreWidget.status = 'start_failed';
        }
    });
    script.addEventListener('error', function () { window.HPGMCStoreWidget.status = 'script_load_failed'; });
    document.head.appendChild(script);
}());
