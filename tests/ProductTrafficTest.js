const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const scope={window:{}};vm.runInNewContext(fs.readFileSync('assets/product-traffic.js','utf8'),scope);
const component=scope.window.PaperbellProductTraffic;
function context(){const c=Object.assign(component.data(),component.methods);for(const [k,get] of Object.entries(component.computed))Object.defineProperty(c,k,{get:()=>get.call(c)});return c;}
async function test(){
 const c=context(),months=[{month:'2026-01',label:'Jan 26'},{month:'2026-02',label:'Feb 26'},{month:'2026-03',label:'Mar 26'}];
 const products=Array.from({length:4},(_,i)=>({code:String(i+1),sku:'SKU'+i,name:'Product '+i,totals:{clicks:100-i,ctr:i},series:{'2026-01':{clicks:i,ctr:0},'2026-03':{clicks:10,ctr:2}}}));
 const data={from:'2025-04',to:'2026-03',minMonth:'2025-04',maxMonth:'2026-03',months,products};
 let query;c.api=async(action,options)=>{assert.equal(action,'shopee_product_traffic');query=options.query;return data;};
 await c.load();assert.equal(c.selected.length,3);assert.equal(c.from,'2025-04');assert.equal(query.from,'');
 c.toggle('4');assert.equal(c.selected.length,3);assert(c.notice.includes('Maksimal'));
 c.toggle('1');c.toggle('4');assert(c.selected.includes('4'));
 c.search='SKU3';assert.equal(c.rows.length,1);assert.equal(c.rows[0].code,'4');
 c.search='no match';assert.equal(c.rows.length,0);assert.equal(c.chosen.length,3,'Search filters table without hiding chart selections.');
 c.focusProduct('1');assert.equal(c.chosen.length,1);
 assert.equal(c.value(products[0],'2026-01'),0);assert.equal(c.value(products[0],'2026-02'),null);
 assert.equal(c.paths(products[0]).length,2,'Missing months must break lines; isolated observations need visible dashes.');
 c.metric='ctr';assert.equal(c.value(products[0],'2026-03'),2);assert.equal(c.metricLabel,'CTR (%)');assert(Number.isFinite(c.y(0)));
 c.from='2026-01';c.to='2026-03';await c.apply();assert.equal(query.from,'2026-01');assert.equal(c.followLatest,false);
 await c.latest();assert.equal(query.from,'');assert.equal(c.followLatest,true);
 c.selected=[];await c.load();assert.equal(c.selected.length,0,'Refreshing must respect a deliberately cleared selection.');
 c.api=async()=>{throw Error('Offline');};await c.load();assert.equal(c.data,data);assert.equal(c.error,'Offline');
 let oldDone,newDone;c.api=()=>new Promise(resolve=>{if(!oldDone)oldDone=resolve;else newDone=resolve;});
 const old=c.load(),latest=c.load();newDone({...data,to:'2026-04'});await latest;oldDone(data);await old;
 assert.equal(c.data.to,'2026-04','Stale requests cannot overwrite a more recent response.');
 console.log('ProductTraffic tests passed');
}
test().catch(e=>{console.error(e);process.exitCode=1;});
