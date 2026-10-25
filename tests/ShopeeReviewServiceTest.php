<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/ShopeeReviewService.php';
function check(bool $value,string $message):void { if (!$value) throw new RuntimeException($message); }
$base = ['comment_id'=>1,'buyer_username'=>'buyer','order_sn'=>'ORDER1','create_time'=>200,'rating_star'=>5,'comment'=>'Bagus, lucu','hidden'=>false];
check(ShopeeReviewService::eligible($base,100),'Written five-star review must qualify.');
check(ShopeeReviewService::eligible(array_replace($base,['rating_star'=>4]),100),'Written four-star review must qualify.');
foreach ([1,2,3] as $stars) check(!ShopeeReviewService::eligible(array_replace($base,['rating_star'=>$stars]),100),'Low-star reviews must never qualify.');
foreach (['','   ',"\n\t"] as $text) check(!ShopeeReviewService::eligible(array_replace($base,['comment'=>$text]),100),'Empty comments must not qualify.');
check(!ShopeeReviewService::eligible($base,300),'Old reviews must not qualify.');
check(!ShopeeReviewService::eligible($base+['comment_reply'=>['reply'=>'Already done']],100),'Existing replies must not qualify.');
check(!ShopeeReviewService::eligible(array_replace($base,['hidden'=>true]),100),'Hidden reviews must not qualify.');
check(ShopeeReviewService::group($base)===ShopeeReviewService::group(array_replace($base,['comment_id'=>2,'item_id'=>999])),'Multiple products in one buyer/order must be deduplicated.');
check(ShopeeReviewService::group($base)!==ShopeeReviewService::group(array_replace($base,['order_sn'=>'ORDER2'])),'A later order must remain eligible.');
check(str_contains(ShopeeReviewService::draft(array_replace($base,['rating_star'=>4])),'Masukan'),'Four-star replies must acknowledge feedback.');
check(str_contains(ShopeeReviewService::draft(array_replace($base,['comment'=>'Bagus tapi ringkih'])),'kendala'),'Mixed feedback must get a help-oriented reply.');
check(str_contains(ShopeeReviewService::draft(array_replace($base,['comment'=>'Dapat bonus'])),'bonus'),'Bonus feedback must receive a relevant reply.');
echo "Shopee review policy checks passed.\n";
