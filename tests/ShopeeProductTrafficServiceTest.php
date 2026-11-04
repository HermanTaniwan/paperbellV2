<?php
declare(strict_types=1);
require __DIR__.'/../src/ShopeeProductTrafficService.php';
function trafficExpect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
trafficExpect(ShopeeProductTrafficService::validMonth('2026-02'),'Accept ISO month.');
trafficExpect(!ShopeeProductTrafficService::validMonth('2026-13'),'Reject invalid month.');
trafficExpect(!ShopeeProductTrafficService::validMonth('2026-2'),'Require padded month.');
$base=['product_code'=>'1','product_name'=>'Before rename','parent_sku'=>'ABC','visitors'=>50,'carts'=>20];
$result=ShopeeProductTrafficService::summarize('2025-12','2026-03',[
    $base+['month_start'=>'2026-01-01','impressions'=>1000,'clicks'=>100,'orders'=>10],
    array_replace($base,['month_start'=>'2026-02-01','product_name'=>'After rename','impressions'=>10,'clicks'=>10,'orders'=>5]),
    array_replace($base,['month_start'=>'2026-03-01','product_code'=>'2','impressions'=>0,'clicks'=>0,'orders'=>0]),
]);
trafficExpect(count($result['months'])===4,'Calendar range must include the missing first month.');
$p=$result['products'][0];
trafficExpect($p['code']==='1'&&$p['name']==='After rename','Keep stable IDs and use the latest name.');
trafficExpect(!isset($p['series']['2025-12']),'Do not invent zeros before a product has records.');
trafficExpect($p['months']===2&&$p['totals']['clicks']===110,'Aggregate only observed product months.');
trafficExpect(abs($p['totals']['ctr']-110/1010*100)<0.0001,'CTR must use weighted totals, not an average of percentages.');
trafficExpect(abs($p['totals']['conversion']-15/110*100)<0.0001,'Checkout conversion uses placed orders per click.');
trafficExpect($result['products'][1]['totals']['clicks']===0,'Preserve genuine zero clicks.');
trafficExpect($result['products'][1]['totals']['ctr']===null,'Zero denominator must not invent a rate.');
echo "ShopeeProductTrafficService tests passed\n";
