<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
require __DIR__.'/src/Database.php';
require __DIR__.'/src/OAuthVault.php';
require __DIR__.'/src/EtsyOAuthService.php';
$config = require __DIR__.'/config.php';
$ok = false;
$message = 'Koneksi Etsy belum berhasil.';
try {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') throw new RuntimeException('Callback Etsy harus dibuka melalui HTTPS.');
    $service = new EtsyOAuthService(Database::mysql($config['mysql']), new OAuthVault($config['oauth']['key_file']), __DIR__.'/storage/etsy/credentials.json');
    $result = $service->handleCallback($_GET, (string)($_COOKIE['paperbell_etsy_browser'] ?? ''));
    $ok = true;
    $message = $result['shop_name'].' terhubung. Token disimpan terenkripsi dan diperbarui otomatis. Belum ada listing yang diubah.';
} catch (Throwable $e) {
    http_response_code(400);
    $message = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Koneksi Etsy gagal. Ulangi dari halaman Koneksi Marketplace.';
}
setcookie('paperbell_etsy_browser', '', ['expires'=>time()-3600, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Koneksi Etsy · Paperbell</title>
<style>body{font-family:Segoe UI,Arial;background:#f5f7f4;color:#17211b;min-height:100vh;display:grid;place-items:center;margin:0}.card{background:white;border:1px solid #e1e7e2;border-radius:18px;padding:32px;width:min(430px,calc(100% - 80px));text-align:center}h1{font-size:24px}p{color:#57665b;line-height:1.6}a{display:inline-block;margin-top:12px;padding:12px 18px;background:#1e6c4b;color:white;border-radius:10px;text-decoration:none}</style></head>
<body><main class="card"><h1><?= $ok ? 'Etsy terhubung' : 'Koneksi belum selesai' ?></h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><a href="./#connections">Kembali ke Paperbell</a></main></body></html>
