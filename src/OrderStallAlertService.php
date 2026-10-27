<?php
declare(strict_types=1);

final class OrderStallAlertService
{
    public function __construct(private PDO $db)
    {
        $db->exec("CREATE TABLE IF NOT EXISTS order_status_observations(order_sn VARCHAR(100) PRIMARY KEY,status VARCHAR(50) NOT NULL,status_since BIGINT NOT NULL,source_updated BIGINT NOT NULL,observed_at BIGINT NOT NULL) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS order_stall_alerts(order_sn VARCHAR(100) PRIMARY KEY,status VARCHAR(50) NOT NULL,status_since BIGINT NOT NULL,business_seconds BIGINT NOT NULL,checked_at BIGINT NOT NULL,episode_key CHAR(64) NOT NULL) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS order_stall_reads(reader_key VARCHAR(100) NOT NULL,episode_key CHAR(64) NOT NULL,read_at BIGINT NOT NULL,PRIMARY KEY(reader_key,episode_key)) ENGINE=InnoDB");
    }

    public static function eligible(string $sn,string $status): bool
    {
        $status=strtoupper($status);
        if(str_starts_with($sn,'TIKTOK:'))return in_array($status,['AWAITING_SHIPMENT','AWAITING_COLLECTION'],true);
        return !str_starts_with($sn,'MANUAL-')&&!str_starts_with($sn,'RANDOM-')&&in_array($status,['READY_TO_SHIP','PROCESSED'],true);
    }

    public static function businessSeconds(int $since,int $until,array $holidays): int
    {
        if($since<=0||$since>=$until)return 0;
        $zone=new DateTimeZone('Asia/Jakarta');$cursor=(new DateTimeImmutable('@'.$since))->setTimezone($zone);$total=0;
        while($cursor->getTimestamp()<$until){
            $next=$cursor->modify('tomorrow')->setTime(0,0);$end=min($until,$next->getTimestamp());
            if((int)$cursor->format('N')<=5&&!isset($holidays[$cursor->format('Y-m-d')]))$total+=$end-$cursor->getTimestamp();
            $cursor=$next;
        }
        return $total;
    }

    public function observe(string $sn,string $status,int $updated,int $created,int $now): array
    {
        $status=strtoupper($status);$updated=max(0,min($updated,$now));
        // Legacy rows have no status history. Last source update is a conservative
        // starting point; later unrelated updates never reset the status timer.
        $since=$updated>0?$updated:$now;
        $stmt=$this->db->prepare('INSERT INTO order_status_observations VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE status_since=IF(VALUES(source_updated)>=source_updated AND status<>VALUES(status),VALUES(observed_at),status_since),status=IF(VALUES(source_updated)>=source_updated,VALUES(status),status),source_updated=GREATEST(source_updated,VALUES(source_updated)),observed_at=VALUES(observed_at)');
        $stmt->execute([$sn,$status,max(min($created,$now),$since),$updated,$now]);
        $stmt=$this->db->prepare('SELECT * FROM order_status_observations WHERE order_sn=?');$stmt->execute([$sn]);return $stmt->fetch();
    }

    public function scan(callable $readStatuses,int $now,array $holidays): array
    {
        $day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        if((int)$day->format('N')>5||isset($holidays[$day->format('Y-m-d')]))return ['skipped'=>true,'reason'=>'non_workday'];
        $rows=$this->db->query("SELECT order_sn,status,update_time,create_time FROM orders WHERE UPPER(status) IN ('READY_TO_SHIP','PROCESSED','AWAITING_SHIPMENT','AWAITING_COLLECTION')")->fetchAll();
        $candidates=[];foreach($rows as $row)if(self::eligible($row['order_sn'],$row['status']))$candidates[$row['order_sn']]=$row;
        // Fetch everything first. A failed provider must not publish a partial scan.
        $live=$readStatuses(array_keys($candidates));$alerts=[];
        $this->db->beginTransaction();
        try{
            foreach($candidates as $sn=>$row){
                if(!isset($live[$sn]))continue; // Unverified orders never become alerts.
                $actual=$live[$sn];$seen=$this->observe($sn,(string)$actual['status'],(int)$actual['update_time'],(int)$row['create_time'],$now);
                if(!self::eligible($sn,$seen['status'])||$seen['status']!==strtoupper((string)$actual['status']))continue;
                $seconds=self::businessSeconds((int)$seen['status_since'],$now,$holidays);
                if($seconds<=86400)continue;
                $episode=hash('sha256',$sn.'|'.$seen['status'].'|'.$seen['status_since']);
                $alerts[]=[$sn,$seen['status'],(int)$seen['status_since'],$seconds,$now,$episode];
            }
            $this->db->exec('DELETE FROM order_stall_alerts');
            $stmt=$this->db->prepare('INSERT INTO order_stall_alerts VALUES(?,?,?,?,?,?)');foreach($alerts as $alert)$stmt->execute($alert);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return ['skipped'=>false,'verified'=>count($live),'candidates'=>count($candidates),'alerts'=>count($alerts),'checked_at'=>$now];
    }

    public function notifications(string $reader): array
    {
        $stmt=$this->db->prepare('SELECT a.*,r.episode_key IS NULL unread FROM order_stall_alerts a JOIN order_status_observations s ON s.order_sn=a.order_sn AND s.status=a.status AND s.status_since=a.status_since LEFT JOIN order_stall_reads r ON r.episode_key=a.episode_key AND r.reader_key=? ORDER BY unread DESC,a.business_seconds DESC,a.order_sn');
        $stmt->execute([$reader]);$items=[];$unread=0;
        foreach($stmt as $row){$isUnread=(bool)$row['unread'];if($isUnread)$unread++;
            $items[]=['id'=>'stall:'.$row['episode_key'],'type'=>'stall','episode_key'=>$row['episode_key'],'order_sn'=>$row['order_sn'],'status'=>$row['status'],'statusSince'=>date('d M Y H:i',(int)$row['status_since']),'businessHours'=>round((int)$row['business_seconds']/3600,1),'checkedAt'=>date('d M Y H:i',(int)$row['checked_at']),'unread'=>$isUnread];
        }
        return ['items'=>$items,'unread'=>$unread,'total'=>count($items)];
    }

    public function acknowledge(string $reader,array $episodes): void
    {
        $stmt=$this->db->prepare('INSERT INTO order_stall_reads SELECT ?,episode_key,? FROM order_stall_alerts WHERE episode_key=? ON DUPLICATE KEY UPDATE read_at=VALUES(read_at)');
        foreach($episodes as $episode)if(is_string($episode)&&preg_match('/^[a-f0-9]{64}$/D',$episode))$stmt->execute([$reader,time(),$episode]);
    }
}
