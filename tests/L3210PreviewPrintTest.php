<?php
declare(strict_types=1);

define('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY', true);
require dirname(__DIR__).'/worker/print-worker.php';

$source = $argv[1] ?? '';
if (!is_file($source)) throw new RuntimeException('Pass a label PDF fixture as the first argument.');
$preview = $labelPreparer->preparePreview($source);
$prepared = $labelPreparer->prepare($source, 'EPSON L3210 Series');
$workerPath = prepareLabelPdf(['id'=>0,'printer'=>'EPSON L3210 Series','file_path'=>$source]);
if ($preview['path'] !== $prepared['path'] || $preview['path'] !== $workerPath) {
    throw new RuntimeException('L3210 must submit the same PDF file served by the preview.');
}
$settings = explode(',', labelPrintSettings('EPSON L3210 Series'));
foreach (['paper=A6','noscale','simplex','monochrome'] as $expected) {
    if (!in_array($expected,$settings,true)) throw new RuntimeException('Missing setting: '.$expected);
}
if (in_array('paperkind=88',$settings,true)) throw new RuntimeException('Legacy B6 paper override must not be used.');
echo "L3210 preview and print use identical PDF and A6 without scaling\n";
