const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let app;
vm.runInNewContext(fs.readFileSync('assets/app.js', 'utf8'), {
  Vue: {createApp(options) {app = options; return {mount() {}};}},
  window: {}, document: {addEventListener() {}},
});
async function test() {
  const context = Object.assign(app.data(), app.methods);
  app.created.call(context);
  assert(context.nav.some(item => item.id === 'shipping-alerts'));
  assert(context.navGroups.find(group => group.id === 'business').items.some(item => item.id === 'shipping-alerts'));
  const data = {items: [], page: 2, pages: 2, total: 26, summary: {total: 26}};
  context.api = async (action, options) => {
    assert.equal(action, 'shipping_fee_alerts');
    assert.equal(options.query.page, 2);
    return data;
  };
  await context.loadShippingFeeAlerts(2);
  assert.equal(context.shippingFeeAlerts, data);
  assert.equal(context.shippingFeeSummary, data.summary);
  assert(context.shippingFeeFrom && context.shippingFeeTo);
  context.api = async () => {throw new Error('Connection failed');};
  await context.loadShippingFeeAlerts();
  assert.equal(context.shippingFeeAlerts, data, 'Keep existing data on a failed refresh');
  assert.equal(context.shippingFeeError, 'Connection failed');
  assert.equal(context.shippingFeeLoading, false);
  let notified = '';
  context.notify = message => {notified = message;};
  context.shippingFeeFrom = '2026-10-27'; context.shippingFeeTo = '2026-10-01';
  await context.syncShippingFeeAlerts();
  assert(notified.includes('valid'), 'Reject a reversed date range before syncing');

  const notice = {order_sn:'HIGH',estimated:13000,actual:125400,unread:true};
  const calls = [];
  context.api = async (action, options) => {
    calls.push(action);
    if(action==='shipping_fee_alerts') return {items:[notice],total:1,summary:{unread:1}};
    assert.equal(action,'read_shipping_fee_alerts');
    assert.equal(options.body.items[0].actual,125400);
    return {unread:0};
  };
  await context.toggleShippingNotifications();
  assert.equal(context.shippingNotificationsOpen,true);
  assert.equal(context.shippingFeeSummary.unread,0,'Opening bell clears displayed unread notifications');
  assert.deepEqual(calls,['shipping_fee_alerts','read_shipping_fee_alerts']);
  await context.toggleShippingNotifications();
  assert.equal(context.shippingNotificationsOpen,false);

  let finishSummary;
  context.api = async () => new Promise(resolve => {finishSummary=resolve;});
  const pending = context.loadShippingFeeSummary();
  context.shippingFeeReadVersion++;
  context.shippingFeeSummary={unread:0};
  finishSummary({unread:1});
  await pending;
  assert.equal(context.shippingFeeSummary.unread,0,'An older poll must not restore a badge after reading');

  context.shippingNotificationsOpen=true;
  let finishList;
  context.api=async action=>{
    assert.equal(action,'shipping_fee_alerts','Closing before load must not mark unseen items read');
    return new Promise(resolve=>{finishList=resolve;});
  };
  const loading=context.loadShippingNotifications();
  context.closeShippingNotifications();
  finishList({items:[notice],total:1,summary:{unread:1}});
  await loading;
  console.log('Shipping fee alert UI tests passed');
}
test().catch(error => {console.error(error); process.exitCode = 1;});
