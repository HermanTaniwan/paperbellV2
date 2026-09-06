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

echo "Print queue service tests passed\n";
