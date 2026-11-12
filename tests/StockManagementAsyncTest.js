const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let app;
vm.runInNewContext(fs.readFileSync('assets/app.js', 'utf8'), {
  Vue: {createApp(options) {app = options; return {mount() {}};}},
  window: {}, document: {addEventListener() {}},
});
const row = id => ({id, shopee: {stock: 5, error: ''}, tiktok: {stock: 8, error: ''}, shopeeTarget: 10, tiktokTarget: 8});
const result = (id, failed = false) => ({items: [{id,
  shopee: {status: 'updated', target: 10, error: ''},
  tiktok: {status: failed ? 'failed' : 'unchanged', target: failed ? 12 : null, error: failed ? 'Marketplace unavailable' : ''},
}]});
async function test() {
  const context = Object.assign(app.data(), app.methods, {notify() {}});
  const first = row(1), second = row(2), draft = row(3);
  context.stockManagement.items = [first, second, draft];
  const pending = new Map();
  context.api = (action, options) => {
    assert.equal(action, 'stock_management_apply');
    assert.equal(options.body.items.length, 1);
    assert.equal(options.body.items[0].tiktok_quantity, null, 'Do not overwrite an unchanged marketplace');
    return new Promise(resolve => pending.set(options.body.items[0].id, resolve));
  };
  const saveFirst = context.saveStockManagementItem(first);
  const saveSecond = context.saveStockManagementItem(second);
  assert(first.saving && second.saving, 'Different items can save concurrently');
  assert.equal(await context.saveStockManagementItem(first), false, 'Prevent duplicate submission');
  await context.loadStockManagement(); // Must not replace rows during a save.
  pending.get(2)(result(2));
  assert.equal(await saveSecond, true);
  assert(first.saving, 'One item can complete while another is pending');
  assert.equal(second.shopee.stock, 10);
  assert.equal(second.tiktok.stock, 8);
  assert.equal(draft.shopeeTarget, 10, 'Keep unsaved edits on other rows');
  pending.get(1)(result(1));
  await saveFirst;
  const partial = row(4);
  partial.tiktokTarget = 12;
  context.api = async () => result(4, true);
  assert.equal(await context.saveStockManagementItem(partial), false);
  assert.equal(partial.shopee.stock, 10);
  assert.equal(partial.tiktok.stock, 8);
  assert.equal(partial.tiktokTarget, 12);
  assert(context.stockManagementChanged(partial), 'Failed marketplace remains retryable');
  context.api = async () => {throw new Error('Connection lost');};
  await context.saveStockManagementItem(partial);
  assert.equal(partial.saving, false);
  assert.equal(partial.lastError, 'Connection lost');
  for (const invalid of ['', null, -1, 1.5]) {
    partial.shopeeTarget = invalid;
    context.api = async () => assert.fail('Invalid target must not be submitted');
    await context.saveStockManagementItem(partial);
  }
  context.stockManagement.items = Array.from({length: 7}, (_, i) => row(i + 10));
  let active = 0, maximum = 0;
  context.api = async (action, options) => {
    maximum = Math.max(maximum, ++active);
    await new Promise(resolve => setTimeout(resolve, 5));
    active--;
    return result(options.body.items[0].id);
  };
  await context.applyStockManagement();
  assert.equal(maximum, 3, 'Limit bulk saving to three concurrent item requests');
  assert.equal(context.stockManagementSaving, false);
  assert.equal(context.stockManagementChanges().length, 0);
  console.log('Stock management async tests passed');
}
test().catch(error => {console.error(error); process.exitCode = 1;});
