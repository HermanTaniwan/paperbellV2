<?php
declare(strict_types=1);

final class ShopeeShopStatsService
{
    private const MONTHS = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];

    public function __construct(private PDO $db) {}

    public function dashboard(): array
    {
        $rows=$this->db->query('SELECT * FROM shopee_shop_stats_monthly ORDER BY month_start')->fetchAll();
        if(!$rows)throw new RuntimeException('Data historis Shopee belum tersedia di database.');

        $monthly=array_map(fn(array $row):array=>$this->monthlyRow($row),$rows);
        $latestRow=$rows[array_key_last($rows)];
        $latest=$monthly[array_key_last($monthly)];
        $previous=count($monthly)>1?$monthly[count($monthly)-2]:$latest;
        $latestMonth=(string)$latestRow['month_start'];
        $latestLabel=$this->monthYear($latestMonth);
        $previousLabel=$this->monthYear((string)$rows[max(0,count($rows)-2)]['month_start']);

        $products=$this->products($latestMonth);
        $traffic=$this->trafficSources($latestMonth);
        $attribution=$this->attribution($latestMonth);
        $sourceFiles=array_values(array_filter(array_map(fn(array $row):string=>(string)$row['source_file'],$rows)));

        return [
            'meta'=>[
                'title'=>'Shopee Shop Stats',
                'periodStart'=>(string)$rows[0]['month_start'],
                'periodEnd'=>(string)$latestRow['month_end'],
                'periodLabel'=>$this->periodLabel((string)$rows[0]['month_start'],(string)$latestRow['month_end']),
                'latestLabel'=>$latestLabel,
                'orderStatus'=>(string)$latestRow['order_status'],
                'source'=>'Shopee Seller Centre',
                'sourceFiles'=>$sourceFiles,
                'storage'=>'MySQL',
            ],
            'latestKpis'=>$this->latestKpis($latest,$previous,$previousLabel),
            'monthly'=>$monthly,
            'insights'=>$this->insights($latest,$previous,$latestLabel,$previousLabel,count($monthly)>1),
            'topProducts'=>$products,
            'topProductsShare'=>array_sum(array_column($products,'share')),
            'trafficSources'=>$traffic,
            'attribution'=>$attribution,
            'ads'=>[
                'name'=>(string)$latestRow['ads_name'],
                'sales'=>(float)$latestRow['ads_attributed_sales'],
                'spend'=>(float)$latestRow['ads_spend'],
                'roas'=>(float)$latestRow['ads_roas'],
                'impressions'=>(int)$latestRow['ads_impressions'],
                'orders'=>(float)$latestRow['ads_orders'],
                'conversion'=>(float)$latestRow['ads_conversion_rate'],
            ],
        ];
    }

    public function comparison(string $from, string $to, string $granularity='daily'): array
    {
        $bounds=$this->db->query('SELECT MIN(stat_date) min_date,MAX(stat_date) max_date FROM shopee_shop_stats_daily')->fetch();
        $minDate=(string)($bounds['min_date']??'');$maxDate=(string)($bounds['max_date']??'');
        if($minDate===''||$maxDate==='')throw new RuntimeException('Data harian Shopee belum tersedia di database.');
        $from=$this->validDate($from)?$from:$minDate;$to=$this->validDate($to)?$to:$maxDate;
        if($from<$minDate)$from=$minDate;if($to>$maxDate)$to=$maxDate;
        if($from>$to)throw new InvalidArgumentException('Tanggal mulai tidak boleh melewati tanggal akhir.');
        if(!in_array($granularity,['daily','monthly'],true))$granularity='daily';

        if($granularity==='monthly'){
            $sql="SELECT DATE_FORMAT(stat_date,'%Y-%m-01') period_date,SUM(sales) sales,SUM(visitors) visitors,SUM(orders_count) orders_count,COUNT(*) day_count FROM shopee_shop_stats_daily WHERE stat_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(stat_date,'%Y-%m-01') ORDER BY period_date";
        }else{
            $sql='SELECT stat_date period_date,sales,visitors,orders_count,1 day_count FROM shopee_shop_stats_daily WHERE stat_date BETWEEN ? AND ? ORDER BY stat_date';
        }
        $stmt=$this->db->prepare($sql);$stmt->execute([$from,$to]);$rows=$stmt->fetchAll();
        $items=array_map(function(array $row)use($granularity):array{
            $date=(string)$row['period_date'];$sales=(float)$row['sales'];$visitors=(int)$row['visitors'];
            return [
                'date'=>$date,
                'label'=>$granularity==='monthly'?$this->monthYear($date):(new DateTimeImmutable($date))->format('d M'),
                'fullLabel'=>$granularity==='monthly'?$this->monthYear($date):(new DateTimeImmutable($date))->format('d-m-Y'),
                'sales'=>$sales,'visitors'=>$visitors,'orders'=>(int)$row['orders_count'],'days'=>(int)$row['day_count'],
                'salesPerVisitor'=>$visitors>0?$sales/$visitors:0.0,
            ];
        },$rows);
        $sales=array_sum(array_column($items,'sales'));$visitors=array_sum(array_column($items,'visitors'));
        $bestSales=$this->bestPeriod($items,'sales');$bestVisitors=$this->bestPeriod($items,'visitors');
        return [
            'from'=>$from,'to'=>$to,'granularity'=>$granularity,'minDate'=>$minDate,'maxDate'=>$maxDate,
            'visitorMetric'=>'Jumlah pengunjung unik harian; tampilan bulanan menjumlahkan nilai harian.',
            'items'=>$items,
            'summary'=>[
                'sales'=>$sales,'visitors'=>$visitors,'orders'=>array_sum(array_column($items,'orders')),
                'salesPerVisitor'=>$visitors>0?$sales/$visitors:0.0,
                'bestSalesPeriod'=>$bestSales,'bestVisitorPeriod'=>$bestVisitors,
            ],
        ];
    }

    public function growthStats(string $from, string $to): array
    {
        $bounds=$this->db->query('SELECT MIN(month_start) min_date,MAX(month_end) max_date FROM shopee_shop_stats_monthly')->fetch();
        $minDate=(string)($bounds['min_date']??'');$maxDate=(string)($bounds['max_date']??'');
        if($minDate===''||$maxDate==='')throw new RuntimeException('Data historis Shopee belum tersedia di database.');
        $from=$this->validDate($from)?$from:$minDate;$to=$this->validDate($to)?$to:$maxDate;
        if($from<$minDate)$from=$minDate;if($to>$maxDate)$to=$maxDate;
        if($from>$to)throw new InvalidArgumentException('Tanggal mulai tidak boleh melewati tanggal akhir.');

        $stmt=$this->db->prepare('SELECT COALESCE(SUM(CASE WHEN ads_spend>0 THEN ads_attributed_sales ELSE 0 END),0) ads_sales,COALESCE(SUM(CASE WHEN ads_spend>0 THEN ads_spend ELSE 0 END),0) ads_spend,COALESCE(SUM(CASE WHEN ads_spend>0 THEN ads_impressions ELSE 0 END),0) ads_impressions,COALESCE(SUM(CASE WHEN ads_spend>0 THEN ads_orders ELSE 0 END),0) ads_orders,COALESCE(SUM(CASE WHEN ads_spend>0 THEN ads_conversion_rate*ads_impressions ELSE 0 END),0) weighted_conversion,MAX(ads_spend>0) has_ads_data,COALESCE(SUM(visitors),0) visitors,COALESCE(SUM(clicks),0) clicks,COALESCE(SUM(orders_count),0) orders FROM shopee_shop_stats_monthly WHERE month_end>=? AND month_start<=?');
        $stmt->execute([$from,$to]);$summary=$stmt->fetch()?:[];
        $impressions=(int)$summary['ads_impressions'];$spend=(float)$summary['ads_spend'];$visitors=(int)$summary['visitors'];
        $adsNameStmt=$this->db->prepare("SELECT ads_name FROM shopee_shop_stats_monthly WHERE month_end>=? AND month_start<=? AND ads_name<>'' ORDER BY month_start DESC LIMIT 1");
        $adsNameStmt->execute([$from,$to]);
        $attributionStmt=$this->db->prepare('SELECT channel_name name,COALESCE(SUM(sales),0) sales FROM shopee_shop_stats_attribution WHERE month_start>=DATE_FORMAT(?,\'%Y-%m-01\') AND month_start<=DATE_FORMAT(?,\'%Y-%m-01\') GROUP BY channel_name ORDER BY sales DESC');
        $attributionStmt->execute([$from,$to]);

        return [
            'from'=>$from,'to'=>$to,'minDate'=>$minDate,'maxDate'=>$maxDate,'periodLabel'=>substr($from,0,7)===substr($to,0,7)?$this->monthYear($from):$this->periodLabel($from,$to),'hasAdsData'=>(bool)$summary['has_ads_data'],
            'ads'=>[
                'name'=>(string)($adsNameStmt->fetchColumn()?:'Shopee Ads'),'sales'=>(float)$summary['ads_sales'],'spend'=>$spend,
                'roas'=>$spend>0?(float)$summary['ads_sales']/$spend:0.0,'impressions'=>$impressions,'orders'=>(float)$summary['ads_orders'],
                'conversion'=>$impressions>0?(float)$summary['weighted_conversion']/$impressions:0.0,
            ],
            'attribution'=>array_map(fn(array $row):array=>['name'=>(string)$row['name'],'sales'=>(float)$row['sales']],$attributionStmt->fetchAll()),
            'funnel'=>['visitors'=>$visitors,'clicks'=>(int)$summary['clicks'],'conversion'=>$visitors>0?(float)$summary['orders']/$visitors:0.0],
        ];
    }

    private function monthlyRow(array $row): array
    {
        $orders=(int)$row['orders_count'];
        return [
            'month'=>substr((string)$row['month_start'],0,7),
            'label'=>self::MONTHS[(int)substr((string)$row['month_start'],5,2)]??substr((string)$row['month_start'],5,2),
            'sales'=>(float)$row['sales'],
            'orders'=>$orders,
            'aov'=>(float)$row['aov'],
            'clicks'=>(int)$row['clicks'],
            'visitors'=>(int)$row['visitors'],
            'conversion'=>(float)$row['conversion_rate'],
            'cancelledOrders'=>(int)$row['cancelled_orders'],
            'cancellationRate'=>$orders>0?(int)$row['cancelled_orders']/$orders:0.0,
            'cancelledSales'=>(float)$row['cancelled_sales'],
            'returnedOrders'=>(int)$row['returned_orders'],
            'returnedSales'=>(float)$row['returned_sales'],
            'buyers'=>(int)$row['buyers'],
            'newBuyers'=>(int)$row['new_buyers'],
            'existingBuyers'=>(int)$row['existing_buyers'],
            'potentialBuyers'=>(int)$row['potential_buyers'],
            'repeatRate'=>(float)$row['repeat_rate'],
        ];
    }

    private function latestKpis(array $latest,array $previous,string $previousLabel): array
    {
        $note='vs '.$previousLabel;
        return [
            ['label'=>'Omzet','value'=>$latest['sales'],'format'=>'currency','delta'=>$this->growth($latest['sales'],$previous['sales']),'deltaFormat'=>'percent','goodWhen'=>'up','note'=>$note],
            ['label'=>'Pesanan','value'=>$latest['orders'],'format'=>'number','delta'=>$this->growth($latest['orders'],$previous['orders']),'deltaFormat'=>'percent','goodWhen'=>'up','note'=>$note],
            ['label'=>'Pengunjung','value'=>$latest['visitors'],'format'=>'number','delta'=>$this->growth($latest['visitors'],$previous['visitors']),'deltaFormat'=>'percent','goodWhen'=>'up','note'=>$note],
            ['label'=>'Konversi','value'=>$latest['conversion'],'format'=>'percent','delta'=>$latest['conversion']-$previous['conversion'],'deltaFormat'=>'point','goodWhen'=>'up','note'=>$note],
            ['label'=>'Nilai per order','value'=>$latest['aov'],'format'=>'currency','delta'=>$this->growth($latest['aov'],$previous['aov']),'deltaFormat'=>'percent','goodWhen'=>'up','note'=>$note],
            ['label'=>'Cancel rate','value'=>$latest['cancellationRate'],'format'=>'percent','delta'=>$latest['cancellationRate']-$previous['cancellationRate'],'deltaFormat'=>'point','goodWhen'=>'down','note'=>$latest['cancelledOrders'].' pesanan dibatalkan'],
            ['label'=>'Repeat purchase','value'=>$latest['repeatRate'],'format'=>'percent','delta'=>$latest['repeatRate']-$previous['repeatRate'],'deltaFormat'=>'point','goodWhen'=>'up','note'=>$note],
        ];
    }

    private function insights(array $latest,array $previous,string $latestLabel,string $previousLabel,bool $hasPrevious): array
    {
        if(!$hasPrevious){
            return [
                ['tone'=>'opportunity','label'=>'Baseline','title'=>'Periode awal untuk pembanding berikutnya','text'=>$latestLabel.' menjadi baseline pertama dengan omzet '.$this->shortCurrency($latest['sales']).', '.$latest['orders'].' pesanan, dan '.$latest['visitors'].' pengunjung.'],
                ['tone'=>'opportunity','label'=>'Funnel','title'=>'Pantau kualitas konversi dan pembatalan','text'=>'Konversi berada di '.$this->percent($latest['conversion'],2).' dengan cancel rate '.$this->percent($latest['cancellationRate'],2).'. Perbandingan tren akan muncul setelah periode berikutnya tersedia.'],
                ['tone'=>'opportunity','label'=>'Pelanggan','title'=>'Mulai bangun baseline retensi','text'=>'Repeat purchase berada di '.$this->percent($latest['repeatRate'],2).'. Data bulan berikutnya akan menunjukkan apakah retensi bergerak naik atau turun.'],
            ];
        }

        $salesGrowth=$this->growth($latest['sales'],$previous['sales']);
        $orderGrowth=$this->growth($latest['orders'],$previous['orders']);
        $visitorGrowth=$this->growth($latest['visitors'],$previous['visitors']);
        $salesPerVisitorGrowth=$this->growth(
            $latest['visitors']>0?$latest['sales']/$latest['visitors']:0,
            $previous['visitors']>0?$previous['sales']/$previous['visitors']:0
        );
        $conversionDelta=$latest['conversion']-$previous['conversion'];
        $cancellationDelta=$latest['cancellationRate']-$previous['cancellationRate'];
        $repeatDelta=$latest['repeatRate']-$previous['repeatRate'];
        $newBuyerShare=$latest['buyers']>0?$latest['newBuyers']/$latest['buyers']:0;

        if($salesGrowth>=0&&$visitorGrowth>=0){
            $performanceTone='growth';
            if($salesGrowth-$visitorGrowth>=0.03)$performanceTitle='Omzet tumbuh lebih cepat daripada traffic';
            elseif($visitorGrowth-$salesGrowth>=0.03){$performanceTone='opportunity';$performanceTitle='Traffic tumbuh lebih cepat daripada omzet';}
            else $performanceTitle='Omzet dan traffic tumbuh relatif seimbang';
        }elseif($salesGrowth>=0){
            $performanceTone='growth';$performanceTitle='Omzet tumbuh meski traffic menurun';
        }elseif($visitorGrowth>=0){
            $performanceTone='risk';$performanceTitle='Traffic naik, tetapi omzet melemah';
        }else{
            $performanceTone='risk';$performanceTitle='Omzet dan traffic sama-sama melemah';
        }

        if($conversionDelta>=0&&$cancellationDelta<=0){
            $funnelTone='growth';$funnelTitle='Funnel membaik dan cancel lebih terkendali';
        }elseif($conversionDelta>=0){
            $funnelTone='risk';$funnelTitle='Konversi membaik, tetapi cancel ikut naik';
        }elseif($cancellationDelta<=0){
            $funnelTone='opportunity';$funnelTitle='Cancel membaik, namun konversi melemah';
        }else{
            $funnelTone='risk';$funnelTitle='Konversi turun dan cancel meningkat';
        }

        if($repeatDelta>0.002){
            $retentionTone='growth';$retentionTitle='Repeat purchase menguat';
        }elseif($repeatDelta<-.002){
            $retentionTone='risk';$retentionTitle='Repeat purchase perlu dipulihkan';
        }else{
            $retentionTone='opportunity';$retentionTitle='Repeat purchase cenderung stabil';
        }
        $acquisitionText=$newBuyerShare>=.8?'Akuisisi pelanggan baru masih dominan.':'Porsi pelanggan lama mulai lebih berarti.';

        return [
            [
                'tone'=>$performanceTone,'label'=>'Performa','title'=>$performanceTitle,
                'text'=>'Dibanding '.$previousLabel.', omzet '.$this->trend($salesGrowth).', order '.$this->trend($orderGrowth).', dan pengunjung '.$this->trend($visitorGrowth).'. Omzet per pengunjung '.$this->trend($salesPerVisitorGrowth).'.',
            ],
            [
                'tone'=>$funnelTone,'label'=>'Funnel','title'=>$funnelTitle,
                'text'=>'Konversi '.$this->pointTrend($conversionDelta).' menjadi '.$this->percent($latest['conversion'],2).', sedangkan cancel rate '.$this->pointTrend($cancellationDelta).' menjadi '.$this->percent($latest['cancellationRate'],2).'. Nilai pembatalan '.$latestLabel.' sebesar '.$this->shortCurrency($latest['cancelledSales']).'.',
            ],
            [
                'tone'=>$retentionTone,'label'=>'Pelanggan','title'=>$retentionTitle,
                'text'=>$this->percent($newBuyerShare).' pembeli '.$latestLabel.' adalah pembeli baru. '.$acquisitionText.' Repeat purchase '.$this->pointTrend($repeatDelta).' menjadi '.$this->percent($latest['repeatRate'],2).'.',
            ],
        ];
    }

    private function products(string $month): array
    {
        $stmt=$this->db->prepare('SELECT * FROM shopee_shop_stats_products WHERE month_start=? ORDER BY rank_order,sales DESC LIMIT 5');
        $stmt->execute([$month]);
        return array_map(fn(array $row):array=>[
            'code'=>(string)$row['product_code'],'name'=>(string)$row['product_name'],'sales'=>(float)$row['sales'],
            'share'=>(float)$row['sales_share'],'orders'=>(float)$row['orders_attributed'],'units'=>(int)$row['units'],
            'clicks'=>(int)$row['clicks'],'conversion'=>(float)$row['conversion_rate'],'aov'=>(float)$row['aov'],
        ],$stmt->fetchAll());
    }

    private function trafficSources(string $month): array
    {
        $stmt=$this->db->prepare('SELECT * FROM shopee_shop_stats_traffic_sources WHERE month_start=? ORDER BY rank_order,sales DESC');
        $stmt->execute([$month]);
        return array_map(fn(array $row):array=>[
            'name'=>(string)$row['source_name'],'sales'=>(float)$row['sales'],'share'=>(float)$row['sales_share'],
            'clicks'=>(int)$row['clicks'],'orders'=>(float)$row['orders_attributed'],
            'conversion'=>(float)$row['conversion_rate'],'aov'=>(float)$row['aov'],
        ],$stmt->fetchAll());
    }

    private function attribution(string $month): array
    {
        $stmt=$this->db->prepare('SELECT channel_name,sales FROM shopee_shop_stats_attribution WHERE month_start=? ORDER BY rank_order,sales DESC');
        $stmt->execute([$month]);
        return array_map(fn(array $row):array=>['name'=>(string)$row['channel_name'],'sales'=>(float)$row['sales']],$stmt->fetchAll());
    }

    private function growth(float|int $current,float|int $previous): float
    {
        return (float)$previous!==0.0?((float)$current/(float)$previous)-1:0.0;
    }

    private function validDate(string $value): bool
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date!==false&&$date->format('Y-m-d')===$value;
    }

    private function bestPeriod(array $items,string $key): ?array
    {
        if(!$items)return null;
        $best=$items[0];foreach($items as $item)if((float)$item[$key]>(float)$best[$key])$best=$item;
        return ['date'=>$best['date'],'label'=>$best['fullLabel'],'value'=>$best[$key]];
    }

    private function periodLabel(string $start,string $end): string
    {
        $startDate=new DateTimeImmutable($start);$endDate=new DateTimeImmutable($end);
        $first=self::MONTHS[(int)$startDate->format('n')]??$startDate->format('M');
        $last=self::MONTHS[(int)$endDate->format('n')]??$endDate->format('M');
        return $startDate->format('Y')===$endDate->format('Y')?"{$first}–{$last} ".$endDate->format('Y'):"{$first} ".$startDate->format('Y')."–{$last} ".$endDate->format('Y');
    }

    private function monthYear(string $date): string
    {
        $value=new DateTimeImmutable($date);return (self::MONTHS[(int)$value->format('n')]??$value->format('M')).' '.$value->format('Y');
    }

    private function percent(float $value,int $digits=1): string
    {
        return number_format($value*100,$digits,',','.').'%';
    }

    private function trend(float $value,int $digits=1): string
    {
        if(abs($value)<.0005)return 'relatif tetap';
        return ($value>0?'naik ':'turun ').$this->percent(abs($value),$digits);
    }

    private function pointTrend(float $value,int $digits=2): string
    {
        if(abs($value)<.00005)return 'relatif tetap';
        return ($value>0?'naik ':'turun ').number_format(abs($value)*100,$digits,',','.').' poin';
    }

    private function shortCurrency(float $value): string
    {
        if(abs($value)>=1000000)return 'Rp'.rtrim(rtrim(number_format($value/1000000,2,',','.'),'0'),',').' juta';
        if(abs($value)>=1000)return 'Rp'.rtrim(rtrim(number_format($value/1000,1,',','.'),'0'),',').' ribu';
        return 'Rp'.number_format($value,0,',','.');
    }
}
