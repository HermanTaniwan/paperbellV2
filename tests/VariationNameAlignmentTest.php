<?php
declare(strict_types=1);
require __DIR__ . '/../src/VariationNameAlignment.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$item = ['item_sku' => 'P', 'model_response' => [
    'model' => [['model_id' => 1, 'model_sku' => 'A5', 'tier_index' => [0]]],
    'tier_variation' => [['option_list' => [['option' => 'A5 (20 Lubang)']]]],
]];
$sku = ['id' => '123', 'seller_sku' => 'PA5', 'price' => ['amount' => '10000'], 'inventory' => [['quantity' => 5]], 'sales_attributes' => [
    ['id' => '10', 'name' => 'Ukuran', 'value_id' => '20', 'value_name' => 'A6 20 Lubang', 'sku_img' => ['uri' => 'image']],
]];
$plan = VariationNameAlignment::plan($item, ['skus' => [$sku]]);
check($plan['changes'][0]['after'] === ['A5 (20 Lubang)'], 'Use Shopee tier label');
check($plan['skus'][0]['id'] === '123' && $plan['skus'][0]['seller_sku'] === 'PA5', 'Preserve SKU identity');
check(!isset($plan['skus'][0]['price']) && !isset($plan['skus'][0]['inventory']), 'Do not send stale stock/price');
check(!isset($plan['skus'][0]['sales_attributes'][0]['value_id']), 'Remove stale value ID when changing label');
check($plan['skus'][0]['sales_attributes'][0]['sku_img']['uri'] === 'image', 'Preserve option image');
$again = VariationNameAlignment::plan($item, ['skus' => $plan['skus']]);
check(!$again['changes'], 'Already aligned names are idempotent');
$twoTier = $item;
$twoTier['model_response']['tier_variation'][] = ['option_list' => [['option' => 'Blue']]];
$twoTier['model_response']['model'][0]['tier_index'] = [0, 0];
$flat = VariationNameAlignment::plan($twoTier, ['skus' => [$sku]]);
check($flat['changes'][0]['after'] === ['A5 (20 Lubang),Blue'], 'Flatten source tiers without changing target structure');
$same = $sku;
$same['sales_attributes'][0]['value_name'] = 'A5 (20 Lubang)';
$unchanged = VariationNameAlignment::plan($item, ['skus' => [$same]]);
check($unchanged['skus'][0]['sales_attributes'][0]['value_id'] === '20', 'Retain value ID for unchanged labels');
$duplicate = $item;
$duplicate['model_response']['model'][] = ['model_id' => 2, 'model_sku' => 'A5', 'tier_index' => [0]];
check(count(VariationNameAlignment::plan($duplicate, ['skus' => [$sku]], ['123' => '1'])['changes']) === 1, 'Audited model ID resolves duplicate source codes');
$empty = $item;
$empty['model_response']['model'][0]['model_sku'] = '';
check(count(VariationNameAlignment::plan($empty, ['skus' => [$sku]], ['123' => '1'])['changes']) === 1, 'Explicit model ID can map an empty source code');
foreach (['missing', 'duplicate', 'dimensions', 'shared-option'] as $case) {
    $badItem = $item; $badProduct = ['skus' => [$sku]];
    if ($case === 'missing') $badProduct['skus'][0]['seller_sku'] = '';
    if ($case === 'duplicate') $badItem['model_response']['model'][] = ['model_id' => 2, 'model_sku' => 'A5', 'tier_index' => [0]];
    if ($case === 'dimensions') $badProduct['skus'][0]['sales_attributes'][] = $sku['sales_attributes'][0];
    if ($case === 'shared-option') {
        $badItem['model_response']['model'][] = ['model_id' => 2, 'model_sku' => 'B5', 'tier_index' => [1]];
        $badItem['model_response']['tier_variation'][0]['option_list'][] = ['option' => 'B5 (26 Lubang)'];
        $badProduct['skus'][] = array_replace($sku, ['id' => '456', 'seller_sku' => 'PB5']);
    }
    $failed = false;
    try { VariationNameAlignment::plan($badItem, $badProduct); } catch (RuntimeException) { $failed = true; }
    check($failed, 'Reject unsafe plan: ' . $case);
}
echo "VariationNameAlignment tests passed\n";
