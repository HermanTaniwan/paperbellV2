<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/DashboardAnalyticsService.php';

function analyticsExpectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

$from = new DateTimeImmutable('2026-08-20');
$to = new DateTimeImmutable('2026-09-02');
[$previousFrom, $previousTo] = DashboardAnalyticsService::previousPeriod($from, $to);

analyticsExpectSame('2026-08-06', $previousFrom->format('Y-m-d'), 'Previous period must use the same inclusive day count.');
analyticsExpectSame('2026-08-19', $previousTo->format('Y-m-d'), 'Previous period must end immediately before the active period.');

[$previousMtdFrom, $previousMtdTo] = DashboardAnalyticsService::previousMonthToDate(
    new DateTimeImmutable('2026-09-01'),
    new DateTimeImmutable('2026-09-05')
);
analyticsExpectSame('2026-08-01', $previousMtdFrom->format('Y-m-d'), 'MTD-1 must start on the first day of the previous month.');
analyticsExpectSame('2026-08-05', $previousMtdTo->format('Y-m-d'), 'MTD-1 must end on the equivalent elapsed calendar day.');

[$clampedMtdFrom, $clampedMtdTo] = DashboardAnalyticsService::previousMonthToDate(
    new DateTimeImmutable('2026-03-01'),
    new DateTimeImmutable('2026-03-31')
);
analyticsExpectSame('2026-02-01', $clampedMtdFrom->format('Y-m-d'), 'MTD-1 must preserve the previous month boundary.');
analyticsExpectSame('2026-02-28', $clampedMtdTo->format('Y-m-d'), 'MTD-1 must clamp to a shorter previous month.');

$counts = [
    '2026-08-20' => [
        'shopee' => ['orders' => 2, 'items' => 5, 'revenue' => 150000, 'pricedOrders' => 2, 'payoutRevenue' => 100000, 'matchedPayout' => 80000],
        'tiktok' => ['orders' => 1, 'items' => 2, 'revenue' => 50000, 'pricedOrders' => 1],
    ],
    '2026-08-19' => [
        'shopee' => ['orders' => 1, 'items' => 2, 'revenue' => 90000, 'pricedOrders' => 1, 'payoutRevenue' => 90000, 'matchedPayout' => 63000],
    ],
];
$escrow = [
    '2026-08-20' => ['orders' => 2, 'payout' => 120000, 'fees' => 10000],
    '2026-08-19' => ['orders' => 1, 'payout' => 70000, 'fees' => 20000],
];
$holidays = ['2026-08-21' => true, '2026-08-18' => true];

$current = DashboardAnalyticsService::summarize($from, $to, $counts, $escrow, $holidays);
$previous = DashboardAnalyticsService::summarize($previousFrom, $previousTo, $counts, $escrow, $holidays, false);

analyticsExpectSame(14, $current['summary']['rangeDays'], 'Active range must remain inclusive.');
analyticsExpectSame(13, $current['summary']['operatingDays'], 'Active holidays must be excluded from operating-day averages.');
analyticsExpectSame(3, $current['summary']['total'], 'Current total orders must include both marketplaces.');
analyticsExpectSame(7, $current['summary']['items'], 'Current item quantity must include both marketplaces.');
analyticsExpectSame(200000.0, $current['summary']['revenue'], 'Current revenue must aggregate both marketplaces.');
analyticsExpectSame(120000.0, $current['summary']['shopeePayout'], 'Current payout must come from escrow data.');
analyticsExpectSame([], $previous['items'], 'Previous daily rows are not needed in the response payload.');

$comparison = DashboardAnalyticsService::comparison(
    $current['summary'],
    $previous['summary'],
    $from,
    $to,
    $previousFrom,
    $previousTo,
    ['shopeeFees']
);

