# Grafik klik produk Shopee

Halaman **Produk & SKU** menampilkan total klik harian seluruh toko dari
laporan Seller Centre, dengan rentang 7/30/90 hari, bulan data terakhir,
semua data, dan tanggal khusus. Rentang cepat berakhir pada tanggal terakhir
yang tersedia, bukan hari ini. Rincian harian per SKU tidak tersedia.

Default halaman adalah **Bulanan / 12 bulan terakhir**, termasuk bulan data
terbaru. Rentang ini otomatis bergeser saat bulan data baru tersedia. Bulan
tanpa observasi tetap ditampilkan sebagai kosong, bukan nol. Pilihan tanggal
atau rentang lain tetap berlaku selama sesi; tombol **12 bulan terakhir**
mengaktifkan kembali rentang otomatis.

Pilihan **Harian / Bulanan** mengubah interval grafik tanpa mengubah rentang
tanggal. Mode bulanan menjumlahkan klik harian per bulan kalender, hanya untuk
tanggal yang dipilih. Tooltip menyebut jumlah hari berdata dan menandai bulan
yang tidak lengkap; bulan tanpa data tetap kosong. Kartu rata-rata tetap
menggunakan hari berdata, sedangkan kartu tertinggi mengikuti interval grafik.

Data grafik disimpan terpisah dalam `shopee_product_clicks_daily` agar impor
metrik klik tidak mengubah omzet, atribusi, dan tabel produk historis lainnya.
Endpoint baca: `api.php?action=shopee_product_clicks&from=YYYY-MM-DD&to=YYYY-MM-DD`.
Tanpa tanggal, endpoint memilih 12 bulan kalender hingga bulan data terakhir
(mulai tanggal 1 pada bulan ke-12, berakhir pada tanggal data terakhir).
Hari tanpa observasi ditampilkan sebagai jeda, bukan nol; rata-rata dihitung
dari hari berdata, termasuk hari yang benar-benar mencatat nol klik.

## Memperbarui data

Siapkan CSV UTF-8 dengan kolom `date,clicks,source_file` dari baris harian
laporan `paperbell.id.shopee-shop-stats.*.xlsx`. Kolom klik adalah
**Produk Diklik / Product Clicks**, bukan pengunjung atau klik iklan.
Pastikan jumlah hari dan penjumlahan klik cocok dengan ringkasan bulan sumber.

```csv
date,clicks,source_file
2026-10-01,829,paperbell.id.shopee-shop-stats.20261001-20261031.xlsx
```

Jalankan dari direktori checkout Windows:

```powershell
C:\xampp\php\php.exe tools\import-shopee-product-clicks.php path\daily-clicks.csv
```

Importer memvalidasi seluruh CSV sebelum menulis, menolak tanggal duplikat
dan jumlah klik negatif, serta memperbarui hanya tanggal yang diberikan dalam
satu transaksi. Tanggal lainnya dipertahankan. Tabel dibuat otomatis jika
belum ada. Data penjualan, pesanan, dan antrean cetak tidak diubah.

Impor awal: Januari–Oktober 2026, 304 hari, 133.503 klik; Oktober 28.448 klik.
Data sumber tetap berada di Drive `Paperbell/Data/Shopee`; CSV impor dan
salinan data operasional tidak disimpan di Git.

Pemeriksaan:

```powershell
C:\xampp\php\php.exe tests\ShopeeProductClicksServiceTest.php
node tests\ProductClicksChartTest.js
```
