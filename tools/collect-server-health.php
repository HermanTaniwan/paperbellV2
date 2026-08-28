<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$config=require $root.'/config.php';
date_default_timezone_set((string)$config['app']['timezone']);
require $root.'/src/ServerHealthService.php';

try {
    $result=(new ServerHealthService($config['server_health']??[],$root))->refresh();
    echo json_encode(['ok'=>true,'checked_at'=>$result['checked_at'],'status'=>$result['status']],JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $error) {
    $logDirectory=$root.'/storage/logs';
    if(!is_dir($logDirectory))@mkdir($logDirectory,0775,true);
    @file_put_contents($logDirectory.'/server-health.log',date('Y-m-d H:i:s').' '.$error->getMessage().PHP_EOL,FILE_APPEND|LOCK_EX);
    fwrite(STDERR,'Server Health collector gagal: '.$error->getMessage().PHP_EOL);
    exit(1);
}