analyticsExpectSame(14, $comparison['current']['days'], 'Current comparison label must state its exact duration.');
analyticsExpectSame('20 Agu 2026 – 2 Sep 2026', $comparison['current']['label'], 'Current period label must be unambiguous.');
analyticsExpectSame('6 Agu 2026 – 19 Agu 2026', $comparison['previous']['label'], 'Previous period label must be unambiguous.');
analyticsExpectSame(2.0, $comparison['metrics']['total']['delta'], 'Absolute order delta must compare equal periods.');
analyticsExpectSame(2.0, $comparison['metrics']['total']['absoluteDelta'], 'Absolute delta must be available without losing direction.');
analyticsExpectSame(200.0, $comparison['metrics']['total']['percentChange'], 'Order percentage must use the previous value as baseline.');
analyticsExpectSame('up', $comparison['metrics']['total']['direction'], 'Positive order delta must point up.');
analyticsExpectSame('positive', $comparison['metrics']['total']['tone'], 'Higher orders must use a positive tone.');
analyticsExpectSame('positive', $comparison['metrics']['shopeeFees']['tone'], 'Lower marketplace fees must be positive when configured as lower-is-better.');

$mtdComparison = DashboardAnalyticsService::comparison(
    ['revenue' => 150000],
    ['revenue' => 100000],
    new DateTimeImmutable('2026-09-01'),
    new DateTimeImmutable('2026-09-05'),
    $previousMtdFrom,
    $previousMtdTo,
    [],
    'mtd'
);
analyticsExpectSame('mtd', $mtdComparison['mode'], 'MTD comparison mode must be explicit in the API payload.');
analyticsExpectSame(50.0, $mtdComparison['metrics']['revenue']['percentChange'], 'MTD revenue must compare with MTD-1 revenue.');

$zeroBaseline = DashboardAnalyticsService::comparison(
    ['total' => 5],
    ['total' => 0],
    $from,
    $to,
    $previousFrom,
    $previousTo
);
analyticsExpectSame(null, $zeroBaseline['metrics']['total']['percentChange'], 'Zero baselines must never produce infinity or NaN.');
analyticsExpectSame('not_comparable', $zeroBaseline['metrics']['total']['percentStatus'], 'A non-zero value over a zero baseline must be explicitly non-comparable.');
analyticsExpectSame('unchanged_zero', $zeroBaseline['metrics']['revenue']['percentStatus'], 'Two zero values must be reported as unchanged zero.');

analyticsExpectSame(100000.0, $current['summary']['shopeePayoutRevenue'], 'Only matched Shopee revenue belongs in the denominator.');
analyticsExpectSame(80.0, $current['summary']['shopeePayoutPercent'], 'Ratio must exclude unmatched Shopee revenue, TikTok revenue and unrelated escrow payouts.');
analyticsExpectSame(70.0, $comparison['metrics']['shopeePayoutPercent']['previous'], 'Previous period must use its own matched orders.');
analyticsExpectSame(10.0, $comparison['metrics']['shopeePayoutPercent']['delta'], 'Ratio difference must compare period ratios.');
analyticsExpectSame(12000.0, DashboardAnalyticsService::shopeeOrderRevenue(['total_amount'=>12000, 'item_list'=>[['model_original_price'=>9000]]]), 'Primary revenue takes priority.');
analyticsExpectSame(15000.0, DashboardAnalyticsService::shopeeOrderRevenue(['item_list'=>[['model_discounted_price'=>5000, 'model_original_price'=>8000, 'model_quantity_purchased'=>3]]]), 'Missing primary revenue falls back to discounted price times quantity.');
$empty = DashboardAnalyticsService::summarize($from, $to, [], [], []);
analyticsExpectSame(null, $empty['summary']['shopeePayoutPercent'], 'Missing payout revenue must not display a misleading zero percent.');
$zeroPayout = DashboardAnalyticsService::summarize($from, $to, ['2026-08-20'=>['shopee'=>['payoutRevenue'=>100000, 'matchedPayout'=>0]]], [], []);
analyticsExpectSame(0.0, $zeroPayout['summary']['shopeePayoutPercent'], 'An available zero payout is a valid zero percent.');
analyticsExpectSame('unavailable', $zeroBaseline['metrics']['shopeePayoutPercent']['percentStatus'], 'Missing ratio data must not produce a comparison.');

echo "DashboardAnalyticsService tests passed\n";
