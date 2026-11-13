<?php
declare(strict_types=1);

define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY', true);
require dirname(__DIR__).'/worker/print-worker.php';

$job = ['job_type'=>'product', 'printer'=>'Brother DCP-T830DW Printer'];
foreach (['bin=1,paper=A5', 'bin=258,paper=B5', 'BIN=1,bin=258,paper=B5'] as $saved) {
    $settings = '1-2,duplexlong,noscale,'.$saved.',20x';
    $paper = str_contains($saved, 'paper=A5') ? 'A5' : 'B5';
    $expected = isWindowsPrintHost() ? '1-2,duplexlong,noscale,paper='.$paper.',20x' : $settings;
    if (brotherProductPrintSettings($job, $settings) !== $expected) {
        throw new RuntimeException('Saved Brother jobs must follow the desktop paper source while preserving paper, copies and duplex.');
    }
}
$settings = '1-2,duplexlong,noscale,paper=A5,20x';
if (brotherProductPrintSettings($job, $settings) !== $settings) throw new RuntimeException('Desktop settings changed.');
$label = array_replace($job, ['job_type'=>'label']);
$labelSettings = '1-,simplex,noscale,bin=258';
if (brotherProductPrintSettings($label, $labelSettings) !== $labelSettings) throw new RuntimeException('Brother labels must keep MP Tray.');
$epson = array_replace($job, ['printer'=>'EPSON WF-C5390 Series']);
$epsonSettings = '1-2,duplexlong,noscale,bin=1,paper=A5,20x';
if (brotherProductPrintSettings($epson, $epsonSettings) !== $epsonSettings) throw new RuntimeException('Other printers changed.');
echo "Brother product settings follow the desktop app; labels and other printers retain their trays\n";
