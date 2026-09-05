'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync(__dirname+'/../assets/js/customer-reviews.js','utf8');
function harness(host, duplicate=false) {
 let appends=0;
 const retry={hidden:true,addEventListener(){}};
 const status={textContent:''};
 const payload={textContent:JSON.stringify({merchant_id:5298746911,order_id:'gcr_abcdefghijklmnop'}),remove(){}};
 const section={dataset:{},querySelector(s){return s.includes('payload')?payload:s.includes('status')?status:retry;}};
 const document={
   querySelector(s){return duplicate&&s.includes('platform.js')?{}:null;},
   querySelectorAll(){return [section];},
   createElement(){return {remove(){}};},
   head:{appendChild(){appends++;}}
 };
 const window={location:{protocol:'https:',hostname:host},sessionStorage:{getItem(){return null},setItem(){}}};
 const context={window,document,setTimeout(){return 1},clearTimeout,console,Set,Error,Promise,JSON};
 vm.runInNewContext(code,context);
 return {window,section,status,get appends(){return appends}};
}
let h=harness('staging-hp.kinsta.cloud');assert.equal(h.appends,0);assert.equal(h.section.dataset.hpGcrMounted,undefined);
h=harness('unknown.example');assert.equal(h.appends,0);
h=harness('holisticpeople.com');assert.equal(h.appends,1);assert.equal(h.section.dataset.hpGcrMounted,'true');
h.window.HPGMCCustomerReviews.mount(h.section);assert.equal(h.appends,1,'repeated mount cannot add loader');
h=harness('holisticpeople.com',true);setTimeout(()=>{assert.equal(h.appends,0);assert.match(h.status.textContent,/could not be loaded/);console.log('5 assertions passed; Google transport intercepted.');},0);
