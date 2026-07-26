<?php
declare(strict_types=1);

final class PrintQueueService
{
    private string $spoolerCacheFile;

    public function __construct(private PDO $db)
    {
        $this->spoolerCacheFile=dirname(__DIR__).'/storage/printer-spooler-cache.json';
    }

    public function overview():array
    {
        $jobs=$this->db->query("SELECT id,job_type,order_sn,original_name,status,message,error,printer,print_settings,copies,attempts,created_by,created_at,started_at,completed_at FROM (SELECT p.*,m.original_name FROM print_jobs p LEFT JOIN manual_pdfs m ON p.job_type IN ('manual','random') AND p.file_path=m.file_path) x ORDER BY id DESC LIMIT 100")->fetchAll();
        foreach($jobs as &$row){$row['createdText']=date('d M Y H:i',(int)$row['created_at']);}
        $spooler=$this->spoolerState();
        $heartbeat=(int)($this->meta('print_worker_heartbeat')?:0);return['items'=>$jobs,'spooler'=>$spooler['jobs'],'printers'=>$spooler['printers'],'worker'=>['heartbeat'=>$heartbeat,'online'=>$heartbeat>time()-10,'text'=>$heartbeat?date('d M Y H:i:s',$heartbeat):'Belum pernah aktif']];
    }

    public function appAction(int $id,string $action):array
    {
        $stmt=$this->db->prepare('SELECT status FROM print_jobs WHERE id=?');$stmt->execute([$id]);$status=(string)($stmt->fetchColumn()?:'');if($status==='')throw new RuntimeException('Job cetak tidak ditemukan.');
        if($action==='cancel'){if(!in_array($status,['queued','processing'],true))throw new RuntimeException('Job ini tidak dapat dibatalkan.');$next=$status==='queued'?'cancelled':'cancel_requested';$stmt=$this->db->prepare("UPDATE print_jobs SET status=?,message='Dibatalkan pengguna',completed_at=? WHERE id=?");$stmt->execute([$next,time(),$id]);}
        elseif($action==='retry'){if(!in_array($status,['failed','cancelled','cancel_requested'],true))throw new RuntimeException('Hanya job gagal/dibatalkan yang dapat diulang.');$stmt=$this->db->prepare("UPDATE print_jobs SET status='queued',message='Menunggu worker printer',error='',started_at=NULL,completed_at=NULL WHERE id=?");$stmt->execute([$id]);}
        elseif($action==='delete'){if(in_array($status,['queued','processing'],true))throw new RuntimeException('Job aktif tidak dapat dihapus.');$stmt=$this->db->prepare('DELETE FROM print_jobs WHERE id=?');$stmt->execute([$id]);}
        else throw new InvalidArgumentException('Aksi job tidak valid.');return['ok'=>true];
    }

    public function clearCompleted():int{$stmt=$this->db->prepare("DELETE FROM print_jobs WHERE status='completed'");$stmt->execute();return$stmt->rowCount();}

    public function spoolerAction(string $printer,int $jobId,string $action):array
    {
        if($printer===''||$jobId<=0)throw new InvalidArgumentException('Printer dan ID spooler wajib diisi.');$verb=match($action){'pause'=>'Suspend-PrintJob','resume'=>'Resume-PrintJob','cancel'=>'Remove-PrintJob',default=>throw new InvalidArgumentException('Aksi spooler tidak valid.')};
        $p64=base64_encode(mb_convert_encoding($printer,'UTF-16LE','UTF-8'));
        $script="\$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('{$p64}')); ";
        $script.=$action==='cancel'
            ? "Remove-PrintJob -PrinterName \$p -ID {$jobId} -ErrorAction Stop"
            : "Get-PrintJob -PrinterName \$p -ID {$jobId} | {$verb} -ErrorAction Stop";
        $this->powershell($script);@unlink($this->spoolerCacheFile);return['ok'=>true];
    }

