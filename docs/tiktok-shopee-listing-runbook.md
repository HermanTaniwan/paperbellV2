# Runbook: Shopee → TikTok Shop

Gunakan alur ini untuk menyalin satu produk Shopee ke TikTok Shop serta memperbarui gambar dan variasi listing TikTok yang sudah ada. Implementasi server berada di `src/TikTokShopeeListingService.php`; endpoint internalnya didefinisikan di `api.php`.

## Prinsip operasional

- Selalu buat **draf** terlebih dahulu (`save_mode: AS_DRAFT`). Aktivasi adalah operasi terpisah dan hanya dilakukan setelah penjual menyetujui.
- Jangan menyalin token atau secret ke skrip. Ambil kredensial lewat `MarketplaceOAuthService::credentials()`; Paperbell menyimpan token terenkripsi dan me-refreshnya otomatis.
- Gunakan `idempotency_key` yang stabil, saat ini `shopee-{item_id}`, agar retry tidak membuat produk ganda.
- Produk Indonesia secara default ditujukan ke **TikTok Shop dan Tokopedia** (`listing_platforms:["TIKTOK_SHOP","TOKOPEDIA"]`). TikTok Shop Indonesia harus memakai taksonomi `v2`; jangan gunakan kembali kategori `v1` dari produk lama.
- Pembuatan draf baru dan sinkronisasi listing yang sudah ada adalah dua alur berbeda. Jangan memakai `tiktok_draft_from_shopee` untuk memperbarui listing karena dapat membuat produk duplikat.

## Urutan API

1. Shopee `GET /api/v2/product/get_item_base_info?item_id_list={item_id}` untuk judul, deskripsi, gambar, berat/dimensi dasar, dan SKU induk.
2. Shopee `GET /api/v2/product/get_model_list?item_id={item_id}` untuk harga, stok, SKU variasi, berat, dan dimensi final.
3. Unduh maksimal sembilan gambar Shopee, lalu TikTok `POST /product/202309/images/upload` dengan multipart `data` dan `use_case=MAIN_IMAGE`. Simpan URI yang dikembalikan TikTok; URL Shopee tidak boleh dipasang langsung di listing TikTok.
4. TikTok `POST /product/202309/categories/recommend` dua kali dengan `category_version=v2`, masing-masing untuk `listing_platform=TIKTOK_SHOP` dan `TOKOPEDIA`, judul, deskripsi, dan URI gambar. Pakai hanya `leaf_category_id` yang sama pada kedua respons; bila berbeda, hentikan dan minta pemetaan kategori bersama.
5. Sebelum membuat listing baru, panggil `GET /product/202309/categories/{category_id}/attributes` dan `.../rules` dengan `category_version=v2`, `locale=id-ID`, serta `shop_cipher`. Simpan/isi semua atribut `is_required`, aturan sertifikasi, size chart, COD, dan ketentuan gudang yang dikembalikan.
6. TikTok `POST /product/202309/products` dengan `save_mode=AS_DRAFT`.
7. Setelah pengguna menyetujui, TikTok `POST /product/202309/products/activate` dengan `product_ids` dan `listing_platforms:["TIKTOK_SHOP"]`.
8. Konfirmasi status melalui `GET /product/202309/products/{product_id}`. Aktivasi dapat masuk antrean audit; jangan menyebut produk sudah tayang sampai status API menunjukkan aktif.

## Memperbarui listing TikTok yang sudah ada

### Menemukan pasangan produk

1. Panggil `tiktok_image_sync_candidates&item_id={shopee_item_id}`. Endpoint ini membaca semua halaman katalog TikTok, bukan hanya 100 produk pertama.
2. Normalisasi judul menjadi huruf kecil, buang tanda baca, dan rapikan spasi. Sinkronisasi otomatis hanya boleh berjalan bila kemiripan judul minimal 95%.
3. Pastikan kandidat teratas unik dan selisihnya jelas dari kandidat lain. Catat `product_id`, status, jumlah gambar, dan jumlah SKU sebelum perubahan.
4. Gunakan `tiktok_variation_sync_plan` untuk membandingkan model Shopee dengan SKU TikTok, termasuk seller SKU, harga, stok, gudang, dan atribut variasi.

### Memperbarui gambar saja

