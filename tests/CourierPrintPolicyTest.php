<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/PrintService.php';
require_once dirname(__DIR__).'/src/PrintQueueService.php';

function check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE orders(order_sn TEXT PRIMARY KEY,raw_json TEXT)');
$db->exec('CREATE TABLE order_process(id INTEGER PRIMARY KEY,order_sn TEXT,item_key TEXT,model_sku TEXT,item_sku TEXT,item_name TEXT,model_name TEXT,qty INTEGER,printed INTEGER,printed_odd INTEGER,printed_even INTEGER,status TEXT)');
$db->exec("INSERT INTO order_process VALUES(1,'BLOCKED','SKU','','','Item','',1,0,0,0,'PROCESSED')");
$db->exec('CREATE TABLE print_jobs(id INTEGER PRIMARY KEY,order_sn TEXT,job_type TEXT,status TEXT,printer TEXT,spooler_job_id INTEGER)');
$db->exec("INSERT INTO print_jobs VALUES(1,'BLOCKED','product','failed','Test',NULL)");
$db->exec("INSERT INTO orders VALUES('BLOCKED','{}')");
$printing=(new ReflectionClass(PrintService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(PrintService::class,'db'))->setValue($printing,$db);
$queue=(new ReflectionClass(PrintQueueService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(PrintQueueService::class,'db'))->setValue($queue,$db);

foreach(['J&T Express','JNT Economy','J & T Cargo','j-t express','SPX Express','spx standard','Shopee Express','Shopee Xpress'] as $courier){
    // A safe top-level name must not hide a blocked courier inside a package.
    $raw=json_encode(['shipping_carrier'=>'JNE','package_list'=>[['shipping_provider_name'=>$courier]]]);
    check(CourierPrintPolicy::warning($raw)!=='','Missed '.$courier);
    $db->prepare('UPDATE orders SET raw_json=?')->execute([$raw]);
    foreach([
        fn()=>$printing->queueOrder('BLOCKED','Test'),
        fn()=>$printing->queueOrderItem('BLOCKED',1,'Test','Test'),
        fn()=>$printing->queueLabel('BLOCKED','Test','Test'),
        fn()=>$queue->appAction(1,'retry'),
        fn()=>CourierPrintPolicy::assertJobAllowed($db,['job_type'=>'label','order_sn'=>'BLOCKED']),
        fn()=>CourierPrintPolicy::assertJobAllowed($db,['job_type'=>'product','order_sn'=>'BLOCKED']),
    ] as $action){
        try{$action();throw new LogicException('Blocked courier could print: '.$courier);}
        catch(RuntimeException $error){check(str_contains($error->getMessage(),'Cetak item dan resi diblokir'),'Unexpected rejection: '.$error->getMessage());}
    }
    check($db->query('SELECT status FROM print_jobs')->fetchColumn()==='failed','Retry changed the blocked job');
}
foreach(['JNE','Anteraja','SiCepat','Pos Indonesia','ID Express',''] as $courier){
    $raw=json_encode(['shipping_carrier'=>$courier,'buyer_message'=>'Please use J&T or SPX']);
    check(CourierPrintPolicy::warning($raw)==='','Unrelated field or allowed courier blocked');
    $db->prepare('UPDATE orders SET raw_json=?')->execute([$raw]);
    CourierPrintPolicy::assertOrderAllowed($db,'BLOCKED');
}
check(CourierPrintPolicy::warning('invalid json')==='','Invalid JSON blocked');
CourierPrintPolicy::assertJobAllowed($db,['job_type'=>'manual','order_sn'=>'BLOCKED']);
echo "PASS: courier detection, item/order/label guards, retry, and worker guards.\n";
