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

  const notice = {id:'shipping:HIGH',type:'shipping',order_sn:'HIGH',estimated:13000,actual:125400,unread:true};
  const feed = () => ({items:[{...notice}],summary:{unread:1,shipping:{unread:1}}});
  const calls = [];
  let finishRead;
  context.api = async (action, options) => {
    calls.push(action);
    if(action==='notifications') return feed();
    assert.equal(action,'read_notifications');
    assert.equal(options.body.items[0].actual,125400);
    return new Promise(resolve=>{finishRead=resolve;});
  };
  await context.loadShippingNotifications(true);
  assert.equal(context.shippingNotificationsOpen,false,'Prefetch must not open the bell');
  const cached=context.shippingNotifications;
  await context.toggleShippingNotifications();
  assert.equal(context.shippingNotifications,cached,'Opening displays prefetched data immediately');
  assert.equal(context.appNotificationSummary.unread,0,'Unread badge clears without waiting for the read API');
  assert.equal(context.shippingNotificationsLoading,false,'Read persistence must not show a loading screen');
  assert.deepEqual(calls,['notifications','read_notifications'],'A warm bell never fetches the list again');
  const ack=context.notificationAckPromise;
  finishRead({items:[{...notice,unread:false}],summary:{unread:0,shipping:{unread:0}}});await ack;
  await context.toggleShippingNotifications();
  await context.toggleShippingNotifications();
  assert.deepEqual(calls,['notifications','read_notifications'],'Repeated open/close reuses cached read items');
  context.closeShippingNotifications();

  let finishFeed;
  context.api=async()=>new Promise(resolve=>{finishFeed=resolve;});
  const pending=context.loadShippingNotifications(true);
  context.shippingFeeReadVersion++;
  context.appNotificationSummary={unread:0};
  finishFeed(feed());await pending;
  assert.equal(context.appNotificationSummary.unread,0,'An older background response cannot restore the badge after reading');

  context.api=async()=>{throw new Error('Offline');};
  await context.loadShippingNotifications(true);
  assert.equal(context.shippingNotifications,cached,'A failed refresh keeps cached notifications visible');
  assert.equal(context.shippingNotificationsError,'Offline');

  context.notificationCacheAt=0;
  context.shippingNotificationsOpen=false;
  let finishList;
  context.api=async action=>{
    assert.equal(action,'notifications','Background prefetch must not acknowledge unseen notifications');
    return new Promise(resolve=>{finishList=resolve;});
  };
  const loading=context.loadShippingNotifications(true);
  finishList(feed());await loading;
  assert.equal(context.appNotificationSummary.unread,1,'Prefetch retains unread count until opened');

  const sample={id:'sample:123',type:'sample',notification_id:'123',unread:true};
  context.applyNotificationFeed({items:[sample],summary:{unread:1,shipping:{unread:0}}});
  context.api=async(action,options)=>{
    assert.equal(action,'read_notifications');assert.equal(options.body.items[0].notification_id,'123');
    throw new Error('Read failed');
  };
  await context.acknowledgeAppNotifications([sample]);
  assert.equal(sample.unread,true,'Failed read persistence restores unread state');
  assert.equal(context.appNotificationSummary.unread,1);
  console.log('Shipping fee alert UI tests passed');
}
test().catch(error => {console.error(error); process.exitCode = 1;});
