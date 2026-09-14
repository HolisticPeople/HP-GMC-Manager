/** Staff preview: only an explicit click loads Google's unmodified store widget. */
(function () {
    'use strict';
    if (window.HPGMCStoreQualityControl) { return; }
    var config = window.HPGMCStoreQualityConfig || {};
    var state = window.HPGMCStoreQualityControl = { status: 'idle', frameVisible: false };
    var started = false;
    var timeout;
    var observer;
    function update(status, message, busy) {
        state.status = status;
        document.querySelectorAll('[data-hp-gmc-quality-status]').forEach(function (node) { node.textContent = message; });
        document.querySelectorAll('[data-hp-gmc-quality-start]').forEach(function (node) {
            node.setAttribute('aria-busy', busy ? 'true' : 'false');
        });
    }
    function visibleLauncher() {
        var wrapper = document.getElementById('google-merchantwidget-iframe-wrapper');
        if (!wrapper || !wrapper.getBoundingClientRect) { return false; }
        var frame = wrapper.querySelector('iframe');
        if (!frame || !frame.getBoundingClientRect) { return false; }
        var rect = frame.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0 || rect.bottom <= 0 || rect.right <= 0 || rect.top >= window.innerHeight || rect.left >= window.innerWidth) { return false; }
        for (var node = frame; node; node = node.parentElement) {
            var style = window.getComputedStyle(node);
            if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') { return false; }
        }
        state.frameVisible = true;
        clearTimeout(timeout);
        if (observer) { observer.disconnect(); }
        update('widget_frame_visible', 'Google’s control has opened near the bottom of this page. Select its button to view store quality. If it is blank, use the link below.', false);
        return true;
    }
    function fail(status, message) {
        clearTimeout(timeout);
        if (observer) { observer.disconnect(); }
        update(status, message + ' You can use “Visit our store page on Google” below. This does not indicate Google eligibility.', false);
    }
    function safeLocation() {
        if (window.location.protocol !== 'https:' || !['holisticpeople.com', 'www.holisticpeople.com'].includes(window.location.hostname)) { return false; }
        var path;
        try { path = decodeURIComponent(window.location.pathname); } catch (error) { return false; }
        if (/(?:^|\/)(?:order-pay|order-received|my-account|hp-account|cart|wp-admin)(?:\/|$)/i.test(path)) { return false; }
        var checkout = ['/hp-checkout', '/hp-checkout/'].includes(path);
        if (/^\/(?:hp-checkout|checkout)(?:\/|$)/i.test(path) && !checkout) { return false; }
        var query = new URLSearchParams(window.location.search);
        if (query.getAll('hp_google_quality_preview').length !== 1 || query.get('hp_google_quality_preview') !== '1') { return false; }
        var allowed = ['hp_google_quality_preview', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'gclid', 'gbraid', 'wbraid'];
        for (var pair of query.entries()) {
            if (pair[0] === 'hp_source' && checkout && pair[1] === 'sidecart') { continue; }
            if (!allowed.includes(pair[0]) || pair[1].length > 160) { return false; }
        }
        return true;
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-hp-gmc-quality-start]');
        if (!button) { return; }
        event.preventDefault();
        if (config.allowGoogle !== true || config.merchantId !== 5298746911 || !safeLocation()) {
            update('preview_only', 'Layout preview only. Google’s live panel can be tested by staff on the production domain.', false);
            return;
        }
        if (started) {
            visibleLauncher();
            return;
        }
        started = true;
        update('loading', 'Loading Google’s store quality control…', true);
        if (visibleLauncher()) { return; }
        if (document.getElementById('merchantWidgetScript') || document.querySelector('script[src*="merchantwidget.js"]')) {
            fail('existing_loader', 'Another Google widget loader is already present; this preview will not load it twice.');
            return;
        }
        timeout = setTimeout(function () { fail('load_timeout', 'Google’s control has not become visible yet. It may be blocked or slow to respond.'); }, 15000);
        var script = document.createElement('script');
        script.id = 'merchantWidgetScript';
        script.src = 'https://www.gstatic.com/shopping/merchant/merchantwidget.js';
        script.async = true;
        script.referrerPolicy = 'strict-origin-when-cross-origin';
        script.addEventListener('error', function () { fail('load_failed', 'Google’s control could not load.'); });
        script.addEventListener('load', function () {
            if (state.status === 'load_timeout') { return; }
            if (!window.merchantwidget || typeof window.merchantwidget.start !== 'function') {
                fail('start_unavailable', 'Google’s control could not start.');
                return;
            }
            try {
                observer = new MutationObserver(visibleLauncher);
                observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
                update('start_attempted', 'Google’s script loaded. Waiting for its button to appear…', true);
                var checkout = /^\/hp-checkout\/?$/.test(window.location.pathname);
                window.merchantwidget.start({ merchant_id: config.merchantId, region: 'US', position: 'LEFT_BOTTOM', sideMargin: 21, bottomMargin: checkout ? 96 : 33, mobileSideMargin: 11, mobileBottomMargin: checkout ? 144 : 96 });
                visibleLauncher();
            } catch (error) { fail('start_failed', 'Google’s control could not start.'); }
        });
        document.head.appendChild(script);
    });
}());
