<?php
declare(strict_types=1);

final class LabelPdfPreparer
{
    private string $script;
    private string $banner;
    private string $cacheDir;

    public function __construct(private array $printingConfig, private string $root)
    {
        $this->script=$root.'/tools/prepare_label_pdf.py';
        $this->banner=$root.'/assets/label-unboxing.jpeg';
        $this->cacheDir=$root.'/storage/print-labels/prepared';
    }

    public function prepare(string $sourcePath,string $printer):array
    {
        $isL3210=stripos($printer,'L3210')!==false;
        if($isL3210&&PHP_OS_FAMILY!=='Windows')return$this->preparePreview($sourcePath);
        $topMarginMm=$isL3210?'4':'2';
        $driverPageMode=$isL3210?'b6':'custom';
        $fingerprint=implode('|',[
            realpath($sourcePath)?:$sourcePath,
            hash_file('sha256',$sourcePath)?:((string)filemtime($sourcePath).'|'.(string)filesize($sourcePath)),
            (string)filemtime($this->script),
            (string)filemtime($this->banner),
            $topMarginMm,
            $driverPageMode,
        ]);
        $output=$this->cacheDir.'/label-ready-'.hash('sha256',$fingerprint).'.pdf';
        return$this->prepareTo($sourcePath,$output,$topMarginMm,$driverPageMode);
    }

    public function preparePreview(string $sourcePath):array
    {
        $fingerprint=implode('|',[
            realpath($sourcePath)?:$sourcePath,
            (string)filemtime($sourcePath),
            (string)filesize($sourcePath),
            (string)filemtime($this->script),
            (string)filemtime($this->banner),
            'a6-v1',
        ]);
        $output=$this->root.'/storage/print-labels/previews/label-preview-'.hash('sha256',$fingerprint).'.pdf';
        return$this->prepareTo($sourcePath,$output,'2','a6');
    }

    private function prepareTo(string $sourcePath,string $output,string $topMarginMm,string $driverPageMode):array
    {
        foreach([$sourcePath,$this->script,$this->banner] as $required){
            if(!is_file($required))throw new RuntimeException('Bahan PDF label tidak lengkap: '.basename($required));
        }
        $outputDir=dirname($output);
        if(!is_dir($outputDir)&&!mkdir($outputDir,0775,true)&&!is_dir($outputDir)){
            throw new RuntimeException('Folder cache label siap cetak tidak dapat dibuat.');
        }
        if(is_file($output)&&filesize($output)>0)return['path'=>$output,'cached'=>true];

        $temporary=$output.'.'.bin2hex(random_bytes(5)).'.tmp';
        $command=[
            (string)($this->printingConfig['python']??'python'),
            $this->script,
            $sourcePath,
            $temporary,
            $topMarginMm,
            $driverPageMode,
        ];
        $pipes=[];
        $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$this->root,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Python penyiapan label tidak dapat dijalankan.');
        $stdout=stream_get_contents($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit=proc_close($process);
        if($exit!==0||!is_file($temporary)||filesize($temporary)<1){
            @unlink($temporary);
            $detail=trim((string)$stderr)?:trim((string)$stdout);
            throw new RuntimeException('PDF label siap cetak gagal dibuat.'.($detail!==''?' '.$detail:''));
        }
        if(!@rename($temporary,$output)){
            if(is_file($output)&&filesize($output)>0)@unlink($temporary);
            else{@unlink($temporary);throw new RuntimeException('PDF label siap cetak tidak dapat disimpan.');}
        }
        return['path'=>$output,'cached'=>false];
    }
}
