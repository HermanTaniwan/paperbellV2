<?php
$config = require __DIR__ . '/config.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
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
  <link rel="stylesheet" href="assets/app.css?v=27">
  <link rel="stylesheet" href="assets/print.css?v=6">
  <link rel="stylesheet" href="assets/order-enhancements.css?v=25">
  <link rel="stylesheet" href="assets/features.css?v=23">
  <link rel="stylesheet" href="assets/tablet.css?v=7">
  <link rel="stylesheet" href="assets/status.css?v=4">
  <link rel="stylesheet" href="assets/theme-pastel.css?v=12">
  <link rel="stylesheet" href="assets/stock-recommendations.css?v=7">
  <link rel="stylesheet" href="assets/pdf-drawer.css?v=3">
  <link rel="stylesheet" href="assets/motion.css?v=1">
  <link rel="stylesheet" href="assets/scanner.css?v=2">
  <link rel="stylesheet" href="assets/shopee-insights.css?v=9">
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
<p>{{ view==='stock'?'Shortlist SKU terbaik untuk memulai stok produk jadi.':subtitle }}</p>
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
      <section v-if="unacknowledgedPrinterIncidents().length" class="printer-incident-banner" role="alert" aria-live="assertive">
        <span class="printer-incident-banner-icon" aria-hidden="true">!</span>
        <div>
          <b>{{unacknowledgedPrinterIncidents().length}} masalah printer perlu diperiksa</b>
          <span>{{unacknowledgedPrinterIncidents()[0].title}} · {{unacknowledgedPrinterIncidents()[0].printer||'Komputer host'}}</span>
        </div>
        <button type="button" class="ghost" @click="enablePrinterNotifications">Aktifkan notifikasi</button>
        <button type="button" @click="openQueuePanel">Lihat masalah</button>
      </section>
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
        <div class="stats" v-if="dashboard">
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
        <article class="panel contribution-panel">
          <div class="analytics-head contribution-head">
            <div><span class="eyebrow">KONTRIBUSI PENJUALAN</span><h3>Komposisi ukuran loose leaf</h3><p>Proporsi berdasarkan quantity item marketplace yang terjual.</p></div>
            <form class="analytics-range" @submit.prevent="loadSalesContribution">
              <div class="analytics-presets"><button v-for="days in [7,14,30,90]" :key="days" type="button" class="ghost" :class="{active:contributionRangeDays===days}" :disabled="contributionLoading" @click="setContributionRange(days)">{{days}} hari</button></div>
              <label class="analytics-month-shortcut">Bulan<input v-model="contributionMonth" type="month" :max="analyticsMonthMax()" :disabled="contributionLoading" @change="setContributionMonth"></label>
              <label>Dari<input v-model="contributionFrom" type="date" :max="contributionTo" required @change="contributionMonth=''"></label>
              <label>Sampai<input v-model="contributionTo" type="date" :min="contributionFrom" required @change="contributionMonth=''"></label>
              <button type="submit" :disabled="contributionLoading">{{contributionLoading?'Memuat…':'Terapkan'}}</button>
            </form>
          </div>
          <div v-if="contributionLoading&&!salesContribution" class="analytics-empty">Memuat kontribusi penjualan…</div>
          <div v-else-if="salesContribution&&!salesContribution.summary.qty" class="analytics-empty">Belum ada penjualan A5 atau B5 pada rentang tanggal ini.</div>
          <div v-else-if="salesContribution" class="contribution-body">
            <section class="contribution-chart-card">
              <div class="contribution-chart-head">
                <div><b>Pergerakan share penjualan</b><small>{{contributionWindow()>1?'Rata-rata berjalan '+contributionWindow()+' hari':'Share per hari'}}</small></div>
                <div class="contribution-legend"><span v-for="item in salesContribution.items" :key="item.key"><i :style="{background:item.color}"></i>{{item.shortLabel}}</span></div>
              </div>
              <div class="contribution-chart-wrap">
                <svg viewBox="0 0 836 265" role="img" aria-label="Time series persentase kontribusi penjualan A5 dan B5">
                  <g class="contribution-grid"><g v-for="tick in [0,25,50,75,100]" :key="tick"><line x1="58" :y1="contributionChartY(tick)" x2="778" :y2="contributionChartY(tick)"></line><text x="48" :y="contributionChartY(tick)+3" text-anchor="end">{{tick}}%</text></g></g>
                  <line class="axis-line" x1="58" y1="40" x2="58" y2="215"></line><line class="axis-line" x1="58" y1="215" x2="778" y2="215"></line>
                  <g class="contribution-x-ticks"><g v-for="tick in contributionChartDateTicks()" :key="tick.index"><line :x1="contributionChartX(tick.index)" y1="215" :x2="contributionChartX(tick.index)" y2="220"></line><text :x="contributionChartX(tick.index)" y="236" text-anchor="middle">{{tick.label}}</text></g></g>
                  <polyline class="contribution-line a5-20" :points="contributionChartPoints('a5_20')"></polyline>
                  <polyline class="contribution-line a5-6" :points="contributionChartPoints('a5_6')"></polyline>
                  <polyline class="contribution-line b5" :points="contributionChartPoints('b5')"></polyline>
                  <g v-if="salesContribution.series.length<=31"><circle v-for="(day,index) in contributionSeries()" :key="'20-'+day.date" class="contribution-point a5-20" :cx="contributionChartX(index)" :cy="contributionChartY(day.share.a5_20)" r="2.8"></circle><circle v-for="(day,index) in contributionSeries()" :key="'6-'+day.date" class="contribution-point a5-6" :cx="contributionChartX(index)" :cy="contributionChartY(day.share.a5_6)" r="2.8"></circle><circle v-for="(day,index) in contributionSeries()" :key="'b5-'+day.date" class="contribution-point b5" :cx="contributionChartX(index)" :cy="contributionChartY(day.share.b5)" r="2.8"></circle></g>
                  <line v-if="contributionSelected" class="contribution-guide" :x1="contributionChartX(contributionSelected.index)" y1="40" :x2="contributionChartX(contributionSelected.index)" y2="215"></line>
                  <rect v-for="(day,index) in contributionSeries()" :key="'hover-'+day.date" class="contribution-hover-zone" :x="index===0?58:(contributionChartX(index-1)+contributionChartX(index))/2" y="40" :width="index===salesContribution.series.length-1?778-(index===0?58:(contributionChartX(index-1)+contributionChartX(index))/2):(contributionChartX(index+1)-contributionChartX(index-1))/2" height="175" @mouseenter="contributionSelect(day,index)" @click.stop="contributionSelect(day,index)"></rect>
                  <text class="axis-title" x="418" y="258" text-anchor="middle">Tanggal</text><text class="axis-title" x="13" y="128" text-anchor="middle" transform="rotate(-90 13 128)">Kontribusi</text>
                </svg>
                <div v-if="contributionSelected" class="contribution-tooltip" :style="{left:(contributionChartX(contributionSelected.index)/8.36)+'%'}"><b>{{contributionSelected.label}}</b><span><i style="background:#ef7558"></i>A5 20L <strong>{{number(contributionSelected.share.a5_20.toFixed(1))}}%</strong></span><span><i style="background:#e7b54a"></i>A5 6L <strong>{{number(contributionSelected.share.a5_6.toFixed(1))}}%</strong></span><span><i style="background:#4f8f78"></i>B5 <strong>{{number(contributionSelected.share.b5.toFixed(1))}}%</strong></span></div>
              </div>
              <div v-if="contributionShift()" class="contribution-shift" :class="contributionShift().delta>=0?'up':'down'"><span>Perubahan terbesar</span><b>{{contributionShiftText()}}</b></div>
            </section>
            <div class="contribution-breakdown">
              <article v-for="item in salesContribution.items" :key="item.key" :class="'contribution-'+item.key">
                <div class="contribution-card-head"><span><i :style="{background:item.color}"></i>{{item.label}}</span><strong>{{number(item.share)}}%</strong></div>
                <div class="contribution-track"><i :style="{width:item.share+'%',background:item.color}"></i></div>
                <div class="contribution-card-foot"><b>{{number(item.qty)}} item</b><span>tersebar di {{number(item.orders)}} order</span></div>
              </article>
              <div class="contribution-coverage"><span>{{number(salesContribution.summary.orders)}} order terkategori</span><span>Cakupan klasifikasi {{number(salesContribution.summary.coverage)}}%</span></div>
            </div>
          </div>
        </article>
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
            <div class="market-summary"><i class="legend-dot shopee"></i><span>Shopee</span><strong>{{number(analytics.summary.shopee)}}</strong></div>
            <div class="market-summary"><i class="legend-dot tiktok"></i><span>TikTok Shop</span><strong>{{number(analytics.summary.tiktok)}}</strong></div>
            <div class="analytics-metric"><span>Total order</span><strong>{{number(analytics.summary.total)}}</strong></div>
            <div class="analytics-metric"><span>Total item</span><strong>{{number(analytics.summary.items)}}</strong></div>
            <div class="analytics-metric"><span>Item / order</span><strong>{{number(analytics.summary.itemsPerOrder)}}</strong></div>
            <div class="analytics-metric average-summary"><span>Rata-rata order / hari</span><strong>{{number(analytics.summary.ordersPerDay)}}</strong><small>{{number(analytics.summary.operatingDays)}} hari operasional</small></div>
            <div class="analytics-metric average-summary"><span>Rata-rata item / hari</span><strong>{{number(analytics.summary.itemsPerDay)}}</strong><small>{{number(analytics.summary.operatingDays)}} hari operasional</small></div>
            <div class="analytics-metric revenue-summary"><span>Omzet pesanan</span><strong>{{currency(analytics.summary.revenue)}}</strong><small>{{number(analytics.summary.pricedOrders)}} order memiliki nominal</small></div>
            <div class="analytics-metric payout-summary"><span>Payout bersih Shopee</span><strong>{{currency(analytics.summary.shopeePayout)}}</strong><small>{{number(analytics.summary.escrowOrders)}} order escrow</small></div>
          </div>
          <details class="store-holiday-settings">
            <summary><span><b>Hari libur toko / nasional</b><small>Tanggal ini dilewati oleh target pengiriman dan tidak dihitung sebagai hari operasional</small></span><em v-if="analytics">{{number(analytics.summary.operatingDays)}} hari operasional <i>&middot;</i> {{number(analytics.summary.holidayDays)}} libur</em></summary>
            <div class="store-holiday-body"><form @submit.prevent="addStoreHoliday"><label>Tambah tanggal libur<input v-model="storeHolidayDate" type="date" required :disabled="holidaySaving"></label><button type="submit" :disabled="holidaySaving||!storeHolidayDate">{{holidaySaving?'Menyimpan...':'Tambah'}}</button></form><div class="store-holiday-list"><span v-if="!analytics?.holidays?.length">Belum ada tanggal libur yang disimpan.</span><button v-for="date in (analytics?.holidays||[])" :key="date" type="button" class="holiday-chip" :class="{'in-range':date>=analyticsFrom&&date<=analyticsTo}" :disabled="holidaySaving" @click="removeStoreHoliday(date)" :title="'Hapus '+holidayDateText(date)">{{holidayDateText(date)}} &times;</button></div></div>
          </details>
          <div v-if="analyticsLoading&&!analytics" class="analytics-empty">Memuat analitik order…</div>
          <div v-else-if="analytics&&!analytics.summary.total" class="analytics-empty">Belum ada order marketplace pada rentang tanggal ini.</div>
          <div v-else-if="analytics" class="analytics-chart-scroll"><small class="chart-drag-hint">Geser jari di grafik untuk melihat data per tanggal</small><div class="analytics-chart" @pointerdown.prevent="dragOrderTooltip($event,true)" @pointermove.prevent="dragOrderTooltip" @pointerup="finishAnalyticsTooltipDrag" @pointercancel="finishAnalyticsTooltipDrag" @pointerleave="finishAnalyticsTooltipDrag">
            <div class="analytics-grid-lines" aria-hidden="true"><div v-for="tick in analyticsBarTicks()" :key="tick" class="analytics-grid-line" :style="{bottom:analyticsBarTickPosition(tick)+'%'}"><span>{{number(tick)}}</span><i></i></div></div>
            <span class="analytics-y-title">Order</span>
            <div v-for="day in analytics.items" :key="day.date" class="analytics-day" :class="{'is-holiday':day.isHoliday}" @mouseenter="showOrderBarTooltip($event,day)" @mousemove="showOrderBarTooltip($event,day)" @click.stop="showOrderBarTooltip($event,day,true)" @mouseleave="hideAnalyticsTooltip">
              <div class="analytics-bars"><div class="analytics-bar shopee" :style="{height:analyticsBarHeight(day.shopee)+'%'}"><b v-if="day.shopee&&analytics.items.length<=31">{{day.shopee}}</b></div><div class="analytics-bar tiktok" :style="{height:analyticsBarHeight(day.tiktok)+'%'}"><b v-if="day.tiktok&&analytics.items.length<=31">{{day.tiktok}}</b></div></div>
              <span v-if="showAnalyticsLabel(day,analytics.items)">{{day.label}}</span>
            </div>
            <i v-if="analyticsTooltip?.type==='orders'" class="chart-selection-guide" :style="{left:analyticsTooltip.guideLeft+'px',top:analyticsTooltip.guideTop+'px',height:analyticsTooltip.guideHeight+'px'}"></i>
            <div v-if="analyticsTooltip?.type==='orders'" class="metric-tooltip google-chart-tooltip analytics-bar-tooltip" :class="{pinned:analyticsTooltip.pinned}" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><div><strong>{{analyticsTooltip.value}}</strong><b>{{analyticsTooltip.date}}</b></div><span>{{analyticsTooltip.detail}}</span></div>
          </div></div>
          <section v-if="analytics?.summary?.total" class="metric-line-grid">
            <article class="metric-line-card revenue-card">
              <div class="metric-line-head revenue-combined-head"><div><span>Omzet pesanan</span><strong>{{currency(analytics.summary.revenue)}}</strong></div><div><span>Payout bersih Shopee</span><strong>{{currency(analytics.summary.shopeePayout)}}</strong></div><div class="revenue-line-legend"><span><i class="gross"></i>Omzet semua marketplace</span><span><i class="payout"></i>Payout Shopee</span></div></div>
              <svg viewBox="0 0 600 190" role="img" aria-label="Line chart omzet pesanan per hari" @pointerdown.prevent="dragAnalyticsLineTooltip($event,'revenue',true)" @pointermove.prevent="dragAnalyticsLineTooltip($event,'revenue')" @pointerup="finishAnalyticsTooltipDrag" @pointercancel="finishAnalyticsTooltipDrag" @pointerleave="finishAnalyticsTooltipDrag">
                <g class="line-grid"><g v-for="tick in analyticsLineTicks('revenue')" :key="tick"><line x1="55" :y1="analyticsLineTickY(tick,'revenue')" x2="580" :y2="analyticsLineTickY(tick,'revenue')"></line><text x="47" :y="analyticsLineTickY(tick,'revenue')+3" text-anchor="end">{{compactCurrency(tick)}}</text></g></g>
                <line class="axis-line" x1="55" y1="20" x2="55" y2="135"></line><line class="axis-line" x1="55" y1="135" x2="580" y2="135"></line>
                <g class="x-ticks"><g v-for="tick in analyticsLineDateTicks()" :key="tick.index"><line :x1="analyticsLineX(tick.index)" y1="135" :x2="analyticsLineX(tick.index)" y2="140"></line><text :x="analyticsLineX(tick.index)" y="153" text-anchor="middle">{{tick.label}}</text></g></g>
                <polyline class="metric-line revenue" :points="analyticsLinePoints('revenue')"></polyline>
                <polyline class="metric-line shopee-payout" :points="analyticsLinePoints('shopeePayout')"></polyline>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="day.date" class="metric-point revenue" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'revenue')" r="3"><title>{{day.label}}: {{currency(day.revenue)}}</title></circle></g>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="'payout-'+day.date" class="metric-point shopee-payout" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'shopeePayout')" r="3"><title>{{day.label}}: {{currency(day.shopeePayout)}}</title></circle></g>
                <rect v-for="(day,index) in analytics.items" :key="'revenue-hover-'+day.date" class="metric-hover-zone" :x="analyticsLineZoneLeft(index)" y="20" :width="analyticsLineZoneWidth(index)" height="115" @mouseenter="showCombinedRevenueTooltip($event,day)" @mousemove="showCombinedRevenueTooltip($event,day)" @click.stop="showCombinedRevenueTooltip($event,day,true)" @mouseleave="hideAnalyticsTooltip"></rect>
                <text class="axis-title" x="318" y="181" text-anchor="middle">Tanggal</text><text class="axis-title" x="13" y="78" text-anchor="middle" transform="rotate(-90 13 78)">Omzet</text>
              </svg>
              <i v-if="analyticsTooltip?.type==='revenue'" class="chart-selection-guide" :style="{left:analyticsTooltip.guideLeft+'px',top:analyticsTooltip.guideTop+'px',height:analyticsTooltip.guideHeight+'px'}"></i>
              <div v-if="analyticsTooltip?.type==='revenue'" class="metric-tooltip google-chart-tooltip" :class="{pinned:analyticsTooltip.pinned}" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><div><strong>{{analyticsTooltip.value}}</strong><b>{{analyticsTooltip.date}}</b></div><span>{{analyticsTooltip.detail}}</span></div>
            </article>
            <article class="metric-line-card">
              <div class="metric-line-head"><div><span>Item terjual</span><strong>{{number(analytics.summary.items)}}</strong></div><small>Total quantity item</small></div>
              <svg viewBox="0 0 600 190" role="img" aria-label="Line chart jumlah item terjual per hari" @pointerdown.prevent="dragAnalyticsLineTooltip($event,'soldItems',true)" @pointermove.prevent="dragAnalyticsLineTooltip($event,'soldItems')" @pointerup="finishAnalyticsTooltipDrag" @pointercancel="finishAnalyticsTooltipDrag" @pointerleave="finishAnalyticsTooltipDrag">
                <g class="line-grid"><g v-for="tick in analyticsLineTicks('soldItems')" :key="tick"><line x1="55" :y1="analyticsLineTickY(tick,'soldItems')" x2="580" :y2="analyticsLineTickY(tick,'soldItems')"></line><text x="47" :y="analyticsLineTickY(tick,'soldItems')+3" text-anchor="end">{{number(tick)}}</text></g></g>
                <line class="axis-line" x1="55" y1="20" x2="55" y2="135"></line><line class="axis-line" x1="55" y1="135" x2="580" y2="135"></line>
                <g class="x-ticks"><g v-for="tick in analyticsLineDateTicks()" :key="tick.index"><line :x1="analyticsLineX(tick.index)" y1="135" :x2="analyticsLineX(tick.index)" y2="140"></line><text :x="analyticsLineX(tick.index)" y="153" text-anchor="middle">{{tick.label}}</text></g></g>
                <polyline class="metric-line sold-items" :points="analyticsLinePoints('soldItems')"></polyline>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="day.date" class="metric-point sold-items" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'soldItems')" r="4"><title>{{day.label}}: {{day.items}} item</title></circle></g>
                <rect v-for="(day,index) in analytics.items" :key="'hover-'+day.date" class="metric-hover-zone" :x="analyticsLineZoneLeft(index)" y="20" :width="analyticsLineZoneWidth(index)" height="115" @mouseenter="showAnalyticsTooltip($event,day,'soldItems')" @mousemove="showAnalyticsTooltip($event,day,'soldItems')" @click.stop="showAnalyticsTooltip($event,day,'soldItems',true)" @mouseleave="hideAnalyticsTooltip"></rect>
                <text class="axis-title" x="318" y="181" text-anchor="middle">Tanggal</text><text class="axis-title" x="13" y="78" text-anchor="middle" transform="rotate(-90 13 78)">Item</text>
              </svg>
              <i v-if="analyticsTooltip?.type==='soldItems'" class="chart-selection-guide" :style="{left:analyticsTooltip.guideLeft+'px',top:analyticsTooltip.guideTop+'px',height:analyticsTooltip.guideHeight+'px'}"></i>
              <div v-if="analyticsTooltip?.type==='soldItems'" class="metric-tooltip google-chart-tooltip" :class="{pinned:analyticsTooltip.pinned}" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><div><strong>{{analyticsTooltip.value}}</strong><b>{{analyticsTooltip.date}}</b></div><span>{{analyticsTooltip.detail}}</span></div>
            </article>
            <article class="metric-line-card">
              <div class="metric-line-head"><div><span>Item per order</span><strong>{{number(analytics.summary.itemsPerOrder)}}</strong></div><small>Rata-rata quantity per order</small></div>
              <svg viewBox="0 0 600 190" role="img" aria-label="Line chart jumlah item per order per hari" @pointerdown.prevent="dragAnalyticsLineTooltip($event,'ratio',true)" @pointermove.prevent="dragAnalyticsLineTooltip($event,'ratio')" @pointerup="finishAnalyticsTooltipDrag" @pointercancel="finishAnalyticsTooltipDrag" @pointerleave="finishAnalyticsTooltipDrag">
                <g class="line-grid"><g v-for="tick in analyticsLineTicks('ratio')" :key="tick"><line x1="55" :y1="analyticsLineTickY(tick,'ratio')" x2="580" :y2="analyticsLineTickY(tick,'ratio')"></line><text x="47" :y="analyticsLineTickY(tick,'ratio')+3" text-anchor="end">{{number(tick)}}</text></g></g>
                <line class="axis-line" x1="55" y1="20" x2="55" y2="135"></line><line class="axis-line" x1="55" y1="135" x2="580" y2="135"></line>
                <g class="x-ticks"><g v-for="tick in analyticsLineDateTicks()" :key="tick.index"><line :x1="analyticsLineX(tick.index)" y1="135" :x2="analyticsLineX(tick.index)" y2="140"></line><text :x="analyticsLineX(tick.index)" y="153" text-anchor="middle">{{tick.label}}</text></g></g>
                <polyline class="metric-line ratio" :points="analyticsLinePoints('ratio')"></polyline>
                <g v-if="analytics.items.length<=31"><circle v-for="(day,index) in analytics.items" :key="day.date" class="metric-point ratio" :cx="analyticsLineX(index)" :cy="analyticsLineY(day,'ratio')" r="4"><title>{{day.label}}: {{number(analyticsLineValue(day,'ratio'))}} item/order</title></circle></g>
                <rect v-for="(day,index) in analytics.items" :key="'hover-'+day.date" class="metric-hover-zone" :x="analyticsLineZoneLeft(index)" y="20" :width="analyticsLineZoneWidth(index)" height="115" @mouseenter="showAnalyticsTooltip($event,day,'ratio')" @mousemove="showAnalyticsTooltip($event,day,'ratio')" @click.stop="showAnalyticsTooltip($event,day,'ratio',true)" @mouseleave="hideAnalyticsTooltip"></rect>
                <text class="axis-title" x="318" y="181" text-anchor="middle">Tanggal</text><text class="axis-title" x="13" y="78" text-anchor="middle" transform="rotate(-90 13 78)">Item / order</text>
              </svg>
              <i v-if="analyticsTooltip?.type==='ratio'" class="chart-selection-guide" :style="{left:analyticsTooltip.guideLeft+'px',top:analyticsTooltip.guideTop+'px',height:analyticsTooltip.guideHeight+'px'}"></i>
              <div v-if="analyticsTooltip?.type==='ratio'" class="metric-tooltip google-chart-tooltip" :class="{pinned:analyticsTooltip.pinned}" :style="{left:analyticsTooltip.left+'px',top:analyticsTooltip.top+'px'}"><div><strong>{{analyticsTooltip.value}}</strong><b>{{analyticsTooltip.date}}</b></div><span>{{analyticsTooltip.detail}}</span></div>
            </article>
          </section>
        </article>
        <article v-if="false" class="panel shopee-insights">
          <div class="shopee-insights-head">
            <div><span class="eyebrow">SHOPEE SHOP STATS</span><h3>Growth, funnel, dan kesehatan pelanggan</h3><p>{{shopeeStats.meta.source}} · {{shopeeStats.meta.periodLabel}} · status {{shopeeStats.meta.orderStatus}}</p></div>
            <span class="shopee-source-badge">{{number(shopeeStats.meta.sourceFiles.length)}} export terhubung</span>
          </div>
          <section class="shopee-kpis" aria-label="Ringkasan Shopee bulan terbaru">
            <article v-for="kpi in shopeeStats.latestKpis" :key="kpi.label" class="shopee-kpi">
              <span>{{kpi.label}}</span><strong>{{shopeeKpiValue(kpi)}}</strong>
              <div class="shopee-kpi-foot"><b :class="shopeeDeltaTone(kpi)">{{shopeeDeltaText(kpi)}}</b><small>{{kpi.note}}</small></div>
            </article>
          </section>
          <div class="shopee-analysis-grid">
            <section class="shopee-card">
              <div class="shopee-card-head">
                <div><h4>Pengunjung vs pendapatan</h4><p>Lihat apakah kenaikan traffic benar-benar diikuti pertumbuhan omzet.</p></div>
                <div class="shopee-trend-tabs" aria-label="Pilih interval grafik"><button type="button" :class="{active:shopeeComparisonGranularity==='daily'}" @click="setShopeeComparisonGranularity('daily')">Harian</button><button type="button" :class="{active:shopeeComparisonGranularity==='monthly'}" @click="setShopeeComparisonGranularity('monthly')">Bulanan</button></div>
              </div>
              <div class="shopee-date-controls">
                <label><span>Dari</span><input v-model="shopeeComparisonFrom" type="date" :min="shopeeComparison?.minDate" :max="shopeeComparison?.maxDate"></label>
                <label><span>Sampai</span><input v-model="shopeeComparisonTo" type="date" :min="shopeeComparison?.minDate" :max="shopeeComparison?.maxDate"></label>
                <button type="button" :disabled="shopeeComparisonLoading" @click="loadShopeeComparison()">{{shopeeComparisonLoading?'Memuat...':'Terapkan'}}</button>
              </div>
              <div v-if="shopeeComparison" class="shopee-comparison-summary">
                <div><span>Total pendapatan</span><strong>{{currency(shopeeComparison.summary.sales)}}</strong></div>
                <div><span>Akumulasi pengunjung harian</span><strong>{{number(shopeeComparison.summary.visitors)}}</strong></div>
                <div><span>Omzet / pengunjung</span><strong>{{currency(shopeeComparison.summary.salesPerVisitor)}}</strong></div>
              </div>
              <div v-if="shopeeComparison" class="shopee-comparison-legend"><span class="sales">Pendapatan</span><span class="visitors">Pengunjung</span><small>{{shopeeComparisonGranularity==='daily'?'Tiap hari':'Dijumlahkan per bulan'}}</small></div>
              <div v-if="shopeeComparison?.items?.length" class="shopee-comparison-scroll">
                <div class="shopee-comparison-canvas">
                  <svg viewBox="0 0 860 290" role="img" aria-label="Grafik perbandingan pendapatan dan pengunjung Shopee">
                    <g class="shopee-comparison-grid"><g v-for="tick in shopeeChartTicks('sales')" :key="'sales-'+tick.ratio"><line :x1="shopeeChartLeft()" :y1="tick.y" :x2="shopeeChartRight()" :y2="tick.y"></line><text :x="shopeeChartLeft()-9" :y="tick.y+3" text-anchor="end">{{shopeeChartCurrencyTick(tick.value)}}</text></g></g>
                    <g class="shopee-comparison-right-axis"><text v-for="tick in shopeeChartTicks('visitors')" :key="'visitor-'+tick.ratio" :x="shopeeChartRight()+9" :y="tick.y+3">{{number(Math.round(tick.value))}}</text></g>
                    <line class="shopee-comparison-axis" :x1="shopeeChartLeft()" y1="225" :x2="shopeeChartRight()" y2="225"></line>
                    <g class="shopee-comparison-bars"><rect v-for="(item,index) in shopeeComparison.items" :key="'bar-'+item.date" :x="shopeeChartX(index)-shopeeChartBarWidth()/2" :y="shopeeChartSalesY(item.sales)" :width="shopeeChartBarWidth()" :height="225-shopeeChartSalesY(item.sales)" :rx="Math.min(4,shopeeChartBarWidth()/2)"><title>{{item.fullLabel}}: {{shopeeComparisonDetail(item)}}</title></rect></g>
                    <polyline class="shopee-comparison-line" :points="shopeeChartVisitorPoints()"></polyline>
                    <g v-if="shopeeComparison.items.length<=90" class="shopee-comparison-points"><circle v-for="(item,index) in shopeeComparison.items" :key="'point-'+item.date" :cx="shopeeChartX(index)" :cy="shopeeChartVisitorY(item.visitors)" r="4"><title>{{item.fullLabel}}: {{number(item.visitors)}} pengunjung</title></circle></g>
                    <g class="shopee-comparison-x-axis"><g v-for="tick in shopeeChartDateTicks()" :key="tick.index"><line :x1="shopeeChartX(tick.index)" y1="225" :x2="shopeeChartX(tick.index)" y2="230"></line><text :x="shopeeChartX(tick.index)" y="244" text-anchor="middle">{{tick.label}}</text></g></g>
                    <g class="shopee-comparison-hover"><rect v-for="(item,index) in shopeeComparison.items" :key="'zone-'+item.date" :x="shopeeChartZoneX(index)" y="28" :width="shopeeChartZoneWidth()" height="197" @mouseenter="shopeeComparisonSelected=item" @click="shopeeComparisonSelected=item"><title>{{item.fullLabel}}: {{shopeeComparisonDetail(item)}}</title></rect></g>
                    <text class="shopee-comparison-axis-title" :x="shopeeChartLeft()" y="17">Pendapatan</text><text class="shopee-comparison-axis-title visitors" :x="shopeeChartRight()" y="17" text-anchor="end">Pengunjung</text>
                  </svg>
                  <div v-if="shopeeComparisonSelected" class="shopee-comparison-tooltip" :style="{left:shopeeChartTooltipPercent(shopeeComparisonSelected)+'%'}"><b>{{shopeeComparisonSelected.fullLabel}}</b><span>{{currency(shopeeComparisonSelected.sales)}} pendapatan</span><span>{{number(shopeeComparisonSelected.visitors)}} pengunjung</span><small>{{currency(shopeeComparisonSelected.salesPerVisitor)}} / pengunjung</small></div>
                </div>
              </div>
              <div v-else-if="shopeeComparisonLoading" class="shopee-comparison-empty">Memuat data Shopee...</div>
              <div v-else class="shopee-comparison-empty">Tidak ada data pada rentang ini.</div>
              <small v-if="shopeeComparison" class="shopee-comparison-note">{{shopeeComparison.visitorMetric}}</small>
            </section>
            <section class="shopee-card">
              <div class="shopee-card-head"><div><h4>Insight prioritas · {{shopeeStats.meta.latestLabel}}</h4><p>Sinyal yang paling relevan untuk keputusan berikutnya.</p></div></div>
              <div class="shopee-insight-list">
                <article v-for="insight in shopeeStats.insights" :key="insight.title" class="shopee-insight" :class="insight.tone"><span>{{insight.label}}</span><h5>{{insight.title}}</h5><p>{{insight.text}}</p></article>
              </div>
            </section>
          </div>
          <div class="shopee-detail-grid">
            <section class="shopee-card">
              <div class="shopee-card-head"><div><h4>Produk penggerak omzet</h4><p>Top 5 menyumbang {{shopeePercent(shopeeStats.topProductsShare,1)}} dari penjualan halaman produk.</p></div></div>
              <div class="shopee-table-wrap"><table class="shopee-product-table"><thead><tr><th>Produk</th><th>Kontribusi</th><th>Omzet</th><th>Order atribusi</th><th>Konversi</th></tr></thead><tbody><tr v-for="product in shopeeStats.topProducts" :key="product.code"><td><b>{{product.name}}</b><small>{{product.code}}</small></td><td><span class="shopee-share"><i :style="{'--share':shopeeProductBar(product)+'%'}"></i>{{shopeePercent(product.share,1)}}</span></td><td>{{currency(product.sales)}}</td><td>{{number(product.orders)}}</td><td>{{shopeePercent(product.conversion,2)}}</td></tr></tbody></table></div>
            </section>
            <section class="shopee-card">
              <div class="shopee-card-head"><div><h4>Sumber traffic yang menghasilkan</h4><p>Porsi omzet dari halaman produk pada {{shopeeStats.meta.latestLabel}}.</p></div></div>
              <div class="shopee-traffic-list"><div v-for="source in shopeeStats.trafficSources" :key="source.name" class="shopee-traffic-row"><span>{{source.name}}</span><i><b :style="{width:shopeeTrafficBar(source)+'%'}"></b></i><strong>{{shopeePercent(source.share,1)}}</strong></div></div>
              <div class="shopee-ads"><div><span>ROAS iklan</span><strong>{{number(shopeeStats.ads.roas)}}×</strong></div><div><span>Belanja iklan</span><strong>{{currency(shopeeStats.ads.spend)}}</strong></div><div><span>Omzet atribusi</span><strong>{{currency(shopeeStats.ads.sales)}}</strong></div></div>
              <small class="shopee-attribution-note">Atribusi channel Shopee dapat saling tumpang tindih, sehingga nilainya tidak dijumlahkan sebagai omzet total.</small>
            </section>
          </div>
        </article>
        <div>
