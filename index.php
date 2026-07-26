<?php
$config = require __DIR__ . '/config.php';
$mappingSheetUrl = 'https://docs.google.com/spreadsheets/d/' . rawurlencode((string) $config['mapping']['spreadsheet_id']) . '/edit#gid=' . rawurlencode((string) $config['mapping']['gid']);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>
<?= htmlspecialchars($config['app']['name']) ?>
</title>
  <link rel="stylesheet" href="assets/app.css?v=20">
  <link rel="stylesheet" href="assets/print.css?v=6">
  <link rel="stylesheet" href="assets/order-enhancements.css?v=18">
  <link rel="stylesheet" href="assets/features.css?v=19">
  <link rel="stylesheet" href="assets/tablet.css?v=7">
  <link rel="stylesheet" href="assets/status.css?v=4">
  <link rel="stylesheet" href="assets/pdf-drawer.css?v=3">
</head>
<body>
<script>
window.PAPERBELL_CONFIG = <?= json_encode(['authEnabled' => (bool)($config['auth']['enabled'] ?? true)], JSON_UNESCAPED_SLASHES) ?>;
</script>
<div id="app" v-cloak>
  <section v-if="authEnabled && !authenticated" class="login-shell">
    <form class="login-card" @submit.prevent="login">
      <div class="brand-mark">P</div>
      <h1>Paperbell</h1>
<p>Masuk untuk mengelola order bersama.</p>
      <label>Username<input v-model="loginForm.username" autocomplete="username" required>
</label>
      <label>Password<input v-model="loginForm.password" type="password" autocomplete="current-password" required>
</label>
      <div v-if="error" class="alert error">{{ error }}</div>
      <button :disabled="busy">{{ busy ? 'Memeriksa…' : 'Masuk' }}</button>
    </form>
  </section>

  <div v-else-if="authenticated" class="app-shell">
    <aside :class="{open:menuOpen}">
      <button class="sidebar-close" type="button" aria-label="Tutup menu" @click="closeMenu">&times;</button>
      <div class="brand">
<div class="brand-mark small">P</div>
<div>
<strong>Paperbell</strong>
<span>Shared workspace</span>
</div>
</div>
      <nav>
        <button v-for="item in nav" :class="{active:view===item.id}" @click="go(item.id)">
<span>{{ item.icon }}</span>{{ item.label }}</button>
      </nav>
      <div class="aside-foot">
<span class="dot online">
</span> MySQL + API native <small>{{ bridgeStatus }}</small>
</div>
    </aside>
    <main>
      <header>
        <button class="menu-button" @click="toggleMenu">☰</button>
        <div>
<h1>{{ title }}</h1>
<p>{{ subtitle }}</p>
</div>
        <div class="header-actions">
<span class="sync-state">
<span class="dot" :class="error?'danger':'online'">
</span>{{ syncText }}</span>
<button class="ghost" @click="refresh(true)" :disabled="loading||busy">{{loading?'Memuat…':'↻ Refresh'}}</button>
<button v-if="authEnabled" class="avatar" @click="logout">A</button>
</div>
      </header>
      <div v-if="toast" class="toast" :class="toast.type">{{ toast.message }}</div>
      <div v-if="loading||busy" class="page-status is-loading" role="status" aria-live="polite">
        <span class="status-spinner" aria-hidden="true"></span>
        <div><b>{{ activityText }}</b><span>Mohon tunggu, proses masih berjalan.</span></div>
      </div>
      <div v-if="error" class="page-status is-error" role="alert">
        <span class="status-icon" aria-hidden="true">!</span>
        <div><b>{{ errorTitle }}</b><span>{{ error }}</span></div>
        <button class="status-retry" type="button" :disabled="loading||busy" @click="refresh(true)">Coba lagi</button>
        <button class="status-dismiss" type="button" aria-label="Tutup pesan error" @click="error=''">×</button>
      </div>

      <section v-if="view==='dashboard'" class="content">
        <div class="hero">
<div>
<span class="eyebrow">OPERASIONAL HARI INI</span>
<h2>Semua order dalam satu tempat.</h2>
<p>Order diambil langsung dari Shopee dan TikTok Shop ke MySQL.</p>
</div>
<button @click="go('orders')">Buka order</button>
</div>
        <div class="stats" v-if="dashboard">
          <article>
<span>Order aktif</span>
<strong>{{ number(dashboard.orders.total-dashboard.orders.cancelled) }}</strong>
<small>{{ number(dashboard.orders.unprinted) }} belum dicetak</small>
</article>
          <article>
<span>Sudah dicetak</span>
<strong>{{ number(dashboard.orders.printed) }}</strong>
<small>Seluruh item selesai dicetak</small>
</article>
          <article>
<span>Label belum cetak</span>
<strong>{{ number(dashboard.labels.unprinted) }}</strong>
<small>dari {{ number(dashboard.labels.total) }} label</small>
</article>
          <article>
