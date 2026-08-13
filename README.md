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

Task **Paperbell Auto Start** berjalan saat pengguna Windows yang memasangnya login dan memeriksa ulang worker setiap 1 menit. Mode ini diperlukan agar worker dapat memakai printer dan konfigurasi SumatraPDF milik pengguna tersebut. Alarm worker offline ditahan 90 detik agar watchdog sempat memulihkannya otomatis sebelum meminta tindakan pengguna.

## Ketahanan MariaDB dan backup

Untuk mencegah database dimatikan paksa saat Windows shutdown, jalankan PowerShell sebagai Administrator lalu pasang MariaDB sebagai Windows Service:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\install-mariadb-service.ps1
```

Service memakai mode **Automatic (Delayed Start)** dan recovery terbatas dua kali. Watchdog Paperbell akan memakai service ini bila tersedia, dengan fallback proses biasa hanya untuk instalasi lama.

Pasang backup harian pukul 02:00 untuk pengguna Windows aktif:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\install-backup-task.ps1
```

Backup lokal disimpan selama 30 hari di `storage/backups`; salinan bulanan terbaru disimpan di `H:\My Drive\Paperbell Backups`. Hasil startup dan backup masing-masing dicatat di `storage/logs/startup.log` dan `storage/logs/backup.log`.

Menu **Konfigurasi Printer** mendeteksi printer Windows pada komputer host. Dari menu ini dapat diatur printer yang muncul di web, printer default label, serta override mapping Brother dan EPSON L3210. Semua pengaturan disimpan di MySQL dan berlaku untuk seluruh komputer pengguna.

## Fitur operasional web

- **Data Mapping**: sinkron langsung dari Google Sheets, menampilkan jumlah mapping serta file PDF yang hilang.
- **Antrean dan alert printer**: status print worker, insiden persisten dengan diagnosis, retry manual, notifikasi browser/Windows, serta pause/resume/cancel Windows print spooler.
- **Inventory**: tambah dari Data Mapping, tambah seluruh item dari nomor order, edit/hapus stok, riwayat perubahan, dan gunakan stok langsung untuk item order.
- **Customer History**: klik nama customer pada halaman Order untuk melihat riwayat satu tahun terakhir.
- **Shopee Shop Stats**: tren omzet, funnel, pelanggan, produk, traffic, dan atribusi iklan disimpan di tabel MySQL historis dan ditampilkan pada Dashboard.
- **PDF Manual**: upload PDF ke host, buka preview browser, atur halaman/ganjil-genap/duplex/kertas/copies/printer, lalu antrekan cetak.
- **Random Pages**: mode Planner atau Loose Leaf, A5/B5, jumlah PDF, exclude kata kunci, merge halaman otomatis, lalu hasilnya masuk ke PDF Manual.
- **ADF Scanner**: scan A5 landscape dari Epson WF-C5790 melalui TWAIN sampai kertas habis, hitung total lembar, tandai halaman blank, serta simpan preview, PDF, dan report CSV.

ADF Scanner memakai Python 3.11 yang berisi `pytwain` dan Pillow. Path default mengikuti instalasi WFScanner pada host ini. Jika dipindahkan ke host lain, set `PAPERBELL_SCANNER_PYTHON_PATH` dan bila perlu `PAPERBELL_SCANNER_SOURCE`. Setting scan dibuat tetap pada ADF, single-sided, A5 landscape, color, dan 200 dpi; blank page tidak dilewati oleh driver agar tetap masuk dalam hitungan.

Cetak label/resi memakai kertas custom 105 x 182 mm, simplex, dan berwarna agar banner pemberitahuan tetap merah. Isi resi tetap maksimal 72% dan diratakan ke kiri-atas. Jika PDF marketplace terdiri dari dua halaman, ruang kosong halaman kedua dipotong dan lanjutan isinya disusun pada lembar yang sama. Gambar pemberitahuan video unboxing ditempatkan tepat di bawah isi resi. Epson L3210 memakai margin atas aman 4 mm sesuai area cetak drivernya, sementara printer lain tetap 2 mm. Untuk Brother DCP label dipaksa melalui MP Tray (`bin=258`), sedangkan Epson WF melalui Rear Paper Feed/tray atas (`bin=261`). Saat mengirim job resi, worker mengubah PrintTicket printer sementara ke 105 x 182 mm lalu mengembalikan konfigurasi sebelumnya sesudah job masuk spooler.

Job berstatus `submitted` berarti file sudah diterima Windows spooler, bukan konfirmasi sensor bahwa kertas berhasil keluar. Paperbell memantau error worker, paper jam/out-of-paper, job spooler bermasalah atau macet, dan heartbeat worker. Printer yang sekadar offline/nonaktif tetap ditampilkan di panel tetapi tidak membuat insiden. Kondisi yang jelas membutuhkan tindakan dikonfirmasi dua kali; status generic `Error + Printing` ditahan selama 90 detik dan diabaikan selama halaman masih bertambah agar gangguan driver sementara tidak menjadi alarm. Insiden aktif harus diperiksa dan di-retry manual agar tidak berisiko tercetak ganda.

Random Pages, pembacaan XLSX, dan penyiapan resi memakai Python host. Path default sudah diarahkan ke runtime yang tersedia pada komputer ini; jika dipindahkan ke host lain, set `PAPERBELL_PYTHON_PATH` ke Python yang memiliki paket `openpyxl`, `pypdf`, `pdfplumber`, dan `reportlab`. Spreadsheet mapping dapat diganti melalui `PAPERBELL_MAPPING_SHEET_ID` dan `PAPERBELL_MAPPING_SHEET_GID`.

Pada detail order, setiap item memiliki **Pengaturan cetak item** untuk memilih range halaman, semua/ganjil/genap, simplex/duplex, ukuran kertas, copies total, dan printer tujuan. Nilai awal mengikuti mapping produk × qty order dan dapat dioverride sebelum cetak per item maupun cetak seluruh order. Seperti desktop, ganjil/genap selalu memakai simplex; B5 memakai aturan printer khusus.

Untuk cetak produk melalui Brother, print worker menyelaraskan ukuran kertas driver Windows dengan ukuran job A4/A5/A6/B5 sebelum mengirim ke Tray 1, lalu mengembalikan konfigurasi driver sebelumnya. Resi Brother memakai ukuran custom 105 x 182 mm melalui MP Tray.

Login web saat ini nonaktif. Untuk mengaktifkannya kembali, set `PAPERBELL_AUTH_ENABLED=true`, lalu atur `PAPERBELL_WEB_USER` dan `PAPERBELL_WEB_PASSWORD`. Konfigurasi database dan path ada di `config.php`.

Paperbell memakai MariaDB/MySQL XAMPP standar di port `3306`. Database `paperbell` berada bersama database XAMPP lain, sedangkan backup migrasi dari server lama `3307` disimpan di `storage/backups`. File mapping produk tersimpan di tabel `data_mappings` dan `mapping_aliases`.

## Status deprecasi desktop

- Cetak produk: native web/worker, tidak bergantung desktop.
- Pengambilan PDF label otomatis berjalan async melalui label worker terpisah; pencetakan tetap melalui print worker.
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