<article v-if="false" class="panel">
<div class="panel-head">
<div>
<h3>Akses cepat</h3>
<p>Pekerjaan yang paling sering dilakukan.</p>
</div>
</div>
<div class="quick-actions">
<button @click="go('stock')">↗<span>
<b>Rekomendasi stok</b>
<small>Cek prioritas per SKU</small>
</span>
</button>
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

      <section v-if="view==='owner'" class="content shopee-page" v-cloak>
        <template v-if="shopeeStats">
          <article class="panel shopee-page-hero">
            <div><span class="eyebrow">RINGKASAN OWNER</span><h2>{{shopeeStats.meta.latestLabel}}</h2><p>Ringkasan performa toko dari {{shopeeStats.meta.periodLabel}} · data Seller Centre.</p></div>
            <span class="shopee-source-badge">{{number(shopeeStats.meta.sourceFiles.length)}} bulan terhubung</span>
          </article>
          <section class="shopee-kpis shopee-page-kpis"><article v-for="kpi in shopeeStats.latestKpis.slice(0,5)" :key="kpi.label" class="shopee-kpi"><span>{{kpi.label}}</span><strong>{{shopeeKpiValue(kpi)}}</strong><div class="shopee-kpi-foot"><b :class="shopeeDeltaTone(kpi)">{{shopeeDeltaText(kpi)}}</b><small>{{kpi.note}}</small></div></article></section>
          <section class="shopee-page-grid">
            <article class="panel shopee-card"><div class="shopee-card-head"><div><h4>Pergerakan omzet</h4><p>Bandingkan penjualan bulanan untuk menangkap arah bisnis.</p></div></div><div class="monthly-bars"><div v-for="month in shopeeStats.monthly" :key="month.month" class="monthly-bar" :class="{latest:month.month===shopeeStats.monthly.at(-1)?.month}"><span>{{currency(month.sales)}}</span><i :style="{height:Math.max(5,month.sales/Math.max(1,...shopeeStats.monthly.map(item=>item.sales))*100)+'%'}"></i><b>{{month.label}}</b><small>{{number(month.orders)}} order</small></div></div></article>
            <article class="panel shopee-card"><div class="shopee-card-head"><div><h4>Prioritas keputusan</h4><p>Sinyal paling penting dibanding bulan sebelumnya.</p></div></div><div class="shopee-insight-list"><article v-for="insight in shopeeStats.insights" :key="insight.title" class="shopee-insight" :class="insight.tone"><span>{{insight.label}}</span><h5>{{insight.title}}</h5><p>{{insight.text}}</p></article></div></article>
          </section>
        </template>
      </section>

      <section v-if="view==='products'" class="content shopee-page" v-cloak>
        <template v-if="shopeeStats"><article class="panel shopee-page-hero"><div><span class="eyebrow">PRODUK & SKU</span><h2>Produk penggerak omzet</h2><p>Top SKU pada {{shopeeStats.meta.latestLabel}} berdasarkan laporan Seller Centre.</p></div><span class="shopee-source-badge">Top 5 menyumbang {{shopeePercent(shopeeStats.topProductsShare,1)}}</span></article>
        <article class="panel shopee-card"><div class="shopee-table-wrap"><table class="shopee-product-table"><thead><tr><th>Produk / SKU</th><th>Kontribusi</th><th>Omzet</th><th>Order atribusi</th><th>Unit</th><th>Konversi</th></tr></thead><tbody><tr v-for="product in shopeeStats.topProducts" :key="product.code"><td><b>{{product.name}}</b><small>{{product.code}}</small></td><td><span class="shopee-share"><i :style="{'--share':shopeeProductBar(product)+'%'}"></i>{{shopeePercent(product.share,1)}}</span></td><td>{{currency(product.sales)}}</td><td>{{number(product.orders)}}</td><td>{{number(product.units)}}</td><td>{{shopeePercent(product.conversion,2)}}</td></tr></tbody></table></div></article>
        <section class="shopee-page-grid"><article class="panel shopee-card"><div class="shopee-card-head"><div><h4>Sumber traffic produk</h4><p>Channel yang paling banyak menghasilkan penjualan halaman produk.</p></div></div><div class="shopee-traffic-list"><div v-for="source in shopeeStats.trafficSources" :key="source.name" class="shopee-traffic-row"><span>{{source.name}}</span><i><b :style="{width:shopeeTrafficBar(source)+'%'}"></b></i><strong>{{shopeePercent(source.share,1)}}</strong></div></div></article><article class="panel shopee-card"><div class="shopee-card-head"><div><h4>Arah stok</h4><p>Gunakan halaman Rekomendasi Stok untuk menggabungkan demand SKU dengan stok aktual.</p></div></div><button type="button" @click="go('stock')">Lihat rekomendasi stok →</button></article></section></template>
      </section>

      <section v-if="view==='profit'" class="content shopee-page" v-cloak>
        <template v-if="shopeeStats"><article class="panel shopee-page-hero"><div><span class="eyebrow">PROFIT & CASHFLOW</span><h2>Omzet, biaya, dan pencairan</h2><p>Omzet memakai laporan Seller Centre; pencairan aktual memakai data Escrow yang tersinkron.</p></div></article>
        <section class="shopee-kpis shopee-page-kpis"><article class="shopee-kpi"><span>Omzet bulan terakhir</span><strong>{{currency(shopeeStats.monthly.at(-1)?.sales)}}</strong><small>{{shopeeStats.meta.latestLabel}}</small></article><article class="shopee-kpi"><span>Nilai pembatalan</span><strong>{{currency(shopeeStats.monthly.at(-1)?.cancelledSales)}}</strong><small>{{number(shopeeStats.monthly.at(-1)?.cancelledOrders)}} order dibatalkan</small></article><article class="shopee-kpi"><span>Belanja iklan</span><strong>{{currency(shopeeStats.ads.spend)}}</strong><small>ROAS {{number(shopeeStats.ads.roas)}}×</small></article><article class="shopee-kpi"><span>Pencairan escrow</span><strong>{{currency(shopeeFinance?.summary?.payout)}}</strong><small>{{number(shopeeFinance?.summary?.orders)}} order tersinkron</small></article></section>
        <article class="panel shopee-card"><div class="shopee-card-head"><div><h4>Rekonsiliasi cashflow</h4><p>Pencairan tidak selalu berada pada bulan order dibuat; gunakan sebagai pandangan arus kas, bukan margin bersih.</p></div><button type="button" class="ghost" @click="syncShopeeFinance" :disabled="financeSyncing">{{financeSyncing?'Sync...':'Sync escrow'}}</button></div><div v-if="shopeeFinance" class="cashflow-summary"><div><span>Gross</span><strong>{{currency(shopeeFinance.summary?.gross)}}</strong></div><div><span>Biaya marketplace</span><strong>{{currency(shopeeFinance.summary?.fees)}}</strong></div><div><span>Pencairan</span><strong>{{currency(shopeeFinance.summary?.payout)}}</strong></div></div><p class="shopee-attribution-note">Biaya bahan, tenaga kerja, dan ongkir di luar settlement belum termasuk. Halaman ini sengaja tidak menyebut angka tersebut sebagai laba bersih.</p></article></template>
      </section>

      <section v-if="view==='growth'" class="content shopee-page" v-cloak>
        <template v-if="shopeeStats"><article class="panel shopee-page-hero"><div><span class="eyebrow">IKLAN & PERTUMBUHAN</span><h2>Efektivitas akuisisi</h2><p>{{shopeeStats.meta.latestLabel}} · attribution antar-channel dapat saling tumpang tindih.</p></div></article>
        <section class="shopee-kpis shopee-page-kpis"><article class="shopee-kpi"><span>Omzet iklan</span><strong>{{currency(shopeeStats.ads.sales)}}</strong><small>{{shopeeStats.ads.name||'Shopee Ads'}}</small></article><article class="shopee-kpi"><span>Belanja iklan</span><strong>{{currency(shopeeStats.ads.spend)}}</strong><small>{{number(shopeeStats.ads.impressions)}} impresi</small></article><article class="shopee-kpi"><span>ROAS</span><strong>{{number(shopeeStats.ads.roas)}}×</strong><small>{{number(shopeeStats.ads.orders)}} order atribusi</small></article><article class="shopee-kpi"><span>Konversi iklan</span><strong>{{shopeePercent(shopeeStats.ads.conversion,2)}}</strong><small>Berbasis laporan iklan</small></article></section>
        <section class="shopee-page-grid"><article class="panel shopee-card"><div class="shopee-card-head"><div><h4>Atribusi channel</h4><p>Kontribusi penjualan yang dicatat per channel oleh Shopee.</p></div></div><div class="attribution-list"><div v-for="channel in shopeeStats.attribution" :key="channel.name"><span>{{channel.name}}</span><strong>{{currency(channel.sales)}}</strong></div></div></article><article class="panel shopee-card"><div class="shopee-card-head"><div><h4>Funnel toko</h4><p>Traffic dan kualitas konversi bulan terakhir.</p></div></div><div class="cashflow-summary"><div><span>Pengunjung</span><strong>{{number(shopeeStats.monthly.at(-1)?.visitors)}}</strong></div><div><span>Klik</span><strong>{{number(shopeeStats.monthly.at(-1)?.clicks)}}</strong></div><div><span>Konversi</span><strong>{{shopeePercent(shopeeStats.monthly.at(-1)?.conversion,2)}}</strong></div></div></article></section></template>
      </section>

      <section v-if="view==='orders'" class="content">
        <div class="toolbar search-toolbar">