<span>Total stok</span>
<strong>{{ number(dashboard.inventory.qty) }}</strong>
<small>{{ number(dashboard.inventory.items) }} jenis produk</small>
</article>
        </div>
        <article class="panel analytics-panel">
          <div class="analytics-head">
            <div><span class="eyebrow">ANALITIK ORDER</span><h3>Order harian per marketplace</h3><p>Order masuk yang tidak dibatalkan, dibedakan antara Shopee dan TikTok Shop.</p></div>
            <form class="analytics-range" @submit.prevent="loadAnalytics">
              <div class="analytics-presets"><button v-for="days in [7,14,30,90]" :key="days" type="button" class="ghost" :class="{active:analyticsRangeDays===days}" :disabled="analyticsLoading" @click="setAnalyticsRange(days)">{{days}} hari</button></div>
              <label class="analytics-month-shortcut">Bulan<input v-model="analyticsMonth" type="month" :max="analyticsMonthMax()" :disabled="analyticsLoading" @change="setAnalyticsMonth"></label>
              <label>Dari<input v-model="analyticsFrom" type="date" :max="analyticsTo" required @change="analyticsMonth=''"></label>
              <label>Sampai<input v-model="analyticsTo" type="date" :min="analyticsFrom" required @change="analyticsMonth=''"></label>
              <button type="submit" :disabled="analyticsLoading">{{analyticsLoading?'Memuat…':'Terapkan'}}</button>
              <button type="button" class="ghost" @click="syncShopeeFinance" :disabled="analyticsLoading||financeSyncing">{{financeSyncing?'Sync...':'Sync Escrow'}}</button>
            </form>
          </div>
          <div v-if="analytics" class="analytics-summary">
            <div><i class="legend-dot shopee"></i><span>Shopee</span><strong>{{number(analytics.summary.shopee)}}</strong></div>
            <div><i class="legend-dot tiktok"></i><span>TikTok Shop</span><strong>{{number(analytics.summary.tiktok)}}</strong></div>
            <div class="analytics-metric"><span>Total order</span><strong>{{number(analytics.summary.total)}}</strong></div>
            <div class="analytics-metric"><span>Total item</span><strong>{{number(analytics.summary.items)}}</strong></div>
            <div class="analytics-metric"><span>Item / order</span><strong>{{number(analytics.summary.itemsPerOrder)}}</strong></div>
            <div class="analytics-metric revenue-summary"><span>Omzet pesanan</span><strong>{{currency(analytics.summary.revenue)}}</strong><small>{{number(analytics.summary.pricedOrders)}} order memiliki nominal</small></div>
            <div class="analytics-metric payout-summary"><span>Payout bersih Shopee</span><strong>{{currency(analytics.summary.shopeePayout)}}</strong><small>{{number(analytics.summary.escrowOrders)}} order escrow</small></div>
          </div>
          <div v-if="analyticsLoading&&!analytics" class="analytics-empty">Memuat analitik order…</div>
          <div v-else-if="analytics&&!analytics.summary.total" class="analytics-empty">Belum ada order marketplace pada rentang tanggal ini.</div>
          <div v-else-if="analytics" class="analytics-chart-scroll"><div class="analytics-chart">
            <div class="analytics-grid-lines" aria-hidden="true"><div v-for="tick in analyticsBarTicks()" :key="tick" class="analytics-grid-line" :style="{bottom:analyticsBarTickPosition(tick)+'%'}"><span>{{number(tick)}}</span><i></i></div></div>
            <span class="analytics-y-title">Order</span>
            <div v-for="day in analytics.items" :key="day.date" class="analytics-day" @mouseenter="showOrderBarTooltip($event,day)" @mousemove="showOrderBarTooltip($event,day)" @mouseleave="analyticsTooltip=null">
              <div class="analytics-bars"><div class="analytics-bar shopee" :style="{height:analyticsBarHeight(day.shopee)+'%'}"><b v-if="day.shopee&&analytics.items.length<=31">{{day.shopee}}</b></div><div class="analytics-bar tiktok" :style="{height:analyticsBarHeight(day.tiktok)+'%'}"><b v-if="day.tiktok&&analytics.items.length<=31">{{day.tiktok}}</b></div></div>
              <span v-if="showAnalyticsLabel(day,analytics.items)">{{day.label}}</span>
            </div>
            <div v-if="analyticsTooltip?.type==='orders'" class="metric-tooltip analytics-bar-tooltip" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><b>{{analyticsTooltip.date}}</b><strong>{{analyticsTooltip.value}}</strong><span>{{analyticsTooltip.detail}}</span></div>
          </div></div>
          <section v-if="analytics?.summary?.total" class="metric-line-grid">
            <article class="metric-line-card revenue-card">
              <div class="metric-line-head revenue-combined-head"><div><span>Omzet pesanan</span><strong>{{currency(analytics.summary.revenue)}}</strong></div><div><span>Payout bersih Shopee</span><strong>{{currency(analytics.summary.shopeePayout)}}</strong></div><div class="revenue-line-legend"><span><i class="gross"></i>Omzet semua marketplace</span><span><i class="payout"></i>Payout Shopee</span></div></div>
              <svg viewBox="0 0 600 190" role="img" aria-label="Line chart omzet pesanan per hari">
                <g class="line-grid"><g v-for="tick in analyticsLineTicks('revenue')" :key="tick"><line x1="55" :y1="analyticsLineTickY(tick,'revenue')" x2="580" :y2="analyticsLineTickY(tick,'revenue')"></line><text x="47" :y="analyticsLineTickY(tick,'revenue')+3" text-anchor="end">{{compactCurrency(tick)}}</text></g></g>
                <line class="axis-line" x1="55" y1="20" x2="55" y2="135"></line><line class="axis-line" x1="55" y1="135" x2="580" y2="135"></line>
                <g class="x-ticks"><g v-for="tick in analyticsLineDateTicks()" :key="tick.index"><line :x1="analyticsLineX(tick.index)" y1="135" :x2="analyticsLineX(tick.index)" y2="140"></line><text :x="analyticsLineX(tick.index)" y="153" text-anchor="middle">{{tick.label}}</text></g></g>
                <polyline class="metric-line revenue" :points="analyticsLinePoints('revenue')"></polyline>
                <polyline class="metric-line shopee-payout" :points="analyticsLinePoints('shopeePayout')"></polyline>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="day.date" class="metric-point revenue" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'revenue')" r="3"><title>{{day.label}}: {{currency(day.revenue)}}</title></circle></g>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="'payout-'+day.date" class="metric-point shopee-payout" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'shopeePayout')" r="3"><title>{{day.label}}: {{currency(day.shopeePayout)}}</title></circle></g>
                <rect v-for="(day,index) in analytics.items" :key="'revenue-hover-'+day.date" class="metric-hover-zone" :x="analyticsLineZoneLeft(index)" y="20" :width="analyticsLineZoneWidth(index)" height="115" @mouseenter="showCombinedRevenueTooltip($event,day)" @mousemove="showCombinedRevenueTooltip($event,day)" @click="showCombinedRevenueTooltip($event,day)" @mouseleave="analyticsTooltip=null"></rect>
                <text class="axis-title" x="318" y="181" text-anchor="middle">Tanggal</text><text class="axis-title" x="13" y="78" text-anchor="middle" transform="rotate(-90 13 78)">Omzet</text>
              </svg>
              <div v-if="analyticsTooltip?.type==='revenue'" class="metric-tooltip" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><b>{{analyticsTooltip.date}}</b><strong>{{analyticsTooltip.value}}</strong><span>{{analyticsTooltip.detail}}</span></div>
            </article>
            <article class="metric-line-card">
              <div class="metric-line-head"><div><span>Item terjual</span><strong>{{number(analytics.summary.items)}}</strong></div><small>Total quantity item</small></div>
              <svg viewBox="0 0 600 190" role="img" aria-label="Line chart jumlah item terjual per hari">
                <g class="line-grid"><g v-for="tick in analyticsLineTicks('soldItems')" :key="tick"><line x1="55" :y1="analyticsLineTickY(tick,'soldItems')" x2="580" :y2="analyticsLineTickY(tick,'soldItems')"></line><text x="47" :y="analyticsLineTickY(tick,'soldItems')+3" text-anchor="end">{{number(tick)}}</text></g></g>
                <line class="axis-line" x1="55" y1="20" x2="55" y2="135"></line><line class="axis-line" x1="55" y1="135" x2="580" y2="135"></line>
                <g class="x-ticks"><g v-for="tick in analyticsLineDateTicks()" :key="tick.index"><line :x1="analyticsLineX(tick.index)" y1="135" :x2="analyticsLineX(tick.index)" y2="140"></line><text :x="analyticsLineX(tick.index)" y="153" text-anchor="middle">{{tick.label}}</text></g></g>
                <polyline class="metric-line sold-items" :points="analyticsLinePoints('soldItems')"></polyline>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="day.date" class="metric-point sold-items" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'soldItems')" r="4"><title>{{day.label}}: {{day.items}} item</title></circle></g>
                <rect v-for="(day,index) in analytics.items" :key="'hover-'+day.date" class="metric-hover-zone" :x="analyticsLineZoneLeft(index)" y="20" :width="analyticsLineZoneWidth(index)" height="115" @mouseenter="showAnalyticsTooltip($event,day,'soldItems')" @mousemove="showAnalyticsTooltip($event,day,'soldItems')" @mouseleave="analyticsTooltip=null"></rect>
                <text class="axis-title" x="318" y="181" text-anchor="middle">Tanggal</text><text class="axis-title" x="13" y="78" text-anchor="middle" transform="rotate(-90 13 78)">Item</text>
              </svg>
              <div v-if="analyticsTooltip?.type==='soldItems'" class="metric-tooltip" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><b>{{analyticsTooltip.date}}</b><strong>{{analyticsTooltip.value}}</strong><span>{{analyticsTooltip.detail}}</span></div>
            </article>
            <article class="metric-line-card">
              <div class="metric-line-head"><div><span>Item per order</span><strong>{{number(analytics.summary.itemsPerOrder)}}</strong></div><small>Rata-rata quantity per order</small></div>
              <svg viewBox="0 0 600 190" role="img" aria-label="Line chart jumlah item per order per hari">
                <g class="line-grid"><g v-for="tick in analyticsLineTicks('ratio')" :key="tick"><line x1="55" :y1="analyticsLineTickY(tick,'ratio')" x2="580" :y2="analyticsLineTickY(tick,'ratio')"></line><text x="47" :y="analyticsLineTickY(tick,'ratio')+3" text-anchor="end">{{number(tick)}}</text></g></g>
                <line class="axis-line" x1="55" y1="20" x2="55" y2="135"></line><line class="axis-line" x1="55" y1="135" x2="580" y2="135"></line>
                <g class="x-ticks"><g v-for="tick in analyticsLineDateTicks()" :key="tick.index"><line :x1="analyticsLineX(tick.index)" y1="135" :x2="analyticsLineX(tick.index)" y2="140"></line><text :x="analyticsLineX(tick.index)" y="153" text-anchor="middle">{{tick.label}}</text></g></g>
                <polyline class="metric-line ratio" :points="analyticsLinePoints('ratio')"></polyline>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="day.date" class="metric-point ratio" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'ratio')" r="4"><title>{{day.label}}: {{number(analyticsLineValue(day,'ratio'))}} item/order</title></circle></g>
                <rect v-for="(day,index) in analytics.items" :key="'hover-'+day.date" class="metric-hover-zone" :x="analyticsLineZoneLeft(index)" y="20" :width="analyticsLineZoneWidth(index)" height="115" @mouseenter="showAnalyticsTooltip($event,day,'ratio')" @mousemove="showAnalyticsTooltip($event,day,'ratio')" @mouseleave="analyticsTooltip=null"></rect>
                <text class="axis-title" x="318" y="181" text-anchor="middle">Tanggal</text><text class="axis-title" x="13" y="78" text-anchor="middle" transform="rotate(-90 13 78)">Item / order</text>
              </svg>
              <div v-if="analyticsTooltip?.type==='ratio'" class="metric-tooltip" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><b>{{analyticsTooltip.date}}</b><strong>{{analyticsTooltip.value}}</strong><span>{{analyticsTooltip.detail}}</span></div>
            </article>
          </section>
          <section v-if="analytics?.products?.length" class="product-analytics">
            <div class="product-analytics-head">
              <div><h4>Produk teratas</h4><p>Jumlah item dan jumlah order per produk pada rentang terpilih.</p></div>
              <div class="product-legend"><span><i class="items"></i>Item</span><span><i class="orders"></i>Order</span></div>
            </div>
            <div class="product-chart">
              <article v-for="product in analytics.products" :key="product.name" class="product-chart-row" :title="product.name+' — '+product.items+' item, '+product.orders+' order'">
                <strong>{{product.name}}</strong>
                <div class="product-bars">
                  <div><span class="product-bar items" :style="{width:analyticsProductWidth(product.items)+'%'}"></span><b>{{number(product.items)}} item</b></div>
                  <div><span class="product-bar orders" :style="{width:analyticsProductWidth(product.orders)+'%'}"></span><b>{{number(product.orders)}} order</b></div>
                </div>
              </article>
            </div>
          </section>
        </article>
        <div class="panel-grid">
