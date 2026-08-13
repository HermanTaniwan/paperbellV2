<?php
declare(strict_types=1);

final class PrintQueueService
{
    private string $spoolerCacheFile;
    private string $notificationScript;

    public function __construct(private PDO $db)
    {
        $root=dirname(__DIR__);
        $this->spoolerCacheFile=$root.'/storage/printer-spooler-cache.json';
        $this->notificationScript=$root.'/tools/show-printer-notification.ps1';
        $this->ensureIncidentTable();
    }

    public function overview():array
    {
        $jobs=$this->db->query("SELECT id,job_type,order_sn,original_name,status,message,error,printer,print_settings,copies,attempts,created_by,created_at,started_at,completed_at,submitted_at,spooler_job_id FROM (SELECT p.*,m.original_name FROM print_jobs p LEFT JOIN manual_pdfs m ON p.job_type IN ('manual','random') AND p.file_path=m.file_path) x ORDER BY id DESC LIMIT 100")->fetchAll();
        foreach($jobs as &$row){$row['createdText']=date('d M Y H:i',(int)$row['created_at']);}
        unset($row);
        $spooler=$this->spoolerState();
        $appJobsBySpooler=[];
        foreach($jobs as $job){
            $spoolerJobId=(int)($job['spooler_job_id']??0);
            if($spoolerJobId>0&&(string)$job['status']==='submitted')$appJobsBySpooler[(string)$job['printer'].'|'.$spoolerJobId]=(int)$job['id'];
        }
        foreach($spooler['jobs'] as &$spoolerJob){
            $key=(string)($spoolerJob['printer']??'').'|'.(int)($spoolerJob['job_id']??0);
            $spoolerJob['print_job_id']=$appJobsBySpooler[$key]??null;
        }
        unset($spoolerJob);
        $heartbeat=(int)($this->meta('print_worker_heartbeat')?:0);
        $worker=['heartbeat'=>$heartbeat,'online'=>$heartbeat>time()-30,'text'=>$heartbeat?date('d M Y H:i:s',$heartbeat):'Belum pernah aktif'];
        $this->monitor($jobs,$spooler,$worker);
        $incidents=$this->activeIncidents();
        $unacknowledged=count(array_filter($incidents,fn(array $row):bool=>(int)$row['acknowledged_at']===0));
        return[
            'items'=>$jobs,
            'spooler'=>$spooler['jobs'],
            'printers'=>$spooler['printers'],
            'spoolerAvailable'=>$spooler['available'],
            'worker'=>$worker,
            'incidents'=>$incidents,
            'incidentCount'=>$unacknowledged,
        ];
    }

    public function appAction(int $id,string $action):array
    {
        $stmt=$this->db->prepare('SELECT status,printer,spooler_job_id FROM print_jobs WHERE id=?');$stmt->execute([$id]);$job=$stmt->fetch();$status=(string)($job['status']??'');if($status==='')throw new RuntimeException('Job cetak tidak ditemukan.');
        if($action==='cancel'){if(!in_array($status,['queued','processing'],true))throw new RuntimeException('Job ini tidak dapat dibatalkan.');$next=$status==='queued'?'cancelled':'cancel_requested';$stmt=$this->db->prepare("UPDATE print_jobs SET status=?,message='Dibatalkan pengguna',completed_at=? WHERE id=?");$stmt->execute([$next,time(),$id]);}
        elseif($action==='retry'){
            if(!in_array($status,['failed','cancelled','cancel_requested','submitted'],true))throw new RuntimeException('Hanya job gagal, dibatalkan, atau sudah dikirim yang dapat diulang.');
            if($status==='submitted'&&(int)($job['spooler_job_id']??0)>0){$state=$this->spoolerState();foreach($state['jobs'] as $spoolerJob)if((string)($spoolerJob['printer']??'')===(string)$job['printer']&&(int)($spoolerJob['job_id']??0)===(int)$job['spooler_job_id'])throw new RuntimeException('Job lama masih ada di Windows spooler. Cancel job spooler terlebih dahulu agar tidak tercetak dobel.');}
            $stmt=$this->db->prepare("UPDATE print_jobs SET status='queued',message='Menunggu worker printer',error='',started_at=NULL,completed_at=NULL,submitted_at=NULL,spooler_job_id=NULL WHERE id=?");$stmt->execute([$id]);
            $resolve=$this->db->prepare("UPDATE printer_incidents SET status='resolved',active_key=NULL,resolved_at=?,healthy_count=2 WHERE print_job_id=? AND status IN ('pending','active')");$resolve->execute([time(),$id]);
        }
        elseif($action==='delete'){if(in_array($status,['queued','processing'],true))throw new RuntimeException('Job aktif tidak dapat dihapus.');$stmt=$this->db->prepare('DELETE FROM print_jobs WHERE id=?');$stmt->execute([$id]);}
        else throw new InvalidArgumentException('Aksi job tidak valid.');return['ok'=>true];
    }

