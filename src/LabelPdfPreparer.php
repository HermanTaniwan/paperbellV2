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
        foreach([$sourcePath,$this->script,$this->banner] as $required){
            if(!is_file($required))throw new RuntimeException('Bahan PDF label tidak lengkap: '.basename($required));
        }
        if(!is_dir($this->cacheDir)&&!mkdir($this->cacheDir,0775,true)&&!is_dir($this->cacheDir)){
            throw new RuntimeException('Folder cache label siap cetak tidak dapat dibuat.');
        }

        $isL3210=stripos($printer,'L3210')!==false;
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