<article class="panel">
<div class="panel-head">
<div>
<h3>Akses cepat</h3>
<p>Pekerjaan yang paling sering dilakukan.</p>
</div>
</div>
<div class="quick-actions">
<button @click="go('orders')">▤<span>
<b>Kelola order</b>
<small>Cek cetak & kemasan</small>
</span>
</button>
<button @click="go('labels')">▧<span>
<b>Label pengiriman</b>
<small>Ambil dan cetak resi</small>
</span>
</button>
<button @click="go('inventory')">◇<span>
<b>Inventory</b>
<small>Atur stok produk</small>
</span>
</button>
<button @click="openQueuePanel">⌁<span>
<b>Printer Job</b>
<small>Pantau dan kontrol job</small>
</span>
</button>
</div>
</article>
<article class="panel status-panel">
<div class="panel-head">
<div>
<h3>Status sistem</h3>
<p>Koneksi data bersama.</p>
</div>
</div>
<dl>
<div>
<dt>
<span class="dot online">
</span>MySQL shared database</dt>
<dd>Online</dd>
</div>
<div>
<dt>
<span class="dot online">
</span>Sinkron data</dt>
<dd>{{ dashboard?.lastSyncText }}</dd>
</div>
<div>
<dt>
<span class="dot" :class="dashboard?.queued?'warning':'online'">
</span>Antrean printer</dt>
<dd>{{ dashboard?.queued || 0 }}</dd>
</div>
</dl>
</article>
</div>
      </section>

      <section v-if="view==='orders'" class="content">
        <div class="toolbar search-toolbar">
<div class="search">⌕<input v-model="query" @input="debouncedLoad" placeholder="Cari Order SN, No. Resi, pembeli, atau produk…">
</div>
<div class="filters">
<button v-for="f in orderFilters" :class="{active:filter===f.id}" :disabled="loading" @click="changeOrderFilter(f.id)">{{ loading&&filter===f.id ? 'Memuat…' : f.label }}</button>
</div>
<div class="filters paper-order-filters" aria-label="Filter jenis kertas">
<button v-for="f in paperFilters" :class="{active:paperFilter===f.id}" :disabled="loading" @click="changePaperFilter(f.id)">{{f.label}}</button>
</div>
<button class="ghost marketplace-sync-button manual-order-add-button" @click="openManualOrder">+ Tambah order cetak</button>
<button class="ghost marketplace-sync-button" :disabled="loading" @click="openRandomPrint">? Cetak Random Pages</button>
<button class="ghost marketplace-sync-button" @click="queue('shopee_sync','')" :disabled="busy">↻ Sync Shopee</button>
<button class="ghost marketplace-sync-button" @click="queue('tiktok_sync','')" :disabled="busy">↻ Sync TikTok</button>
</div>
        <div v-if="syncSummary" class="sync-result-box" :class="{warning:syncSummary.cancel_requests?.length}">
<div>
<b>Hasil Sync {{syncSummary.marketplace}}</b>
<span>{{syncSummary.new_orders}} order baru dari {{syncSummary.orders}} order diperiksa</span>
</div>
<p v-if="syncSummary.cancel_requests?.length">
<strong>Permintaan cancel:</strong> {{syncSummary.cancel_requests.slice(0,8).join(', ')}}<span v-if="syncSummary.cancel_requests.length>8"> +{{syncSummary.cancel_requests.length-8}} lainnya</span>
</p>
<p v-else>Tidak ada permintaan cancel.</p>
</div>
        <transition-group name="delete-list" tag="div" class="order-groups">
<div v-if="loading" key="orders-loading" class="panel empty">Memuat data…</div>
<div v-else-if="!pageData.items?.length" key="orders-empty" class="panel empty">Tidak ada order ditemukan.</div>
<article v-for="row in pageData.items" :key="row.order_sn" class="panel order-group">
<header class="order-group-head">
<div>
<span class="eyebrow">ORDER</span>
<h3>{{row.order_sn}}</h3>
<p>
<button class="customer-history-link" @click="openCustomerHistory(row.buyer_username)">{{row.buyer_username||'Tanpa nama pembeli'}}</button> · {{row.createdText}}</p>
</div>
<div class="order-group-status">
<span class="badge gray">{{row.status}}</span>
<span class="badge" :class="row.unprinted_lines>0?'amber':'green'">{{row.unprinted_lines>0?row.unprinted_lines+' belum tercetak':'Cetak selesai'}}</span>
</div>
<p v-if="row.customer_note&&!row.order_sn.startsWith('RANDOM-')" class="customer-note-text">Catatan: {{row.customer_note}}</p>
</header>
<section v-if="isMarketplaceOrder(row)" class="order-resi-panel" :class="{'is-ready':row.has_label_pdf,'is-printed':row.resi_printed}">
<div class="order-resi-info">
<span class="order-resi-icon" aria-hidden="true">▧</span>
<div>
<span class="eyebrow">RESI PENGIRIMAN</span>
<b class="order-resi-number">{{row.tracking_number||'Nomor resi belum tersedia'}}</b>
<div class="order-resi-badges">
<span class="badge" :class="row.has_label_pdf?'blue':'gray'">{{row.has_label_pdf?'PDF siap':'PDF belum diambil'}}</span>
<span class="badge" :class="row.resi_printed?'green':'amber'">{{row.resi_printed?'Sudah tercetak':'Belum tercetak'}}</span>
</div>
</div>
</div>
<div class="order-resi-controls">
<label v-if="row.has_label_pdf">Printer resi<select v-model="row.label_printer" aria-label="Printer resi"><option value="">Pilih printer…</option><option v-for="printer in pageData.printers" :value="printer">{{printer}}</option></select></label>
<div class="order-resi-actions">
<button class="ghost" :disabled="row.label_fetching" @click="fetchOrderLabel(row)">{{row.label_fetching?'Mengambil resi…':(row.has_label_pdf?'Ambil ulang':'Ambil resi')}}</button>
<button v-if="row.has_label_pdf" class="ghost" @click="openOrderLabel(row)">Buka PDF</button>
<button v-if="row.has_label_pdf" :disabled="!row.label_printer||row.label_printing" @click="printOrderLabel(row)">{{row.label_printing?'Mengantre…':'Cetak resi'}}</button>
</div>
</div>
</section>
<div class="grouped-items">
<article v-for="line in row.items" :key="line.id" class="inline-print-item" :class="{'six-hole-item':isSixHole(line),'is-status-changing':line.marking_printed}">
<div class="inline-item-main">
<b>{{line.item_name||line.model_name||line.sku_id}}</b>
<small>{{line.model_name||'-'}}</small>
<span v-if="line.print_options?.paper==='A5'||line.print_options?.paper==='B5'" class="paper-size-indicator" :class="line.print_options.paper==='B5'?'paper-b5':'paper-a5'" :title="'Ukuran kertas '+line.print_options.paper">
<i aria-hidden="true"></i>{{line.print_options.paper==='B5'?'B5 JIS':'A5'}}
</span>
<span v-if="isSixHole(line)" class="six-hole-alert">6 LUBANG</span>
<small>SKU ID: {{line.sku_id||'-'}}</small>
<button v-if="line.has_pdf" class="link detail-pdf-link" @click="openProductPdf(line.id,line.file_name)">Buka PDF: {{line.file_name}} ↗</button>
<small v-else class="text-error">{{line.print_reason}}</small>
</div>
<div class="inline-item-summary">
<b>{{line.qty}} pcs</b>
<span class="badge" :class="line.queued?'blue':(line.printed?'green':(line.print_ready?'amber':'red'))">{{line.queued?'Dalam antrean':(line.printed?'Tercetak':(line.print_ready?'Siap cetak':'Tidak siap'))}}</span>
<small v-if="line.printed_odd||line.printed_even">Ganjil {{line.printed_odd?'✓':'—'}} · Genap {{line.printed_even?'✓':'—'}}</small>
</div>
<label class="inline-printer">Printer<select v-model="line.selected_printer" :disabled="!line.print_ready||line.queued">
<option value="">Pilih printer…</option>
<option v-for="printer in pageData.printers" :value="printer">{{printer}}</option>
</select>
<small v-if="!line.printer_available&&line.default_printer" class="text-warning">Default mapping tidak tersedia</small>
</label>
<div class="inline-item-actions">
<button class="inline-print-button" :class="{ghost:line.printed}" :disabled="!line.print_ready||!line.selected_printer||line.queueing||line.queued" @click="printItem(line)">{{line.queueing?'Mengantre…':(line.printed?'Cetak ulang':'Cetak item')}}</button>
<button class="ghost mark-printed-button" :class="{revert:line.printed}" :disabled="line.marking_printed" @click="setOrderItemPrinted(line,!line.printed)">{{line.marking_printed?'Menyimpan…':(line.printed?'Belum tercetak':'Sudah dicetak')}}</button>
<button v-if="!line.printed&&line.has_inventory" class="ghost inventory-use-button" @click="useInventory(line)">Gunakan inventory ({{line.inventory_qty}})</button>
</div>
<details class="advanced-print inline-advanced">
<summary>Pengaturan cetak item</summary>
<div class="print-options-grid">
<label>Halaman dari<input type="number" min="1" v-model.number="line.print_options.page_from" :disabled="line.queued">
</label>
<label>Sampai<input type="number" min="0" v-model.number="line.print_options.page_to" :disabled="line.queued">
<small>0 = akhir PDF</small>
</label>
<label>Pilihan halaman<select v-model="line.print_options.parity" :disabled="line.queued" @change="normalizePrintSide(line)">
<option value="all">Semua halaman</option>
<option value="odd">Ganjil saja</option>
<option value="even">Genap saja</option>
</select>
</label>
<label>Sisi cetak<select v-model="line.print_options.duplex" :disabled="line.queued||line.print_options.parity!=='all'">
<option value="simplex">Simplex / satu sisi</option>
<option value="duplexlong">Duplex sisi panjang</option>
<option value="duplexshort">Duplex sisi pendek</option>
</select>
</label>
<label>Ukuran kertas<select v-model="line.print_options.paper" :disabled="line.queued">
<option value="DEFAULT">Default / ikuti driver</option>
<option value="A4">A4</option>
<option value="A5">A5</option>
<option value="A6">A6</option>
<option value="B5">B5 JIS</option>
</select>
</label>
<label>Copies total<input type="number" min="1" max="99" v-model.number="line.print_options.copies" :disabled="line.queued">
</label>
</div>
</details>
</article>
</div>
</article>
<pagination key="orders-pagination" :data="pageData" @change="p=>{page=p;loadOrders()}"/>
</transition-group>
        <div v-if="manualOrder.open" class="modal-backdrop" @click.self="manualOrder.open=false">
