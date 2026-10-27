<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Database.php';
require dirname(__DIR__).'/src/ShippingFeeAlertService.php';
require dirname(__DIR__).'/src/AppNotificationService.php';
$config=require dirname(__DIR__).'/config.php';$db=Database::mysql($config['mysql']);
// All database writes use connection-local temporary tables.
$db->exec('CREATE TEMPORARY TABLE shopee_escrow_details(order_sn VARCHAR(64) PRIMARY KEY,order_create_time BIGINT,synced_at BIGINT,currency VARCHAR(10),raw_json LONGTEXT)');
$db->exec('CREATE TEMPORARY TABLE orders(order_sn VARCHAR(64) PRIMARY KEY,raw_json LONGTEXT)');
$db->exec('CREATE TEMPORARY TABLE shipping_fee_notification_reads(reader_key VARCHAR(100),order_sn VARCHAR(64),estimated DECIMAL(18,2),actual DECIMAL(18,2),read_at BIGINT,PRIMARY KEY(reader_key,order_sn))');
$db->exec('CREATE TEMPORARY TABLE sample_notification_reads(reader_key VARCHAR(100),notification_id VARCHAR(64),read_at BIGINT,PRIMARY KEY(reader_key,notification_id))');
$insert=$db->prepare('INSERT INTO shopee_escrow_details VALUES(?,1700000000,1700000100,?,?)');
$insert->execute(['ORDER','IDR',json_encode(['order_income'=>['estimated_shipping_fee'=>5000,'actual_shipping_fee'=>6000]])]);
$dir=sys_get_temp_dir().'/paperbell-notifications-'.bin2hex(random_bytes(8));mkdir($dir);$statePath=$dir.'/state.json';$cacheDir=$dir.'/cache';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
try{
    $state=['last_checked'=>1700000100,'pending'=>['111'=>['created_at'=>'2026-10-27T09:00:00+07:00','title'=>'Permintaan sampel gratis','content'=>'Creator meminta sampel.','url'=>'https://untrusted.example/']],'sent'=>['222'=>['at'=>1700000000]]];
    file_put_contents($statePath,json_encode($state));
    $service=new AppNotificationService($db,'test-user',$statePath,$cacheDir);
    $feed=$service->feed();
    check($feed['summary']['unread']===3,'Combine unread shipping and samples, including sent emails.');
    check($feed['items'][0]['type']==='sample','Samples appear alongside shipping differences.');
    check($feed['items'][0]['content']==='Creator meminta sampel.','Preserve available sample details.');
    check(str_starts_with($feed['items'][0]['url'],'https://seller.shopee.co.id/'),'Only allow Seller Centre links.');
    $cachePath=$cacheDir.'/'.hash('sha256','test-user').'.json';
    $cache=json_decode(file_get_contents($cachePath),true);$cache['data']['cachedAt']=123;file_put_contents($cachePath,json_encode($cache));
    check($service->feed()['cachedAt']===123,'Unchanged sources reuse the cached response.');
    $read=$service->acknowledge($feed['items']);
    check($read['summary']['unread']===0,'Reading clears both kinds of notifications.');
    check($read['summary']['total']===3,'Read notifications remain in history.');
    check((new AppNotificationService($db,'test-user',$statePath,$cacheDir))->feed()['summary']['unread']===0,'Read state survives refresh.');
    check((new AppNotificationService($db,'other-user',$statePath,$cacheDir))->feed()['summary']['unread']===3,'Caches and read receipts are isolated by user.');
    $state['sent']['333']=['at'=>1700000200];file_put_contents($statePath,json_encode($state));
    check($service->feed()['summary']['unread']===1,'A new collected sample invalidates the cache immediately.');
    $db->exec("UPDATE shopee_escrow_details SET synced_at=synced_at+1,raw_json=JSON_SET(raw_json,'$.order_income.actual_shipping_fee',7000) WHERE order_sn='ORDER'");
    check($service->feed()['summary']['unread']===2,'Updated escrow invalidates the cache and notifies again.');
    echo "Combined notification cache and sample tests passed (temporary tables only).\n";
}finally{
    foreach(['test-user','other-user'] as $reader){$path=$cacheDir.'/'.hash('sha256',$reader).'.json';if(is_file($path))unlink($path);}
    if(is_file($statePath))unlink($statePath);if(is_dir($cacheDir))rmdir($cacheDir);rmdir($dir);
}
