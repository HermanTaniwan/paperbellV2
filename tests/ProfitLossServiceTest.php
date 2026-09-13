<?php
declare(strict_types=1);
require __DIR__.'/../src/ProfitLossService.php';

function profitExpectSame(mixed $expected,mixed $actual,string $message): void { if($expected!==$actual) throw new RuntimeException($message.' Expected '.var_export($expected,true).', got '.var_export($actual,true)); }

$result=ProfitLossService::calculate(['shopee'=>200000.0,'tiktok'=>100000.0],120000.0,18000.0,['tiktok_fee'=>5000.0,'external_ads'=>10000.0,'shipping_packing'=>7000.0,'payroll'=>20000.0,'operations'=>5000.0,'other'=>0.0]);
profitExpectSame(115000.0,$result['netProfit'],'P&L must subtract HPP, marketplace fees, and every manual expense exactly once.');
profitExpectSame(38.33,$result['margin'],'Margin must use total cross-marketplace revenue as the denominator.');
profitExpectSame(185000.0,$result['totalCost'],'Total cost must include HPP, marketplace fees, and every manual expense exactly once.');
$zero=ProfitLossService::calculate(['shopee'=>0.0,'tiktok'=>0.0],0.0,0.0,[]);
profitExpectSame(0.0,$zero['netProfit'],'Empty month should have zero profit.');
profitExpectSame(0.0,$zero['margin'],'Empty month must not divide by zero.');
profitExpectSame('category:journal_a5',ProfitLossService::categoryFor(['sku_id'=>'J-A5','group_name'=>'Jurnal Jurnalan','paper'=>'A5'])['key'],'Single-sided journal A5 must use its category HPP.');
profitExpectSame('category:loose_leaf_b5',ProfitLossService::categoryFor(['sku_id'=>'L-B5','product_name'=>'Loose Leaf Polos','paper'=>'B5'])['key'],'Loose leaf B5 must use its category HPP.');
profitExpectSame('category:journal_a5',ProfitLossService::categoryFor(['sku_id'=>'P-A5','group_name'=>'P','paper'=>'A5'])['key'],'Planner group must resolve to the journal category.');
profitExpectSame('category:loose_leaf_a5',ProfitLossService::categoryFor(['sku_id'=>'L-A5','group_name'=>'L','paper'=>'A5'])['key'],'Loose group must resolve to the loose leaf category.');
profitExpectSame('category:cover_b5',ProfitLossService::categoryFor(['sku_id'=>'C-B5','product_name'=>'Sampul Binder','paper'=>'B5'])['key'],'Covers must separate A5 and B5 category HPP.');
$stickerOne=ProfitLossService::categoryFor(['sku_id'=>'STICKER-CAT','product_name'=>'Sticker Kucing Duduk'])['key'];
$stickerTwo=ProfitLossService::categoryFor(['sku_id'=>'OTHER-MARKETPLACE-ID','product_name'=>'Sticker Kucing Duduk'])['key'];
profitExpectSame($stickerOne,$stickerTwo,'The same sticker design from different marketplaces must share one HPP entry.');
profitExpectSame($stickerOne,ProfitLossService::categoryFor(['sku_id'=>'OTHER-VARIATION','product_name'=>'Sticker Kucing Duduk','variation_name'=>'Pack 50'])['key'],'Sticker marketplace variations must not duplicate a design-level HPP entry.');
profitExpectSame(true,str_starts_with(ProfitLossService::categoryFor(['sku_id'=>'STIKER-CAT','product_name'=>'STIKER KUCING HITAM'])['key'],'sticker:name:'),'Indonesian STIKER names must retain a distinct HPP entry.');
echo "ProfitLossService tests passed\n";
