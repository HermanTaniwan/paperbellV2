<?php
declare(strict_types=1);
require __DIR__.'/../src/ShopeeProductClicksService.php';

function clicksExpect(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message.' Expected '.var_export($expected,true).', got '.var_export($actual,true));
}

clicksExpect(false, ShopeeProductClicksService::validDate('2026-02-30'), 'Reject impossible dates rather than rolling into March.');
clicksExpect(false, ShopeeProductClicksService::validDate('2026-2-01'), 'Require ISO dates.');
clicksExpect(true, ShopeeProductClicksService::validDate('2028-02-29'), 'Accept leap day.');
$result = ShopeeProductClicksService::series('2026-09-30','2026-10-03',[
    ['stat_date'=>'2026-09-30','clicks'=>0],
    ['stat_date'=>'2026-10-02','clicks'=>20],
    ['stat_date'=>'2026-10-03','clicks'=>10],
]);
clicksExpect(4, count($result['items']), 'Include each selected date across a month boundary.');
clicksExpect(0, $result['items'][0]['clicks'], 'A recorded zero is real data.');
clicksExpect(null, $result['items'][1]['clicks'], 'A missing observation must remain a gap, not an invented zero.');
clicksExpect(30, $result['summary']['clicks'], 'Sum observed clicks exactly once.');
clicksExpect(3, $result['summary']['days'], 'Count recorded zero days in coverage.');
clicksExpect(1, $result['summary']['missingDays'], 'Report missing dates.');
clicksExpect(10, $result['summary']['average'], 'Average over observed days, including real zeros.');
clicksExpect('2026-10-02', $result['summary']['peak']['date'], 'Return the correct peak date.');
$empty = ShopeeProductClicksService::series('2026-11-01','2026-11-02',[]);
clicksExpect(null, $empty['summary']['peak'], 'No peak when no days are recorded.');
clicksExpect(0, $empty['summary']['average'], 'An empty range must not divide by zero.');
echo "ShopeeProductClicksService tests passed\n";
