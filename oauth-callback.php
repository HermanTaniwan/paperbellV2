<?php
declare(strict_types=1);

$config=require __DIR__.'/config.php';
date_default_timezone_set($config['app']['timezone']);
require __DIR__.'/src/Database.php';
require __DIR__.'/src/OAuthVault.php';
require __DIR__.'/src/MarketplaceOAuthService.php';

$ok=false;$title='Koneksi gagal';$message='Callback OAuth tidak dapat diproses.';
try {
    $provider=strtolower(trim((string)($_GET['provider']??'')));
    $service=new MarketplaceOAuthService(Database::mysql($config['mysql']),new OAuthVault($config['oauth']['key_file']),$config['oauth']);
    $result=$service->handleCallback($provider,(string)($_GET['state']??''),$_GET);
    $ok=true;$title=($provider==='shopee'?'Shopee':'TikTok Shop').' terhubung';
    $message='Token tersimpan terenkripsi di komputer host dan akan diperbarui otomatis.';
} catch(Throwable $e) {http_response_code(400);$message=$e->getMessage();}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title)?></title><style>body{font-family:Segoe UI,Arial;background:#f5f7f4;color:#17211b;min-height:100vh;display:grid;place-items:center;margin:0}.card{background:#fff;border:1px solid #e1e7e2;border-radius:18px;padding:34px;width:min(430px,calc(100% - 50px));box-shadow:0 18px 50px #193a2820;text-align:center}.mark{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;margin:auto;background:<?=$ok?'#e2f3e9':'#f9e5e5'?>;color:<?=$ok?'#1e6c4b':'#a73d3d'?>;font-size:30px;font-weight:700}h1{font-size:23px;margin:18px 0 8px}p{color:#69746d;font-size:14px;line-height:1.55}a{display:inline-block;margin-top:12px;padding:11px 16px;border-radius:10px;background:#1e6c4b;color:#fff;text-decoration:none;font-weight:650}</style></head><body><main class="card"><div class="mark"><?=$ok?'✓':'!'?></div><h1><?=htmlspecialchars($title)?></h1><p><?=htmlspecialchars($message)?></p><a href="<?=htmlspecialchars(rtrim((string)$config['app']['base_path'],'/').'/')?>">Kembali ke Paperbell</a></main></body></html>
