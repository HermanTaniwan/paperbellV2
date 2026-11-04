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
  ctx.productClicksChartItems=[{clicks:10},{clicks:null},{clicks:0},{clicks:20}];
  assert.equal(ctx.productClicksPaths().length,2,'Missing dates must break the line; real zeros must remain.');
  assert(ctx.productClicksPaths().every(path=>!path.includes('NaN')));
  ctx.productClicksChartItems=[{clicks:0}];
  assert(Number.isFinite(ctx.productClicksY(0)),'All-zero and one-day ranges need finite coordinates.');
  assert.equal(ctx.productClicksZoneWidth(0),756,'A single day must have a usable hover target.');
  ctx.productClicks={items:[
    {date:'2026-09-29',clicks:10},{date:'2026-09-30',clicks:0},
    {date:'2026-10-01',clicks:20},{date:'2026-10-02',clicks:null},
    {date:'2026-11-01',clicks:null},
  ]};
  ctx.setProductClicksInterval('monthly');
  assert.equal(ctx.productClicksChartItems.length,3,'Group across month boundaries.');
  assert.equal(ctx.productClicksChartItems[0].clicks,10);
  assert.equal(ctx.productClicksChartItems[0].days,2,'Recorded zeros count as covered days.');
  assert.equal(ctx.productClicksChartItems[1].clicks,20,'Only sum dates inside the selected range.');
  assert.equal(ctx.productClicksChartItems[1].missingDays,1);
  assert.equal(ctx.productClicksChartItems[1].partial,true,'Flag incomplete months.');
  assert.equal(ctx.productClicksChartItems[2].clicks,null,'A month with no data must not become zero.');
  assert.equal(ctx.productClicksPeak().date,'2026-10-01');
  ctx.productClicksSelected=ctx.productClicksChartItems[1];
  assert(ctx.productClicksTooltipLeft()>16,'Tooltip position follows monthly buckets.');
  ctx.productClicks={items:Array.from({length:31},(_,i)=>({date:`2026-10-${String(i+1).padStart(2,'0')}`,clicks:1}))};
  ctx.setProductClicksInterval('monthly');
  assert.equal(ctx.productClicksChartItems[0].partial,false,'Recognize a complete calendar month.');
  assert.equal(ctx.productClicksChartItems[0].clicks,31);
  assert.equal(ctx.productClicksPaths()[0].split(' ').length,2,'One month still renders a visible line without circle markers.');
  ctx.setProductClicksInterval('daily');
  assert.equal(ctx.productClicksChartItems.length,31,'Switch back without changing the selected dates or refetching.');
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
