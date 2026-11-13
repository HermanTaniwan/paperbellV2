<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
require __DIR__.'/src/Database.php';
require __DIR__.'/src/OAuthVault.php';
require __DIR__.'/src/EtsyOAuthService.php';
$config = require __DIR__.'/config.php';
try {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') throw new RuntimeException('Buka koneksi Etsy melalui URL HTTPS dari Paperbell.');
    $service = new EtsyOAuthService(Database::mysql($config['mysql']), new OAuthVault($config['oauth']['key_file']), __DIR__.'/storage/etsy/credentials.json');
    $browser = bin2hex(random_bytes(32));
    $url = $service->authorize((string)($_GET['ticket'] ?? ''), $browser);
    setcookie('paperbell_etsy_browser', $browser, ['expires'=>time()+600, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
    header('Location: '.$url, true, 303);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Koneksi Etsy belum dapat dimulai. Ulangi dari Paperbell.';
}
