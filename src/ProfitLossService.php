<?php
declare(strict_types=1);

final class ProfitLossService
{
    public const EXPENSES = [
        'tiktok_fee' => 'Fee / penyesuaian TikTok',
        'external_ads' => 'Iklan di luar fee Shopee',
        'shipping_packing' => 'Ongkir & packing',
        'payroll' => 'Gaji / tenaga kerja',
        'operations' => 'Tools & operasional',
        'other' => 'Lainnya',
    ];
    public const CATEGORY_COSTS = [
        'journal_a5' => 'Jurnal Jurnalan A5', 'journal_b5' => 'Jurnal Jurnalan B5',
        'loose_leaf_a5' => 'Loose Leaf A5', 'loose_leaf_b5' => 'Loose Leaf B5',
        'ring_binder' => 'Ring Binder', 'cover_a5' => 'Sampul A5', 'cover_b5' => 'Sampul B5',
    ];

    public function __construct(private PDO $db) { $this->ensureSchema(); }

    private function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS product_costs (sku_id VARCHAR(255) PRIMARY KEY, unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL) ENGINE=InnoDB");
        $this->db->exec("CREATE TABLE IF NOT EXISTS order_line_costs (order_process_id BIGINT PRIMARY KEY, sku_id VARCHAR(255) NOT NULL, unit_cost DECIMAL(18,2) NOT NULL, captured_at BIGINT NOT NULL, INDEX ix_order_line_costs_sku(sku_id)) ENGINE=InnoDB");
        $this->db->exec("CREATE TABLE IF NOT EXISTS monthly_profit_expenses (month_start DATE PRIMARY KEY, tiktok_fee DECIMAL(18,2) NOT NULL DEFAULT 0, external_ads DECIMAL(18,2) NOT NULL DEFAULT 0, shipping_packing DECIMAL(18,2) NOT NULL DEFAULT 0, payroll DECIMAL(18,2) NOT NULL DEFAULT 0, operations DECIMAL(18,2) NOT NULL DEFAULT 0, other DECIMAL(18,2) NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL) ENGINE=InnoDB");
    }

    public function costs(): array
    {
        $configured=[];foreach($this->db->query('SELECT sku_id,unit_cost FROM product_costs')->fetchAll() as $row)$configured[(string)$row['sku_id']]=(float)$row['unit_cost'];
        $categories=[];foreach(self::CATEGORY_COSTS as $id=>$label){$key='category:'.$id;$categories[]=['key'=>$key,'label'=>$label,'unit_cost'=>$configured[$key]??0,'configured'=>isset($configured[$key])];}
        $stickers=[];$addSticker=function(array $mapping)use(&$stickers,$configured):void{$category=self::categoryFor($mapping);if(($category['type']??'')!=='sticker')return;$key=(string)$category['key'];if(isset($stickers[$key]))return;$stickers[$key]=['key'=>$key,'sku_id'=>(string)$mapping['sku_id'],'product_name'=>(string)$mapping['product_name'],'variation_name'=>(string)$mapping['variation_name'],'unit_cost'=>$configured[$key]??0,'configured'=>isset($configured[$key])];};
        foreach($this->db->query("SELECT sku_id,product_name,variation_name,group_name,paper,duplex FROM data_mappings WHERE sku_id<>'' ORDER BY product_name,variation_name")->fetchAll() as $mapping)$addSticker($mapping);
        foreach($this->db->query("SELECT item_key,model_sku,item_sku,item_name,model_name FROM order_process WHERE item_name LIKE '%STIKER%' OR item_name LIKE '%STICKER%' OR model_name LIKE '%STIKER%' OR model_name LIKE '%STICKER%' GROUP BY item_key,model_sku,item_sku,item_name,model_name ORDER BY item_name,model_name")->fetchAll() as $line)$addSticker($this->lineAsMapping($line));
        $stickers=array_values($stickers);
        return ['categories'=>$categories,'stickers'=>$stickers];
    }

    public function saveCost(string $key, float $cost): void
    {
        $key=trim($key);if($key===''||$cost<0)throw new InvalidArgumentException($key===''?'Kategori HPP wajib dipilih.':'HPP tidak boleh negatif.');
        if(str_starts_with($key,'category:')){if(!isset(self::CATEGORY_COSTS[substr($key,9)]))throw new InvalidArgumentException('Kategori HPP tidak valid.');}
        elseif(str_starts_with($key,'sticker:')){$mapping=null;foreach($this->db->query("SELECT sku_id,product_name,variation_name,group_name,paper,duplex FROM data_mappings WHERE sku_id<>''")->fetchAll() as $candidate)if((self::categoryFor($candidate)['key']??'')===$key){$mapping=$candidate;break;}if(!$mapping)foreach($this->db->query("SELECT item_key,model_sku,item_sku,item_name,model_name FROM order_process WHERE item_name LIKE '%STIKER%' OR item_name LIKE '%STICKER%' OR model_name LIKE '%STIKER%' OR model_name LIKE '%STICKER%'")->fetchAll() as $line){$candidate=$this->lineAsMapping($line);if((self::categoryFor($candidate)['key']??'')===$key){$mapping=$candidate;break;}}if(!$mapping)throw new InvalidArgumentException('SKU sticker tidak ditemukan.');}
        else throw new InvalidArgumentException('Kategori HPP tidak valid.');
        $stmt=$this->db->prepare('INSERT INTO product_costs(sku_id,unit_cost,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE unit_cost=VALUES(unit_cost),updated_at=VALUES(updated_at)');$stmt->execute([$key,$cost,time()]);
    }

    public function snapshotLine(int $lineId): void
    {
        $exists=$this->db->prepare('SELECT 1 FROM order_line_costs WHERE order_process_id=?');$exists->execute([$lineId]);if($exists->fetchColumn())return;
        $line=$this->db->prepare('SELECT item_key,model_sku,item_sku FROM order_process WHERE id=?');$line->execute([$lineId]);$row=$line->fetch();if(!$row)return;
        $cost=$this->resolveCostFromLookup($row,$this->costLookup());if($cost===null)return;
        $save=$this->db->prepare('INSERT IGNORE INTO order_line_costs(order_process_id,sku_id,unit_cost,captured_at) VALUES(?,?,?,?)');$save->execute([$lineId,$cost['sku'], $cost['cost'],time()]);
    }

    public function dashboard(DateTimeImmutable $month): array
    {
        $from=$month->modify('first day of this month');$to=$from->modify('+1 month');$start=$from->getTimestamp();$end=$to->getTimestamp();
        $expenses=$this->expenses($from);
        $orders=$this->db->prepare("SELECT o.order_sn,o.raw_json,CASE WHEN o.order_sn LIKE 'TIKTOK:%' THEN 'tiktok' ELSE 'shopee' END marketplace FROM orders o WHERE o.create_time>=? AND o.create_time<? AND o.order_sn NOT LIKE 'MANUAL-%' AND o.order_sn NOT LIKE 'RANDOM-%' AND UPPER(o.status) NOT IN ('CANCELLED','CANCELED')");
        $orders->execute([$start,$end]);$orderRows=$orders->fetchAll();$revenue=['shopee'=>0.0,'tiktok'=>0.0];foreach($orderRows as $order)$revenue[$order['marketplace']]+=self::orderAmount((string)$order['raw_json'],(string)$order['marketplace']);
        $lines=$this->db->prepare("SELECT op.id,op.item_key,op.model_sku,op.item_sku,op.item_name,op.model_name,op.qty,olc.sku_id snapshot_sku,olc.unit_cost snapshot_cost FROM order_process op JOIN orders o ON o.order_sn=op.order_sn LEFT JOIN order_line_costs olc ON olc.order_process_id=op.id WHERE o.create_time>=? AND o.create_time<? AND o.order_sn NOT LIKE 'MANUAL-%' AND o.order_sn NOT LIKE 'RANDOM-%' AND UPPER(o.status) NOT IN ('CANCELLED','CANCELED') AND op.qty>0");
        $lines->execute([$start,$end]);$costLookup=$this->costLookup();$cogs=0.0;$estimatedUnits=0;$missingUnits=0;$missing=[];foreach($lines->fetchAll() as $line){$qty=(int)$line['qty'];if($line['snapshot_sku']!==null){$cogs+=$qty*(float)$line['snapshot_cost'];continue;}$cost=$this->resolveCostFromLookup($line,$costLookup);if($cost===null){$missingUnits+=$qty;$missing[(string)($line['item_key']?:$line['item_name'])]=true;continue;}$cogs+=$qty*$cost['cost'];$estimatedUnits+=$qty;}
        $fee=$this->db->prepare("SELECT COUNT(*) orders,COALESCE(SUM(e.total_marketplace_fee),0) fees,COALESCE(SUM(e.payout_amount),0) payout FROM shopee_escrow_details e JOIN orders o ON o.order_sn=e.order_sn WHERE e.order_create_time>=? AND e.order_create_time<? AND o.order_sn NOT LIKE 'TIKTOK:%' AND o.order_sn NOT LIKE 'MANUAL-%' AND o.order_sn NOT LIKE 'RANDOM-%' AND UPPER(o.status) NOT IN ('CANCELLED','CANCELED')");$fee->execute([$start,$end]);$escrow=$fee->fetch()?:[];$shopeeOrders=count(array_filter($orderRows,fn($row)=>$row['marketplace']==='shopee'));
        $summary=self::calculate($revenue,$cogs,(float)($escrow['fees']??0),$expenses);
        return ['month'=>$from->format('Y-m'),'label'=>$this->monthLabel($from),'revenue'=>$revenue,'expenses'=>$expenses,'cogs'=>$cogs,'marketplaceFees'=>(float)($escrow['fees']??0),'payout'=>(float)($escrow['payout']??0),'netProfit'=>$summary['netProfit'],'margin'=>$summary['margin'],'coverage'=>['shopeeOrders'=>$shopeeOrders,'escrowOrders'=>(int)($escrow['orders']??0),'escrowPercent'=>$shopeeOrders?round((int)($escrow['orders']??0)/$shopeeOrders*100,1):100,'missingUnits'=>$missingUnits,'estimatedUnits'=>$estimatedUnits,'missingSkus'=>array_keys($missing)]];
    }

    public function history(DateTimeImmutable $month): array
    {
        $items=[];for($i=11;$i>=0;$i--){$report=$this->dashboard($month->modify('first day of this month')->modify('-'.$i.' months'));$items[]=['month'=>$report['month'],'label'=>$report['label'],'revenue'=>array_sum($report['revenue']),'netProfit'=>$report['netProfit'],'margin'=>$report['margin']];}return $items;
    }

    public function expenses(DateTimeImmutable $month): array
    {
        $stmt=$this->db->prepare('SELECT * FROM monthly_profit_expenses WHERE month_start=?');$stmt->execute([$month->modify('first day of this month')->format('Y-m-d')]);$row=$stmt->fetch()?:[];$result=[];foreach(self::EXPENSES as $key=>$label)$result[$key]=(float)($row[$key]??0);return $result;
    }

    public function saveExpenses(DateTimeImmutable $month,array $input): void
    {
        $values=[];foreach(self::EXPENSES as $key=>$_){$value=(float)($input[$key]??0);if($value<0)throw new InvalidArgumentException('Biaya tidak boleh negatif.');$values[$key]=$value;}
        $sql='INSERT INTO monthly_profit_expenses(month_start,'.implode(',',array_keys(self::EXPENSES)).',updated_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '.implode(',',array_map(fn($key)=>$key.'=VALUES('.$key.')',array_keys(self::EXPENSES))).',updated_at=VALUES(updated_at)';
        $this->db->prepare($sql)->execute(array_merge([$month->modify('first day of this month')->format('Y-m-d')],array_values($values),[time()]));
    }

    public static function calculate(array $revenue,float $cogs,float $marketplaceFees,array $expenses): array
    {
        $total=array_sum($revenue);$net=$total-$cogs-$marketplaceFees-array_sum($expenses);return ['netProfit'=>$net,'margin'=>$total>0?round($net/$total*100,2):0.0];
    }

    private static function orderAmount(string $json,string $marketplace): float
    {
        $raw=json_decode($json,true);if(!is_array($raw))return 0.0;$primary=$marketplace==='tiktok'?(float)($raw['payment']['total_amount']??0):(float)($raw['total_amount']??0);if($primary>0)return $primary;if($marketplace==='tiktok'){ $fallback=(float)($raw['payment']['sub_total']??0);if($fallback>0)return $fallback;foreach(($raw['line_items']??[]) as $line)$fallback+=(float)($line['sale_price']??0)*max(1,(int)($line['quantity']??1));return $fallback; }$fallback=0.0;foreach(($raw['item_list']??[]) as $line)$fallback+=(float)($line['model_discounted_price']??$line['model_original_price']??0)*max(1,(int)($line['model_quantity_purchased']??1));return $fallback;
    }

    private function costLookup(): array
    {
        $costs=[];foreach($this->db->query('SELECT sku_id,unit_cost FROM product_costs')->fetchAll() as $row)$costs[(string)$row['sku_id']]=(float)$row['unit_cost'];$lookup=[];$byId=[];
        foreach($this->db->query("SELECT id,sku_id,product_name,variation_name,group_name,paper,duplex FROM data_mappings WHERE sku_id<>''")->fetchAll() as $mapping){$category=self::categoryFor($mapping);if($category===null||!array_key_exists($category['key'],$costs))continue;$cost=['sku'=>$category['key'],'cost'=>$costs[$category['key']]];$byId[(int)$mapping['id']]=$cost;$keys=$this->keys(['item_key'=>$mapping['sku_id']]);if($keys)$lookup[$keys[0]]=$cost;}
        if($byId)foreach($this->db->query('SELECT mapping_id,alias_key FROM mapping_aliases')->fetchAll() as $row)if(isset($byId[(int)$row['mapping_id']]))$lookup[(string)$row['alias_key']]=$byId[(int)$row['mapping_id']];return ['mapped'=>$lookup,'costs'=>$costs];
    }
    private function resolveCostFromLookup(array $line,array $lookup): ?array { foreach($this->keys($line) as $key)if(isset($lookup['mapped'][$key]))return $lookup['mapped'][$key];$category=self::categoryFor($this->lineAsMapping($line));if(($category['type']??'')==='sticker'&&isset($lookup['costs'][$category['key']]))return ['sku'=>$category['key'],'cost'=>$lookup['costs'][$category['key']]];return null; }
    public static function categoryFor(array $mapping): ?array
    {
        $sku=trim((string)($mapping['sku_id']??''));$group=mb_strtolower(trim((string)($mapping['group_name']??'')));$text=mb_strtolower(implode(' ',[$group,(string)($mapping['product_name']??''),(string)($mapping['variation_name']??'')]));$paper=strtoupper(trim((string)($mapping['paper']??'')));if($paper!=='A5'&&$paper!=='B5')$paper=str_contains($text,'b5')?'B5':(str_contains($text,'a5')?'A5':'');
        if(str_contains($text,'sticker')||str_contains($text,'stiker')){$identity=mb_strtolower(trim((string)($mapping['product_name']??'').'|'.(string)($mapping['variation_name']??'')));return $identity===''?null:['type'=>'sticker','key'=>'sticker:name:'.hash('sha256',$identity)];}
        if(preg_match('/sampul|cover/u',$text))return $paper===''?null:['type'=>'category','key'=>'category:cover_'.strtolower($paper)];
        if(str_contains($text,'loose leaf')||str_contains($text,'looseleaf')||preg_match('/^l\b/u',$group))return $paper===''?null:['type'=>'category','key'=>'category:loose_leaf_'.strtolower($paper)];
        if(str_contains($text,'jurnal')||str_contains($text,'journal')||preg_match('/^[pj]\b/u',$group))return $paper===''?null:['type'=>'category','key'=>'category:journal_'.strtolower($paper)];
        if(str_contains($text,'ring binder')||str_contains($text,'binder'))return ['type'=>'category','key'=>'category:ring_binder'];
        return null;
    }
    private function lineAsMapping(array $line): array { $name=(string)($line['item_name']??'');$variation=(string)($line['model_name']??'');$sku=trim((string)($line['item_key']??''))?:trim((string)($line['model_sku']??''))?:trim((string)($line['item_sku']??''));if($sku==='')$sku='name:'.hash('sha256',mb_strtolower(trim($name.'|'.$variation)));return ['sku_id'=>$sku,'product_name'=>$name,'variation_name'=>$variation,'group_name'=>'','paper'=>'','duplex'=>'']; }
    private function keys(array $line): array { $norm=fn($value)=>strtoupper(preg_replace('/\s+/','',trim((string)$value)));return array_values(array_unique(array_filter([$norm($line['item_key']??''),$norm(($line['model_sku']??'').($line['item_sku']??'')),$norm(($line['item_sku']??'').($line['model_sku']??'')),$norm($line['model_sku']??''),$norm($line['item_sku']??'')]))); }
    private function monthLabel(DateTimeImmutable $month): string { return ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'][(int)$month->format('n')-1].' '.$month->format('Y'); }
}
