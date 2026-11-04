const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let app;
vm.runInNewContext(fs.readFileSync('assets/app.js','utf8'), {
  Vue: {createApp(options) {app=options;return {mount(){}};}}, window:{}, document:{addEventListener(){}},
});
async function test() {
  const ctx=Object.assign(app.data(),app.methods);
  const data={from:'2026-10-01',to:'2026-10-31',minDate:'2026-01-01',maxDate:'2026-10-31',items:[],summary:{days:31}};
  ctx.api=async action=>{assert.equal(action,'shopee_product_clicks');return data;};
  await ctx.loadProductClicks();
  assert.equal(ctx.productClicksFrom,'2026-10-01');
  assert.equal(ctx.productClicksTo,'2026-10-31');
  const loads=[];ctx.loadProductClicks=()=>loads.push([ctx.productClicksFrom,ctx.productClicksTo]);
  ctx.setProductClicksRange(7);assert.deepEqual(loads.at(-1),['2026-10-25','2026-10-31']);
  ctx.setProductClicksRange(30);assert.deepEqual(loads.at(-1),['2026-10-02','2026-10-31']);
  ctx.setProductClicksRange('all');assert.deepEqual(loads.at(-1),['2026-01-01','2026-10-31']);
  ctx.setProductClicksRange('month');assert.deepEqual(loads.at(-1),['2026-10-01','2026-10-31']);
  ctx.productClicks={items:[{clicks:10},{clicks:null},{clicks:0},{clicks:20}]};
  assert.equal(ctx.productClicksPaths().length,2,'Missing dates must break the line; real zeros must remain.');
  assert(ctx.productClicksPaths().every(path=>!path.includes('NaN')));
  ctx.productClicks={items:[{clicks:0}]};
  assert(Number.isFinite(ctx.productClicksY(0)),'All-zero and one-day ranges need finite coordinates.');
  assert.equal(ctx.productClicksZoneWidth(0),756,'A single day must have a usable hover target.');
  ctx.loadProductClicks=app.methods.loadProductClicks;
  ctx.productClicksFrom='2026-10-02';ctx.productClicksTo='2026-10-01';
  ctx.api=()=>{throw new Error('Invalid range reached API');};
  await ctx.loadProductClicks();assert(ctx.productClicksError.includes('valid'));
  ctx.productClicksFrom='2026-10-01';ctx.productClicksTo='2026-10-31';
  let firstDone,secondDone;
  ctx.api=()=>new Promise(resolve=>{if(!firstDone)firstDone=resolve;else secondDone=resolve;});
  const first=ctx.loadProductClicks(),second=ctx.loadProductClicks();
  const latest={...data,summary:{clicks:200}};secondDone(latest);await second;
  firstDone({...data,summary:{clicks:100}});await first;
  assert.equal(ctx.productClicks.summary.clicks,200,'An older response must not overwrite the newest range.');
  ctx.api=async()=>{throw new Error('Offline');};await ctx.loadProductClicks();
  assert.equal(ctx.productClicks.summary.clicks,200,'Keep the existing chart on a failed refresh.');
  assert.equal(ctx.productClicksError,'Offline');assert.equal(ctx.productClicksLoading,false);
  console.log('ProductClicksChart tests passed');
}
test().catch(e=>{console.error(e);process.exitCode=1;});
