<?php
declare(strict_types=1);

/** Durable usage ledger, independent of the deletable print queue. */
final class PrinterUsageService
{
    public function __construct(private PDO $db)
    {
        $db->exec("CREATE TABLE IF NOT EXISTS printer_usage_periods (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, started_at BIGINT NOT NULL,
            ended_at BIGINT NULL, start_job_id BIGINT NOT NULL, point_limit BIGINT NOT NULL DEFAULT 0,
            reason VARCHAR(30) NOT NULL, created_by VARCHAR(100) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS printer_usage_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, period_id BIGINT NOT NULL,
            job_id BIGINT NULL, attempt INT NULL, a5 BIGINT NOT NULL DEFAULT 0,
            b5 BIGINT NOT NULL DEFAULT 0, unresolved INT NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '', created_by VARCHAR(100) NOT NULL DEFAULT '',
            created_at BIGINT NOT NULL, UNIQUE KEY uq_usage_job(job_id,attempt),
            INDEX ix_usage_period(period_id,id)
        ) ENGINE=InnoDB");
        // Serialize initialization and all period boundaries across API requests.
        $db->exec("INSERT IGNORE INTO app_meta(meta_key,meta_value) VALUES('printer_usage_lock','1')");
        $this->locked(function (): void {
            if (!$this->current()) {
                $last=(int)$this->db->query('SELECT COALESCE(MAX(id),0) FROM print_jobs')->fetchColumn();
                $stmt=$this->db->prepare("INSERT INTO printer_usage_periods(started_at,start_job_id,reason) VALUES(?,?,'start')");
                $stmt->execute([time(),$last]);
            }
        });
    }

    public static function tracks(string $printer): bool
    {
        return (bool)preg_match('/WF(?:C)?5390/',preg_replace('/[^A-Z0-9]/','',strtoupper($printer)));
    }

    /** Count printed PDF sides, not physical sheets. Duplex does not double PDF pages. */
    public static function countSides(string $settings,int $copies,array $sizes): array
    {
        $tokens=array_map('trim',explode(',',strtolower($settings)));
        $from=1;$to=count($sizes);$parity='all';$paper='';
        foreach ($tokens as $token) {
            if (preg_match('/^(\d+)(?:-(\d*))?$/',$token,$m)) {
                $from=max(1,(int)$m[1]);
                $to=isset($m[2])?($m[2]===''?count($sizes):(int)$m[2]):$from;
            }
            if (in_array($token,['odd','even'],true)) $parity=$token;
            if (str_starts_with($token,'paper=')) $paper=strtoupper(substr($token,6));
            if ($token==='paperkind=13') $paper='B5';
        }
        $a5=0;$b5=0;$unknown=0;
        for ($page=$from;$page<=min($to,count($sizes));$page++) {
            if (($parity==='odd'&&$page%2===0)||($parity==='even'&&$page%2!==0)) continue;
            $kind=$paper;
            if ($kind==='') {
                $size=$sizes[$page-1];sort($size);
                if (abs($size[0]-148)<=3&&abs($size[1]-210)<=3) $kind='A5';
                elseif ((abs($size[0]-176)<=3&&abs($size[1]-250)<=3)||(abs($size[0]-182)<=3&&abs($size[1]-257)<=3)) $kind='B5';
                else $unknown+=max(1,$copies);
            }
            if ($kind==='A5') $a5+=max(1,$copies);
            if ($kind==='B5') $b5+=max(1,$copies);
        }
        return ['a5'=>$a5,'b5'=>$b5,'unresolved'=>$unknown];
    }

