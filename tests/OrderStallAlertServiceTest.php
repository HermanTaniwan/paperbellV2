<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Database.php';require dirname(__DIR__).'/src/OrderStallAlertService.php';
$config=require dirname(__DIR__).'/config.php';$db=Database::mysql($config['mysql']);require __DIR__.'/OrderStallTestTables.php';
$db->exec('CREATE TEMPORARY TABLE orders(order_sn VARCHAR(100) PRIMARY KEY,status VARCHAR(50),create_time BIGINT,update_time BIGINT)');
date_default_timezone_set('Asia/Jakarta');
function ts(string $date): int{return (new DateTimeImmutable($date,new DateTimeZone('Asia/Jakarta')))->getTimestamp();}
function check(bool $value,string $message): void{if(!$value)throw new RuntimeException($message);}
check(OrderStallAlertService::businessSeconds(ts('2026-10-23 10:00'),ts('2026-10-26 10:00'),[])===86400,'Exclude all of Saturday and Sunday.');
check(OrderStallAlertService::businessSeconds(ts('2026-10-23 10:00'),ts('2026-10-27 10:00'),['2026-10-26'=>true])===86400,'Exclude configured holidays.');
check(OrderStallAlertService::businessSeconds(ts('2026-10-24 10:00'),ts('2026-10-26 01:00'),[])===3600,'Do not count weekend arrival time.');
check(OrderStallAlertService::businessSeconds(0,ts('2026-10-27'),[])===0,'Do not count missing timestamps.');
$service=new OrderStallAlertService($db);$now=ts('2026-10-27 12:00');$since=ts('2026-10-26 10:00');$insert=$db->prepare('INSERT INTO orders VALUES(?,?,?,?)');
foreach(['OLD'=>'PROCESSED','EXACT'=>'READY_TO_SHIP','SHIPPED_NOW'=>'PROCESSED','MISSING'=>'PROCESSED','RECENT_SOURCE'=>'PROCESSED','TIKTOK:1'=>'AWAITING_COLLECTION','MANUAL-1'=>'PROCESSED','DONE'=>'COMPLETED'] as $sn=>$status)$insert->execute([$sn,$status,$since-86400,$since]);
$live=['OLD'=>['status'=>'PROCESSED','update_time'=>$since],'EXACT'=>['status'=>'READY_TO_SHIP','update_time'=>$now-86400],'SHIPPED_NOW'=>['status'=>'SHIPPED','update_time'=>$now-3600],'RECENT_SOURCE'=>['status'=>'PROCESSED','update_time'=>$now-3600],'TIKTOK:1'=>['status'=>'AWAITING_COLLECTION','update_time'=>$since]];
$reader=static fn(array $sns):array=>$live;
$scan=$service->scan($reader,$now,[]);
check($scan['alerts']===2,'Only verified eligible orders older than 24 hours alert.');
$notifications=$service->notifications('test');check($notifications['unread']===2,'Initial episodes are unread.');
$service->acknowledge('test',array_column($notifications['items'],'episode_key'));
check($service->notifications('test')['unread']===0,'Viewed episodes stay read.');
$service->scan($reader,$now+3600,[]);check($service->notifications('test')['unread']===1,'Only the order newly exceeding 24 hours becomes unread at the next check.');
$service->observe('OLD','PROCESSED',$now, $since-86400,$now+3600);
$row=$db->query("SELECT status_since FROM order_status_observations WHERE order_sn='OLD'")->fetch();check((int)$row['status_since']===$since,'Unrelated source updates do not reset the status timer.');
$changed=$service->observe('OLD','READY_TO_SHIP',$now+1,$since-86400,$now+3600);
check((int)$changed['status_since']===$now+3600,'A change between eligible statuses starts a new timer.');
$service->observe('OLD','SHIPPED',$now+3600,$since-86400,$now+3600);
check($service->notifications('test')['total']===2,'A later normal sync removes resolved risk immediately.');
$service->observe('OLD','PROCESSED',$since,$since-86400,$now+4000);
check($service->notifications('test')['total']===2,'An older response cannot roll the status back.');
try{$service->scan(static function(){throw new RuntimeException('offline');},$now+5000,[]);}catch(RuntimeException $e){}
check($service->notifications('test')['total']===2,'A failed scan preserves the prior snapshot.');
check($service->scan(static function(){throw new RuntimeException('Must not query');},ts('2026-10-31 09:00'),[])['skipped']===true,'Skip scheduled checks on weekends.');
check($service->scan(static function(){throw new RuntimeException('Must not query');},$now,['2026-10-27'=>true])['skipped']===true,'Skip scheduled checks on store holidays.');
echo "Order stall calendar, status tracking and notification tests passed.\n";
