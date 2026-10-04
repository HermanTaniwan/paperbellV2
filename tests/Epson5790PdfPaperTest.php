<?php
declare(strict_types=1);
define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY', true);
require dirname(__DIR__).'/worker/print-worker.php';
$source = $argv[1] ?? '';
if (!is_file($source)) throw new RuntimeException('Pass an A5 PDF fixture.');
$job = ['id'=>0,'job_type'=>'product','printer'=>'WF-C5790 Series(Network)','file_path'=>$source];
$config['printing']['epson5790_printer'] = 'WF-C5790 Series(Network)';
$aliasJob = array_replace($job, ['printer'=>'EPSONA90DDD (WF-C5790 Series)']);
if (printPrinterForJob($aliasJob,'paper=A5,duplexlong') !== 'WF-C5790 Series(Network)') throw new RuntimeException('WSD queue must route to the configured validated Epson queue.');
if (printPrinterForJob(array_replace($aliasJob,['job_type'=>'label']),'paper=A5') !== $aliasJob['printer']) throw new RuntimeException('Label routing changed.');
if (printPrinterForJob(array_replace($job,['printer'=>'EPSON WF-C5390 Series']),'paper=A5') !== 'EPSON WF-C5390 Series') throw new RuntimeException('Other Epson routing changed.');
$config['printing']['epson5790_printer'] = '';
if (printPrinterForJob($aliasJob,'paper=A5') !== $aliasJob['printer']) throw new RuntimeException('Unconfigured host routing changed.');
$settings = epson5790PdfPrintSettings($job, '1-2,duplexlong,noscale,bin=1,paper=A4,20x');
if ($settings !== '1-2,paper=A5,bin=7,noscale,duplexlong,20x') throw new RuntimeException('WF-5790 Sumatra settings differ from the validated format: '.$settings);
if (epson5790PdfPrintSettings($job,'1-2,duplexlong,noscale,bin=1,paper=A4') !== '1-2,paper=A5,bin=7,noscale,duplexlong,1x') throw new RuntimeException('Single-copy WF-5790 command differs from the validated format.');
foreach (['bin=258','bin=261, BIN=1',''] as $savedTray) {
    $auto = epson5790PdfPrintSettings($job,'1-2,noscale,paper=A5,'.$savedTray);
    $bins = array_values(preg_grep('/^\s*bin=/i',explode(',',$auto)));
    if ($bins !== ['bin=7']) throw new RuntimeException('Saved tray overrides must resolve to a single Auto Select option.');
}
$job['job_type']='label';
if (epson5790PdfPrintSettings($job,'paper=A4') !== 'paper=A4') throw new RuntimeException('Label profile changed.');
$job['job_type']='product'; $job['printer']='Brother DCP-T830DW Printer';
if (epson5790PdfPrintSettings($job,'paper=A4') !== 'paper=A4') throw new RuntimeException('Other printer profile changed.');
echo "WF-5790 uses PDF A5 and Auto Select, preserving range, duplex and copies\n";
