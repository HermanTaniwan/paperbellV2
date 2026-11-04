# Trafik bulanan per produk

Bagian **Trafik per produk** di Produk & SKU membandingkan maksimal sepuluh
produk dalam satu grafik tanpa marker bulat. Default adalah 12 bulan hingga
bulan data terbaru, dengan tiga produk dengan total klik tertinggi pada
rentang tersebut. Pilihan produk, pencarian, metrik, dan urutan tabel tetap
dipertahankan saat refresh. Klik nama produk untuk melihat satu produk;
checkbox menambah/menghapus produk dari perbandingan.

Pill di atas grafik diurutkan dari total metrik terbesar ke terkecil pada
rentang pilihan. Tooltip diurutkan berdasarkan nilai bulan yang ditunjuk.
Urutan mengikuti metrik aktif; nilai kosong di bawah nol. Warna tiap produk
tetap sama ketika urutannya berubah.

Metrik grafik: klik, dilihat, CTR, pengunjung produk, dan jumlah produk
ditambahkan ke keranjang. Filter menggunakan bulan kalender penuh, sebab
data sumber tidak menyediakan trafik harian per produk. Bulan tanpa catatan
tetap kosong; nol yang tercatat dipertahankan. Pengunjung adalah akumulasi
angka bulanan dan tidak dideduplikasi lintas bulan. CTR agregat dihitung dari
total klik dibagi total dilihat, bukan rata-rata CTR bulanan.

Tabel menampilkan **konversi checkout** (pesanan dibuat / klik), yang berbeda
dari acuan omzet pesanan dibayar. File parentskudetail yang tersedia tidak
memberikan rincian pesanan dibayar per produk. Tabel ini tidak mengubah
metrik omzet maupun atribusi pada halaman lainnya.

## Impor

Data disimpan dalam `shopee_product_traffic_monthly`, terpisah dari snapshot
top produk dan metrik total toko. Endpoint baca:
`api.php?action=shopee_product_traffic&from=YYYY-MM&to=YYYY-MM`.

Ekstrak hanya baris **produk induk** (`Kode Variasi = -`) dari sheet pertama
`parentskudetail.*.xlsx`; jangan tambahkan baris variasi. JSON berupa array:

```json
[{"month":"2026-10","code":"42067744116","name":"Kotak Tulis","sku":"WMAN","impressions":62420,"clicks":3377,"visitors":1567,"carts":972,"orders":142,"source_file":"parentskudetail.20261001_20261031.xlsx"}]
```

Nilai sumber `-`/kosong harus `null`, bukan nol. Kolom sumber masing-masing:
Jumlah Produk Dilihat, Produk Diklik, Pengunjung Produk (Kunjungan),
Dimasukkan ke Keranjang (Produk), dan Pesanan Dibuat. Jumlah keranjang
merupakan unit produk yang ditambahkan, bukan jumlah orang.

```powershell
C:\xampp\php\php.exe tools\import-shopee-product-traffic.php path\monthly-traffic.json
```

Importer memvalidasi seluruh input sebelum menulis, menolak kode/tanggal
duplikat, dan menjalankan upsert dalam transaksi. Tanggal/produk yang tidak
disertakan tidak dihapus. JSON dan workbook sumber tidak masuk Git.
Impor awal: 546 catatan produk-bulan, 82 produk unik, Januari–Oktober 2026.

```powershell
C:\xampp\php\php.exe tests\ShopeeProductTrafficServiceTest.php
node tests\ProductTrafficTest.js
```
