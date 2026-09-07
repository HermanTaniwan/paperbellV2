<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PrintQueueService.php';

$service=(new ReflectionClass(PrintQueueService::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod(PrintQueueService::class,'cupsPrintingRequests');
$requests=$method->invoke($service,implode("\n",[
    'printer EPSON_WF_C5390_Series now printing EPSON_WF_C5390_Series-68. enabled since Sun 06 Sep 2026 09:20:00 PM WIB',
    'printer Label_Printer is idle. enabled since Sun 06 Sep 2026 09:10:00 PM WIB',
    'printer Backup is now printing Backup-7 enabled since Sun 06 Sep 2026 09:22:00 PM WIB',
]));

assert($requests === [
    'EPSON_WF_C5390_Series-68'=>true,
    'Backup-7'=>true,
]);

$progressMethod=new ReflectionMethod(PrintQueueService::class,'cupsPageProgress');
$progress=$progressMethod->invoke($service,implode("\n",[
    'EPSON_WF_C5390_Series-119 www-data 3325952 Mon Sep 7 05:10:57 2026',
    '    Status: cfFilterGhostscript: Processing page 10...',
    '    Alerts: job-printing',
    'EPSON_WF_C5390_Series-120 www-data 3325952 Mon Sep 7 05:10:58 2026',
    '    Alerts: none',
]));
assert($progress === ['EPSON_WF_C5390_Series-119'=>10]);

$deviceUriMethod=new ReflectionMethod(PrintQueueService::class,'cupsDirectPrinterUri');
$deviceUri=$deviceUriMethod->invoke($service,'EPSON_WF_C5390_Series',implode("\n",[
    'device for EPSON_WF-C5390: ipp://192.168.1.6/ipp/print',
    'device for EPSON_WF_C5390_Series: implicitclass://EPSON_WF_C5390_Series/',
]));
assert($deviceUri === 'ipp://192.168.1.6/ipp/print');

$ippProgressMethod=new ReflectionMethod(PrintQueueService::class,'cupsDeviceImpressionsRows');
$ippProgress=$ippProgressMethod->invoke($service,implode("\n",[
    'job-id,job-state,job-impressions-completed,job-media-sheets-completed',
    '63,processing,10,',
    '120,pending,0,0',
]));
assert($ippProgress === 10);

$printerStateMethod=new ReflectionMethod(PrintQueueService::class,'cupsPrinterStateRows');
$printerState=$printerStateMethod->invoke($service,implode("\n",[
    'printer-state,printer-state-reasons,printer-state-message',
    '5,media-empty,Load paper in Tray 1',
]));
assert($printerState === [
    'printer-state'=>'5',
    'printer-state-reasons'=>'media-empty',
    'printer-state-message'=>'Load paper in Tray 1',
]);

$printerUriMethod=new ReflectionMethod(PrintQueueService::class,'cupsLocalPrinterUri');
assert($printerUriMethod->invoke($service,'EPSON WF-C5390') === 'ipp://localhost:631/printers/EPSON%20WF-C5390');

$completedMethod=new ReflectionMethod(PrintQueueService::class,'completedSubmittedJobIds');
$completed=$completedMethod->invoke($service,[
    ['id'=>101,'printer'=>'EPSON_WF_C5390_Series','spooler_job_id'=>68],
    ['id'=>102,'printer'=>'EPSON_WF_C5390_Series','spooler_job_id'=>69],
    ['id'=>103,'printer'=>'Legacy','spooler_job_id'=>null,'submitted_at'=>time()-601],
    ['id'=>104,'printer'=>'Unknown','spooler_job_id'=>null,'submitted_at'=>time()],
],[
    ['printer'=>'EPSON_WF_C5390_Series','job_id'=>68],
]);
assert($completed === [102,103]);

echo "Print queue service tests passed\n";
