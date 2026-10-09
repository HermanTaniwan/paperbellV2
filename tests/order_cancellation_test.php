<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/MarketplaceOrderSyncService.php';
require dirname(__DIR__).'/src/PrintService.php';

function check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE order_process(id INTEGER PRIMARY KEY,order_sn TEXT,order_item_id TEXT,item_key TEXT,model_sku TEXT,item_sku TEXT,item_name TEXT,model_name TEXT,qty INTEGER,printed INTEGER,printed_odd INTEGER,printed_even INTEGER,printed_at INTEGER,status TEXT)');
$insert=$db->prepare('INSERT INTO order_process VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute([1,'TEST-ORDER','item-1','SKU','','','Product','Variant',2,1,1,1,1234,'PROCESSED']);
$insert->execute([2,'TEST-ORDER','item-2','SKU','','','Product 2','Variant 2',1,0,0,0,null,'PROCESSED']);
$original=$db->query('SELECT * FROM order_process ORDER BY id')->fetchAll();
$reflection=new ReflectionClass(MarketplaceOrderSyncService::class);
$sync=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('db')->setValue($sync,$db);
$finish=$reflection->getMethod('finishSyncedLines');

foreach(['CANCELLED','CANCELED',' cancelled '] as $status){
    foreach([[],['item-1']] as $payloadIds){
        $finish->invoke($sync,'TEST-ORDER',$status,$payloadIds);
        $rows=$db->query('SELECT * FROM order_process ORDER BY id')->fetchAll();
        check(count($rows)===2,'Cancellation removed a saved item');
        foreach($rows as $index=>$row){
            check($row['status']===$status,'Saved item did not receive cancellation status');
            unset($row['status']);$expected=$original[$index];unset($expected['status']);
            check($row===$expected,'Cancellation changed item details or print history');
        }
        $printing=(new ReflectionClass(PrintService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PrintService::class,'db'))->setValue($printing,$db);
        try{$printing->queueOrderItem('TEST-ORDER',2,'Test printer','Test');throw new LogicException('Cancelled item was allowed to print');}
        catch(RuntimeException $error){check(str_contains($error->getMessage(),'dibatalkan'),'Print did not stop at the cancellation guard');}
    }
}

$finish->invoke($sync,'TEST-ORDER','PROCESSED',['item-1']);
check((int)$db->query('SELECT COUNT(*) FROM order_process')->fetchColumn()===1,'Active-order stale item cleanup stopped working');
echo "PASS: cancellation keeps items and print history, blocks printing, and preserves active-order cleanup.\n";
