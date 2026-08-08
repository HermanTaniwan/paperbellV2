<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Setup hanya boleh dijalankan dari command line.'); }

$config = require __DIR__ . '/config.php';
require __DIR__ . '/src/Database.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $mysql = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$config['mysql']['host'],$config['mysql']['port']),$config['mysql']['username'],$config['mysql']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach (['schema.sql','shopee_shop_stats_seed.sql','shopee_shop_stats_daily_seed.sql'] as $sqlFile) {
        $sql = file_get_contents(__DIR__ . '/database/' . $sqlFile);
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', (string)$sql))) as $statement) $mysql->exec($statement);
    }
    $db = Database::mysql($config['mysql']);
    echo "Paperbell Web siap.\n";
    echo "Database MySQL: {$config['mysql']['database']}\n";
    echo "Mode data: MySQL native (tanpa SQLite desktop)\n";
    echo "setup.php hanya bisa dijalankan lewat command line dan diblokir dari web.\n";
} catch(Throwable $e){http_response_code(500);echo "Setup gagal: {$e->getMessage()}\n";exit(1);}
