<?php
declare(strict_types=1);

define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY',true);
require dirname(__DIR__).'/worker/print-worker.php';

function expectContains(array $values,string $expected):void
{
    if(!in_array($expected,$values,true))throw new RuntimeException("Missing CUPS option: {$expected}");
}

$options=cupsOptions('2-7,odd,duplexlong,noscale,paper=B5,bin=261','EPSON_WF_C5390_Series');
foreach(['page-ranges=2-7','page-set=odd','sides=two-sided-long-edge','scaling=100','media=Custom.182x257mm','InputSlot=Rear','cupsPrintQuality=High'] as $expected){
    expectContains($options,$expected);
}

$brotherOptions=cupsOptions('3-4,duplexlong,noscale,paper=A5','Brother_DCP_T830DW');
if(in_array('cupsPrintQuality=High',$brotherOptions,true))throw new RuntimeException('High quality must only be forced for WF printers.');
foreach(['page-ranges=3-4','Duplex=DuplexNoTumble','PageSize=A5','InputSlot=Tray1','MediaType=Stationery'] as $expected){
    expectContains($brotherOptions,$expected);
}
foreach(['sides=two-sided-long-edge','scaling=100','media=iso_a5_148x210mm'] as $unexpected){
    if(in_array($unexpected,$brotherOptions,true))throw new RuntimeException("Unexpected generic Brother option: {$unexpected}");
}

$brotherB5Options=cupsOptions('1-,simplex,noscale,paper=B5','Brother_DCP_T830DW');
foreach(['Duplex=None','PageSize=Custom.182x257mm','InputSlot=Tray1','MediaType=Stationery'] as $expected){
    expectContains($brotherB5Options,$expected);
}

$label=labelPrintSettings('Brother_DCP_T830DW');
if(PHP_OS_FAMILY!=='Windows'&&!str_contains($label,'paper=Custom.105x182mm')){
    throw new RuntimeException('Linux label media size was not added.');
}

echo "Linux print worker tests passed\n";
