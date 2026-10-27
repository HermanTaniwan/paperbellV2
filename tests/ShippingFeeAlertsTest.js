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
  console.log('Shipping fee alert UI tests passed');
}
test().catch(error => {console.error(error); process.exitCode = 1;});