<div class="search">⌕<input v-model="query" @input="debouncedLoad" placeholder="Cari Order SN, No. Resi, pembeli, atau produk…">
</div>
<div class="filters">
<button v-for="f in orderFilters" :class="{active:filter===f.id}" :disabled="loading" @click="changeOrderFilter(f.id)">{{ loading&&filter===f.id ? 'Memuat…' : f.label }}</button>
</div>
<div class="filters shipping-due-filter" aria-label="Filter target pengiriman">
<button :class="{active:shippingFilter==='all'}" :disabled="loading" @click="changeShippingFilter('all')">Semua</button>
<button :class="{active:shippingFilter==='due_today'}" :disabled="loading" @click="changeShippingFilter('due_today')">Kirim hari ini</button>
</div>
<div class="filters paper-order-filters" aria-label="Filter jenis kertas">
<button v-for="f in paperFilters" :class="{active:paperFilter===f.id}" :disabled="loading" @click="changePaperFilter(f.id)">{{f.label}}</button>
</div>
<button class="ghost marketplace-sync-button manual-order-add-button" @click="openManualOrder">+ Tambah order cetak</button>
<button class="ghost marketplace-sync-button" :disabled="loading" @click="openRandomPrint">? Cetak Random Pages</button>
<button class="ghost marketplace-sync-button" @click="queue('shopee_sync','')" :disabled="busy">↻ Sync Shopee</button>
<button class="ghost marketplace-sync-button" @click="queue('tiktok_sync','')" :disabled="busy">↻ Sync TikTok</button>
</div>
        <section v-if="pageData.shippingSummary" class="shipping-today-summary" :class="{'is-complete':pageData.shippingSummary.total>0&&pageData.shippingSummary.unprinted===0}" aria-live="polite">
          <div class="shipping-summary-main">
            <span class="shipping-summary-icon" aria-hidden="true">▤</span>
            <div>
              <span v-if="pageData.shippingSummary.period==='next'" class="eyebrow">TARGET KIRIM BERIKUTNYA · {{holidayDateText(pageData.shippingSummary.date)}}</span>
              <span v-else class="eyebrow">TARGET KIRIM HARI INI</span>
              <h3 v-if="pageData.shippingSummary.total">Sisa <strong>{{number(pageData.shippingSummary.unprinted)}}</strong> order belum tercetak dari total <strong>{{number(pageData.shippingSummary.total)}}</strong> order</h3>
              <h3 v-else>{{pageData.shippingSummary.period==='next'?'Belum ada order untuk target berikutnya':'Tidak ada target pengiriman hari ini'}}</h3>
              <p v-if="pageData.shippingSummary.period==='next'">Target hari ini selesai 100% · {{number(pageData.shippingSummary.todayTotal)}} order</p>
              <p v-else-if="pageData.shippingSummary.total">{{number(pageData.shippingSummary.printed)}} order sudah selesai dicetak</p>
              <p v-else>Sabtu, Minggu, dan tanggal libur tidak dihitung sebagai hari kirim.</p>
            </div>
          </div>
          <div v-if="pageData.shippingSummary.total" class="shipping-summary-progress">
            <div><span>Progres cetak</span><b>{{Math.round(pageData.shippingSummary.printed/pageData.shippingSummary.total*100)}}%</b></div>
            <span class="shipping-progress-track"><i :style="{width:Math.round(pageData.shippingSummary.printed/pageData.shippingSummary.total*100)+'%'}"></i></span>
          </div>
        </section>
        <div v-if="syncSummary" class="sync-result-box" :class="{warning:syncSummary.cancel_requests?.length}">