    /** Called in the same transaction that marks a spooler job completed. */
    public function recordCompleted(array $job): void
    {
        if (!self::tracks((string)$job['printer'])) return;
        $period=$this->current();
        $initial=$this->db->query('SELECT started_at,start_job_id FROM printer_usage_periods ORDER BY id LIMIT 1')->fetch();
        if (!$period||((int)$job['id']<=(int)$initial['start_job_id']&&(int)($job['started_at']??0)<(int)$initial['started_at'])) return;
        $existing=$this->db->prepare('SELECT id FROM printer_usage_events WHERE job_id=? AND attempt=?');
        $existing->execute([$job['id'],$job['attempts']]);
        if ($existing->fetchColumn()) return;
        $counts=['a5'=>0,'b5'=>0,'unresolved'=>1];$note='';
        try {
            $counts=self::countSides((string)$job['print_settings'],(int)$job['copies'],$this->pdfSizes((string)$job['file_path']));
            if ($counts['unresolved']>0) $note='Ukuran halaman tidak dikenali; koreksi manual bila A5/B5.';
        } catch (Throwable $e) {
            $note='PDF tidak dapat dibaca; tambahkan jumlah halaman melalui koreksi manual.';
            error_log('[printer-usage] Job '.(int)$job['id'].': '.$e->getMessage());
        }
        $stmt=$this->db->prepare('INSERT IGNORE INTO printer_usage_events(period_id,job_id,attempt,a5,b5,unresolved,note,created_at) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$period['id'],$job['id'],$job['attempts'],$counts['a5'],$counts['b5'],$counts['unresolved'],$note,time()]);
    }

    private function pdfSizes(string $path): array
    {
        $config=require dirname(__DIR__).'/config.php';
        require_once __DIR__.'/HostPathResolver.php';
        $path=(new HostPathResolver($config['paths']??[]))->resolve($path);
        $process=proc_open([(string)$config['printing']['python'],dirname(__DIR__).'/tools/pdf_usage_info.py',$path],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Pembaca PDF tidak tersedia.');
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        $data=json_decode($out,true);
        if ($code!==0||!isset($data['sizes'])||!is_array($data['sizes'])||!$data['sizes']) throw new RuntimeException('Pembaca PDF gagal: '.$error);
        return $data['sizes'];
    }

    public function locked(callable $action): mixed
    {
        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT meta_value FROM app_meta WHERE meta_key='printer_usage_lock' FOR UPDATE")->fetchColumn();
            $result=$action();$this->db->commit();return $result;
        } catch (Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function current(): array|false
    {
        return $this->db->query('SELECT * FROM printer_usage_periods ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function summary(bool $history=false): array
    {
        $row=$this->db->query('SELECT p.*,COALESCE(SUM(e.a5),0) a5,COALESCE(SUM(e.b5),0) b5,COALESCE(SUM(e.unresolved>0),0) unresolved_jobs FROM printer_usage_periods p LEFT JOIN printer_usage_events e ON e.period_id=p.id WHERE p.id=(SELECT MAX(id) FROM printer_usage_periods) GROUP BY p.id')->fetch();
        $row=$this->format($row);$row['history']=[];$row['events']=[];
        if ($history) {
            $rows=$this->db->query('SELECT p.*,COALESCE(SUM(e.a5),0) a5,COALESCE(SUM(e.b5),0) b5,COALESCE(SUM(e.unresolved>0),0) unresolved_jobs FROM printer_usage_periods p LEFT JOIN printer_usage_events e ON e.period_id=p.id WHERE p.ended_at IS NOT NULL GROUP BY p.id ORDER BY p.id DESC LIMIT 20')->fetchAll();
            $row['history']=array_map(fn($p)=>$this->format($p),$rows);
            $stmt=$this->db->prepare('SELECT * FROM printer_usage_events WHERE period_id=? ORDER BY (unresolved>0) DESC,id DESC LIMIT 30');$stmt->execute([$row['id']]);
            $row['events']=$stmt->fetchAll();
        }
        return $row;
    }

    private function format(array $row): array
    {
        foreach (['id','a5','b5','point_limit','unresolved_jobs'] as $key) $row[$key]=(int)$row[$key];
        $row['points']=$row['a5']+2*$row['b5'];
        $row['percent']=$row['point_limit']>0?round($row['points']/$row['point_limit']*100,1):null;
        $row['needs_check']=$row['point_limit']>0&&$row['points']*2>=$row['point_limit'];
        $row['started_text']=date('d M Y H:i',(int)$row['started_at']);
        $row['ended_text']=$row['ended_at']?date('d M Y H:i',(int)$row['ended_at']):'';
        return $row;
    }

    public function change(array $input,string $user): void
    {
        $this->locked(function () use ($input,$user): void {
            $period=$this->current();$operation=(string)($input['operation']??'');
            if ($operation==='limit') {
                $limit=filter_var($input['point_limit']??null,FILTER_VALIDATE_INT);
                if ($limit===false||$limit<0||$limit>1000000000) throw new InvalidArgumentException('Batas poin harus bilangan bulat 0 sampai 1.000.000.000.');
                $stmt=$this->db->prepare('UPDATE printer_usage_periods SET point_limit=? WHERE id=?');$stmt->execute([$limit,$period['id']]);
            } elseif ($operation==='refill') {
                $jobs=$this->db->query("SELECT printer FROM print_jobs WHERE status IN ('queued','processing','submitted','moving','cancel_requested')")->fetchAll();
                foreach ($jobs as $job) if(self::tracks($job['printer'])) throw new RuntimeException('Tunggu antrean WF-C5390 selesai sebelum mencatat refill.');
                $now=time();$last=(int)$period['start_job_id'];
                $stmt=$this->db->prepare('UPDATE printer_usage_periods SET ended_at=? WHERE id=?');$stmt->execute([$now,$period['id']]);
                $stmt=$this->db->prepare("INSERT INTO printer_usage_periods(started_at,start_job_id,point_limit,reason,created_by) VALUES(?,?,?,'refill',?)");$stmt->execute([$now,$last,$period['point_limit'],$user]);
            } elseif ($operation==='resolve') {
                $id=filter_var($input['event_id']??null,FILTER_VALIDATE_INT);
                if ($id===false||$id<1) throw new InvalidArgumentException('Aktivitas tidak valid.');
                $stmt=$this->db->prepare('UPDATE printer_usage_events SET unresolved=0 WHERE id=? AND period_id=? AND unresolved>0');$stmt->execute([$id,$period['id']]);
                if (!$stmt->rowCount()) throw new InvalidArgumentException('Aktivitas belum terhitung tidak ditemukan di periode ini.');
                $stmt=$this->db->prepare('INSERT INTO printer_usage_events(period_id,note,created_by,created_at) VALUES(?,?,?,?)');$stmt->execute([$period['id'],'Aktivitas #'.$id.' sudah diperiksa/dikoreksi manual.',$user,time()]);
            } elseif ($operation==='adjust') {
                $a5=filter_var($input['a5']??null,FILTER_VALIDATE_INT);$b5=filter_var($input['b5']??null,FILTER_VALIDATE_INT);
                $note=trim((string)($input['note']??''));
                if ($a5===false||$b5===false||abs($a5)>10000000||abs($b5)>10000000||($a5===0&&$b5===0)||$note==='') throw new InvalidArgumentException('Isi koreksi halaman A5/B5 dan alasannya. Gunakan angka minus untuk mengurangi.');
                $summary=$this->summary();
                if($summary['a5']+$a5<0||$summary['b5']+$b5<0) throw new InvalidArgumentException('Hasil koreksi tidak boleh negatif.');
                $stmt=$this->db->prepare('INSERT INTO printer_usage_events(period_id,a5,b5,note,created_by,created_at) VALUES(?,?,?,?,?,?)');$stmt->execute([$period['id'],$a5,$b5,mb_substr($note,0,255),$user,time()]);
            } else throw new InvalidArgumentException('Aksi pemakaian tidak valid.');
        });
    }
}