    public function acknowledgeIncident(int $id,string $user):array
    {
        if($id<=0)throw new InvalidArgumentException('Insiden printer tidak valid.');
        $stmt=$this->db->prepare("UPDATE printer_incidents SET acknowledged_at=?,acknowledged_by=? WHERE id=? AND status='active'");
        $stmt->execute([time(),mb_substr($user,0,100),$id]);
        if($stmt->rowCount()===0)throw new RuntimeException('Insiden aktif tidak ditemukan.');
        return['ok'=>true];
    }

    public function clearCompleted():int{$stmt=$this->db->prepare("DELETE FROM print_jobs WHERE status IN ('completed','submitted')");$stmt->execute();return$stmt->rowCount();}

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

    public function moveSpoolerJob(string $printer,int $jobId,string $targetPrinter):array
    {
        if($printer===''||$jobId<=0||$targetPrinter==='')throw new InvalidArgumentException('Printer asal, printer tujuan, dan ID spooler wajib diisi.');
        if($printer===$targetPrinter)throw new InvalidArgumentException('Pilih printer tujuan yang berbeda.');

        $state=$this->spoolerState();$sourceJob=null;$target=null;
        foreach($state['jobs'] as $row)if((string)($row['printer']??'')===$printer&&(int)($row['job_id']??0)===$jobId){$sourceJob=$row;break;}
        foreach($state['printers'] as $row)if((string)($row['name']??'')===$targetPrinter){$target=$row;break;}
        if(!$sourceJob)throw new RuntimeException('Job sudah tidak ada di Windows spooler. Muat ulang antrean.');
        if(!$target)throw new RuntimeException('Printer tujuan tidak tersedia pada konfigurasi Paperbell.');
        if(!(bool)($target['active']??false))throw new RuntimeException('Printer tujuan sedang bermasalah atau offline. Pilih printer yang aktif.');

        $find=$this->db->prepare("SELECT id,file_path,status FROM print_jobs WHERE printer=? AND spooler_job_id=? AND status='submitted' ORDER BY id DESC LIMIT 1");
        $find->execute([$printer,$jobId]);$appJob=$find->fetch();
        if(!$appJob)throw new RuntimeException('File sumber job spooler ini tidak ditemukan di antrean Paperbell, jadi tidak aman untuk dikirim ulang.');
        if(!is_file((string)$appJob['file_path']))throw new RuntimeException('File PDF sumber sudah tidak tersedia.');

        $appJobId=(int)$appJob['id'];
        $claim=$this->db->prepare("UPDATE print_jobs SET status='moving',message='Memindahkan ke printer lain' WHERE id=? AND status='submitted' AND printer=? AND spooler_job_id=?");
        $claim->execute([$appJobId,$printer,$jobId]);
        if($claim->rowCount()!==1)throw new RuntimeException('Job sedang diperbarui oleh proses lain. Muat ulang antrean.');

        try{$this->spoolerAction($printer,$jobId,'cancel');}
        catch(Throwable $e){
            $restore=$this->db->prepare("UPDATE print_jobs SET status='submitted',message='Diserahkan ke Windows spooler' WHERE id=? AND status='moving'");$restore->execute([$appJobId]);
            throw $e;
        }

        try{
            $queue=$this->db->prepare("UPDATE print_jobs SET printer=?,status='queued',message='Menunggu worker printer (dipindahkan)',error='',started_at=NULL,completed_at=NULL,submitted_at=NULL,spooler_job_id=NULL WHERE id=? AND status='moving'");
            $queue->execute([$targetPrinter,$appJobId]);
            if($queue->rowCount()!==1)throw new RuntimeException('Status job berubah saat dipindahkan.');
            $resolve=$this->db->prepare("UPDATE printer_incidents SET status='resolved',active_key=NULL,resolved_at=?,healthy_count=2 WHERE print_job_id=? AND status IN ('pending','active')");$resolve->execute([time(),$appJobId]);
        }catch(Throwable $e){
            $fail=$this->db->prepare("UPDATE print_jobs SET status='failed',printer=?,message='Gagal mengantrekan ulang setelah spooler lama dibatalkan',error=?,completed_at=? WHERE id=?");
            $fail->execute([$targetPrinter,$e->getMessage(),time(),$appJobId]);
            throw new RuntimeException('Job lama sudah dibatalkan, tetapi gagal masuk antrean printer tujuan. Gunakan Coba lagi pada job aplikasi.',0,$e);
        }
        return['ok'=>true,'print_job_id'=>$appJobId,'printer'=>$targetPrinter];
    }

