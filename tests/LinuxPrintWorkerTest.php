<?php
declare(strict_types=1);

define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY',true);
require dirname(__DIR__).'/worker/print-worker.php';

function expectContains(array $values,string $expected):void
{
    if(!in_array($expected,$values,true))throw new RuntimeException("Missing CUPS option: {$expected}");
}

function expectSame(array $actual,array $expected,string $message):void
{
    if($actual!==$expected)throw new RuntimeException($message.' Expected '.json_encode($expected).', got '.json_encode($actual));
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

// Multiple packs must stay together: the printer must receive an explicit
// collation request, rather than relying on the queue default (often page 1
// x N, then page 2 x N).
$command=cupsPrintCommand('EPSON_WF_C5390_Series','1-,simplex',5,'/tmp/hiragana.pdf');
expectSame($command,[
    'lp','-d','EPSON_WF_C5390_Series','-n','5',
    '-o','Collate=True',
    '-o','multiple-document-handling=separate-documents-collated-copies',
    '-o','page-ranges=1-',
    '-o','sides=one-sided',
    '-o','cupsPrintQuality=High',
    '/tmp/hiragana.pdf',
],'CUPS copies command is not collated.');

$brotherB5Options=cupsOptions('1-,simplex,noscale,paper=B5','Brother_DCP_T830DW');
foreach(['Duplex=None','PageSize=Custom.182x257mm','InputSlot=Tray1','MediaType=Stationery'] as $expected){
    expectContains($brotherB5Options,$expected);
}

$label=labelPrintSettings('Brother_DCP_T830DW');
if(PHP_OS_FAMILY!=='Windows'&&!str_contains($label,'paper=Custom.105x182mm')){
    throw new RuntimeException('Linux label media size was not added.');
}

$l3210Label=labelPrintSettings('L3210-Series');
if(PHP_OS_FAMILY!=='Windows'){
    if(str_contains($l3210Label,'paper=Custom.105x182mm'))throw new RuntimeException('L3210 must use its proven A6 profile instead of a custom media size.');
    $l3210Options=cupsOptions($l3210Label,'L3210-Series');
    foreach(['PageSize=A6','MediaType=PLAIN_NORMAL','Ink=MONO','print-scaling=none'] as $expected)expectContains($l3210Options,$expected);
    foreach(['PageSize=B6','scaling=100'] as $unexpected)if(in_array($unexpected,$l3210Options,true))throw new RuntimeException("Unexpected legacy L3210 option: {$unexpected}");
}

echo "Linux print worker tests passed\n";
