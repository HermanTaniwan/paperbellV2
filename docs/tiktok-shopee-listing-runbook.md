# Runbook: Shopee → TikTok Shop

Gunakan alur ini untuk menyalin satu produk Shopee ke TikTok Shop. Implementasi server berada di `src/TikTokShopeeListingService.php`; endpoint internalnya adalah `tiktok_draft_from_shopee` dan `tiktok_activate_product` di `api.php`.

## Prinsip operasional

- Selalu buat **draf** terlebih dahulu (`save_mode: AS_DRAFT`). Aktivasi adalah operasi terpisah dan hanya dilakukan setelah penjual menyetujui.
- Jangan menyalin token atau secret ke skrip. Ambil kredensial lewat `MarketplaceOAuthService::credentials()`; Paperbell menyimpan token terenkripsi dan me-refreshnya otomatis.
- Gunakan `idempotency_key` yang stabil, saat ini `shopee-{item_id}`, agar retry tidak membuat produk ganda.
- TikTok Shop Indonesia harus memakai taksonomi `v2`; jangan gunakan kembali kategori `v1` dari produk lama.
- Produk multivariasi dihentikan dengan sengaja sampai sales attributes TikTok dipetakan. Produk satu SKU dapat diproses otomatis.

## Urutan API

1. Shopee `GET /api/v2/product/get_item_base_info?item_id_list={item_id}` untuk judul, deskripsi, gambar, berat/dimensi dasar, dan SKU induk.
2. Shopee `GET /api/v2/product/get_model_list?item_id={item_id}` untuk harga, stok, SKU variasi, berat, dan dimensi final.
3. Unduh maksimal sembilan gambar Shopee, lalu TikTok `POST /product/202309/images/upload` dengan multipart `data` dan `use_case=MAIN_IMAGE`. Simpan URI yang dikembalikan TikTok; URL Shopee tidak boleh dipasang langsung di listing TikTok.
4. TikTok `POST /product/202309/categories/recommend` dengan `category_version=v2`, `listing_platform=TIKTOK_SHOP`, judul, deskripsi, dan URI gambar. Pakai `data.leaf_category_id` (atau category ID leaf yang dikembalikan), bukan hasil pencarian kata kunci.
5. Sebelum membuat listing baru, panggil `GET /product/202309/categories/{category_id}/attributes` dan `.../rules` dengan `category_version=v2`, `locale=id-ID`, serta `shop_cipher`. Simpan/isi semua atribut `is_required`, aturan sertifikasi, size chart, COD, dan ketentuan gudang yang dikembalikan.
6. TikTok `POST /product/202309/products` dengan `save_mode=AS_DRAFT`.
7. Setelah pengguna menyetujui, TikTok `POST /product/202309/products/activate` dengan `product_ids` dan `listing_platforms:["TIKTOK_SHOP"]`.
8. Konfirmasi status melalui `GET /product/202309/products/{product_id}`. Aktivasi dapat masuk antrean audit; jangan menyebut produk sudah tayang sampai status API menunjukkan aktif.

## Pemetaan field

| Shopee | TikTok |
| --- | --- |
| `item_id` | `external_product_id`, `idempotency_key`, serta audit source ID |
| `item_name` | `title` (bersihkan whitespace; panjang minimum TikTok 25 karakter) |
| `description` | `description` HTML yang aman |
| `image.image_url_list` | unggah → `main_images[].uri` |
| `model.price_info[0].original_price` | `skus[].price.amount` dengan `currency: IDR` |
| `model.model_sku` | `skus[].seller_sku` |
| `model.stock_info_v2` | `skus[].inventory[].quantity` |
| warehouse dari SKU TikTok/template | `skus[].inventory[].warehouse_id` |
| `model.weight` atau `item.weight` (gram) | `package_weight.value` dalam kilogram |
| `model.dimension` atau `item.dimension` | `package_dimensions` dalam sentimeter |
| rekomendasi kategori TikTok | `category_id`, `category_version:v2` |

## Data audit yang harus disimpan

Tambahkan tabel audit permanen sebelum menjalankan batch besar. Minimum fieldnya:

`id`, `source_provider`, `source_item_id`, `source_model_id`, `tiktok_product_id`, `tiktok_sku_id`, `idempotency_key`, `category_id`, `category_version`, `title`, `seller_sku`, `price`, `currency`, `stock`, `warehouse_id`, `image_uris_json`, `request_json` (tanpa token), `response_json` (tanpa token), `status`, `activation_requested_at`, `last_checked_at`, `last_error`, `created_by`, `created_at`, `updated_at`.

Status yang disarankan: `prepared`, `draft_created`, `activation_requested`, `pending_audit`, `active`, `failed`, `rejected`. Simpan request/response teredaksi untuk menelusuri penolakan kategori atau atribut tanpa membocorkan token.

## Signature dan keamanan

- Shopee: signature HMAC-SHA256 atas `partner_id + path + timestamp + access_token + shop_id` dengan Partner Key; kirim `partner_id`, `timestamp`, `access_token`, `shop_id`, dan `sign` di query.
- TikTok JSON: signature HMAC-SHA256 atas `app_secret + path + query-terurut + json_body + app_secret`; tambahkan `app_key`, `shop_cipher`, `timestamp`, `sign`, dan header `x-tts-access-token`.
- Upload gambar TikTok adalah multipart dan tidak menerima `shop_cipher`; signature-nya hanya memakai `app_key`, `timestamp`, dan path.
- Jangan masukkan access token, refresh token, App Secret, Partner Key, atau gambar mentah ke log audit.

## Catatan kasus Golden Flower

Produk Shopee `49867791743` dibuat sebagai draf TikTok `1737496494872692083`. Pemetaan kategori berhasil setelah memakai `POST /product/202309/categories/recommend`; kategori hasil pencarian `GET /categories` saja pernah ditolak TikTok. Aktivasi telah dikirim, tetapi status harus dipantau sampai tidak lagi `DRAFT`/`PENDING`.