<section class="modal-card manual-order-modal">
<div class="modal-head"><div><span class="eyebrow">ORDER MANUAL</span><h2>Tambah order untuk dicetak</h2><p>Pilih satu atau beberapa PDF produk dari Data Mapping.</p></div><button class="icon-button" @click="manualOrder.open=false">×</button></div>
<div class="manual-order-body">
<label class="manual-order-search-label">Cari Data Mapping
<div class="manual-order-search"><input v-model="manualOrder.query" @input="searchManualOrderMappings" placeholder="Cari SKU, nama produk, variasi, atau file PDF…"><span v-if="manualOrder.loading">Mencari…</span></div>
</label>
<div v-if="manualOrder.suggestions.length" class="manual-order-suggestions">
<button v-for="item in manualOrder.suggestions" :key="item.id" @click="addManualOrderItem(item)"><span><b>{{item.sku_id||item.parent_sku||'-'}} · {{item.product_name}}</b><small>{{item.variation_name||'-'}} · {{item.file_name}}</small></span><strong>Tambah</strong></button>
</div>
<div v-else-if="!manualOrder.loading&&!manualOrder.items.length" class="manual-order-empty">Ketik kata kunci atau pilih dari mapping terbaru.</div>
<div v-if="manualOrder.items.length" class="manual-order-selected">
<h3>Item order ({{manualOrder.items.length}})</h3>
<article v-for="(item,index) in manualOrder.items" :key="item.mapping_id"><div><b>{{item.product_name}}</b><small>{{item.sku_id}} · {{item.variation_name||item.file_name}}</small></div><label>Qty<input type="number" min="1" max="999" v-model.number="item.qty"></label><button class="danger-button" @click="removeManualOrderItem(index)">Hapus</button></article>
</div>
<label class="manual-order-note">Catatan opsional<textarea v-model="manualOrder.note" maxlength="500" placeholder="Contoh: cetak untuk stok toko"></textarea></label>
</div>
<div class="manual-order-footer"><button class="ghost" @click="manualOrder.open=false">Batal</button><button :disabled="!manualOrder.items.length||manualOrder.saving" @click="createManualOrder">{{manualOrder.saving?'Menyimpan…':'Buat order cetak'}}</button></div>
</section>
</div>
        <div v-if="randomPrint.open" class="modal-backdrop" @click.self="randomPrint.open=false">
<section class="modal-card random-print-modal">
<div class="modal-head"><div><span class="eyebrow">RANDOM PAGES</span><h2>Cetak Random Pages</h2><p>Pilih sumber halaman dan pengaturan cetaknya.</p></div><button class="icon-button" @click="randomPrint.open=false">×</button></div>
<div class="random-print-body">
<div class="random-print-grid">
<label>Mode<select v-model="randomPrint.mode"><option value="planner">Planner — 1 halaman per PDF</option><option value="loose">Loose Leaf — pasangan ganjil/genap</option></select></label>
<label>Ukuran kertas<select v-model="randomPrint.paper"><option>A5</option><option value="B5">B5 JIS</option></select></label>
<label>Jumlah PDF sumber<input type="number" min="1" max="100" v-model.number="randomPrint.count"></label>
</div>
<label class="random-print-exclude">Exclude produk<textarea v-model="randomPrint.exclude" placeholder="Pisahkan dengan koma atau baris baru"></textarea><small>SKU, produk, atau variasi yang cocok tidak akan dipilih.</small></label>
<p class="random-print-hint">Hasil akan ditambahkan sebagai order baru dengan nomor RANDOM-…. Printer dan pengaturan cetak dapat dipilih dari item order setelah PDF selesai dibuat.</p>
</div>
<div class="manual-order-footer"><button class="ghost" @click="randomPrint.open=false">Batal</button><button :disabled="randomPrint.printing" @click="generateRandomFromPopup">{{randomPrint.printing?'Membuat PDF…':'Buat Random Pages'}}</button></div>
</section>
</div>
      </section>

      <section v-if="view==='inventory'" class="content">
        <div class="feature-grid inventory-tools">
          <article class="panel">
            <div class="panel-head">
<div>
<h3>Tambah produk</h3>
<p>Cari produk dari Data Mapping lalu tambahkan stok.</p>
</div>
</div>
            <div class="form-row">
<input v-model="inventoryAdd.query" @input="loadInventorySuggestions" placeholder="Cari SKU, produk, atau varian">
<input type="number" min="1" v-model.number="inventoryAdd.qty">
<button :disabled="!inventoryAdd.selected" @click="addInventoryMapping">Tambah</button>
</div>
            <div v-if="inventorySuggestions.length" class="suggestions">
<button v-for="item in inventorySuggestions" @click="selectInventoryMapping(item)">
<b>{{item.search_alias||item.product_name}}</b>
<small>{{item.sku_id}} · {{item.variation_name}}</small>
</button>
</div>
            <p v-if="inventoryAdd.selected" class="selected-hint">Dipilih: {{inventoryAdd.selected.search_alias||inventoryAdd.selected.product_name}} ({{inventoryAdd.selected.sku_id}})</p>
          </article>
          <article class="panel">
            <div class="panel-head">
<div>
<h3>Tambah dari order</h3>
<p>Untuk retur atau stok dari seluruh item order.</p>
</div>
            </div>
            <div class="form-row">
<input v-model="inventoryOrderSn" @input="inventoryOrderPreview=null" @keyup.enter="lookupInventoryOrder(inventoryOrderSn)" placeholder="Nomor resi atau nomor order">
<button type="button" class="ghost scan-barcode-button" @click="openInventoryScanner">▣ Scan barcode</button>
<button type="button" @click="lookupInventoryOrder(inventoryOrderSn)">Cari order</button>
</div>
            <div v-if="inventoryOrderPreview" class="inventory-order-preview">
              <div class="inventory-order-preview-head">
                <div><span>ORDER DITEMUKAN</span><b>{{inventoryOrderPreview.order_sn}}</b><small v-if="inventoryOrderPreview.tracking_number">Resi {{inventoryOrderPreview.tracking_number}}</small></div>
                <strong>{{inventoryOrderPreview.item_qty}} item</strong>
              </div>
              <div class="inventory-order-preview-lines">
                <div v-for="line in inventoryOrderPreview.lines" :key="line.id" class="inventory-order-preview-line">
                  <div><b>{{line.item_name||line.item_key}}</b><small>{{line.model_name||'Tanpa varian'}} · SKU {{line.model_sku||line.item_sku||line.item_key||'-'}}</small><button v-if="line.has_pdf" type="button" class="link inventory-preview-pdf-link" @click="openProductPdf(line.id,line.file_name)">Preview PDF{{line.file_name?' · '+line.file_name:''}} ↗</button><small v-else class="inventory-preview-pdf-missing">{{line.pdf_reason}}</small></div>
                  <strong>×{{line.qty}}</strong>
                </div>
                <p v-if="!inventoryOrderPreview.lines.length" class="inventory-order-preview-empty">Order ini tidak memiliki item yang dapat ditambahkan.</p>
              </div>
              <button class="inventory-order-add-button" :disabled="!inventoryOrderPreview.lines.length||inventoryOrderAdding" @click="addInventoryOrder">{{inventoryOrderAdding?'Menambahkan…':'Tambahkan semua item ke inventory'}}</button>
            </div>
          </article>
        </div>
        <div class="toolbar search-toolbar">
