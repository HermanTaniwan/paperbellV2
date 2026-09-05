<?php
declare(strict_types=1);

define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY',true);
require dirname(__DIR__).'/worker/print-worker.php';

function expectContains(array $values,string $expected):void
{
    if(!in_array($expected,$values,true))throw new RuntimeException("Missing CUPS option: {$expected}");
}

$options=cupsOptions('2-7,odd,duplexlong,noscale,paper=B5,bin=261','EPSON_WF_C5390_Series');
foreach(['page-ranges=2-7','page-set=odd','sides=two-sided-long-edge','scaling=100','media=B5','InputSlot=Rear'] as $expected){
    expectContains($options,$expected);
}

$label=labelPrintSettings('Brother_DCP_T830DW');
if(PHP_OS_FAMILY!=='Windows'&&!str_contains($label,'paper=Custom.105x182mm')){
    throw new RuntimeException('Linux label media size was not added.');
}

echo "Linux print worker tests passed\n";
