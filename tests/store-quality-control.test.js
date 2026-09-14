'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/store-quality-control.js'), 'utf8');

// A small browser boundary double: no network or provider content is contacted.
function browser(options = {}) {
    const url = new URL(options.url || 'https://holisticpeople.com/hp-checkout/?hp_google_quality_preview=1&hp_source=sidecart');
    const scripts = [];
    const timers = new Map();
    const observers = [];
    const listeners = {};
    const starts = [];
    const events = [];
    const form = { values: { email: 'fixture@example.test', quantity: 2 }, submissions: 0, resets: 0 };
    const controls = Array.from({ length: 2 }, () => ({
        attrs: { type: 'button' },
        setAttribute(key, value) { this.attrs[key] = value; },
        closest(selector) { return selector === '[data-hp-gmc-quality-start]' ? this : null; },
    }));
    const statuses = Array.from({ length: 2 }, () => ({ textContent: '' }));
    let wrapper = null;
    let timerId = 0;
    const document = {
        body: {},
        head: { appendChild(script) { scripts.push(script); } },
        querySelectorAll(selector) { return selector === '[data-hp-gmc-quality-status]' ? statuses : controls; },
        getElementById(id) {
            if (id === 'google-merchantwidget-iframe-wrapper') { return wrapper; }
            if (id === 'merchantWidgetScript') { return options.existingLoader || scripts.find(script => script.id === id) || null; }
            return null;
        },
        querySelector() { return options.existingLoader || null; },
        createElement(tag) {
            assert.equal(tag, 'script', 'The controller may only create the provider script');
            return { listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; } };
        },
        addEventListener(name, fn) { (listeners[name] ||= []).push(fn); },
    };
    const window = {
        location: url, innerHeight: 844, innerWidth: 390,
        HPGMCStoreQualityConfig: options.config || { allowGoogle: true, merchantId: 5298746911 },
        getComputedStyle(node) { return node.style; },
        merchantwidget: { start(params) { starts.push(params); if (options.startThrows) throw Error('provider failed'); } },
    };
    const context = vm.createContext({
        window, document, URLSearchParams,
        setTimeout(fn, delay) { const id = ++timerId; timers.set(id, { fn, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        MutationObserver: class {
            constructor(fn) { this.fn = fn; this.connected = false; observers.push(this); }
            observe() { this.connected = true; }
            disconnect() { this.connected = false; }
        },
    });
    function run() { vm.runInContext(source, context); }
    function click(index = 0, unrelated = false) {
        const event = {
            target: unrelated ? { closest() { return null; } } : controls[index],
            prevented: false,
            stopped: false,
            preventDefault() { this.prevented = true; },
            stopPropagation() { this.stopped = true; },
        };
        (listeners.click || []).forEach(fn => fn(event));
        events.push(event);
        return event;
    }
    run();
    return {
        window, scripts, starts, controls, statuses, observers, timers, form, events, run, click,
        state() { return window.HPGMCStoreQualityControl; },
        load() { assert.equal(scripts.length, 1); scripts[0].listeners.load(); },
        error() { scripts[0].listeners.error(); },
        elapse() { [...timers.values()].forEach(timer => timer.fn()); },
        launcher(rect = {}, style = {}, settings = {}) {
            const box = { width: 64, height: 64, top: 700, left: 12, bottom: 764, right: 76, ...rect };
            const normal = { display: 'block', visibility: 'visible', opacity: '1' };
            const ancestor = { style: { ...normal, ...settings.ancestorStyle }, parentElement: null };
            const frame = settings.empty ? null : {
                getBoundingClientRect() { return box; }, style: { ...normal, ...style }, parentElement: null,
            };
            wrapper = {
                getBoundingClientRect() { return box; },
                querySelector(selector) { assert.equal(selector, 'iframe'); return frame; },
                style: normal, parentElement: ancestor,
            };
            if (frame) frame.parentElement = wrapper;
            observers.filter(observer => observer.connected).forEach(observer => observer.fn());
        },
    };
}

test('nothing contacts Google until the quality button is explicitly selected', () => {
    const b = browser();
    assert.equal(b.scripts.length, 0);
    assert.equal(b.starts.length, 0);
    assert.equal(b.timers.size, 0);
    assert.equal(b.state().status, 'idle');
    assert.equal(b.click(0, true).prevented, false);
    assert.equal(b.scripts.length, 0);
});

test('two controls and repeated script execution share exactly one loader and start', () => {
    const b = browser();
    b.run();
    b.click(0); b.click(1); b.click(0);
    assert.equal(b.scripts.length, 1);
    assert.equal(b.scripts[0].src, 'https://www.gstatic.com/shopping/merchant/merchantwidget.js');
    assert.equal(b.scripts[0].referrerPolicy, 'strict-origin-when-cross-origin');
    assert.equal(b.starts.length, 0);
    b.load(); b.click(1);
    assert.equal(b.starts.length, 1);
    assert.equal(b.starts[0].position, 'LEFT_BOTTOM');
    assert.equal(b.starts[0].merchant_id, 5298746911);
});

test('staging, private routes and sensitive query parameters never append a Google script', () => {
    const denied = [
        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/?hp_google_quality_preview=1',
        'http://holisticpeople.com/?hp_google_quality_preview=1',
        'https://example.com/?hp_google_quality_preview=1',
        'https://holisticpeople.com/hp-account/?hp_google_quality_preview=1',
        'https://holisticpeople.com/hp-checkout/order-received/123/?hp_google_quality_preview=1',
        'https://holisticpeople.com/order-pay/123/?hp_google_quality_preview=1',
        'https://holisticpeople.com/hp-checkout/?hp_google_quality_preview=1&key=wc_order_secret',
        'https://holisticpeople.com/?hp_google_quality_preview=1&email=private@example.test',
        'https://holisticpeople.com/?hp_google_quality_preview=1&hp_google_quality_preview=1',
        'https://holisticpeople.com/?hp_google_quality_preview=0',
        'https://holisticpeople.com/?hp_source=sidecart&hp_google_quality_preview=1',
        'https://holisticpeople.com/%E0%A4%A?hp_google_quality_preview=1',
    ];
    for (const url of denied) {
        const b = browser({ url }); b.click();
        assert.equal(b.scripts.length, 0, url);
        assert.equal(b.starts.length, 0, url);
        assert.equal(b.state().status, 'preview_only', url);
    }
});

test('disabled config and wrong merchant remain local layout previews', () => {
    for (const config of [{ allowGoogle: false, merchantId: 5298746911 }, { allowGoogle: true, merchantId: 1 }, {}]) {
        const b = browser({ config }); b.click();
        assert.equal(b.scripts.length, 0);
        assert.equal(b.state().status, 'preview_only');
    }
});

test('approved public homepage and sidecart checkout routes can activate', () => {
    for (const url of [
        'https://holisticpeople.com/?hp_google_quality_preview=1',
        'https://www.holisticpeople.com/return-policy/?hp_google_quality_preview=1&utm_source=fixture',
        'https://holisticpeople.com/hp-checkout/?hp_google_quality_preview=1&hp_source=sidecart',
    ]) {
        const b = browser({ url }); b.click(); assert.equal(b.scripts.length, 1, url);
    }
});

test('a loaded script is not reported as a visible Google frame', () => {
    const b = browser(); b.click(); b.load();
    assert.equal(b.state().status, 'start_attempted');
    assert.equal(b.state().frameVisible, false);
    b.launcher({ width: 0, height: 64 });
    assert.equal(b.state().frameVisible, false);
    b.launcher(undefined, { display: 'none' });
    assert.equal(b.state().frameVisible, false);
    b.launcher(undefined, { visibility: 'hidden' });
    assert.equal(b.state().frameVisible, false);
    b.launcher(undefined, { opacity: '0' });
    assert.equal(b.state().frameVisible, false);
    b.launcher();
    assert.equal(b.state().status, 'widget_frame_visible');
    assert.equal(b.state().frameVisible, true);
    assert.equal(b.timers.size, 0);
    assert.ok(b.observers.every(observer => !observer.connected));
    assert.ok(b.statuses.every(node => node.textContent.includes('Select its button')));
    assert.ok(b.controls.every(node => node.attrs['aria-busy'] === 'false'));
});

test('network failure and timeout keep fallback guidance without an eligibility claim', () => {
    for (const failure of ['error', 'elapse']) {
        const b = browser(); b.click();
        assert.ok(b.controls.every(node => node.attrs['aria-busy'] === 'true'));
        b[failure]();
        assert.equal(b.state().status, failure === 'error' ? 'load_failed' : 'load_timeout');
        assert.equal(b.state().frameVisible, false);
        assert.ok(b.statuses.every(node => node.textContent.includes('Visit our store page on Google')));
        assert.ok(b.statuses.every(node => node.textContent.includes('does not indicate Google eligibility')));
        assert.ok(b.controls.every(node => node.attrs['aria-busy'] === 'false'));
    }
});

test('provider API failure and existing loader do not duplicate requests', () => {
    const missing = browser(); missing.window.merchantwidget = null; missing.click(); missing.load();
    assert.equal(missing.state().status, 'start_unavailable');
    const throwing = browser({ startThrows: true }); throwing.click(); throwing.load();
    assert.equal(throwing.state().status, 'start_failed');
    assert.ok(throwing.observers.every(observer => !observer.connected));
    const existing = browser({ existingLoader: {} }); existing.click(); existing.click(1);
    assert.equal(existing.state().status, 'existing_loader');
    assert.equal(existing.scripts.length, 0);
    assert.equal(existing.starts.length, 0);
});

test('checkout click prevents default only; no submit, reset, value or location mutation', () => {
    const b = browser();
    const before = JSON.stringify(b.form);
    const location = b.window.location.href;
    const event = b.click();
    assert.equal(event.prevented, true);
    assert.equal(event.stopped, false);
    b.load();
    assert.equal(JSON.stringify(b.form), before);
    assert.equal(b.window.location.href, location);
    assert.equal(b.starts[0].bottomMargin, 96);
    assert.equal(b.starts[0].mobileBottomMargin, 144);
});


test('empty wrapper, offscreen frame and hidden ancestors cannot prove frame visibility', () => {
    const cases = [
        [{}, {}, { empty: true }],
        [{ bottom: 0, top: -64 }, {}, {}],
        [{ right: 0, left: -64 }, {}, {}],
        [{ top: 844, bottom: 908 }, {}, {}],
        [{ left: 390, right: 454 }, {}, {}],
        [{}, {}, { ancestorStyle: { display: 'none' } }],
        [{}, {}, { ancestorStyle: { visibility: 'hidden' } }],
        [{}, {}, { ancestorStyle: { opacity: '0' } }],
    ];
    for (const args of cases) {
        const b = browser(); b.click(); b.load(); b.launcher(...args);
        assert.equal(b.state().frameVisible, false, JSON.stringify(args));
        assert.equal(b.state().status, 'start_attempted', JSON.stringify(args));
        assert.ok(b.timers.size > 0);
        b.elapse();
        assert.equal(b.state().status, 'load_timeout');
    }
});

test('a script arriving after timeout does not initialize Google or overwrite failure guidance', () => {
    const b = browser(); b.click(); b.elapse();
    const message = b.statuses[0].textContent;
    b.load();
    assert.equal(b.starts.length, 0);
    assert.equal(b.observers.length, 0);
    assert.equal(b.state().status, 'load_timeout');
    assert.equal(b.statuses[0].textContent, message);
    assert.equal(b.state().frameVisible, false);
});