<div class="search">⌕<input v-model="query" @input="debouncedLoad" placeholder="Cari SKU, No. Ref, atau nama produk…">
</div>
<button class="ghost" @click="loadInventory">↻ Refresh</button>
</div>
        <div class="table-card">
<table>
<thead>
<tr>
<th>Produk</th>
<th>SKU / No. Ref</th>
<th>Varian</th>
<th>Stok</th>
<th>Diperbarui</th>
<th>Aksi</th>
</tr>
</thead>
<tbody is="vue:transition-group" name="delete-list">
<tr v-if="loading" key="inventory-loading">
<td colspan="6" class="empty">Memuat inventory…</td>
</tr>
<tr v-for="row in pageData.items" :key="row.item_key" :class="{'is-deleting':row.deleting}">
<td>
<b>{{ row.item_name || row.model_name || row.item_key }}</b>
<small>{{ row.item_key }}</small>
</td>
<td>{{ row.model_sku || row.item_sku || '-' }}<small>{{ row.no_ref || '-' }}</small>
</td>
<td>{{ row.model_name || '-' }}</td>
<td>
<div class="qty">
<button @click="setQty(row,row.qty-1)">−</button>
<input type="number" min="0" :value="row.qty" @change="setQty(row,$event.target.value)">
<button @click="setQty(row,+row.qty+1)">+</button>
</div>
</td>
<td>{{ timeText(row.updated_at) }}</td>
<td class="actions">
<button class="ghost" @click="openInventoryHistory(row)">Riwayat</button>
<button class="danger-button" :disabled="row.deleting" @click="deleteInventory(row)">{{row.deleting?'Menghapus…':'Hapus'}}</button>
</td>
</tr>
</tbody>
</table>
<pagination :data="pageData" @change="p=>{page=p;loadInventory()}"/>
</div>
      </section>

      <section v-if="view==='labels'" class="content labels-content">
        <div class="toolbar search-toolbar">
<div class="search">⌕<input v-model="query" @input="debouncedLoad" placeholder="Cari Order SN atau No. Resi…">
</div>
<div class="filters">
<button v-for="f in labelFilters" :class="{active:filter===f.id}" @click="filter=f.id;page=1;loadLabels()">{{ f.label }}</button>
</div>
<label class="label-global-printer">Printer label<select v-model="labelPrinter"><option v-for="printer in pageData.printers" :value="printer">{{printer}}</option></select></label>
<button class="label-toolbar-button" :disabled="!selected.size||labelBulkFetching" @click="bulkCommand('fetch_label')">{{labelBulkFetching?'Mengambil PDF…':'Ambil PDF ('+selected.size+')'}}</button>
<button class="label-toolbar-button" :disabled="!selected.size||!labelPrinter" @click="bulkCommand('print_label')">Cetak ({{selected.size}})</button>
</div>
<div class="labels-split">
<div class="labels-list">
        <label class="labels-tablet-select-all">
          <input type="checkbox"
            :checked="pageData.items.length > 0 && pageData.items.every(row => selected.has(row.order_sn))"
            :disabled="loading || !pageData.items.length"
            @change="selectPage($event.target.checked)">
          <span>Pilih semua di halaman</span>
        </label>
        <div class="table-card">
<table>
<thead>
<tr>
<th>
<input type="checkbox" @change="selectPage($event.target.checked)">
</th>
<th>Order</th>
<th>No. Resi</th>
<th>Dibuat</th>
<th>PDF</th>
<th>Status label</th>
<th>Aksi</th>
</tr>
</thead>
<tbody is="vue:transition-group" name="delete-list">
<tr v-if="loading" key="labels-loading" class="labels-loading-row">
<td colspan="7" class="empty">Memuat label…</td>
</tr>
<tr v-else-if="!pageData.items.length" key="labels-empty" class="labels-empty-row">
<td colspan="7" class="empty">{{filter==='unprinted'?'Tidak ada resi yang belum dicetak.':filter==='printed'?'Belum ada resi yang sudah dicetak.':filter==='cancelled'?'Tidak ada resi yang dibatalkan.':'Tidak ada resi yang tersedia.'}}</td>
</tr>
<tr v-for="row in pageData.items" :key="row.order_sn" :class="{'is-status-changing':row.statusChanging,'is-previewing':labelPreview?.order_sn===row.order_sn}" @click="openLabelRow(row,$event)">
<td>
<input type="checkbox" :checked="selected.has(row.order_sn)" @change="selectOne(row.order_sn,$event.target.checked)">
</td>
<td>
<b>{{row.order_sn}}</b>
<small>{{row.status}}</small>
</td>
<td><b class="tracking-number">{{row.tracking_number||'-'}}</b></td>
<td>{{row.createdText}}</td>
<td>
<button v-if="row.hasPdf" class="link" @click="openLabel(row.order_sn)">Buka PDF</button>
<span v-else class="badge gray">Belum ada</span>
</td>
<td>
<span class="badge" :class="row.resi_printed?'green':'amber'">{{row.resi_printed?'Sudah cetak':'Belum cetak'}}</span>
</td>
<td class="actions">
<button class="ghost" :disabled="labelFetches.has(row.order_sn)" @click="queue('fetch_label',row.order_sn)">{{labelFetches.has(row.order_sn)?'Mengambil…':'Ambil'}}</button>
<button :disabled="!row.hasPdf||!labelPrinter||row.labelPrinting" @click="queue('print_label',row.order_sn,labelPrinter)">{{row.labelPrinting?'Mengantre…':'Cetak'}}</button>
<button class="ghost mark-printed-button" :class="{revert:row.resi_printed}" :disabled="row.statusChanging" @click="queue('set_label_printed',row.order_sn+'|'+(row.resi_printed?'0':'1'))">{{row.statusChanging?'Menyimpan…':(row.resi_printed?'Belum tercetak':'Sudah dicetak')}}</button>
</td>
</tr>
</tbody>
</table>
<pagination :data="pageData" @change="p=>{page=p;loadLabels()}"/>
</div>
</div>
</div>
      </section>

      <section v-if="view==='mapping'" class="content">
        <div class="toolbar search-toolbar">
<div class="search">⌕<input v-model="query" @input="debouncedLoad" placeholder="Cari SKU, SKU Inti, produk, atau varian">
</div>
<a class="google-sheet-link" href="<?= htmlspecialchars($mappingSheetUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Buka Google Sheet ↗</a>
<button @click="syncMapping" :disabled="busy">{{busy?'Menyinkronkan…':'↻ Sync Google Sheets'}}</button>
</div>
        <div v-if="mappingData.stats" class="stats compact-stats">
<article>
<span>Total mapping</span>
<strong>{{number(mappingData.stats.total)}}</strong>
</article>
<article>
<span>File tidak ditemukan</span>
<strong>{{number(mappingData.stats.missing_files)}}</strong>
</article>
<article>
<span>Sync terakhir</span>
<strong class="small-stat">{{timeText(mappingData.stats.last_sync_at)}}</strong>
<small>{{mappingData.stats.last_sync_source||'-'}}</small>
</article>
</div>
        <div class="table-card">
<table>
<thead>
<tr>
<th>SKU</th>
<th>Produk</th>
<th>Aturan cetak</th>
<th>PDF</th>
<th>Printer</th>
</tr>
</thead>
<tbody>
<tr v-for="row in mappingData.items">
<td>
<b>{{row.sku_id}}</b>
<small>SKU Inti: {{row.parent_sku||'-'}}</small>
</td>
<td>{{row.product_name}}<small>{{row.variation_name}}</small>
</td>
<td>{{row.page_from}}-{{row.page_to||'akhir'}} · {{row.duplex||'simplex'}} · {{row.paper}} · {{row.copies}}x</td>
<td>
<span class="badge" :class="row.file_exists?'green':'red'">{{row.file_exists?row.file_name:'File hilang'}}</span>
</td>
<td>{{row.printer||'-'}}</td>
</tr>
</tbody>
</table>
<pagination :data="mappingData" @change="p=>{page=p;loadMapping()}"/>
</div>
      </section>

      <section v-if="view==='manual'" class="content">
        <div class="manual-source-grid">
        <div class="panel upload-panel">
<div>
<h3>Tambah PDF manual</h3>
<p>PDF disimpan di komputer host dan dapat dicetak oleh pengguna web mana pun.</p>
</div>
<label class="upload-button">Pilih PDF<input type="file" accept="application/pdf,.pdf" @change="uploadManualPdf">
</label>
</div>
        <div class="panel mapping-pdf-panel">
