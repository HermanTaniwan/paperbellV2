<?php
declare(strict_types=1);

final class ShopeeProductTrafficService
{
    public function __construct(private PDO $db) {}

    public function monthly(string $from = '', string $to = ''): array
    {
        $bounds=$this->db->query('SELECT MIN(month_start) first_month,MAX(month_start) last_month FROM shopee_product_traffic_monthly')->fetch();
        $first=substr((string)($bounds['first_month']??''),0,7);
        $last=substr((string)($bounds['last_month']??''),0,7);
        if ($last==='') return ['from'=>'','to'=>'','minMonth'=>'','maxMonth'=>'','months'=>[],'products'=>[]];
        $defaultFrom=(new DateTimeImmutable($last.'-01'))->modify('-11 months')->format('Y-m');
        $from=$from===''?$defaultFrom:$from;$to=$to===''?$last:$to;
        foreach([$from,$to] as $month) if(!self::validMonth($month)) throw new InvalidArgumentException('Bulan trafik produk tidak valid.');
        $distance=(new DateTimeImmutable($from.'-01'))->diff(new DateTimeImmutable($to.'-01'));
        if($from>$to || $distance->m+12*$distance->y>119) throw new InvalidArgumentException('Pilih rentang trafik produk hingga 120 bulan.');
        $stmt=$this->db->prepare('SELECT * FROM shopee_product_traffic_monthly WHERE month_start BETWEEN ? AND ? ORDER BY month_start,product_code');
        $stmt->execute([$from.'-01',$to.'-01']);
        return self::summarize($from,$to,$stmt->fetchAll())+['minMonth'=>min($first,$defaultFrom),'dataMinMonth'=>$first,'maxMonth'=>$last];
    }

    public static function validMonth(string $month): bool
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01');
        return $date!==false&&$date->format('Y-m')===$month;
    }

    public static function summarize(string $from,string $to,array $rows): array
    {
        $months=[];$names=[1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];
        for($date=new DateTimeImmutable($from.'-01');$date<=new DateTimeImmutable($to.'-01');$date=$date->modify('+1 month')) $months[]=['month'=>$date->format('Y-m'),'label'=>$names[(int)$date->format('n')].' '.$date->format('y')];
        $products=[];$fields=['impressions','clicks','visitors','carts','orders'];
        foreach($rows as $row){
            $code=(string)$row['product_code'];$month=substr((string)$row['month_start'],0,7);
            if($month<$from||$month>$to)continue;
            if(!isset($products[$code]))$products[$code]=['code'=>$code,'name'=>'','sku'=>'','series'=>[],'totals'=>array_fill_keys($fields,null),'months'=>0];
            $product=&$products[$code];$product['name']=(string)$row['product_name'];$product['sku']=(string)$row['parent_sku'];$point=[];
            foreach($fields as $field){$point[$field]=$row[$field]===null?null:(int)$row[$field];if($point[$field]!==null)$product['totals'][$field]=($product['totals'][$field]??0)+$point[$field];}
            $point['ctr']=($point['impressions']??0)>0&&$point['clicks']!==null?$point['clicks']/$point['impressions']*100:null;
            $product['series'][$month]=$point;$product['months']++;
            unset($product);
        }
        foreach($products as &$product){$t=&$product['totals'];$t['ctr']=($t['impressions']??0)>0&&$t['clicks']!==null?$t['clicks']/$t['impressions']*100:null;$t['conversion']=($t['clicks']??0)>0&&$t['orders']!==null?$t['orders']/$t['clicks']*100:null;unset($t);}
        unset($product);
        $products=array_values($products);usort($products,fn($a,$b)=>($b['totals']['clicks']??-1)<=>($a['totals']['clicks']??-1)?:strcmp($a['code'],$b['code']));
        return ['from'=>$from,'to'=>$to,'months'=>$months,'products'=>$products];
    }
}
