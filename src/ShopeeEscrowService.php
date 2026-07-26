<?php
declare(strict_types=1);

final class ShopeeEscrowService
{
    public function __construct(private PDO $db, private MarketplaceOAuthService $oauth)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS shopee_escrow_details (
            order_sn VARCHAR(64) PRIMARY KEY,
            order_create_time BIGINT NOT NULL,
            order_status VARCHAR(50) NOT NULL DEFAULT '',
            currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
            gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            payout_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            commission_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
            service_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
            processing_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
            ads_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
            campaign_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
            shipping_adjustment DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
            raw_json LONGTEXT NOT NULL,
            synced_at BIGINT NOT NULL,
            INDEX ix_shopee_escrow_created(order_create_time),
            INDEX ix_shopee_escrow_synced(synced_at)
        ) ENGINE=InnoDB");
    }

    public function sync(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to) throw new InvalidArgumentException('Tanggal mulai tidak boleh melewati tanggal akhir.');
        if ((int)$from->diff($to)->format('%a') > 365) throw new InvalidArgumentException('Rentang sinkronisasi maksimal 366 hari.');

        $stmt=$this->db->prepare("SELECT order_sn,create_time,status FROM orders WHERE create_time>=? AND create_time<? AND order_sn NOT LIKE 'TIKTOK:%' AND order_sn NOT LIKE 'MANUAL-%' AND order_sn NOT LIKE 'RANDOM-%' AND UPPER(status) NOT IN ('CANCELLED','CANCELED') ORDER BY create_time");
        $stmt->execute([$from->getTimestamp(),$to->modify('+1 day')->getTimestamp()]);
        $orders=$stmt->fetchAll();
        if (!$orders) return ['ok'=>true,'orders'=>0,'synced'=>0,'failed'=>0,'from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')];

        $auth=$this->oauth->credentials('shopee');
        $cfg=$auth['config'];$token=(string)$auth['access_token'];$shop=(string)$auth['account_id'];
        $bySn=[];foreach($orders as $order)$bySn[(string)$order['order_sn']]=$order;
        $synced=0;$failed=[];

        foreach(array_chunk(array_keys($bySn),50) as $batch){
            $json=$this->requestBatch($batch,$cfg,$token,$shop);
            $error=(string)($json['error']??'');
            if($error!==''&&$error!=='0')throw new RuntimeException('Shopee escrow: '.($json['message']??$error).' ['.$error.']');
            $seen=[];
            foreach(($json['response']??[]) as $item){
                $detail=is_array($item['escrow_detail']??null)?$item['escrow_detail']:[];
                $sn=(string)($detail['order_sn']??'');
                if($sn===''||!isset($bySn[$sn]))continue;
                $this->store($bySn[$sn],$detail);$seen[$sn]=true;$synced++;
            }
            foreach($batch as $sn)if(!isset($seen[$sn]))$failed[]=$sn;
            usleep(120000);
        }
        return ['ok'=>true,'orders'=>count($orders),'synced'=>$synced,'failed'=>count($failed),'from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')];
    }

    public function dashboard(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to) throw new InvalidArgumentException('Tanggal mulai tidak boleh melewati tanggal akhir.');
        $start=$from->getTimestamp();$end=$to->modify('+1 day')->getTimestamp();
        $eligible=$this->db->prepare("SELECT COUNT(*) FROM orders WHERE create_time>=? AND create_time<? AND order_sn NOT LIKE 'TIKTOK:%' AND order_sn NOT LIKE 'MANUAL-%' AND order_sn NOT LIKE 'RANDOM-%' AND UPPER(status) NOT IN ('CANCELLED','CANCELED')");
        $eligible->execute([$start,$end]);$orderTotal=(int)$eligible->fetchColumn();
        $stmt=$this->db->prepare("SELECT DATE_FORMAT(FROM_UNIXTIME(order_create_time),'%Y-%m') month,COUNT(*) synced_orders,COALESCE(SUM(gross_amount),0) gross,COALESCE(SUM(payout_amount),0) payout,COALESCE(SUM(total_marketplace_fee),0) fees,COALESCE(SUM(commission_fee),0) commission,COALESCE(SUM(service_fee),0) service,COALESCE(SUM(processing_fee),0) processing,COALESCE(SUM(ads_fee),0) ads,COALESCE(SUM(campaign_fee),0) campaign,COALESCE(SUM(shipping_adjustment),0) shipping_adjustment,MAX(synced_at) last_synced FROM shopee_escrow_details WHERE order_create_time>=? AND order_create_time<? GROUP BY month ORDER BY month");
        $stmt->execute([$start,$end]);$found=[];foreach($stmt->fetchAll() as $row)$found[(string)$row['month']]=$row;
        $months=[];$totals=['orders'=>$orderTotal,'syncedOrders'=>0,'gross'=>0.0,'payout'=>0.0,'fees'=>0.0,'commission'=>0.0,'service'=>0.0,'processing'=>0.0,'ads'=>0.0,'campaign'=>0.0,'shippingAdjustment'=>0.0];$lastSynced=0;
        for($month=$from->modify('first day of this month');$month<=$to;$month=$month->modify('+1 month')){
            $key=$month->format('Y-m');$row=$found[$key]??[];$entry=['month'=>$key,'label'=>$month->format('M Y'),'orders'=>(int)($row['synced_orders']??0),'gross'=>(float)($row['gross']??0),'payout'=>(float)($row['payout']??0),'fees'=>(float)($row['fees']??0),'commission'=>(float)($row['commission']??0),'service'=>(float)($row['service']??0),'processing'=>(float)($row['processing']??0),'ads'=>(float)($row['ads']??0),'campaign'=>(float)($row['campaign']??0),'shippingAdjustment'=>(float)($row['shipping_adjustment']??0)];$months[]=$entry;
            foreach(['gross','payout','fees','commission','service','processing','ads','campaign','shippingAdjustment'] as $field)$totals[$field]+=$entry[$field];
            $totals['syncedOrders']+=$entry['orders'];$lastSynced=max($lastSynced,(int)($row['last_synced']??0));
        }
        $totals['coverage']=$orderTotal>0?round($totals['syncedOrders']/$orderTotal*100,1):0;
        $totals['feeRate']=$totals['gross']>0?round($totals['fees']/$totals['gross']*100,2):0;
        $dailyStmt=$this->db->prepare("SELECT DATE(FROM_UNIXTIME(order_create_time)) order_date,COUNT(*) orders,COALESCE(SUM(gross_amount),0) gross,COALESCE(SUM(payout_amount),0) payout,COALESCE(SUM(total_marketplace_fee),0) fees FROM shopee_escrow_details WHERE order_create_time>=? AND order_create_time<? GROUP BY order_date ORDER BY order_date");
        $dailyStmt->execute([$start,$end]);$dailyFound=[];foreach($dailyStmt->fetchAll() as $row)$dailyFound[(string)$row['order_date']]=$row;
        $days=[];for($date=$from;$date<=$to;$date=$date->modify('+1 day')){$key=$date->format('Y-m-d');$row=$dailyFound[$key]??[];$days[]=['date'=>$key,'label'=>$date->format('d M'),'orders'=>(int)($row['orders']??0),'gross'=>(float)($row['gross']??0),'payout'=>(float)($row['payout']??0),'fees'=>(float)($row['fees']??0)];}
        return ['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'basis'=>'order_create_time','summary'=>$totals,'days'=>$days,'months'=>$months,'lastSynced'=>$lastSynced,'lastSyncedText'=>$lastSynced?date('d M Y H:i',$lastSynced):'-'];
    }

    private function store(array $order,array $detail): void
    {
        $income=is_array($detail['order_income']??null)?$detail['order_income']:[];
        $gross=$this->money($income,'order_selling_price',$this->money($income,'buyer_total_amount'));
        $payout=$this->money($income,'escrow_amount_after_adjustment',$this->money($income,'escrow_amount'));
        $commission=$this->money($income,'commission_fee')+$this->money($income,'order_ams_commission_fee');
        $service=$this->money($income,'service_fee');$processing=$this->money($income,'seller_order_processing_fee');
        $ads=$this->money($income,'ads_escrow_top_up_fee_or_technical_support_fee');$campaign=$this->money($income,'campaign_fee');
        $shipping=$this->money($income,'actual_shipping_fee')-$this->money($income,'shopee_shipping_rebate')-$this->money($income,'buyer_paid_shipping_fee')-$this->money($income,'seller_shipping_discount');
        $fees=$commission+$service+$processing+$ads+$campaign;
        $stmt=$this->db->prepare("INSERT INTO shopee_escrow_details(order_sn,order_create_time,order_status,currency,gross_amount,payout_amount,commission_fee,service_fee,processing_fee,ads_fee,campaign_fee,shipping_adjustment,total_marketplace_fee,raw_json,synced_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE order_create_time=VALUES(order_create_time),order_status=VALUES(order_status),currency=VALUES(currency),gross_amount=VALUES(gross_amount),payout_amount=VALUES(payout_amount),commission_fee=VALUES(commission_fee),service_fee=VALUES(service_fee),processing_fee=VALUES(processing_fee),ads_fee=VALUES(ads_fee),campaign_fee=VALUES(campaign_fee),shipping_adjustment=VALUES(shipping_adjustment),total_marketplace_fee=VALUES(total_marketplace_fee),raw_json=VALUES(raw_json),synced_at=VALUES(synced_at)");
        $stmt->execute([(string)$order['order_sn'],(int)$order['create_time'],(string)$order['status'],(string)($income['currency']??'IDR'),$gross,$payout,$commission,$service,$processing,$ads,$campaign,$shipping,$fees,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),time()]);
    }

    private function money(array $income,string $key,float $fallback=0.0): float{return isset($income[$key])&&is_numeric($income[$key])?(float)$income[$key]:$fallback;}

    private function requestBatch(array $orderSns,array $cfg,string $token,string $shop): array
    {
        $path='/api/v2/payment/get_escrow_detail_batch';$ts=time();$partner=(string)($cfg['partner_id']??'');
        $query=['partner_id'=>$partner,'timestamp'=>$ts,'sign'=>hash_hmac('sha256',$partner.$path.$ts.$token.$shop,(string)($cfg['partner_key']??'')),'access_token'=>$token,'shop_id'=>$shop];
        $url=rtrim((string)($cfg['api_host']??'https://partner.shopeemobile.com'),'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['order_sn_list'=>array_values($orderSns)],JSON_THROW_ON_ERROR)]);
        $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($raw===false)throw new RuntimeException('Koneksi Shopee escrow gagal: '.$error);$json=json_decode($raw,true);if(!is_array($json))throw new RuntimeException('Respons Shopee escrow bukan JSON valid.');if($http<200||$http>=300)throw new RuntimeException('Shopee escrow HTTP '.$http.': '.($json['message']??$json['error']??'rejected'));return$json;
    }
}
