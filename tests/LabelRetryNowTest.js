const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let app;
vm.runInNewContext(fs.readFileSync('assets/app.js', 'utf8'), {
  Vue: { createApp(options) { app = options; return { mount() {} }; } },
  document: { addEventListener() {} },
});

async function test() {
  const waiting = { order_sn: 'TEST', label_fetch_status: 'queued', label_fetch_attempts: 1 };
  let calls = 0, refreshed = 0, release;
  const context = Object.assign({}, app.methods, {
    view: 'orders', labelFetches: new Set(), pageData: { items: [waiting] },
    api(action, options) {
      calls++;
      assert.equal(action, 'retry_label_now');
      assert.equal(options.body.order_sn, 'TEST');
      return new Promise(resolve => { release = resolve; });
    },
    notify() {},
    async loadOrders() { refreshed++; },
    async loadLabels() { refreshed++; },
  });
  assert.equal(context.labelFetchRetryWaiting(waiting), true);
  assert.equal(context.labelFetchRetryWaiting({ label_fetch_status: 'queued', label_fetch_attempts: 0 }), false);
  assert.equal(context.labelFetchRetryWaiting({ label_fetch_status: 'processing', label_fetch_attempts: 1 }), false);
  assert.equal(context.labelFetchButtonText(waiting, true), 'Coba sekarang');
  const pending = context.retryLabelNow(waiting);
  assert.equal(waiting.label_fetching, true);
  await context.retryLabelNow(waiting);
  assert.equal(calls, 1, 'Repeated clicks must not submit another request');
  release({ message: 'Queued' });
  await pending;
  assert.equal(refreshed, 1);
  assert.equal(waiting.label_fetching, false);
  context.view = 'labels';
  context.api = async () => ({ message: 'Queued' });
  await context.retryLabelNow(waiting);
  assert.equal(refreshed, 2);
  context.api = async () => { throw new Error('Offline'); };
  await context.retryLabelNow(waiting);
  assert.equal(waiting.label_fetching, false, 'Failure must allow another click');
  console.log('Label retry button tests passed');
}
test().catch(error => { console.error(error); process.exitCode = 1; });
