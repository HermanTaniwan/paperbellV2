const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

let app;
const sandbox = {
  Vue: { createApp(options) { app = options; return { mount() {} }; } },
  document: { addEventListener() {} },
  window: {},
  localStorage: {
    getItem() { return '[]'; },
    setItem() { throw new Error('Storage denied'); },
  },
  Notification: class {
    static permission = 'granted';
    constructor() { throw new Error('Notifications unavailable'); }
  },
  setTimeout,
};
sandbox.window.Notification = sandbox.Notification;
vm.runInNewContext(fs.readFileSync('assets/app.js', 'utf8'), sandbox);

async function test() {
  const data = { items: [], spooler: [], incidents: [{ id: 652, title: 'Printer memerlukan tindakan' }] };
  const context = Object.assign(app.data(), app.methods, {
    api: async () => data,
    notify() {},
    playPrinterAlert() {},
  });
  await context.loadQueueWidget();
  assert.equal(context.queueData, data, 'Denied storage/notification must not discard queue data');
  assert.equal(context.queueWidgetError, '');
  context.api = async () => { throw new Error('HTTP 504'); };
  await context.loadQueueWidget();
  assert.equal(context.queueWidgetError, 'HTTP 504', 'Refresh failures must be visible');
  assert.equal(context.queueData, data, 'Keep previous data on refresh failure');
  context.api = async () => data;
  await context.loadQueueWidget();
  assert.equal(context.queueWidgetError, '', 'Successful refresh clears the warning');
  console.log('Queue notification tests passed');
}
test().catch(error => { console.error(error); process.exitCode = 1; });