    private function ensureIncidentTable():void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS printer_incidents (id BIGINT AUTO_INCREMENT PRIMARY KEY,incident_key CHAR(64) NOT NULL,active_key CHAR(64) NULL,incident_type VARCHAR(50) NOT NULL,severity VARCHAR(20) NOT NULL DEFAULT 'error',printer VARCHAR(255) NOT NULL DEFAULT '',print_job_id BIGINT NULL,spooler_job_id INT NULL,title VARCHAR(255) NOT NULL,technical_message TEXT NOT NULL,guidance TEXT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',observed_count INT NOT NULL DEFAULT 1,healthy_count INT NOT NULL DEFAULT 0,acknowledged_at BIGINT NULL,acknowledged_by VARCHAR(100) NOT NULL DEFAULT '',host_notified_at BIGINT NULL,first_seen_at BIGINT NOT NULL,last_seen_at BIGINT NOT NULL,resolved_at BIGINT NULL,UNIQUE KEY uq_printer_incident_active(active_key),INDEX ix_printer_incidents_status(status,last_seen_at),INDEX ix_printer_incidents_job(print_job_id)) ENGINE=InnoDB");
        $this->db->exec('ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS submitted_at BIGINT NULL AFTER completed_at');
        $this->db->exec('ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS spooler_job_id INT NULL AFTER submitted_at');
    }

    private function activeIncidents():array
    {
        $rows=$this->db->query("SELECT i.*,p.order_sn,p.original_name FROM printer_incidents i LEFT JOIN (SELECT j.*,m.original_name FROM print_jobs j LEFT JOIN manual_pdfs m ON j.job_type IN ('manual','random') AND j.file_path=m.file_path) p ON p.id=i.print_job_id WHERE i.status='active' AND i.acknowledged_at IS NULL ORDER BY i.last_seen_at DESC,i.id DESC")->fetchAll();
        foreach($rows as &$row){
            $row['createdText']=date('d M Y H:i:s',(int)$row['first_seen_at']);
            $row['acknowledged_at']=(int)($row['acknowledged_at']??0);
            $row['print_job_id']=$row['print_job_id']===null?null:(int)$row['print_job_id'];
            $row['spooler_job_id']=$row['spooler_job_id']===null?null:(int)$row['spooler_job_id'];
        }
        unset($row);return$rows;
    }

