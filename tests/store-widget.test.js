'use strict';
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const code = fs.readFileSync(__dirname + '/../assets/js/store-widget.js', 'utf8');
function run(overrides = {}) {
  const result = { appended: [], starts: [] };
  const context = {
    URLSearchParams,
    window: {
      location: { protocol: 'https:', hostname: 'holisticpeople.com', pathname: '/product/example/', search: '', ...overrides.location },
      HPGMCStoreWidgetConfig: { merchantId: 5298746911, position: 'LEFT_BOTTOM', region: 'US', sideMargin: 21, bottomMargin: 96, mobileSideMargin: 11, mobileBottomMargin: 144, ...overrides.config },
      merchantwidget: overrides.noStart ? undefined : { start(args) { if (overrides.startError) throw Error('mock'); result.starts.push(args); } }
    },
    document: {
      getElementById() { return overrides.existingId ? {} : null; },
      querySelector() { return overrides.existingSrc ? {} : null; },
      createElement() { return { addEventListener(type, callback) { this[type] = callback; } }; },
      head: { appendChild(node) { result.appended.push(node); } }
    }
  };
  vm.createContext(context); vm.runInContext(code, context);
  return { ...result, context, repeat() { vm.runInContext(code, context); } };
}
let r = run({ location: { pathname: '/hp-checkout/' } });
assert.equal(r.appended.length, 1);
assert.equal(r.appended[0].src, 'https://www.gstatic.com/shopping/merchant/merchantwidget.js');
assert.equal(r.appended[0].referrerPolicy, 'strict-origin-when-cross-origin');
r.appended[0].load();
assert.deepEqual(r.starts[0], { merchant_id: 5298746911, position: 'LEFT_BOTTOM', region: 'US', sideMargin: 21, bottomMargin: 96, mobileSideMargin: 11, mobileBottomMargin: 144 });
assert.equal(r.context.window.HPGMCStoreWidget.status, 'start_attempted');
r.repeat(); assert.equal(r.appended.length, 1); assert.equal(r.starts.length, 1);
for (const pathname of ['/hp-checkout/order-pay/1/', '/hp-checkout/order-received/1/', '/hp-checkout/%6frder-pay/1/', '/checkout/', '/hp-account/', '/my-account/orders/', '/cart/', '/wp-admin/']) {
  assert.equal(run({ location: { pathname } }).appended.length, 0, pathname);
}
for (const search of ['?key=private', '?order_id=123', '?email=test', '?utm_source[]=nested', '?utm_source=' + 'x'.repeat(161)]) {
  assert.equal(run({ location: { search } }).appended.length, 0, search);
}
assert.equal(run({ location: { search: '?utm_source=google&gclid=public-click' } }).appended.length, 1);
assert.equal(run({ location: { protocol: 'http:' } }).appended.length, 0);
assert.equal(run({ location: { hostname: 'env-holisticpeoplecom-hpdevplus.kinsta.cloud' } }).appended.length, 0);
assert.equal(run({ config: { merchantId: 123 } }).appended.length, 0);
for (const option of ['existingId', 'existingSrc']) {
  r = run({ [option]: true }); assert.equal(r.appended.length, 0); assert.equal(r.context.window.HPGMCStoreWidget.status, 'foreign_or_existing_loader');
}
r = run(); r.appended[0].error(); assert.equal(r.context.window.HPGMCStoreWidget.status, 'script_load_failed');
r = run({ noStart: true }); r.appended[0].load(); assert.equal(r.context.window.HPGMCStoreWidget.status, 'start_unavailable');
r = run({ startError: true }); r.appended[0].load(); assert.equal(r.context.window.HPGMCStoreWidget.status, 'start_failed');
console.log('Store widget mocked checkout, privacy, duplicate and failure checks passed.');
