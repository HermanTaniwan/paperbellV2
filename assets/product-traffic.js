window.PaperbellProductTraffic={
 props:{api:{type:Function,required:true},refreshKey:{default:null}},
 data(){return{data:null,loading:false,error:'',from:'',to:'',followLatest:true,metric:'clicks',selected:[],search:'',sort:'clicks',hover:null,request:0,notice:'',colors:['#7357c7','#19836d','#d16c25'],metrics:[{key:'clicks',label:'Klik produk'},{key:'impressions',label:'Produk dilihat'},{key:'ctr',label:'CTR (%)'},{key:'visitors',label:'Pengunjung produk'},{key:'carts',label:'Tambah ke keranjang'}]}},
 mounted(){this.load()},
 watch:{refreshKey(){this.load(true)}},
 computed:{
  chosen(){return this.selected.map(code=>this.data?.products.find(p=>p.code===code)).filter(Boolean)},
  rows(){const q=this.search.trim().toLocaleLowerCase('id-ID');return(this.data?.products||[]).filter(p=>!q||`${p.name} ${p.sku} ${p.code}`.toLocaleLowerCase('id-ID').includes(q)).slice().sort((a,b)=>(b.totals[this.sort]??-1)-(a.totals[this.sort]??-1)||a.name.localeCompare(b.name))},
  months(){return this.data?.months||[]},
  metricLabel(){return this.metrics.find(m=>m.key===this.metric)?.label||'Klik produk'},
  scale(){const max=Math.max(1,...this.chosen.flatMap(p=>this.months.map(m=>this.value(p,m.month)??0))),rough=max/4,magnitude=10**Math.floor(Math.log10(rough)),n=rough/magnitude,step=Math.max(this.metric==='ctr'?.01:1,(n<=1?1:n<=2?2:n<=5?5:10)*magnitude);return{max:Math.ceil(max/step)*step,step}},
  ticks(){const ticks=[];for(let v=0;v<=this.scale.max;v+=this.scale.step)ticks.push({value:v,y:222-v/this.scale.max*182});return ticks},
  dateTicks(){const step=Math.max(1,Math.ceil(this.months.length/10)),ticks=[];for(let i=0;i<this.months.length;i+=step)ticks.push({index:i,label:this.months[i].label});if(this.months.length&&ticks.at(-1)?.index!==this.months.length-1)ticks.push({index:this.months.length-1,label:this.months.at(-1).label});return ticks},
 },
 methods:{
  async load(silent=false){const from=this.followLatest?'':this.from,to=this.followLatest?'':this.to;if((from||to)&&(!from||!to||from>to)){this.error='Pilih rentang bulan yang valid.';return}const request=++this.request;this.loading=true;try{const data=await this.api('shopee_product_traffic',{query:{from,to}});if(request!==this.request)return;const first=this.data===null;this.data=data;if(this.followLatest||(!from&&!to)){this.from=data.from;this.to=data.to}this.selected=this.selected.filter(code=>data.products.some(p=>p.code===code));if(first)this.selected=data.products.slice(0,3).map(p=>p.code);this.hover=null;this.error=''}catch(e){if(request===this.request)this.error=e.message}finally{if(request===this.request)this.loading=false}},
  apply(){this.followLatest=false;return this.load()},
  latest(){this.followLatest=true;return this.load()},
  toggle(code){this.notice='';if(this.selected.includes(code))this.selected=this.selected.filter(c=>c!==code);else if(this.selected.length<3)this.selected.push(code);else this.notice='Maksimal 3 produk. Lepaskan satu pilihan untuk membandingkan produk lain.';this.hover=null},
  focusProduct(code){this.selected=[code];this.hover=null;this.notice=''},
  shortName(p){return p.name.split(' - ')[0].split(' – ')[0]},
  number(value,percent=false){return value===null||value===undefined?'—':new Intl.NumberFormat('id-ID',{maximumFractionDigits:percent?2:0}).format(value)+(percent?'%':'')},
  value(product,month){return product.series[month]?.[this.metric]??null},
  x(index){return this.months.length===1?450:72+index/Math.max(1,this.months.length-1)*756},
  y(value){return 222-value/this.scale.max*182},
  paths(product){const paths=[];let segment=[];const flush=()=>{if(segment.length===1){const [x,y]=segment[0].split(',').map(Number);paths.push(`${x-4},${y} ${x+4},${y}`)}else if(segment.length)paths.push(segment.join(' '));segment=[]};for(const [i,m] of this.months.entries()){const value=this.value(product,m.month);if(value===null)flush();else segment.push(`${this.x(i).toFixed(2)},${this.y(value).toFixed(2)}`)}flush();return paths},
  zoneX(i){return i===0?72:(this.x(i-1)+this.x(i))/2},
  zoneWidth(i){return(i===this.months.length-1?828:(this.x(i)+this.x(i+1))/2)-this.zoneX(i)},
  hoverLabel(){const month=this.months[this.hover]?.month;return month?new Date(month+'-01T12:00:00').toLocaleDateString('id-ID',{month:'long',year:'numeric'}):''},
  tooltipLeft(){return Math.max(18,Math.min(82,this.x(this.hover??0)/860*100))},
 },
 template:`
 <article class="panel shopee-card product-traffic-card" :aria-busy="loading">
  <div class="shopee-card-head"><div><h4>Trafik per produk</h4><p>Bandingkan tren bulanan hingga 3 produk. Pilih produk melalui tabel di bawah.</p></div><span v-if="data?.maxMonth" class="shopee-source-badge">Data s.d. {{data.maxMonth}}</span></div>
  <form class="product-traffic-controls" @submit.prevent="apply">
   <label><span>Metrik grafik</span><select v-model="metric"><option v-for="item in metrics" :key="item.key" :value="item.key">{{item.label}}</option></select></label>
   <label><span>Dari bulan</span><input v-model="from" type="month" required :min="data?.minMonth" :max="data?.maxMonth"></label>
   <label><span>Sampai bulan</span><input v-model="to" type="month" required :min="data?.minMonth" :max="data?.maxMonth"></label>
   <button type="submit" :disabled="loading">Terapkan</button><button type="button" class="ghost" :disabled="loading" :aria-pressed="followLatest" @click="latest">12 bulan terakhir</button>
  </form>
  <p v-if="error" role="alert" class="product-clicks-error">{{error}} <button class="ghost" type="button" :disabled="loading" @click="load()">Coba lagi</button></p>
  <p v-if="notice" role="status" class="product-traffic-note">{{notice}}</p>
  <div v-if="chosen.length" class="product-traffic-legend"><button v-for="(product,index) in chosen" :key="product.code" type="button" :title="product.name+' — hapus dari perbandingan'" @click="toggle(product.code)"><i :style="{background:colors[index]}"></i><span>{{shortName(product)}}</span><b>{{number(product.totals[metric],metric==='ctr')}}</b><span aria-hidden="true">×</span></button><button type="button" class="ghost" @click="selected=[];hover=null">Kosongkan pilihan</button></div>
  <p v-if="data" class="product-clicks-period">{{data.from}} – {{data.to}} · {{chosen.length}} produk dibandingkan · {{metricLabel}}</p>
  <div v-if="chosen.length&&months.length" class="shopee-comparison-scroll product-clicks-scroll"><div class="shopee-comparison-canvas" @mouseleave="hover=null">
   <svg viewBox="0 0 860 280" role="img" :aria-label="'Tren bulanan '+metricLabel+' per produk'">
    <g class="shopee-comparison-grid"><g v-for="tick in ticks" :key="tick.value"><line x1="72" x2="828" :y1="tick.y" :y2="tick.y"></line><text x="62" :y="tick.y+3" text-anchor="end">{{number(tick.value,metric==='ctr')}}</text></g></g>
    <text class="shopee-comparison-axis-title visitors" x="72" y="22">{{metricLabel}}</text><line class="shopee-comparison-axis" x1="72" x2="828" y1="222" y2="222"></line>
    <g v-for="(product,index) in chosen" :key="product.code"><polyline v-for="(points,part) in paths(product)" :key="part" class="shopee-comparison-line" :style="{stroke:colors[index]}" :points="points"></polyline></g>
    <g class="shopee-comparison-x-axis"><text v-for="tick in dateTicks" :key="tick.index" :x="x(tick.index)" y="248" text-anchor="middle">{{tick.label}}</text></g>
    <g class="shopee-comparison-hover"><rect v-for="(month,index) in months" :key="month.month" :x="zoneX(index)" :width="zoneWidth(index)" y="35" height="187" tabindex="0" role="button" :aria-label="month.label+': '+chosen.map(p=>shortName(p)+' '+number(value(p,month.month),metric==='ctr')).join(', ')" @mouseenter="hover=index" @click="hover=index" @focus="hover=index" @blur="hover=null" @keydown.enter.prevent="hover=index" @keydown.space.prevent="hover=index" @keydown.escape="hover=null"></rect></g>
   </svg>
   <div v-if="hover!==null" class="shopee-comparison-tooltip product-traffic-tooltip" :style="{left:tooltipLeft()+'%'}"><b>{{hoverLabel()}}</b><span v-for="(product,index) in chosen" :key="product.code"><i :style="{background:colors[index]}"></i>{{shortName(product)}} <strong>{{number(value(product,months[hover].month),metric==='ctr')}}</strong></span></div>
  </div></div>
  <div v-else class="shopee-comparison-empty" role="status">{{loading?'Memuat trafik produk...':data?.products.length?'Pilih hingga 3 produk dari tabel untuk melihat grafik.':'Belum ada data produk pada rentang ini.'}}</div>
  <p class="product-traffic-note">Data per bulan; bulan tanpa catatan ditampilkan kosong. Klik, dilihat, dan keranjang dijumlahkan dalam rentang ini. CTR dihitung dari total klik ÷ total dilihat. Pengunjung diakumulasi dari laporan bulanan dan dapat menghitung orang yang sama lintas bulan.</p>
  <div class="product-traffic-table-controls"><label><span>Cari produk / SKU</span><input v-model="search" type="search" placeholder="Nama produk, SKU induk, atau kode Shopee"></label><label><span>Urutkan terbesar</span><select v-model="sort"><option value="clicks">Klik produk</option><option value="impressions">Produk dilihat</option><option value="ctr">CTR</option><option value="visitors">Pengunjung</option><option value="carts">Tambah keranjang</option><option value="conversion">Konversi checkout</option></select></label><small>{{rows.length}} produk · {{selected.length}}/3 dipilih</small></div>
  <div class="shopee-table-wrap product-traffic-table"><table class="shopee-product-table"><thead><tr><th>Bandingkan</th><th>Produk / SKU</th><th>Klik</th><th>Dilihat</th><th>CTR</th><th>Pengunjung*</th><th>Keranjang</th><th>Konversi checkout**</th></tr></thead><tbody><tr v-for="product in rows" :key="product.code" :class="{selected:selected.includes(product.code)}"><td><input type="checkbox" :checked="selected.includes(product.code)" :disabled="selected.length>=3&&!selected.includes(product.code)" :aria-label="'Bandingkan '+product.name" @change="toggle(product.code)"></td><td><button class="product-traffic-name" type="button" :title="'Lihat tren '+product.name" @click="focusProduct(product.code)">{{product.name}}</button><small>{{product.sku||'Tanpa SKU induk'}} · {{product.code}} · {{product.months}} bulan berdata</small></td><td>{{number(product.totals.clicks)}}</td><td>{{number(product.totals.impressions)}}</td><td>{{number(product.totals.ctr,true)}}</td><td>{{number(product.totals.visitors)}}</td><td>{{number(product.totals.carts)}}</td><td>{{number(product.totals.conversion,true)}}</td></tr><tr v-if="!rows.length"><td colspan="8">{{loading?'Memuat data...':'Tidak ada produk yang cocok.'}}</td></tr></tbody></table></div>
  <small class="shopee-comparison-note">* Pengunjung merupakan akumulasi nilai bulanan. ** Konversi checkout = pesanan dibuat ÷ klik produk, bukan pesanan dibayar. Data hanya baris produk induk dari laporan parentskudetail; variasi tidak dijumlahkan ulang.</small>
 </article>`,
};
