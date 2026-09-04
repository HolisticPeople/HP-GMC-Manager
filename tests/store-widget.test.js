'use strict';
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const code = fs.readFileSync(__dirname + '/../assets/js/store-widget.js', 'utf8');
let appended;
const script = { addEventListener(type, callback) { this[type] = callback; } };
const context = {
  window: { location: { protocol: 'https:', hostname: 'holisticpeople.com' }, HPGMCStoreWidgetConfig: { merchantId: 5298746911, position: 'LEFT_BOTTOM', region: 'US', sideMargin: 21, bottomMargin: 33, mobileSideMargin: 11, mobileBottomMargin: 19 }, merchantwidget: { start(args) { context.args = args; } } },
  document: { getElementById() { return null; }, querySelector() { return null; }, createElement() { return script; }, head: { appendChild(node) { appended = node; } } }
};
vm.runInNewContext(code, context);
assert.equal(appended.src, 'https://www.gstatic.com/shopping/merchant/merchantwidget.js');
appended.load();
assert.deepEqual(context.args, { merchant_id: 5298746911, position: 'LEFT_BOTTOM', region: 'US', sideMargin: 21, bottomMargin: 33, mobileSideMargin: 11, mobileBottomMargin: 19 });
assert.equal(context.window.HPGMCStoreWidget.status, 'start_attempted');
console.log('store widget uses the scoped Merchant Center contract');