    private function monitor(array $jobs,array $spooler,array $worker):void
    {
        $signals=[];
        foreach($jobs as $job)if((string)$job['status']==='failed'){
            $message=trim((string)$job['error'])?:'Worker printer melaporkan kegagalan tanpa detail.';
            $type=str_contains(strtolower($message),'sumatra')?'sumatra_failed':(str_contains(strtolower($message),'file pdf')?'file_missing':'job_failed');
            $guidance=$type==='file_missing'?'Pastikan file PDF masih ada dan storage dapat dibaca, lalu klik Coba lagi.':($type==='sumatra_failed'?'Periksa path SumatraPDF dan printer tujuan, lalu klik Coba lagi.':'Periksa pesan teknis, koneksi printer, dan antrean Windows sebelum mencoba lagi.');
            $signals[]=$this->signal($type,(string)$job['printer'],(int)$job['id'],null,'Job cetak gagal',$message,$guidance);
        }
        if(!$worker['online'])$signals[]=$this->signal('worker_offline','',null,null,'Print worker tidak aktif','Heartbeat terakhir: '.$worker['text'],'Paperbell mencoba mengaktifkan worker otomatis setiap 1 menit. Jika pesan ini tetap muncul, periksa task Paperbell Auto Start serta MariaDB.','critical',90);

        if($spooler['available']){
            foreach($spooler['printers'] as $printer){
                $type=(string)($printer['error_type']??'');if($type==='')continue;
                // Printer yang sengaja dimatikan/offline bukan insiden operasional.
                // Statusnya tetap terlihat di panel, tetapi tidak memicu notifikasi.
                if($type==='printer_offline')continue;
                $guidance=match($type){
                    'paper_jam'=>'Buka jalur kertas, keluarkan kertas yang tersangkut, lalu tutup kembali printer.',
                    'out_of_paper'=>'Isi tray kertas yang sesuai dan pastikan paper guide terpasang rapat.',
                    'paused'=>'Buka antrean Windows dan nonaktifkan Pause Printing.',
                    default=>'Periksa panel printer, koneksi, driver Windows, dan antrean cetak.',
                };
                $signals[]=$this->signal($type,(string)$printer['name'],null,null,'Printer '.$printer['status'],(string)$printer['diagnostic'],$guidance);
            }
            foreach($spooler['jobs'] as $job){
                $mask=(int)($job['status_mask']??0);$age=(int)($job['age_seconds']??0);$printing=($mask&16)!==0;$progressing=(bool)($job['progress_observed']??false);$type='';$title='';$guidance='';$activationDelay=0;
                if(($mask&64)!==0){$type='spooler_paper_out';$title='Spooler kehabisan kertas';$guidance='Isi tray printer, lalu resume job dari antrean Windows.';}
                elseif(($mask&(32|512|1024))!==0){$type='spooler_error';$title='Job Windows memerlukan tindakan';$guidance='Periksa printer dan detail antrean Windows; cancel job bila perlu sebelum retry.';}
                elseif(($mask&2)!==0&&!($printing&&$progressing)){
                    $type='spooler_error';$title=$printing?'Job Windows tetap error saat mencetak':'Job Windows memerlukan tindakan';$guidance='Periksa printer dan detail antrean Windows; cancel job bila perlu sebelum retry.';
                    // Driver Windows kadang mengirim "Error | Printing" sesaat walau job
                    // tetap bergerak. Tahan alarmnya agar gangguan singkat tidak menjadi toast.
                    if($printing)$activationDelay=90;
                }
                elseif($age>120&&!$printing){$type='spooler_stalled';$title='Job Windows tidak bergerak';$guidance='Periksa antrean Windows dan printer tujuan; pause/resume atau cancel sebelum retry.';}
                if($type!==''){$appJobId=null;foreach($jobs as $appJob)if((string)($appJob['printer']??'')===(string)($job['printer']??'')&&(int)($appJob['spooler_job_id']??0)===(int)($job['job_id']??0)){$appJobId=(int)$appJob['id'];break;}$pageProgress=(int)($job['total_pages']??0)>0?' · halaman '.(int)($job['pages_printed']??0).'/'.(int)$job['total_pages']:'';$signals[]=$this->signal($type,(string)($job['printer']??''),$appJobId,(int)($job['job_id']??0),$title,(string)($job['status']??'Status spooler tidak normal').' ('.$age.' detik)'.$pageProgress,$guidance,'error',$activationDelay);}
            }
        }
        $seen=[];foreach($signals as $signal){$seen[$signal['key']]=true;$this->observe($signal);}
        $this->healMissing(array_keys($seen),$spooler['available']);
        $this->sendPendingHostNotifications();
    }

    private function signal(string $type,string $printer,?int $printJobId,?int $spoolerJobId,string $title,string $message,string $guidance,string $severity='error',int $activationDelaySeconds=0):array
    {
        $identity=implode('|',[$type,mb_strtolower(trim($printer)),(string)($printJobId??0),(string)($spoolerJobId??0)]);
        return['key'=>hash('sha256',$identity),'type'=>$type,'printer'=>$printer,'print_job_id'=>$printJobId,'spooler_job_id'=>$spoolerJobId,'title'=>$title,'message'=>mb_substr($message,0,4000),'guidance'=>mb_substr($guidance,0,2000),'severity'=>$severity,'activation_delay_seconds'=>max(0,$activationDelaySeconds)];
    }

