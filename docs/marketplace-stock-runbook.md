# Runbook: Pembaruan stok Shopee dan TikTok Shop

Gunakan runbook ini saat diminta menyetel stok produk marketplace. Selalu tentukan produk dan SKU/variasi secara eksplisit, lalu baca ulang stok setelah perubahan. Jangan memakai kredensial OAuth secara manual; endpoint internal Paperbell mengambil token terenkripsi dan menulis audit ke `marketplace_stock_updates`.

## Alur aman

1. Ambil produk yang cocok berdasarkan judul atau SKU dan pastikan itu milik toko yang terhubung.
2. Baca stok serta ID variasi/SKU dengan endpoint read-only.
3. Ubah hanya SKU yang telah dikonfirmasi pengguna ke jumlah absolut yang diminta—bukan penambahan atau pengurangan relatif.
4. Baca ulang stok. Shopee dapat memiliki propagasi tertunda; bila respons pembaruan diterima tetapi pembacaan langsung belum berubah, cek lagi setelah kira-kira 15 detik.
5. Laporkan stok sebelum dan sesudah. Kegagalan maupun keberhasilan tersimpan di `marketplace_stock_updates`.

## Shopee

`GET /api.php?action=shopee_product_stock&item_id=<item_id>` mengembalikan judul produk dan daftar `models`; gunakan `model_id` yang dikembalikan. Produk tanpa variasi menggunakan `model_id: 0`.

Untuk mengubah stok, kirim:

```json
POST /api.php?action=shopee_update_stock
{"item_id":44767800302,"model_id":0,"quantity":2}
```

Implementasi `ShopeeStockService` memanggil `POST /api/v2/product/update_stock`. Payload Shopee wajib memakai `seller_stock` dan mempertahankan `location_id` gudang yang diberikan API; jangan menggantinya dengan `normal_stock`. Pembaruan otomatis hanya boleh dilakukan untuk satu lokasi gudang. Jika produk memakai beberapa lokasi, berhenti dan minta keputusan pengguna untuk distribusi stok per gudang.

Contoh yang telah diverifikasi: produk `44767800302`, **STICKER MYSTICAL POTION**, tanpa variasi (`model_id: 0`) berhasil disetel menjadi stok `2`.

## TikTok Shop

Cari dahulu produk yang cocok melalui `GET /api.php?action=marketplace_tiktok_catalog_sample&q=<kata_kunci>&limit=20`, lalu baca SKU dan gudangnya:

`GET /api.php?action=tiktok_product_stock&product_id=<product_id>`

Untuk mengubah satu SKU, kirim:

```json
POST /api.php?action=tiktok_update_stock
{"product_id":"1737496527229715827","sku_id":"1737496511718196595","quantity":1}
```

Implementasi `TikTokStockService` memanggil `POST /product/202309/products/{product_id}/inventory/update` dengan `skus[].inventory[].warehouse_id` yang dibaca dari TikTok. Jangan menghapus field gudang. Pembaruan otomatis hanya dilakukan untuk satu gudang; jika SKU mempunyai lebih dari satu gudang, minta pengguna menentukan pembagian stok.

Contoh yang telah diverifikasi: SKU `1737496511718196595` (`SHOPEE-44767800302`) pada produk TikTok `1737496527229715827`, **STICKER MYSTICAL POTION**, berhasil disetel dari `0` menjadi `1`.