<div>
<b>Hasil Sync {{syncSummary.marketplace}}</b>
<span>{{syncSummary.new_orders}} order baru dari {{syncSummary.orders}} order diperiksa · {{syncSummary.labels_queued||0}} resi masuk antrean async</span>
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
<p v-if="row.unprinted_lines==0&&row.printed_at" class="printed-update-time">Dipindahkan ke Sudah Dicetak: {{timeText(row.printed_at)}}</p>
</div>
<div class="order-group-overview-actions">
<div class="order-group-status">
<span class="badge gray">{{row.status}}</span>
<span v-if="row.shipping_due_today" class="shipping-due-badge">KIRIM HARI INI</span>
<span class="badge" :class="row.unprinted_lines>0?'amber':'green'">{{row.unprinted_lines>0?row.unprinted_lines+' belum tercetak':'Cetak selesai'}}</span>
</div>
<button class="print-all-order-button" :disabled="row.items_loading||row.printing_all||!printableOrderCount(row)" @click="printAllOrder(row)">{{row.items_loading?'Memuat item…':(row.printing_all?'Mengantrekan…':('Cetak semua'+(printableOrderCount(row)?' ('+printableOrderCount(row)+')':'')))}}</button>
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
<span class="badge" :class="labelFetchBadgeClass(row)" :title="row.label_fetch_error||row.label_fetch_message||''">{{labelFetchBadgeText(row)}}</span>
<span class="badge" :class="row.resi_printed?'green':'amber'">{{row.resi_printed?'Sudah tercetak':'Belum tercetak'}}</span>
</div>
</div>
</div>
<div class="order-resi-controls">
<label v-if="row.has_label_pdf">Printer resi<select v-model="row.label_printer" aria-label="Printer resi"><option value="">Pilih printer…</option><option v-for="printer in (pageData.labelPrinters||pageData.printers)" :value="printer">{{printer}}</option></select></label>
<div class="order-resi-actions">
<button class="ghost" :disabled="row.label_fetching||labelFetchActive(row)" :title="row.label_fetch_error||row.label_fetch_message||''" @click="fetchOrderLabel(row)">{{labelFetchButtonText(row)}}</button>
<button v-if="row.has_label_pdf" class="ghost" @click="openOrderLabel(row)">Buka PDF</button>
<button v-if="row.has_label_pdf" :disabled="!row.label_printer||row.label_printing" @click="printOrderLabel(row)">{{row.label_printing?'Mengantre…':'Cetak resi'}}</button>
</div>
</div>
</section>
<div class="grouped-items">
<div v-if="row.items_loading" class="order-items-loading" role="status" aria-live="polite">
<span class="order-items-spinner" aria-hidden="true"></span>
<div><b>Memuat item order…</b><small>Detail dan pengaturan cetak sedang disiapkan.</small></div>
</div>
<div v-else-if="row.items_error" class="order-items-error">
<div><b>Item belum berhasil dimuat</b><small>{{row.items_error}}</small></div>
<button class="ghost" @click="loadOrderItems([row])">Coba lagi</button>
</div>
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
<small v-if="line.printed&&line.printed_at">Dicetak {{timeText(line.printed_at)}}</small>
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

      <section v-if="view==='stock'" class="content stock-dashboard">
        <article class="panel stock-control-panel">
          <form class="stock-controls" @submit.prevent="applyStockFilters">
            <label class="stock-search">Cari SKU / produk<input v-model="stockFilters.q" placeholder="Contoh: planner A5 atau kode SKU"></label>
            <label>Tahap<select v-model="stockFilters.priority"><option value="all">Semua tahap</option><option value="start">Mulai dulu</option><option value="next">Tahap berikut</option><option value="trial">Uji terbatas</option><option value="wait">Belum perlu</option></select></label>
            <label>Urutkan<select v-model="stockFilters.sort"><option value="score">Skor terbaik</option><option value="sales">Penjualan 30 hari</option><option value="opening">Jumlah stok awal</option><option value="trend">Tren tertinggi</option></select></label>
            <label>Stok awal untuk<input type="number" min="7" max="30" v-model.number="stockFilters.coverDays"><small>hari</small></label>
            <button type="submit" :disabled="loading">Hitung ulang</button>
          </form>
          <div class="stock-formula" v-if="stockRecommendations"><b>Cara memilih</b><span>Skor menggabungkan kecepatan jual, jumlah order, hari aktif terjual, momentum 7 hari, tren 30 hari, dan recency. Terjual, kecepatan, dan stok awal dihitung dalam pak untuk {{stockRecommendations.settings.coverDays}} hari + buffer 3 hari. Saat dicetak, 1 pak = {{stockRecommendations.settings.packSize||20}} lembar.</span></div>
        </article>

        <article v-if="stockRecommendations" class="panel stock-table-panel">
          <div class="stock-table-head"><div><h3>Shortlist stok perdana</h3><p>{{number(stockRecommendations.total)}} SKU rekomendasi berdasarkan penjualan 90 hari terakhir.</p></div><span class="stock-updated">Diperbarui {{stockRecommendations.settings.generatedText}}</span></div>
          <div class="table-wrap stock-table-wrap"><table class="stock-table">
            <thead><tr><th>Ranking</th><th>SKU & produk</th><th>Skor</th><th>Terjual<br><small>pak</small></th><th>Tren 30H</th><th>Kecepatan</th><th>Konsistensi</th><th>Stok terkini</th><th>Alasan</th><th>Stok awal</th><th>Cetak produk<br><small>1 pak = 20 lembar</small></th></tr></thead>
            <tbody>
              <tr v-if="!stockRecommendations.items.length"><td colspan="11" class="empty">Tidak ada SKU yang cocok dengan filter ini.</td></tr>
              <tr v-for="row in stockRecommendations.items" :key="row.sku" :class="'stock-row-'+row.priority">
                <td><b>#{{row.rank}}</b><span class="stock-priority" :class="row.priority"><i></i>{{stockPriorityLabel(row.priority)}}</span><small class="stock-confidence">{{stockConfidenceLabel(row.confidence)}}</small></td>
                <td class="stock-product"><button v-if="row.hasPdf" type="button" class="stock-pdf-link" @click="openStockPdf(row)"><b>{{row.productName}}</b><span>{{row.variationName||'Tanpa varian'}}</span><code>{{row.sku}}<template v-if="row.parentSku"> · Induk {{row.parentSku}}</template></code><em>Preview PDF ↗</em></button><template v-else><b>{{row.productName}}</b><span>{{row.variationName||'Tanpa varian'}}</span><code>{{row.sku}}<template v-if="row.parentSku"> · Induk {{row.parentSku}}</template></code></template><small>Terakhir laku {{row.lastSaleText}}</small></td>
                <td><strong class="stock-current">{{number(row.starterScore)}}</strong><small>dari 100</small></td>
                <td><div class="stock-sales"><b>{{number(row.sold30)}}</b><span>30H</span></div><small>{{number(row.sold7)}} / 7H · {{number(row.sold90)}} / 90H</small></td>
                <td><span class="stock-trend" :class="{up:row.trend>0,down:row.trend<0}">{{row.trend>0?'+':''}}{{number(row.trend)}}%</span><small>vs 30H sebelumnya</small></td>
                <td><b>{{number(row.dailyVelocity)}}</b><small>pak / hari</small></td>
                <td><b>{{number(row.activeDays30)}} hari</b><small>{{number(row.orders30)}} order / 30H</small></td>
                <td><strong class="inventory-stock" :class="{empty:row.stock<=0}">{{number(row.stock)}}</strong><small>pak di inventory</small></td>
                <td class="starter-reason">{{row.reason}}</td>
                <td><strong class="stock-recommendation" :class="{zero:!row.openingQty}">{{number(row.openingQty)}}</strong><small>{{row.openingQty?'pak pembukaan · '+number(row.openingSheets)+' lembar':'jangan dulu'}}</small></td>
                <td class="stock-print-cell">
                  <div v-if="row.hasPdf" class="stock-print-controls">
                    <label><span>Qty (pak)</span><input type="number" min="0" step="1" v-model.number="row.printQty" :disabled="row.printing"></label>
                    <label><span>Printer</span><select v-model="row.selectedPrinter" :disabled="row.printing"><option value="" disabled>Pilih printer</option><option v-for="printer in stockRecommendations.printers" :key="printer" :value="printer">{{printer}}</option></select></label>
                    <button type="button" @click="printStockProduct(row)" :disabled="row.printing||!row.selectedPrinter||row.printQty<1">{{row.printing?'Mengantre…':'Cetak'}}</button>
                  </div>
                  <small v-else class="stock-print-unavailable">PDF belum tersedia</small>
                </td>
              </tr>
            </tbody>
          </table></div>
          <pagination :data="stockRecommendations" @change="p=>{page=p;refresh()}"></pagination>
        </article>
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
<div class="filters shipping-due-filter" aria-label="Filter target pengiriman">
<button :class="{active:shippingFilter==='all'}" @click="changeShippingFilter('all')">Semua</button>
<button :class="{active:shippingFilter==='due_today'}" @click="changeShippingFilter('due_today')">Kirim hari ini</button>
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
<th>Jumlah Item</th>
<th>No. Resi</th>
<th>Dibuat</th>
<th>PDF</th>
<th>Status label</th>
<th>Aksi</th>
</tr>
</thead>
<tbody is="vue:transition-group" name="delete-list">
<tr v-if="loading" key="labels-loading" class="labels-loading-row">
<td colspan="8" class="empty">Memuat label…</td>
</tr>
<tr v-else-if="!pageData.items.length" key="labels-empty" class="labels-empty-row">
<td colspan="8" class="empty">{{filter==='unprinted'?'Tidak ada resi yang belum dicetak.':filter==='printed'?'Belum ada resi yang sudah dicetak.':filter==='cancelled'?'Tidak ada resi yang dibatalkan.':'Tidak ada resi yang tersedia.'}}</td>
</tr>
<tr v-for="row in pageData.items" :key="row.order_sn" :class="{'is-status-changing':row.statusChanging,'is-previewing':labelPreview?.order_sn===row.order_sn,'shipping-due-today-row':row.shipping_due_today}" @click="openLabelRow(row,$event)">
<td>
<input type="checkbox" :checked="selected.has(row.order_sn)" @change="selectOne(row.order_sn,$event.target.checked)">
</td>
<td>
<b>{{row.order_sn}}</b>
<small>{{row.status}}</small>
<small v-if="row.shipping_due_today" class="shipping-due-label">KIRIM HARI INI</small>
</td>
<td class="label-item-qty"><b>{{number(row.item_qty)}}</b><small>pcs</small></td>
<td><b class="tracking-number">{{row.tracking_number||'Belum tersedia'}}</b><small v-if="!row.tracking_number" class="badge amber">Nomor resi belum tersedia</small></td>
<td>{{row.createdText}}</td>
<td>
<button v-if="row.hasPdf" class="link" @click="openLabel(row.order_sn)">Buka PDF</button>
<span v-else class="badge" :class="labelFetchBadgeClass(row)" :title="row.label_fetch_error||row.label_fetch_message||''">{{labelFetchBadgeText(row)}}</span>
</td>
<td>
<span class="badge" :class="row.resi_printed?'green':'amber'">{{row.resi_printed?'Sudah cetak':'Belum cetak'}}</span>
<small v-if="row.resi_printed&&row.resi_printed_at">{{timeText(row.resi_printed_at)}}</small>
</td>
<td class="actions">
<button class="ghost" :disabled="labelFetches.has(row.order_sn)||labelFetchActive(row)" :title="row.label_fetch_error||row.label_fetch_message||''" @click="queue('fetch_label',row.order_sn)">{{labelFetches.has(row.order_sn)?'Mengambil…':labelFetchButtonText(row,true)}}</button>
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

      <section v-if="view==='scanner'" class="content adf-scanner-page">
        <div class="adf-scanner-grid">
          <article class="panel adf-control-card">
            <div class="panel-head">
              <div>
                <span class="eyebrow">TWAIN · WF-5790</span>
                <h3>Scan dan hitung lembar</h3>
                <p>Masukkan kertas ke ADF. Halaman blank tetap discan dan ikut dalam total.</p>
              </div>
              <span class="badge" :class="scannerData.available?'green':'red'">{{scannerData.available?'Scanner siap':'Scanner tidak siap'}}</span>
            </div>

            <div v-if="scannerData.source_error" class="scanner-inline-error">{{scannerData.source_error}}</div>
            <div class="adf-settings">
              <label>TWAIN source
                <select v-model="scannerForm.source" :disabled="scanIsActive(scannerData.current)||scannerStarting">
                  <option v-for="source in scannerData.sources" :key="source" :value="source">{{source}}</option>
                </select>
              </label>
              <label>Batas blank (%)
                <input v-model.number="scannerForm.blank_threshold" type="number" min="0.01" max="5" step="0.01" :disabled="scanIsActive(scannerData.current)||scannerStarting">
                <small>Default 0,18%. Naikkan bila halaman kosong masih dianggap tercetak.</small>
              </label>
            </div>

            <div class="adf-fixed-settings">
              <span><b>A5 landscape</b><small>Ukuran</small></span>
              <span><b>200 dpi</b><small>Resolusi</small></span>
              <span><b>Color</b><small>Mode</small></span>
              <span><b>1 sisi</b><small>ADF</small></span>
            </div>

            <button class="adf-start-button" type="button" @click="startAdfScan" :disabled="!scannerData.available||scannerStarting||scanIsActive(scannerData.current)">
              <span aria-hidden="true">▦</span>
              {{scannerStarting?'Menjalankan…':scanIsActive(scannerData.current)?'Scan sedang berjalan…':'Mulai Scan Sampai Habis'}}
            </button>
            <p class="adf-safety-note">ADF akan berhenti otomatis saat kertas habis. Jangan menutup Epson Scan 2 Utility selama proses.</p>
          </article>

          <article class="panel adf-result-card" v-if="scannerData.current">
            <div class="panel-head">
              <div>
                <span class="eyebrow">HASIL TERKINI</span>
                <h3>{{scannerData.current.message||'Menunggu scanner…'}}</h3>
                <p>{{scannerData.current.source||scannerForm.source}}</p>
              </div>
              <span class="badge" :class="scanStatusClass(scannerData.current.status)">{{scanStatusLabel(scannerData.current.status)}}</span>
            </div>

            <div v-if="scanIsActive(scannerData.current)" class="adf-live-status" role="status" aria-live="polite">
              <span class="status-spinner" aria-hidden="true"></span>
              <div><b>{{scannerData.current.message}}</b><small>{{scannerData.current.captured_pages||0}} halaman sudah diterima</small></div>
              <button class="danger-button" type="button" @click="cancelAdfScan">Batalkan</button>
            </div>
            <div v-if="scannerData.current.status==='failed'" class="scanner-inline-error">
              <b>Scan gagal</b><span>{{scannerData.current.error}}</span>
            </div>

            <div class="adf-counts">
              <div class="total"><strong>{{scannerData.current.total_sheets||scannerData.current.captured_pages||0}}</strong><span>Total lembar</span></div>
              <div><strong>{{scannerData.current.printed_pages||0}}</strong><span>Berisi</span></div>
              <div class="blank"><strong>{{scannerData.current.blank_pages||0}}</strong><span>Blank</span></div>
            </div>

            <div v-if="scannerData.current.status==='completed'" class="adf-blank-summary" :class="{clear:!scannerData.current.blank_pages}">
              <b>{{scannerData.current.blank_pages?scannerData.current.blank_pages+' halaman blank ditemukan':'Tidak ada halaman blank'}}</b>
              <span v-if="scannerData.current.blank_pages">Halaman {{scannerData.current.blank_page_numbers.join(', ')}}</span>
              <span v-else>Semua lembar terdeteksi memiliki isi.</span>
            </div>

            <div v-if="scannerData.current.pdf_url" class="adf-result-actions">
              <a class="button-link" :href="scannerData.current.pdf_url" target="_blank" rel="noopener">Buka PDF hasil ↗</a>
              <a class="button-link ghost" :href="scannerData.current.report_url" download>Unduh report CSV</a>
            </div>
          </article>

          <article v-else class="panel adf-result-card adf-empty-result">
            <span aria-hidden="true">▤</span>
            <h3>Belum ada hasil scan</h3>
            <p>Hasil hitungan dan nomor halaman blank akan muncul di sini.</p>
          </article>
        </div>

        <article v-if="scannerData.current?.pages?.length" class="panel adf-pages-panel">
          <div class="panel-head">
            <div><h3>Pemeriksaan per halaman</h3><p>Kartu merah adalah halaman yang terdeteksi blank.</p></div>
            <span class="badge gray">{{scannerData.current.pages.length}} halaman</span>
          </div>
          <div class="adf-page-list">
            <article v-for="page in scannerData.current.pages" :key="page.number" :class="{blank:page.is_blank}">
              <img :src="page.preview_url" :alt="'Preview halaman '+page.number" loading="lazy">
              <div><b>Halaman {{page.number}}</b><span>{{page.is_blank?'BLANK':'BERISI'}}</span><small>Dark pixel {{Number(page.dark_ratio).toFixed(3)}}%</small></div>
            </article>
          </div>
        </article>

        <article v-if="scannerData.jobs.length" class="panel adf-history-panel">
          <div class="panel-head"><div><h3>Riwayat scan</h3><p>Pilih sesi untuk melihat hasilnya kembali.</p></div></div>
          <div class="adf-history-list">
            <button v-for="job in scannerData.jobs" :key="job.id" type="button" :class="{active:scannerData.current?.id===job.id}" @click="selectAdfJob(job)">
              <span><b>{{new Date(job.created_at).toLocaleString('id-ID')}}</b><small>{{job.source}}</small></span>
              <span><b>{{job.total_sheets||job.captured_pages||0}} lembar</b><small :class="job.blank_pages?'has-blank':''">{{job.blank_pages||0}} blank · {{scanStatusLabel(job.status)}}</small></span>
            </button>
          </div>
        </article>
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
        <button v-if="!queuePanelOpen" class="printer-queue-fab" :class="{'has-incident':unacknowledgedPrinterIncidents().length}" type="button" aria-expanded="false" aria-controls="printer-queue-drawer" @click="openQueuePanel">
          <span class="printer-queue-fab-icon" aria-hidden="true">&#128424;</span>
          <span class="printer-queue-fab-copy">
            <b>{{unacknowledgedPrinterIncidents().length?unacknowledgedPrinterIncidents().length+' masalah':(queueWidgetAppJobs.length+(queueData.spooler?.length||0))+' job aktif'}}</b>
            <small>{{unacknowledgedPrinterIncidents().length?'Segera periksa printer':queueWidgetPrinterSummary}}</small>
          </span>
          <span class="printer-queue-fab-count">{{unacknowledgedPrinterIncidents().length||(queueWidgetAppJobs.length+(queueData.spooler?.length||0))}}</span>
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

          <div v-if="(queueData.incidents||[]).length" class="printer-queue-section printer-incident-section">
            <h3>Masalah aktif</h3>
            <div class="printer-incidents">
              <article v-for="incident in (queueData.incidents||[])" :key="incident.id" class="printer-incident-card" :class="{acknowledged:incident.acknowledged_at}">
                <div class="printer-incident-card-head">
                  <span class="status-icon" aria-hidden="true">!</span>
                  <div>
                    <b>{{incident.title}}</b>
                    <small>{{incident.printer||'Komputer host'}} · {{incident.createdText}}</small>
                  </div>
                  <span class="badge" :class="incident.acknowledged_at?'gray':'red'">{{incident.acknowledged_at?'Diperiksa':'Perlu tindakan'}}</span>
                </div>
                <p v-if="incident.order_sn||incident.original_name" class="printer-incident-document">{{incident.order_sn||incident.original_name}}</p>
                <p class="printer-incident-message">{{incident.technical_message}}</p>
                <p class="printer-incident-guidance"><b>Yang perlu dicek:</b> {{incident.guidance}}</p>
                <div class="printer-queue-job-actions">
                  <button v-if="incident.print_job_id" type="button" :disabled="!!queueActionKey" @click="retryIncident(incident)">Coba lagi</button>
                  <button v-if="!incident.acknowledged_at" class="ghost" type="button" :disabled="!!queueActionKey" @click="acknowledgeIncident(incident)">Sudah diperiksa</button>
                </div>
              </article>
            </div>
          </div>

          <div class="printer-queue-section">
            <h3>Printer aktif / tujuan</h3>
            <div class="printer-queue-printers">
              <article v-for="printer in queueWidgetPrinters" :key="printer.name">
                <span class="dot" :class="printer.active?'online':'danger'"></span>
                <div>
                  <b>{{printer.name}}</b>
                  <small>{{printer.status}} · {{printer.queue_count||0}} di spooler</small>
                </div>
                <span class="badge" :class="printer.active?'green':'red'">{{printer.active?'Aktif':'Masalah'}}</span>
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
                <div v-if="job.print_job_id&&spoolerMoveTargets(job).length" class="printer-queue-move">
                  <select v-model="job.move_printer" :disabled="!!queueActionKey" aria-label="Printer tujuan" @focus="beginQueueMoveSelection" @change="endQueueMoveSelection" @blur="endQueueMoveSelection">
                    <option value="">Pilih printer tujuan…</option>
                    <option v-for="printer in spoolerMoveTargets(job)" :key="printer.name" :value="printer.name">{{printer.name}}</option>
                  </select>
                  <button type="button" :disabled="!!queueActionKey||!job.move_printer" @click="moveSpoolerJob(job)">Pindah</button>
                </div>
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
<script src="assets/app.js?v=109">
</script>
</body>
</html>