    private function observe(array $signal):void
    {
        $now=time();$find=$this->db->prepare("SELECT id,status,observed_count,first_seen_at FROM printer_incidents WHERE active_key=? LIMIT 1");$find->execute([$signal['key']]);$row=$find->fetch();
        if(!$row){$insert=$this->db->prepare("INSERT INTO printer_incidents(incident_key,active_key,incident_type,severity,printer,print_job_id,spooler_job_id,title,technical_message,guidance,status,observed_count,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,'pending',1,?,?)");$insert->execute([$signal['key'],$signal['key'],$signal['type'],$signal['severity'],$signal['printer'],$signal['print_job_id'],$signal['spooler_job_id'],$signal['title'],$signal['message'],$signal['guidance'],$now,$now]);return;}
        $count=(int)$row['observed_count']+1;$delay=(int)($signal['activation_delay_seconds']??0);$confirmed=$count>=2&&$now-(int)$row['first_seen_at']>=$delay;$status=(string)$row['status']==='pending'&&$confirmed?'active':(string)$row['status'];
        $update=$this->db->prepare('UPDATE printer_incidents SET status=?,observed_count=?,healthy_count=0,last_seen_at=?,technical_message=?,guidance=? WHERE id=?');$update->execute([$status,$count,$now,$signal['message'],$signal['guidance'],$row['id']]);
    }

    private function healMissing(array $seen,bool $spoolerAvailable):void
    {
        $rows=$this->db->query("SELECT id,incident_key,incident_type,healthy_count FROM printer_incidents WHERE status IN ('pending','active')")->fetchAll();$now=time();$seenMap=array_flip($seen);
        foreach($rows as $row){
            if(isset($seenMap[(string)$row['incident_key']]))continue;
            $type=(string)$row['incident_type'];if(!$spoolerAvailable&&(str_starts_with($type,'spooler_')||in_array($type,['paper_jam','out_of_paper','paused','printer_offline','printer_error'],true)))continue;
            $healthy=(int)$row['healthy_count']+1;if($healthy>=2){$stmt=$this->db->prepare("UPDATE printer_incidents SET status='resolved',active_key=NULL,healthy_count=?,resolved_at=? WHERE id=?");$stmt->execute([$healthy,$now,$row['id']]);}else{$stmt=$this->db->prepare('UPDATE printer_incidents SET healthy_count=? WHERE id=?');$stmt->execute([$healthy,$row['id']]);}
        }
    }

    private function sendPendingHostNotifications():void
    {
        if(!is_file($this->notificationScript))return;
        $rows=$this->db->query("SELECT id,title,technical_message FROM printer_incidents WHERE status='active' AND host_notified_at IS NULL ORDER BY id LIMIT 5")->fetchAll();
        foreach($rows as $row){
            if($this->notifyWindows((string)$row['title'],(string)$row['technical_message'])){$stmt=$this->db->prepare('UPDATE printer_incidents SET host_notified_at=? WHERE id=? AND host_notified_at IS NULL');$stmt->execute([time(),$row['id']]);}
        }
    }

    private function notifyWindows(string $title,string $message):bool
    {
        $path64=base64_encode(mb_convert_encoding($this->notificationScript,'UTF-16LE','UTF-8'));$title64=base64_encode(mb_convert_encoding($title,'UTF-16LE','UTF-8'));$message64=base64_encode(mb_convert_encoding(mb_substr($message,0,500),'UTF-16LE','UTF-8'));
        $script="\$e=[Text.Encoding]::Unicode;\$p=\$e.GetString([Convert]::FromBase64String('{$path64}'));\$a=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',\$p,'-TitleBase64','{$title64}','-MessageBase64','{$message64}');Start-Process -FilePath 'powershell.exe' -ArgumentList \$a -WindowStyle Hidden";
        try{$this->powershell($script);return true;}catch(Throwable){return false;}
    }