<div class="panel-head"><h3>Pilih dari Data Mapping</h3><p>Cari berdasarkan SKU, produk, variasi, atau nama file PDF.</p></div>
<div class="mapping-pdf-search">
<input v-model="mappingPdfPicker.query" @input="searchMappingPdfs" @focus="!mappingPdfPicker.selected&&loadMappingPdfChoices()" placeholder="Ketik untuk mencari PDF mapping…">
<span v-if="mappingPdfPicker.loading" class="mapping-search-state">Mencari…</span>
<div v-if="!mappingPdfPicker.selected&&mappingPdfPicker.items.length" class="mapping-pdf-results">
<button v-for="item in mappingPdfPicker.items" :key="item.id" type="button" @click="selectMappingPdf(item)"><b>{{item.sku_id||item.parent_sku||'-'}} · {{item.product_name||item.file_name}}</b><small>{{item.variation_name||'-'}} · {{item.file_name}}</small></button>
</div>
</div>
<div v-if="mappingPdfPicker.selected" class="mapping-pdf-selection">
<div><b>{{mappingPdfPicker.selected.file_name}}</b><small>{{mappingPdfPicker.selected.sku_id}} · {{mappingPdfPicker.selected.variation_name||mappingPdfPicker.selected.product_name}}</small></div>
<div class="mapping-pdf-settings">
<select v-model="mappingPdfPicker.printer"><option value="">Pilih printer…</option><option v-for="p in manualData.printers" :value="p">{{p}}</option></select>
<select v-model="mappingPdfPicker.settings.paper"><option value="DEFAULT">Default</option><option>A4</option><option>A5</option><option value="B5">B5 JIS</option></select>
<select v-model="mappingPdfPicker.settings.duplex"><option value="simplex">Simplex</option><option value="duplexlong">Duplex panjang</option><option value="duplexshort">Duplex pendek</option></select>
<input title="Jumlah salinan" type="number" min="1" max="99" v-model.number="mappingPdfPicker.settings.copies">
<button :disabled="mappingPdfPicker.printing" @click="printMappingPdf">{{mappingPdfPicker.printing?'Mengantre…':'Cetak mapping'}}</button>
</div>
<details class="manual-advanced"><summary>Pengaturan halaman</summary><div><label>Dari<input type="number" min="1" v-model.number="mappingPdfPicker.settings.page_from"></label><label>Sampai<input type="number" min="0" v-model.number="mappingPdfPicker.settings.page_to"><small>0 = akhir PDF</small></label><label>Pilihan<select v-model="mappingPdfPicker.settings.parity" @change="mappingPdfPicker.settings.parity!=='all'&&(mappingPdfPicker.settings.duplex='simplex')"><option value="all">Semua halaman</option><option value="odd">Ganjil</option><option value="even">Genap</option></select></label></div></details>
</div>
</div>
</div>
        <transition-group name="delete-list" tag="div" class="document-grid">
<article v-for="doc in manualData.items" :key="doc.id" class="panel document-card" :class="{'is-deleting':doc.deleting}">
<div>
<span class="badge" :class="doc.source_type==='random'?'blue':'gray'">{{doc.source_type}}</span>
<h3>{{doc.original_name}}</h3>
<p>{{doc.page_count}} halaman · {{doc.file_size_text}} · {{doc.createdText}}</p>
<small v-if="doc.summary">{{doc.summary}}</small>
</div>
<div class="document-print-settings">
<select v-model="doc.printer">
<option value="">Pilih printer…</option>
<option v-for="p in manualData.printers" :value="p">{{p}}</option>
</select>
<select v-model="doc.settings.paper">
<option value="DEFAULT">Default</option>
<option>A4</option>
<option>A5</option>
<option value="B5">B5 JIS</option>
</select>
<select v-model="doc.settings.duplex">
<option value="simplex">Simplex</option>
<option value="duplexlong">Duplex panjang</option>
<option value="duplexshort">Duplex pendek</option>
</select>
<input type="number" min="1" max="99" v-model.number="doc.settings.copies">
<button @click="printManual(doc)">Cetak</button>
<button class="ghost" @click="openManualPdf(doc.id,doc.original_name)">Buka</button>
<button class="danger-button" :disabled="doc.deleting" @click="deleteManual(doc)">{{doc.deleting?'Menghapus…':'Hapus'}}</button>
</div>
<details class="manual-advanced"><summary>Pengaturan halaman</summary><div><label>Dari<input type="number" min="1" v-model.number="doc.settings.page_from"></label><label>Sampai<input type="number" min="0" v-model.number="doc.settings.page_to"><small>0 = akhir PDF</small></label><label>Pilihan<select v-model="doc.settings.parity" @change="doc.settings.parity!=='all'&&(doc.settings.duplex='simplex')"><option value="all">Semua halaman</option><option value="odd">Ganjil</option><option value="even">Genap</option></select></label></div></details>
</article>
</transition-group>
      </section>

      <section v-if="view==='random'" class="content">
        <div class="feature-grid">
<article class="panel">
<div class="panel-head">
<div>
<h3>Random Pages</h3>
<p>Sumber PDF diambil langsung dari Data Mapping terbaru.</p>
</div>
</div>
<div class="form-stack">
<label>Mode<select v-model="randomForm.mode">
<option value="planner">Planner - 1 halaman per PDF</option>
<option value="loose">Loose Leaf - pasangan ganjil/genap</option>
</select>
</label>
<label>Ukuran<select v-model="randomForm.paper">
<option>A5</option>
<option value="B5">B5 JIS</option>
</select>
</label>
<label>Jumlah PDF<input type="number" min="1" max="100" v-model.number="randomForm.count">
</label>
<label>Exclude produk<textarea v-model="randomForm.exclude" placeholder="Pisahkan dengan koma atau baris baru">
</textarea>
</label>
<button @click="generateRandom" :disabled="busy">{{busy?'Menggabungkan PDF…':'Buat Random Pages'}}</button>
</div>
</article>
<article class="panel">
<div class="panel-head">
<div>
<h3>Pool tersedia</h3>
<p>File hilang/rusak akan dilewati otomatis.</p>
</div>
</div>
<div class="pool-count">
<div>
<strong>{{randomPool.counts?.planner||0}}</strong>
<span>Planner</span>
</div>
<div>
<strong>{{randomPool.counts?.loose||0}}</strong>
<span>Loose Leaf</span>
</div>
</div>
<p>Hasil akan masuk ke menu PDF Manual sehingga bisa dibuka, dikonfigurasi, lalu dicetak.</p>
</article>
</div>
      </section>

      <section v-if="view==='connections'" class="content marketplace-settings">
        <div class="toolbar connection-toolbar">
<div>
<h2>Koneksi akun marketplace</h2>
<p>Token disimpan terenkripsi di komputer host dan direfresh otomatis sebelum kedaluwarsa.</p>
</div>
</div>
        <div v-if="loading&&!oauthData" class="panel connection-load-state" role="status" aria-live="polite">
          <span class="status-spinner" aria-hidden="true"></span>
          <div><h3>Memeriksa koneksi marketplace</h3><p>Sedang mengambil status Shopee dan TikTok Shop dari server.</p></div>
        </div>
        <div v-else-if="error&&!oauthData" class="panel connection-load-state is-error">
          <span class="status-icon" aria-hidden="true">!</span>
          <div><h3>Status koneksi belum tersedia</h3><p>Terjadi error saat menghubungi server. Gunakan tombol Coba lagi di atas.</p></div>
        </div>
        <div v-if="oauthData" class="connection-grid">
          <article v-for="provider in ['shopee','tiktok']" :key="provider" class="panel connection-card">
            <div class="connection-head">
<div class="market-logo" :class="provider">{{provider==='shopee'?'S':'T'}}</div>
<div>
<h3>{{provider==='shopee'?'Shopee':'TikTok Shop'}}</h3>
<p v-if="oauthData.items[provider].connected">{{oauthData.items[provider].account_name||oauthData.items[provider].account_id||'Akun terhubung'}}</p>
<p v-else>Belum ada akun aktif</p>
</div>
<span class="badge" :class="oauthStatusClass(oauthData.items[provider])">{{oauthStatusLabel(oauthData.items[provider])}}</span>
</div>
            <div v-if="oauthData.items[provider].last_error" class="connection-error">{{oauthData.items[provider].last_error}}</div>
            <div class="connection-facts">
<div>
<span>Access token</span>
<b>{{oauthData.items[provider].connected?(oauthData.items[provider].access_expires_at?'Aktif s.d. '+timeText(oauthData.items[provider].access_expires_at):'Aktif'):'Tidak aktif'}}</b>
</div>
<div>
<span>Callback URL</span>
<button class="copy-url" @click="copyText(oauthData.items[provider].callback_url)">Salin</button>
<code>{{oauthData.items[provider].callback_url}}</code>
</div>
</div>
            <details class="credential-box" :open="!oauthData.items[provider].configured">
<summary>Konfigurasi aplikasi</summary>
              <div v-if="provider==='shopee'" class="credential-grid">
