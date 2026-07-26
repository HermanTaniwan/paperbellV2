# Paperbell Web

Web PHP + MySQL untuk mengakses data Paperbell yang sama dari beberapa komputer. MySQL menjadi sumber utama status operasional. Cetak produk dan label diproses langsung oleh worker PHP di komputer host melalui SumatraPDF, tanpa aplikasi desktop.

## Instalasi

1. Jalankan `powershell -ExecutionPolicy Bypass -File .\start-paperbell.ps1`.
2. Untuk instalasi pertama, jalankan `C:\xampp\php\php.exe setup.php` dari folder ini.
3. Buka `http://localhost/paperbell/`.
4. Aplikasi langsung terbuka tanpa login.
5. Hubungkan akun pada menu **Koneksi Marketplace**, lalu jalankan Sync Shopee/TikTok dari web.

Supaya Apache, MySQL, dan print worker otomatis aktif lagi setelah Windows restart, pasang task auto-start satu kali:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-autostart.ps1
```

Task **Paperbell Auto Start** berjalan saat pengguna Windows yang memasangnya login. Mode ini diperlukan agar worker dapat memakai printer dan konfigurasi SumatraPDF milik pengguna tersebut.

Menu **Konfigurasi Printer** mendeteksi printer Windows pada komputer host. Dari menu ini dapat diatur printer yang muncul di web, printer default label, serta override mapping Brother dan EPSON L3210. Semua pengaturan disimpan di MySQL dan berlaku untuk seluruh komputer pengguna.

## Fitur operasional web

- **Data Mapping**: sinkron langsung dari Google Sheets, menampilkan jumlah mapping serta file PDF yang hilang.
- **Antrean printer**: status print worker, retry/cancel/hapus job aplikasi, serta pause/resume/cancel Windows print spooler.
- **Inventory**: tambah dari Data Mapping, tambah seluruh item dari nomor order, edit/hapus stok, riwayat perubahan, dan gunakan stok langsung untuk item order.
- **Customer History**: klik nama customer pada halaman Order untuk melihat riwayat satu tahun terakhir.
- **PDF Manual**: upload PDF ke host, buka preview browser, atur halaman/ganjil-genap/duplex/kertas/copies/printer, lalu antrekan cetak.
- **Random Pages**: mode Planner atau Loose Leaf, A5/B5, jumlah PDF, exclude kata kunci, merge halaman otomatis, lalu hasilnya masuk ke PDF Manual.

Cetak label/resi mengikuti preset aplikasi desktop: semua halaman dicetak pada kertas A6, isi berskala 72% dan diratakan ke kiri-atas, simplex, serta hitam-putih. Untuk Brother DCP label dipaksa melalui MP Tray (`bin=258`), sedangkan Epson WF melalui Rear Paper Feed/tray atas (`bin=261`).

Random Pages dan pembacaan XLSX memakai Python host. Path default sudah diarahkan ke runtime yang tersedia pada komputer ini; jika dipindahkan ke host lain, set `PAPERBELL_PYTHON_PATH` ke Python yang memiliki paket `openpyxl` dan `pypdf`. Spreadsheet mapping dapat diganti melalui `PAPERBELL_MAPPING_SHEET_ID` dan `PAPERBELL_MAPPING_SHEET_GID`.

Pada detail order, setiap item memiliki **Pengaturan cetak item** untuk memilih range halaman, semua/ganjil/genap, simplex/duplex, ukuran kertas, copies total, dan printer tujuan. Nilai awal mengikuti mapping produk × qty order dan dapat dioverride sebelum cetak per item maupun cetak seluruh order. Seperti desktop, ganjil/genap selalu memakai simplex; B5 memakai aturan printer khusus.

Login web saat ini nonaktif. Untuk mengaktifkannya kembali, set `PAPERBELL_AUTH_ENABLED=true`, lalu atur `PAPERBELL_WEB_USER` dan `PAPERBELL_WEB_PASSWORD`. Konfigurasi database dan path ada di `config.php`.

Paperbell memakai MariaDB/MySQL XAMPP standar di port `3306`. Database `paperbell` berada bersama database XAMPP lain, sedangkan backup migrasi dari server lama `3307` disimpan di `storage/backups`. File mapping produk tersimpan di tabel `data_mappings` dan `mapping_aliases`.

## Status deprecasi desktop

- Cetak produk: native web/worker, tidak bergantung desktop.
- Pengambilan dan pencetakan PDF label: native web/worker, tidak bergantung desktop.
- Order, inventory, dan status operasional: MySQL canonical.
- Koneksi OAuth Shopee/TikTok: native web, token terenkripsi dan refresh otomatis.
- Sync order Shopee/TikTok: native PHP langsung ke API marketplace dan MySQL.
- Runtime web tidak membuka SQLite maupun bridge desktop.

## Koneksi OAuth marketplace

Buka **Koneksi Marketplace** dari sidebar. Credential aplikasi dan token disimpan terenkripsi AES-256-GCM pada komputer host; browser hanya menerima status dan metadata non-rahasia.

- Shopee: isi Partner ID/Key lalu klik **Hubungkan Shopee** untuk otorisasi ke callback web.
- TikTok Shop: isi App Key/Secret dan Service ID dari TikTok Shop Partner Center.
- Daftarkan callback URL yang ditampilkan di halaman tersebut pada dashboard developer masing-masing marketplace.
- Jika web memakai alamat LAN permanen atau domain, set `PAPERBELL_PUBLIC_URL` (contoh `http://192.168.1.10/paperbell`) agar callback konsisten.

Jangan bagikan `storage/secrets/oauth.key`. File tersebut diblokir oleh Apache dan diabaikan Git.

## Akses komputer lain

Pastikan komputer berada di jaringan yang sama, Apache diizinkan pada Windows Firewall, lalu buka `http://IP-KOMPUTER-UTAMA/paperbell/`. Komputer utama dan print worker harus tetap menyala untuk pencetakan. Aplikasi Paperbell desktop tidak diperlukan oleh runtime web.