    private function spoolerState():array
    {
        $cached=$this->readSpoolerCache();
        if($cached!==null&&(int)($cached['saved_at']??0)>=time()-15)return $cached['data'];
        $script="\$jobs=@(Get-CimInstance Win32_PrintJob -ErrorAction Stop | ForEach-Object { \$parts=\$_.Name -split ', '; [pscustomobject]@{printer=(\$parts[0]);job_id=([int](\$parts[-1]));document=\$_.Document;status=\$_.JobStatus;size=\$_.Size;submitted=[string]\$_.TimeSubmitted} }); \$printers=@(Get-CimInstance Win32_Printer -ErrorAction Stop | ForEach-Object { [pscustomobject]@{name=\$_.Name;status_code=([int]\$_.PrinterStatus);work_offline=([bool]\$_.WorkOffline);is_default=([bool]\$_.Default);port=\$_.PortName} }); [pscustomobject]@{jobs=\$jobs;printers=\$printers} | ConvertTo-Json -Depth 4 -Compress";
        try{
            $raw=$this->powershell($script);$data=json_decode(trim($raw),true);if(!is_array($data))return['jobs'=>[],'printers'=>[]];
            $jobs=is_array($data['jobs']??null)?$data['jobs']:[];$printerRows=is_array($data['printers']??null)?$data['printers']:[];
            $visibleRaw=(string)($this->db->query("SELECT setting_value FROM printer_settings WHERE setting_key='visible_printers'")->fetchColumn()?:'');$visible=json_decode($visibleRaw,true);$visible=is_array($visible)?array_flip(array_map('strval',$visible)):[];
            $jobCounts=[];foreach($jobs as $job){$name=(string)($job['printer']??'');$jobCounts[$name]=($jobCounts[$name]??0)+1;}
            $statusLabels=[1=>'Terhubung',2=>'Status tidak diketahui',3=>'Siap',4=>'Sedang mencetak',5=>'Pemanasan',6=>'Berhenti',7=>'Offline'];$printers=[];
            foreach($printerRows as $printer){$name=(string)($printer['name']??'');if($name===''||($visible&&!isset($visible[$name])))continue;$code=(int)($printer['status_code']??0);$offline=(bool)($printer['work_offline']??false)||in_array($code,[6,7],true);$printers[]=['name'=>$name,'active'=>!$offline,'status'=>$offline?'Offline':($statusLabels[$code]??'Status tidak diketahui'),'status_code'=>$code,'is_default'=>(bool)($printer['is_default']??false),'port'=>(string)($printer['port']??''),'queue_count'=>(int)($jobCounts[$name]??0)];}
            usort($printers,fn($a,$b)=>(int)$b['active']<=>(int)$a['active']?:strnatcasecmp($a['name'],$b['name']));$result=['jobs'=>$jobs,'printers'=>$printers];$this->writeSpoolerCache($result);return$result;
        }catch(Throwable){return$cached['data']??['jobs'=>[],'printers'=>[]];}
    }
    private function readSpoolerCache():?array{if(!is_file($this->spoolerCacheFile))return null;$data=json_decode((string)@file_get_contents($this->spoolerCacheFile),true);return is_array($data)&&is_array($data['data']??null)?$data:null;}
    private function writeSpoolerCache(array $data):void{@file_put_contents($this->spoolerCacheFile,json_encode(['saved_at'=>time(),'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);}
    private function powershell(string $script):string
    {
        $encoded=base64_encode(mb_convert_encoding($script,'UTF-16LE','UTF-8'));$pipes=[];$process=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-EncodedCommand',$encoded],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('PowerShell tidak dapat dijalankan.');stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$err='';$deadline=microtime(true)+8;$timedOut=false;
        do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);if(!$status['running'])break;if(microtime(true)>=$deadline){$timedOut=true;$pid=(int)($status['pid']??0);proc_terminate($process);if($pid>0){$kp=[];$killer=proc_open(['taskkill.exe','/PID',(string)$pid,'/T','/F'],[1=>['pipe','w'],2=>['pipe','w']],$kp);if(is_resource($killer)){foreach($kp as $pipe)fclose($pipe);proc_close($killer);}}break;}usleep(100000);}while(true);$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($timedOut)throw new RuntimeException('Windows print spooler tidak merespons dalam 8 detik.');if($exit!==0&&$exit!==-1)throw new RuntimeException(trim($err?:$out?:'Perintah printer gagal.'));return$out;
    }
    private function meta(string $key):string{$stmt=$this->db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?');$stmt->execute([$key]);return(string)($stmt->fetchColumn()?:'');}
}