<label>Partner ID<input v-model="oauthForms.shopee.partner_id" inputmode="numeric" placeholder="Partner ID">
</label>
<label>Partner Key<input v-model="oauthForms.shopee.partner_key" type="password" :placeholder="oauthData.items.shopee.config.has_partner_key?'Tersimpan — kosongkan jika tidak diubah':'Partner Key'">
</label>
<label class="wide">API host<input v-model="oauthForms.shopee.api_host">
</label>
</div>
              <div v-else class="credential-grid">
<label>App Key<input v-model="oauthForms.tiktok.app_key" placeholder="App Key">
</label>
<label>App Secret<input v-model="oauthForms.tiktok.app_secret" type="password" :placeholder="oauthData.items.tiktok.config.has_app_secret?'Tersimpan — kosongkan jika tidak diubah':'App Secret'">
</label>
<label>Service ID<input v-model="oauthForms.tiktok.service_id" placeholder="Dari Partner Center">
</label>
<label>Market<select v-model="oauthForms.tiktok.market">
<option value="row">Indonesia / ROW</option>
<option value="us">United States</option>
</select>
</label>
<label>Shop ID<input v-model="oauthForms.tiktok.shop_id" placeholder="Opsional">
</label>
<label>Shop cipher<input v-model="oauthForms.tiktok.shop_cipher" placeholder="Opsional">
</label>
</div>
              <button @click="saveOAuthConfig(provider)" :disabled="busy">Simpan konfigurasi</button>
            </details>
            <div class="connection-actions">
<button v-if="!oauthData.items[provider].connected" @click="connectOAuth(provider)" :disabled="busy||!oauthData.items[provider].configured">Hubungkan {{provider==='shopee'?'Shopee':'TikTok'}}</button>
<button v-else class="ghost" @click="connectOAuth(provider)" :disabled="busy">Otorisasi ulang</button>
<button v-if="oauthData.items[provider].connected" class="danger-button" @click="disconnectOAuth(provider)" :disabled="busy">Putuskan</button>
</div>
          </article>
        </div>
      </section>

      <section v-if="view==='printers'" class="content printer-settings">
        <div v-if="printerSettings" class="settings-grid">
          <article class="panel printer-list-panel">
            <div class="panel-head">
<div>
<h3>Printer yang ditampilkan</h3>
<p>Hanya printer terpilih yang muncul pada pilihan cetak produk dan label.</p>
</div>
<span class="badge blue">{{printerSettings.visible.length}} / {{printerSettings.installed.length}} aktif</span>
</div>
            <div class="settings-actions">
<button class="ghost" @click="selectAllPrinters(true)">Pilih semua</button>
<button class="ghost" @click="selectAllPrinters(false)">Kosongkan</button>
<button class="ghost" @click="loadPrinterSettings">Deteksi ulang</button>
</div>
            <div v-if="!printerSettings.installed.length" class="empty printer-empty">Windows tidak mendeteksi printer pada komputer host.</div>
            <label v-for="printer in printerSettings.installed" :key="printer" class="printer-option">
              <input type="checkbox" :checked="printerSettings.visible.includes(printer)" @change="togglePrinter(printer,$event.target.checked)">
              <span>
<b>{{printer}}</b>
<small>{{printerSettings.visible.includes(printer)?'Ditampilkan di web':'Disembunyikan'}}</small>
</span>
              <i :class="printerSettings.visible.includes(printer)?'online':'muted'">
</i>
            </label>
          </article>
          <article class="panel printer-rules-panel">
            <div class="panel-head">
<div>
<h3>Default dan override</h3>
<p>Aturan ini diterapkan oleh print worker di komputer host.</p>
</div>
</div>
            <label>Printer default label pengiriman
              <select v-model="printerSettings.default_label_printer">
<option value="" disabled>Pilih printer…</option>
<option v-for="printer in printerSettings.visible" :value="printer">{{printer}}</option>
</select>
              <small>Dipilih otomatis untuk PDF label/resi.</small>
            </label>
            <label>Override mapping Brother
              <select v-model="printerSettings.override_brother">
<option value="">Otomatis — ikuti mapping PDF</option>
<option v-for="printer in printerSettings.visible" :value="printer">{{printer}}</option>
</select>
              <small>Mapping dengan nama printer mengandung “Brother” dapat dialihkan ke printer ini.</small>
            </label>
            <label>Override mapping EPSON L3210
              <select v-model="printerSettings.override_l3210">
<option value="">Otomatis — ikuti mapping PDF</option>
<option v-for="printer in printerSettings.visible" :value="printer">{{printer}}</option>
</select>
              <small>Mapping dengan nama printer mengandung “L3210” dapat dialihkan ke printer ini.</small>
            </label>
            <div class="settings-note">
<b>Berlaku global</b>
<p>Perubahan tersimpan di MySQL dan langsung dipakai komputer lain saat membuka atau memperbarui halaman.</p>
</div>
            <button class="save-settings" :disabled="busy||!printerSettings.visible.length||!printerSettings.default_label_printer" @click="savePrinterSettings">{{busy?'Menyimpan…':'Simpan konfigurasi printer'}}</button>
          </article>
        </div>
      </section>

      <div v-if="queueWidgetVisible" class="printer-queue-widget">
        <button v-if="!queuePanelOpen" class="printer-queue-fab" type="button" aria-expanded="false" aria-controls="printer-queue-drawer" @click="openQueuePanel">
          <span class="printer-queue-fab-icon" aria-hidden="true">&#128424;</span>
          <span class="printer-queue-fab-copy">
            <b>{{queueWidgetCount}} job aktif</b>
            <small>{{queueWidgetPrinterSummary}}</small>
          </span>
          <span class="printer-queue-fab-count">{{queueWidgetCount}}</span>
        </button>

        <button v-if="queuePanelOpen" class="printer-queue-scrim" type="button" aria-label="Tutup panel Printer Job" @click="closeQueuePanel"></button>

        <section id="printer-queue-drawer" class="printer-queue-drawer" :class="{open:queuePanelOpen}" role="dialog" aria-modal="true" aria-label="Printer Job" :aria-hidden="queuePanelOpen?'false':'true'" :inert="!queuePanelOpen">
          <div class="printer-queue-drawer-head">
            <div>
              <span class="eyebrow">PRINTER LIVE</span>
              <h2>Printer Job</h2>
              <p>Diperbarui otomatis dari host.</p>
            </div>
            <button class="printer-queue-close" type="button" aria-label="Tutup panel" @click="closeQueuePanel">&times;</button>
          </div>

          <div class="printer-queue-totals">
            <div><strong>{{queueWidgetAppJobs.length}}</strong><span>Antrean aplikasi</span></div>
            <div><strong>{{queueData.spooler?.length||0}}</strong><span>Windows spooler</span></div>
          </div>

          <div class="printer-queue-section">
            <h3>Printer aktif / tujuan</h3>
            <div class="printer-queue-printers">
              <article v-for="printer in queueWidgetPrinters" :key="printer.name">
                <span class="dot" :class="printer.active?'online':'danger'"></span>
                <div>
                  <b>{{printer.name}}</b>
                  <small>{{printer.active?printer.status:'Offline'}} · {{printer.queue_count||0}} di spooler</small>
                </div>
                <span class="badge" :class="printer.active?'green':'red'">{{printer.active?'Aktif':'Offline'}}</span>
              </article>
            </div>
          </div>

          <div class="printer-queue-section">
            <h3>Job aktif</h3>
            <div class="printer-queue-jobs">
              <article v-for="job in queueWidgetAppJobs" :key="job.id" class="printer-queue-job">
                <div>
                  <b>#{{job.id}} · {{commandLabel('print_'+job.job_type)}}</b>
                  <small class="printer-queue-job-name">{{job.order_sn||job.original_name||'Dokumen'}}</small>
                  <small>{{job.printer||'Printer belum dipilih'}}</small>
                </div>
                <span class="badge" :class="statusClass(job.status)">{{job.status}}</span>
                <div class="printer-queue-job-actions">
                  <button class="danger-button" type="button" :disabled="!!queueActionKey" @click="jobAction(job,'cancel')">Cancel</button>
                </div>
              </article>
              <p v-if="!queueWidgetAppJobs.length" class="printer-queue-empty">Tidak ada job aplikasi yang aktif.</p>
            </div>
          </div>

          <div class="printer-queue-section">
            <h3>Windows spooler</h3>
            <div class="printer-queue-jobs">
              <article v-for="job in (queueData.spooler||[])" :key="job.printer+'-'+job.job_id" class="printer-queue-job">
                <div>
                  <b>Spooler #{{job.job_id}}</b>
                  <small class="printer-queue-job-name">{{job.document||'Dokumen'}}</small>
                  <small>{{job.printer}}</small>
                </div>
                <span class="badge blue">{{job.status||'Queued'}}</span>
                <div class="printer-queue-job-actions">
                  <button class="ghost" type="button" :disabled="!!queueActionKey" @click="spoolerAction(job,'pause')">Pause</button>
                  <button class="ghost" type="button" :disabled="!!queueActionKey" @click="spoolerAction(job,'resume')">Resume</button>
                  <button class="danger-button" type="button" :disabled="!!queueActionKey" @click="spoolerAction(job,'cancel')">Cancel</button>
                </div>
              </article>
              <p v-if="!queueData.spooler?.length" class="printer-queue-empty">Tidak ada job pada Windows spooler.</p>
            </div>
          </div>
        </section>
      </div>

      <div v-if="customerHistory" class="modal-backdrop" @click.self="customerHistory=null">
        <article class="modal-card history-modal">
