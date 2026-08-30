<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/CustomerLoyaltyService.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function orders(int $count, float $totalSpend, int $daysAgo, int $now, ?int $recentCount = null): array
{
    $recentCount ??= $count;
    $result = [];
    for ($i = 0; $i < $count; $i++) {
        $days = $i < $recentCount ? $daysAgo + $i : 200 + $i;
        $result[] = ['create_time' => $now - $days * 86400, 'amount' => $totalSpend / $count];
    }
    return $result;
}

$now = 2_000_000_000;

expectSame(true, CustomerLoyaltyService::isValidOrder(['status' => 'COMPLETED', 'create_time' => $now]), 'Completed orders must count.');
expectSame(true, CustomerLoyaltyService::isValidOrder(['status' => 'READY_TO_SHIP', 'create_time' => $now]), 'Paid fulfillment orders must count.');
expectSame(false, CustomerLoyaltyService::isValidOrder(['status' => 'CANCELLED', 'create_time' => $now]), 'Cancelled orders must not count.');
expectSame(false, CustomerLoyaltyService::isValidOrder(['status' => 'PENDING_PAYMENT', 'create_time' => $now]), 'Unpaid orders must not count.');

$new = CustomerLoyaltyService::summarize(orders(1, 45000, 0, $now), $now);
expectSame('new', $new['primary']['key'], 'One valid order must be New.');
expectSame(25, $new['score'], 'New customer score must include monetary and recency points.');

$returning = CustomerLoyaltyService::summarize(orders(2, 900000, 5, $now), $now);
expectSame('returning', $returning['primary']['key'], 'High spend must not promote two orders beyond Returning.');
expectSame('high-value', $returning['secondary'][0]['key'], 'High Value should still appear as context.');

$loyal = CustomerLoyaltyService::summarize(orders(4, 250000, 20, $now), $now);
expectSame(55, $loyal['score'], 'Four-order Loyal boundary should score correctly.');
expectSame('loyal', $loyal['primary']['key'], 'Four orders and score >=50 must be Loyal.');

$regular = CustomerLoyaltyService::summarize(orders(8, 500000, 10, $now), $now);
expectSame(82, $regular['score'], 'Regular score components should total correctly.');
expectSame('regular', $regular['primary']['key'], 'Eight qualifying orders must be Regular, not Bestie.');

$bestie = CustomerLoyaltyService::summarize(orders(13, 1000000, 1, $now), $now);
expectSame(100, $bestie['score'], 'Maximum loyalty score must be 100.');
expectSame('bestie', $bestie['primary']['key'], 'Thirteen qualifying orders must receive the highest badge.');
expectSame(['repeat', 'high-value'], array_column($bestie['secondary'], 'key'), 'Only two secondary badges should be shown in priority order.');

$atRisk = CustomerLoyaltyService::summarize(orders(4, 800000, 91, $now), $now);
expectSame('at-risk', $atRisk['secondary'][0]['key'], 'Day 91 with four orders must be At Risk.');
expectSame(['at-risk', 'high-value'], array_column($atRisk['secondary'], 'key'), 'At Risk must outrank High Value.');

$dormant = CustomerLoyaltyService::summarize(orders(2, 120000, 181, $now), $now);
expectSame('dormant', $dormant['secondary'][0]['key'], 'More than 180 days with repeat history must be Dormant.');

$repeatBoundary = CustomerLoyaltyService::summarize(orders(3, 99000, 88, $now, 3), $now);
expectSame('repeat', $repeatBoundary['secondary'][0]['key'], 'Three orders within 90 days must receive Repeat.');

echo "CustomerLoyaltyService tests passed\n";
