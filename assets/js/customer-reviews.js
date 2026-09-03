/** HP-GMC sole Customer Reviews adapter. No Google call on staging/unknown hosts. */
(function () {
    'use strict';
    if (window.HPGMCCustomerReviews) { return; }
    var pending = null;
    var rendered = new Set();

    function production() {
        return window.location.protocol === 'https:' &&
            ['holisticpeople.com', 'www.holisticpeople.com'].includes(window.location.hostname);
    }

    function loader() {
        if (pending) { return pending; }
        pending = new Promise(function (resolve, reject) {
            // A foreign platform loader may belong to another opt-in integration.
            // Fail closed rather than issuing a second invitation.
            if (document.querySelector('script[src*="apis.google.com/js/platform.js"]') ||
                (window.gapi && window.gapi.surveyoptin)) {
                reject(new Error('existing_google_integration'));
                return;
            }
            var script = document.createElement('script');
            var timer;
            function fail() {
                clearTimeout(timer);
                script.remove();
                delete window.hpGmcGcrLoaded;
                reject(new Error('load_failed'));
            }
            window.hpGmcGcrLoaded = function () {
                clearTimeout(timer);
                if (!window.gapi || typeof window.gapi.load !== 'function') { fail(); return; }
                window.gapi.load('surveyoptin', {
                    callback: function () { resolve(window.gapi.surveyoptin); },
                    onerror: fail,
                    timeout: 15000,
                    ontimeout: fail
                });
            };
            script.src = 'https://apis.google.com/js/platform.js?onload=hpGmcGcrLoaded';
            script.async = true;
            script.defer = true;
            script.referrerPolicy = 'no-referrer';
            script.onerror = fail;
            timer = setTimeout(fail, 15000);
            document.head.appendChild(script);
        });
        pending = pending.catch(function (error) { pending = null; throw error; });
        return pending;
    }

    function mount(element) {
        if (!production() || element.dataset.hpGcrMounted) { return; }
        var source = element.querySelector('[data-hp-gcr-payload]');
        if (!source) { return; }
        var payload;
        try { payload = JSON.parse(source.textContent); } catch (error) { return; }
        if (payload.merchant_id !== 5298746911 || typeof payload.order_id !== 'string') { return; }
        source.remove();
        element.dataset.hpGcrMounted = 'true';
        var status = element.querySelector('[data-hp-gcr-status]');
        var retry = element.querySelector('[data-hp-gcr-retry]');
        var marker = 'hp-gmc-gcr-rendered:' + payload.order_id;
        function alreadyRendered() {
            if (rendered.has(payload.order_id)) { return true; }
            try { return window.sessionStorage.getItem(marker) === '1'; } catch (error) { return false; }
        }
        function run() {
            retry.hidden = true;
            if (alreadyRendered()) {
                status.textContent = 'This browser session already requested Google’s invitation.';
                return;
            }
            status.textContent = 'Loading Google’s optional invitation…';
            loader().then(function (api) {
                if (alreadyRendered()) { return; }
                if (!api || typeof api.render !== 'function') { throw new Error('render_unavailable'); }
                api.render(payload);
                rendered.add(payload.order_id);
                try { window.sessionStorage.setItem(marker, '1'); } catch (error) { /* Storage is optional. */ }
                status.textContent = 'Google’s invitation was requested. Choose in Google’s invitation whether to receive a survey email.';
            }).catch(function () {
                status.textContent = 'Google’s invitation could not be loaded. Your order is unaffected.';
                retry.hidden = false;
            });
        }
        retry.addEventListener('click', run);
        run();
    }
    window.HPGMCCustomerReviews = { mount: mount };
    document.querySelectorAll('.hp-gmc-customer-reviews').forEach(mount);
}());
