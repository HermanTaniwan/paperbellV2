<?php
declare(strict_types=1);

final class ShippingFeeAlertService
{
    public function __construct(private PDO $db) {}

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
        $sql=$this->differencesSql();
        $row=$this->db->query("SELECT COUNT(*) total,COALESCE(SUM(actual>estimated),0) higher,
            COALESCE(SUM(actual<estimated AND actual>0),0) lower,COALESCE(SUM(actual=0),0) zero_actual,MAX(synced_at) last_synced
            FROM ($sql) fees WHERE actual<>estimated")->fetch();
        return ['total'=>(int)$row['total'],'higher'=>(int)$row['higher'],'lower'=>(int)$row['lower'],'zeroActual'=>(int)$row['zero_actual'],
            'lastSyncedText'=>$row['last_synced']?date('d M Y H:i',(int)$row['last_synced']):null];
    }

    public function listing(int $page=1,string $query=''): array
    {
        $pageSize=25;$query=trim($query);$sql=$this->differencesSql();
        $where='fees.actual<>fees.estimated';$params=[];
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
            $items[]=['order_sn'=>$row['order_sn'],'currency'=>$row['currency'],'carrier'=>$row['carrier'],
                'date'=>date('d M Y',(int)$row['order_create_time']),
                'estimated'=>(float)$row['estimated'],'actual'=>(float)$row['actual'],'difference'=>(float)$row['difference'],
                'syncedAt'=>date('d M Y H:i',(int)$row['synced_at'])];
        }
        return ['items'=>$items,'page'=>$page,'pages'=>$pages,'total'=>$total,'summary'=>$this->summary()];
    }
}