1. Ambil maksimal sembilan URL dari `image.image_url_list` Shopee.
2. Unduh setiap gambar dan unggah ke TikTok melalui `POST /product/202309/images/upload` dengan `use_case=MAIN_IMAGE`.
3. Kirim URI hasil unggahan ke `POST /product/202509/products/{product_id}/partial_edit` dengan `save_mode=LISTING` dan `main_images`.
4. Gunakan endpoint Paperbell `tiktok_sync_images_from_shopee` setelah pasangan produk dipastikan.
5. Edit TikTok dapat diproses secara asynchronous. Respons edit berhasil dapat diikuti hasil `GET Product` yang masih lama. Jangan langsung retry karena dapat mengunggah gambar dua kali; tunggu dan baca ulang produk.
6. Verifikasi jumlah, urutan, dan isi visual gambar. URI TikTok dapat berubah setelah pemrosesan CDN sehingga kesamaan URI bukan satu-satunya bukti.

### Menambahkan variasi dan memperbarui gambar

1. Cocokkan SKU lama menggunakan `seller_sku`; pertahankan `id`, `sales_attributes`, gambar atribut, harga, inventori, dimensi, dan berat SKU lama.
2. Untuk SKU baru, isi `seller_sku`, satu set `sales_attributes`, harga integer IDR, inventori dengan `warehouse_id` aktif, dimensi, dan berat.
3. Paperbell memakai awalan produk pada seller SKU TikTok. Untuk produk Maze, polanya `WMAZ{model_sku}`. SKU sumber yang kosong harus diberi nilai eksplisit dan stabil, misalnya `WMAZCAMPURB5`.
4. Bila atribut TikTok lama mempunyai `sku_img`, setiap nilai variasi baru juga wajib mempunyai `sku_img`. Unggah gambarnya melalui endpoint upload yang sama dengan `use_case=ATTRIBUTE_IMAGE`. Gunakan gambar model Shopee bila tersedia; bila Shopee tidak menyediakannya, gunakan gambar utama sebagai fallback.
5. Kirim seluruh susunan SKU yang dipertahankan dan ditambahkan melalui `POST /product/202509/products/{product_id}/partial_edit`, bersama `main_images` bila gambar juga diperbarui.
6. Gunakan endpoint Paperbell `tiktok_sync_variations_and_images`. Pengaman implementasi kasus Maze mengharuskan tepat 2 SKU lama, 28 SKU baru, total 30 SKU, dan 7 gambar.
7. TikTok dapat menerima edit dengan `code: 0` tetapi baru menampilkan SKU beberapa puluh detik kemudian. Jika verifikasi langsung gagal, baca ulang katalog sebelum retry. Pengaman komposisi harus menghentikan retry setelah SKU baru muncul agar tidak membuat duplikat.
8. Verifikasi akhir: status produk tetap `ACTIVATE`, jumlah SKU sumber dan target sama, semua seller SKU unik dan tidak kosong, harga target sesuai sumber, stok nol tetap nol, serta jumlah dan isi gambar sesuai.

### Endpoint Paperbell yang dapat dipakai ulang

| Endpoint | Tujuan |
| --- | --- |
| `tiktok_image_sync_candidates` | Menelusuri katalog TikTok dan menilai kandidat berdasarkan judul |
| `tiktok_variation_sync_plan` | Membandingkan model Shopee dengan detail SKU TikTok tanpa menulis |
| `tiktok_sync_images_from_shopee` | Mengganti galeri gambar produk TikTok yang sudah ada |
| `tiktok_sync_variations_and_images` | Menambah variasi sekaligus menyinkronkan gambar |

Sebelum memakai endpoint tulis untuk produk lain, sesuaikan aturan prefix seller SKU dan pengaman jumlah SKU di `TikTokShopeeListingService`. Jangan mengasumsikan pola `WMAZ`, jumlah 2 + 28, atau tujuh gambar berlaku untuk produk lain.

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

## Kasus sinkronisasi yang sudah diverifikasi

- Shopee `42067744116` → TikTok `1732418161526801779`: delapan gambar utama diperbarui; judul cocok 97,2%; produk tetap aktif.
- Shopee `44411485281` → TikTok `1732124739647538547`: tujuh gambar utama diperbarui; dua SKU lama dipertahankan dan 28 SKU baru ditambahkan; total 30 SKU unik; harga Rp15.500/Rp17.500; variasi Campur B5 tetap stok 0; produk tetap aktif.
