<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/LabelPdfPreparer.php';

$root=sys_get_temp_dir().'/paperbell-label-preparer-'.bin2hex(random_bytes(5));
$source=$root.'/source.pdf';
try{
    mkdir($root.'/tools',0775,true);
    mkdir($root.'/assets',0775,true);
    mkdir($root.'/storage/print-labels/previews',0775,true);
    file_put_contents($source,'source');
    file_put_contents($root.'/tools/prepare_label_pdf.py','script');
    file_put_contents($root.'/assets/label-unboxing.jpeg','banner');
    $fingerprint=implode('|',[
        realpath($source)?:$source,
        (string)filemtime($source),
        (string)filesize($source),
        (string)filemtime($root.'/tools/prepare_label_pdf.py'),
        (string)filemtime($root.'/assets/label-unboxing.jpeg'),
        'a6-v2-scale75',
    ]);
    $expected=$root.'/storage/print-labels/previews/label-preview-'.hash('sha256',$fingerprint).'.pdf';
    file_put_contents($expected,'prepared preview');
    $result=(new LabelPdfPreparer([], $root))->preparePreview($source);
    assert($result['path']===$expected);
    assert($result['cached']===true);
    echo "LabelPdfPreparer tests passed\n";
}finally{
    if(is_dir($root)){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
        rmdir($root);
    }
}
