<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Database.php';
require dirname(__DIR__).'/src/ShippingFeeAlertService.php';
$config=require dirname(__DIR__).'/config.php';
$db=Database::mysql($config['mysql']);
// Session-local tables shadow production tables; no saved orders are modified.
$db->exec('CREATE TEMPORARY TABLE shopee_escrow_details(order_sn VARCHAR(64) PRIMARY KEY,order_create_time BIGINT,synced_at BIGINT,currency VARCHAR(10),raw_json LONGTEXT)');
$db->exec('CREATE TEMPORARY TABLE orders(order_sn VARCHAR(64) PRIMARY KEY,raw_json LONGTEXT)');
$insert=$db->prepare('INSERT INTO shopee_escrow_details VALUES(?,1700000000,1700000100,?,?)');
$fixtures=[
    'HIGH'=>['estimated_shipping_fee'=>13000,'actual_shipping_fee'=>125400],
    'LOW'=>['estimated_shipping_fee'=>'10000','actual_shipping_fee'=>'8000'],
    'ZERO'=>['estimated_shipping_fee'=>5000,'actual_shipping_fee'=>0],
    'FREE_ESTIMATE'=>['estimated_shipping_fee'=>0,'actual_shipping_fee'=>500],
    'SAME'=>['estimated_shipping_fee'=>5000,'actual_shipping_fee'=>5000],
    'MISSING'=>['estimated_shipping_fee'=>5000],
    'NULL'=>['estimated_shipping_fee'=>5000,'actual_shipping_fee'=>null],
    'INVALID'=>['estimated_shipping_fee'=>'oops','actual_shipping_fee'=>1000],
    'NEGATIVE'=>['estimated_shipping_fee'=>5000,'actual_shipping_fee'=>-100],
];
foreach($fixtures as $sn=>$income)$insert->execute([$sn,'IDR',json_encode(['order_income'=>$income])]);
$db->exec("INSERT INTO orders VALUES('HIGH','{\"package_list\":[{\"shipping_carrier\":\"SPX Hemat\"}]}')");
$service=new ShippingFeeAlertService($db);
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$summary=$service->summary();
check($summary['total']===4,'Compare only present, valid, different fees, including zero.');
check($summary['higher']===2 && $summary['lower']===1 && $summary['zeroActual']===1,'Count both directions and distinguish zero actual fees.');
$list=$service->listing();
check($list['items'][0]['order_sn']==='HIGH','Largest difference comes first.');
check($list['items'][0]['difference']===112400.0,'Difference is actual minus estimated, before subsidy.');
check($list['items'][0]['carrier']==='SPX Hemat','Read package carrier fallback.');
check($service->listing(1,'HIGH')['total']===1,'Search by order number.');
check($service->listing(1,"' OR 1=1 --")['total']===0,'Bind search as data.');
check($service->listing(-1)['page']===1,'Clamp negative page.');
for($i=0;$i<27;$i++)$insert->execute(['PAGE-'.$i,'IDR',json_encode(['order_income'=>['estimated_shipping_fee'=>500,'actual_shipping_fee'=>600]])]);
$page=$service->listing(999);
check($page['page']===2 && $page['pages']===2 && count($page['items'])===6,'Clamp and paginate without losing rows.');
echo "Shipping fee alert service tests passed (temporary tables only).\n";
