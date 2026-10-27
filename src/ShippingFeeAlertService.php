<?php
declare(strict_types=1);

final class ShippingFeeAlertService
{
    public function __construct(private PDO $db,private string $reader='local')
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS shipping_fee_notification_reads (
            reader_key VARCHAR(100) NOT NULL,order_sn VARCHAR(64) NOT NULL,
            estimated DECIMAL(18,2) NOT NULL,actual DECIMAL(18,2) NOT NULL,read_at BIGINT NOT NULL,
            PRIMARY KEY(reader_key,order_sn)
        ) ENGINE=InnoDB");
    }

    // Missing/null/invalid amounts must not be treated as zero shipping fees.
    private function differencesSql(): string
    {
        return "SELECT e.order_sn,e.order_create_time,e.synced_at,e.currency,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.order_income.estimated_shipping_fee')) AS DECIMAL(18,2)) estimated,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.order_income.actual_shipping_fee')) AS DECIMAL(18,2)) actual
            FROM shopee_escrow_details e
            WHERE JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.order_income.estimated_shipping_fee')) REGEXP '^[0-9]+([.][0-9]+)?$'
              AND JSON_UNQUOTE(JSON_EXTRACT(e.raw_json,'$.order_income.actual_shipping_fee')) REGEXP '^[0-9]+([.][0-9]+)?$'";
    }

    public function summary(): array
    {
        $sql=$this->notificationSql();
        $stmt=$this->db->prepare("SELECT COUNT(*) total,COALESCE(SUM(unread),0) unread,COALESCE(SUM(actual>estimated),0) higher,
            COALESCE(SUM(actual<estimated AND actual>0),0) lower,COALESCE(SUM(actual=0),0) zero_actual,MAX(synced_at) last_synced
            FROM ($sql) fees WHERE actual<>estimated");
        $stmt->execute([$this->reader]);$row=$stmt->fetch();
        return ['total'=>(int)$row['total'],'unread'=>(int)$row['unread'],'higher'=>(int)$row['higher'],'lower'=>(int)$row['lower'],'zeroActual'=>(int)$row['zero_actual'],
            'lastSyncedText'=>$row['last_synced']?date('d M Y H:i',(int)$row['last_synced']):null];
    }

    private function notificationSql(): string
    {
        $sql=$this->differencesSql();
        return "SELECT fees.*,(seen.order_sn IS NULL OR seen.estimated<>fees.estimated OR seen.actual<>fees.actual) unread
            FROM ($sql) fees LEFT JOIN shipping_fee_notification_reads seen
            ON seen.order_sn=fees.order_sn AND seen.reader_key=?";
    }

    public function acknowledge(array $items): array
    {
        if(count($items)>25)throw new InvalidArgumentException('Maksimal 25 notifikasi per permintaan.');
        $sql=$this->differencesSql();
        // Acknowledge only the amounts actually displayed, never a newer unseen change.
        $stmt=$this->db->prepare("INSERT INTO shipping_fee_notification_reads(reader_key,order_sn,estimated,actual,read_at)
            SELECT ?,fees.order_sn,fees.estimated,fees.actual,? FROM ($sql) fees
            WHERE fees.order_sn=? AND fees.estimated=? AND fees.actual=? AND fees.actual<>fees.estimated
            ON DUPLICATE KEY UPDATE estimated=VALUES(estimated),actual=VALUES(actual),read_at=VALUES(read_at)");
        foreach($items as $item){
            if(!is_array($item)||!is_numeric($item['estimated']??null)||!is_numeric($item['actual']??null))continue;
            $stmt->execute([$this->reader,time(),substr((string)($item['order_sn']??''),0,64),$item['estimated'],$item['actual']]);
        }
        return $this->summary();
    }

    public function listing(int $page=1,string $query='',bool $unreadOnly=false): array
    {
        $pageSize=25;$query=trim($query);$sql=$this->notificationSql();
        $where='fees.actual<>fees.estimated';$params=[$this->reader];
        if($unreadOnly)$where.=' AND fees.unread=1';
        if($query!==''){$where.=' AND fees.order_sn LIKE ?';$params[]='%'.$query.'%';}
        $count=$this->db->prepare("SELECT COUNT(*) FROM ($sql) fees WHERE $where");
        $count->execute($params);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($page,$pages));$offset=($page-1)*$pageSize;
        $stmt=$this->db->prepare("SELECT fees.*,fees.actual-fees.estimated difference,
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw_json,'$.shipping_carrier')),
                JSON_UNQUOTE(JSON_EXTRACT(o.raw_json,'$.package_list[0].shipping_carrier')),'') carrier
            FROM ($sql) fees LEFT JOIN orders o ON o.order_sn=fees.order_sn WHERE $where
            ORDER BY ABS(fees.actual-fees.estimated) DESC,fees.order_sn DESC LIMIT $pageSize OFFSET $offset");
        $stmt->execute($params);$items=[];
        foreach($stmt->fetchAll() as $row){
            $items[]=['order_sn'=>$row['order_sn'],'unread'=>(bool)$row['unread'],'currency'=>$row['currency'],'carrier'=>$row['carrier'],
                'date'=>date('d M Y',(int)$row['order_create_time']),
                'estimated'=>(float)$row['estimated'],'actual'=>(float)$row['actual'],'difference'=>(float)$row['difference'],
                'syncedAt'=>date('d M Y H:i',(int)$row['synced_at'])];
        }
        return ['items'=>$items,'page'=>$page,'pages'=>$pages,'total'=>$total,'summary'=>$this->summary()];
    }
}