    private function spoolerState():array
    {
        $cached=$this->readSpoolerCache();
        if($cached!==null&&(int)($cached['saved_at']??0)>=time()-15)return $cached['data']+['available'=>true];
        $script="\$now=Get-Date; \$jobs=@(Get-CimInstance Win32_PrintJob -ErrorAction Stop | ForEach-Object { \$parts=\$_.Name -split ', '; \$submitted=try{[Management.ManagementDateTimeConverter]::ToDateTime(\$_.TimeSubmitted)}catch{\$now}; \$jobStatus=if(\$_.JobStatus){\$_.JobStatus}else{\$_.Status}; [pscustomobject]@{printer=(\$parts[0]);job_id=([int](\$parts[-1]));document=\$_.Document;status=([string]\$jobStatus);status_mask=([int]\$_.StatusMask);size=([int64]\$_.Size);pages_printed=([int]\$_.PagesPrinted);total_pages=([int]\$_.TotalPages);age_seconds=([int][Math]::Max(0,(\$now-\$submitted).TotalSeconds))} }); \$printers=@(Get-CimInstance Win32_Printer -ErrorAction Stop | ForEach-Object { [pscustomobject]@{name=\$_.Name;status_code=([int]\$_.PrinterStatus);extended_status=([int]\$_.ExtendedPrinterStatus);error_state=([int]\$_.DetectedErrorState);printer_state=([int]\$_.PrinterState);work_offline=([bool]\$_.WorkOffline);is_default=([bool]\$_.Default);port=\$_.PortName} }); [pscustomobject]@{jobs=\$jobs;printers=\$printers} | ConvertTo-Json -Depth 4 -Compress";
        try{
            $raw=$this->powershell($script);$data=json_decode(trim($raw),true);if(!is_array($data))throw new RuntimeException('Respons spooler tidak valid.');
            $jobs=is_array($data['jobs']??null)?$data['jobs']:[];$printerRows=is_array($data['printers']??null)?$data['printers']:[];
            $previousJobs=[];foreach(($cached['data']['jobs']??[]) as $previousJob){$previousKey=(string)($previousJob['printer']??'').'|'.(int)($previousJob['job_id']??0);$previousJobs[$previousKey]=$previousJob;}
            foreach($jobs as &$job){$jobKey=(string)($job['printer']??'').'|'.(int)($job['job_id']??0);$previous=$previousJobs[$jobKey]??null;$job['progress_observed']=is_array($previous)&&(int)($job['pages_printed']??0)>(int)($previous['pages_printed']??0);}unset($job);
            $visibleRaw=(string)($this->db->query("SELECT setting_value FROM printer_settings WHERE setting_key='visible_printers'")->fetchColumn()?:'');$visible=json_decode($visibleRaw,true);$visible=is_array($visible)?array_flip(array_map('strval',$visible)):[];
            $jobCounts=[];foreach($jobs as $job){$name=(string)($job['printer']??'');$jobCounts[$name]=($jobCounts[$name]??0)+1;}
            $statusLabels=[1=>'Terhubung',2=>'Status tidak diketahui',3=>'Siap',4=>'Sedang mencetak',5=>'Pemanasan',6=>'Berhenti',7=>'Offline'];$errorLabels=[3=>['low_paper','Kertas hampir habis'],4=>['out_of_paper','Kertas habis'],5=>['low_toner','Tinta/toner hampir habis'],6=>['out_of_toner','Tinta/toner habis'],7=>['printer_error','Pintu printer terbuka'],8=>['paper_jam','Kertas tersangkut'],9=>['printer_offline','Offline'],10=>['printer_error','Memerlukan servis'],11=>['printer_error','Output tray penuh']];$printers=[];
            foreach($printerRows as $printer){$name=(string)($printer['name']??'');if($name===''||($visible&&!isset($visible[$name])))continue;$code=(int)($printer['status_code']??0);$state=(int)($printer['printer_state']??0);$errorState=(int)($printer['error_state']??0);$offline=(bool)($printer['work_offline']??false)||in_array($code,[6,7],true);$errorType='';$status=$statusLabels[$code]??'Status tidak diketahui';if($offline){$errorType='printer_offline';$status='Offline';}elseif(($state&1)!==0){$errorType='paused';$status='Dijeda';}elseif(($state&8)!==0){$errorType='paper_jam';$status='Kertas tersangkut';}elseif(($state&(16|64))!==0){$errorType='out_of_paper';$status='Kertas habis atau salah dimuat';}elseif(($state&1048576)!==0){$errorType='printer_error';$status='Printer memerlukan tindakan';}elseif(isset($errorLabels[$errorState])){[$errorType,$status]=$errorLabels[$errorState];}
                $diagnostic="PrinterStatus={$code}; ExtendedPrinterStatus=".(int)($printer['extended_status']??0)."; DetectedErrorState={$errorState}; PrinterState={$state}; WorkOffline=".($offline?'true':'false');
                $printers[]=['name'=>$name,'active'=>$errorType==='','status'=>$status,'status_code'=>$code,'error_type'=>$errorType,'diagnostic'=>$diagnostic,'is_default'=>(bool)($printer['is_default']??false),'port'=>(string)($printer['port']??''),'queue_count'=>(int)($jobCounts[$name]??0)];
            }
            usort($printers,fn($a,$b)=>(int)$b['active']<=>(int)$a['active']?:strnatcasecmp($a['name'],$b['name']));$result=['jobs'=>$jobs,'printers'=>$printers,'available'=>true];$this->writeSpoolerCache($result);return$result;
        }catch(Throwable){return($cached['data']??['jobs'=>[],'printers'=>[]])+['available'=>false];}
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
