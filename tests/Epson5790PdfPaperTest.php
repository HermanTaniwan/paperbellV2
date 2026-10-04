<?php
declare(strict_types=1);
define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY', true);
require dirname(__DIR__).'/worker/print-worker.php';
$source = $argv[1] ?? '';
if (!is_file($source)) throw new RuntimeException('Pass an A5 PDF fixture.');
$job = ['id'=>0,'job_type'=>'product','printer'=>'WF-C5790 Series(Network)','file_path'=>$source];
$settings = epson5790PdfPrintSettings($job, '3-4,duplexlong,noscale,bin=1,paper=A4,20x');
foreach (['3-4','duplexlong','noscale','bin=258','paper=A5','paperkind=11','20x'] as $token) {
    if (!in_array($token,explode(',',$settings),true)) throw new RuntimeException('Missing option: '.$token);
}
if (str_contains($settings,'paper=A4')) throw new RuntimeException('A4 override must not supersede PDF size.');
if (in_array('bin=1',explode(',',$settings),true)) throw new RuntimeException('Use Epson Cassette 1 kind 258, not unsupported bin 1.');
$rear = epson5790PdfPrintSettings($job,'3-4,noscale,bin=261,paper=A5');
if (!in_array('bin=261',explode(',',$rear),true)) throw new RuntimeException('Explicit rear-feed choice must be preserved.');
$job['job_type']='label';
if (epson5790PdfPrintSettings($job,'paper=A4') !== 'paper=A4') throw new RuntimeException('Label profile changed.');
$job['job_type']='product'; $job['printer']='Brother DCP-T830DW Printer';
if (epson5790PdfPrintSettings($job,'paper=A4') !== 'paper=A4') throw new RuntimeException('Other printer profile changed.');
echo "WF-5790 uses PDF A5 and preserves range, duplex, tray and copies\n";