<div class="modal-head">
<div>
<span class="eyebrow">CUSTOMER HISTORY</span>
<h2>{{customerHistory.buyer}}</h2>
<p>1 tahun terakhir: {{customerHistory.summary.orders}} order · {{customerHistory.summary.lines}} item · total {{customerHistory.summary.qty}} pcs</p>
</div>
<button class="icon-button" @click="customerHistory=null">×</button>
</div>
<div class="table-card modal-table">
<table>
<thead>
<tr>
<th>Tanggal</th>
<th>Order</th>
<th>Produk</th>
<th>Variasi</th>
<th>Qty</th>
</tr>
</thead>
<tbody>
<tr v-if="!customerHistory.items.length">
<td colspan="5" class="empty">Belum ada riwayat customer.</td>
</tr>
<tr v-for="row in customerHistory.items">
<td>{{row.createdText}}</td>
<td>{{row.order_sn}}</td>
<td>{{row.item_name}}</td>
<td>{{row.model_name||'-'}}</td>
<td>{{row.qty}}</td>
</tr>
</tbody>
</table>
</div>
</article>
      </div>
      <div v-if="inventoryHistory" class="modal-backdrop" @click.self="inventoryHistory=null">
        <article class="modal-card history-modal">
<div class="modal-head">
<div>
<span class="eyebrow">INVENTORY HISTORY</span>
<h2>{{inventoryHistory.name}}</h2>
</div>
<button class="icon-button" @click="inventoryHistory=null">×</button>
</div>
<div class="table-card modal-table">
<table>
<thead>
<tr>
<th>Waktu</th>
<th>Aktivitas</th>
<th>Perubahan</th>
<th>Stok akhir</th>
<th>Order / Catatan</th>
<th>Pengguna</th>
</tr>
</thead>
<tbody>
<tr v-for="row in inventoryHistory.items">
<td>{{row.createdText}}</td>
<td>{{row.movement_type}}</td>
<td :class="row.qty_delta>=0?'text-success':'text-error'">{{row.qty_delta>0?'+':''}}{{row.qty_delta}}</td>
<td>{{row.qty_after}}</td>
<td>{{row.order_sn||row.note}}</td>
<td>{{row.created_by}}</td>
</tr>
</tbody>
</table>
</div>
</article>
      </div>

      <div v-if="scannerOpen" class="modal-backdrop barcode-backdrop" @click.self="closeInventoryScanner">
        <article class="modal-card barcode-modal">
          <div class="modal-head">
            <div><span class="eyebrow">SCAN ORDER</span><h2>Scan barcode label</h2><p>Arahkan kamera ke barcode atau QR pada label pengiriman.</p></div>
            <button class="icon-button" @click="closeInventoryScanner">×</button>
          </div>
          <div class="barcode-body">
            <div id="inventory-barcode-reader" class="barcode-reader"></div>
            <p class="barcode-status" :class="{error:scannerError}">{{scannerStatus}}</p>
            <div v-if="!scannerSecureContext" class="barcode-certificate-notice">
              <b>Kamera live memerlukan sertifikat di Android</b>
              <span>Instal sertifikat sekali, lalu buka versi HTTPS dan izinkan akses kamera.</span>
              <a href="http://app.paperbell.id/paperbell-local-ca.crt" download="paperbell-local-ca.crt">Unduh sertifikat kamera</a>
            </div>
            <label class="barcode-photo-button">
              <input type="file" accept="image/*" capture="environment" @change="scanInventoryPhoto">
              Ambil foto barcode
            </label>
            <div v-if="scannerMatches.length" class="barcode-matches">
              <p v-if="scannerMatches.length>1">Ada beberapa order yang cocok. Pilih order:</p>
              <button v-for="order in scannerMatches" :key="order.order_sn" class="barcode-match" @click="selectScannedOrder(order)">
                <b>{{order.order_sn}}</b><span>{{order.tracking_number?'Resi '+order.tracking_number+' · ':''}}{{order.buyer_username||'Tanpa nama pembeli'}} · {{order.item_qty}} item</span>
              </button>
            </div>
          </div>
        </article>
      </div>

      <transition name="pdf-drawer">
        <div v-if="labelPreview" class="pdf-drawer-backdrop" @click.self="closeLabelPreview">
          <aside class="pdf-drawer-panel" :class="{'is-swiping':pdfDrawerSwipe.tracking}" :style="{'--pdf-drawer-drag':pdfDrawerSwipe.deltaX+'px'}" role="dialog" aria-modal="true" :aria-label="'Preview PDF '+(labelPreview.title||labelPreview.order_sn)">
            <div class="label-preview-content">
              <div class="pdf-drawer-swipe-handle" aria-hidden="true" @touchstart="startPdfDrawerSwipe" @touchmove="movePdfDrawerSwipe" @touchend="endPdfDrawerSwipe" @touchcancel="cancelPdfDrawerSwipe"><i></i></div>
              <div class="label-preview-head">
                <div><span class="eyebrow">{{labelPreview.order_sn?'RESI PENGIRIMAN':'PREVIEW PDF'}}</span><b>{{labelPreview.title||labelPreview.order_sn}}</b></div>
                <div class="label-preview-head-actions"><button class="ghost" @click="openLabelNewTab">Buka tab baru ↗</button><button class="ghost label-preview-close" type="button" aria-label="Tutup preview" @click="closeLabelPreview">&times;</button></div>
              </div>
              <div class="pdf-viewer-toolbar" v-if="labelPdfState.pages">
                <div v-if="labelPreview.order_sn&&view==='labels'" class="pdf-order-controls">
                  <button class="ghost" type="button" aria-label="Order sebelumnya" :disabled="labelPdfState.loading||labelPreviewIndex<=0" @click="openAdjacentLabel(-1)">&larr;</button>
                  <span>Order {{labelPreviewIndex+1}} / {{labelPreviewRows.length}}</span>
                  <button class="ghost" type="button" aria-label="Order berikutnya" :disabled="labelPdfState.loading||labelPreviewIndex<0||labelPreviewIndex>=labelPreviewRows.length-1" @click="openAdjacentLabel(1)">&rarr;</button>
                </div>
                <div class="pdf-page-controls">
                  <span>Halaman {{labelPdfState.page}} / {{labelPdfState.pages}}</span>
                </div>
                <div class="pdf-zoom-controls">
                  <button class="ghost" type="button" aria-label="Perkecil PDF" :disabled="labelPdfState.loading||labelPdfState.zoom<=60" @click="labelPdfZoom(0.8)">&minus;</button>
                  <button class="ghost pdf-zoom-value" type="button" title="Pas ke lebar" :disabled="labelPdfState.loading" @click="labelPdfFit">{{labelPdfState.zoom}}%</button>
                  <button class="ghost" type="button" aria-label="Perbesar PDF" :disabled="labelPdfState.loading||labelPdfState.zoom>=250" @click="labelPdfZoom(1.25)">+</button>
                </div>
              </div>
              <div ref="labelPdfViewport" class="pdf-viewer-viewport" :class="{loading:labelPdfState.loading}">
                <div class="pdf-page-stack"><canvas ref="labelPdfCanvas" :aria-label="'Preview PDF '+(labelPreview.title||labelPreview.order_sn)"></canvas></div>
                <div v-if="labelPdfState.loading" class="pdf-viewer-message" role="status"><span class="pdf-spinner"></span>Memuat preview&hellip;</div>
                <div v-else-if="labelPdfState.error" class="pdf-viewer-message error" role="alert"><b>Preview gagal</b><span>{{labelPdfState.error}}</span><button type="button" @click="openLabelNewTab">Buka PDF</button></div>
              </div>
              <div v-if="labelPreviewRow" class="label-preview-actions">
                <button type="button" :disabled="!labelPrinter||labelPreviewRow.labelPrinting" @click="printPreviewLabel">{{labelPreviewRow.labelPrinting?'Mengantre…':'Cetak label'}}</button>
                <button class="ghost" type="button" :class="{revert:labelPreviewRow.resi_printed}" :disabled="labelPreviewRow.statusChanging" @click="togglePreviewPrinted">{{labelPreviewRow.resi_printed?'Tandai belum cetak':'Tandai sudah cetak'}}</button>
              </div>
            </div>
          </aside>
        </div>
      </transition>
    </main>
  </div>

</div>
<script src="assets/vue.global.prod.js">
</script>
<script src="assets/app.js?v=75">
</script>
</body>
</html>




