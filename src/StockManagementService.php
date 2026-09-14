<?php
declare(strict_types=1);

/** Curated, manually linked Shopee ↔ TikTok/Tokopedia SKU stock management. */
final class StockManagementService
{
    private ShopeeStockService $shopee;
    private TikTokStockService $tiktok;

    public function __construct(private PDO $db, MarketplaceOAuthService $oauth)
    {
        $this->shopee=new ShopeeStockService($db,$oauth);$this->tiktok=new TikTokStockService($db,$oauth);
        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_management_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, shopee_item_id VARCHAR(80) NOT NULL, shopee_model_id VARCHAR(80) NOT NULL DEFAULT '0', tiktok_product_id VARCHAR(80) NOT NULL, tiktok_sku_id VARCHAR(80) NOT NULL, label VARCHAR(500) NOT NULL, shopee_name VARCHAR(500) NOT NULL, shopee_model_name VARCHAR(500) NOT NULL, tiktok_name VARCHAR(500) NOT NULL, tiktok_seller_sku VARCHAR(255) NOT NULL, created_by VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE KEY uq_stock_management_pair(shopee_item_id,shopee_model_id,tiktok_product_id,tiktok_sku_id), INDEX ix_stock_management_label(label)) ENGINE=InnoDB");
        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_management_runs (id BIGINT AUTO_INCREMENT PRIMARY KEY, created_by VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, completed_at BIGINT NULL, requested_items INT NOT NULL DEFAULT 0, success_items INT NOT NULL DEFAULT 0, failed_items INT NOT NULL DEFAULT 0) ENGINE=InnoDB");
        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_management_run_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, run_id BIGINT NOT NULL, management_item_id BIGINT NOT NULL, shopee_before INT NULL, shopee_target INT NULL, shopee_status VARCHAR(20) NOT NULL, shopee_error TEXT NOT NULL, tiktok_before INT NULL, tiktok_target INT NULL, tiktok_status VARCHAR(20) NOT NULL, tiktok_error TEXT NOT NULL, created_at BIGINT NOT NULL, INDEX ix_stock_management_run(run_id)) ENGINE=InnoDB");
    }

    public function search(string $provider,string $query): array { return $provider==='shopee'?$this->shopee->search($query):($provider==='tiktok'?$this->tiktok->search($query):throw new InvalidArgumentException('Marketplace pencarian tidak didukung.')); }
    public function searchParents(string $provider,string $query): array
    {
        $rows=$this->search($provider,$query)['items'];$parents=[];
        foreach($rows as $row){if($provider==='shopee'){$key=(string)$row['item_id'];$parents[$key]??=['id'=>$key,'title'=>(string)$row['item_name'],'sku'=>(string)($row['model_sku']??''),'variants'=>0];$parents[$key]['variants']++;}
            else {$key=(string)$row['product_id'];$parents[$key]??=['id'=>$key,'title'=>(string)$row['title'],'sku'=>(string)($row['seller_sku']??''),'variants'=>0];$parents[$key]['variants']++;}}
        return ['items'=>array_values($parents)];
    }
    public function overview(): array { $items=[];foreach($this->rows() as $row)$items[]=$this->hydrate($row);return ['items'=>$items]; }

    public function create(array $input,string $user): array
    {
        $si=(int)($input['shopee_item_id']??0);$sm=(int)($input['shopee_model_id']??-1);$tp=trim((string)($input['tiktok_product_id']??''));$ts=trim((string)($input['tiktok_sku_id']??''));if($si<1||$sm<0||!ctype_digit($tp)||!ctype_digit($ts))throw new InvalidArgumentException('Pilih variasi Shopee dan SKU TikTok terlebih dahulu.');
        $s=$this->findShopee($this->shopee->product($si),$sm);$t=$this->findTikTok($this->tiktok->product($tp),$ts);$now=time();$label=trim((string)($input['label']??''))?:((string)$s['item_name'].($s['model_name']?' · '.$s['model_name']:''));
        $stmt=$this->db->prepare('INSERT INTO stock_management_items(shopee_item_id,shopee_model_id,tiktok_product_id,tiktok_sku_id,label,shopee_name,shopee_model_name,tiktok_name,tiktok_seller_sku,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        try{$stmt->execute([(string)$si,(string)$sm,$tp,$ts,mb_substr($label,0,500),mb_substr((string)$s['item_name'],0,500),mb_substr((string)$s['model_name'],0,500),mb_substr((string)$t['title'],0,500),mb_substr((string)$t['seller_sku'],0,255),mb_substr($user,0,100),$now,$now]);}catch(PDOException $e){if((string)$e->getCode()==='23000')throw new RuntimeException('Pasangan SKU ini sudah ada di Stock Management.');throw $e;}
        return $this->hydrate($this->row((int)$this->db->lastInsertId()));
    }

    /** Link every exactly matching child SKU after the user chooses two product parents. */
    public function createAuto(int $shopeeItemId,string $tiktokProductId,string $user): array
    {
        if($shopeeItemId<1||!ctype_digit($tiktokProductId))throw new InvalidArgumentException('Pilih produk induk Shopee dan TikTok terlebih dahulu.');
        $shopee=$this->shopee->product($shopeeItemId);$tiktok=$this->tiktok->product($tiktokProductId);$models=$shopee['models']??[];$skus=$tiktok['skus']??[];$pairs=[];$unmatched=[];
        if(count($models)===1&&count($skus)===1)$pairs[]=['model'=>$models[0],'sku'=>$skus[0]];
        else {
            $bySku=$this->uniqueIndex($skus,'seller_sku');
            $byName=$this->uniqueIndex($skus,'variant_name');
            foreach($models as $model){
                $skuKey=$this->key((string)($model['model_sku']??''));
                $nameKey=$this->key((string)($model['model_name']??''));
                $sku=$skuKey!==''?($bySku[$skuKey]??null):null;
                if($sku===null&&$nameKey!=='')$sku=$byName[$nameKey]??null;
                if($sku!==null)$pairs[]=['model'=>$model,'sku'=>$sku];else $unmatched[]=(string)($model['model_name']?:$model['model_sku']?:'Variasi tanpa SKU');
            }
        }
        $created=[];$skipped=[];foreach($pairs as $pair){try{$created[]=$this->create(['shopee_item_id'=>$shopeeItemId,'shopee_model_id'=>$pair['model']['model_id'],'tiktok_product_id'=>$tiktokProductId,'tiktok_sku_id'=>$pair['sku']['sku_id']],$user);}catch(Throwable $e){$skipped[]=$e->getMessage();}}
        return ['ok'=>true,'created'=>count($created),'items'=>$created,'unmatched'=>$unmatched,'skipped'=>$skipped];
    }

    public function delete(int $id): array { $stmt=$this->db->prepare('DELETE FROM stock_management_items WHERE id=?');$stmt->execute([$id]);if(!$stmt->rowCount())throw new RuntimeException('Produk terkelola tidak ditemukan.');return['ok'=>true]; }

    public function apply(array $changes,string $user): array
    {
        $changes=array_values(array_filter($changes,'is_array'));if(!$changes)throw new InvalidArgumentException('Tidak ada perubahan stok untuk disimpan.');$now=time();$run=$this->db->prepare('INSERT INTO stock_management_runs(created_by,created_at,requested_items) VALUES(?,?,?)');$run->execute([mb_substr($user,0,100),$now,count($changes)]);$runId=(int)$this->db->lastInsertId();$results=[];$success=0;$failed=0;
        foreach($changes as $change){$id=(int)($change['id']??0);$row=$this->row($id);$shTarget=array_key_exists('shopee_quantity',$change)&&$change['shopee_quantity']!==null?(int)$change['shopee_quantity']:null;$ttTarget=array_key_exists('tiktok_quantity',$change)&&$change['tiktok_quantity']!==null?(int)$change['tiktok_quantity']:null;if(($shTarget!==null&&$shTarget<0)||($ttTarget!==null&&$ttTarget<0))throw new InvalidArgumentException('Stok target tidak boleh negatif.');$shBefore=null;$ttBefore=null;$shStatus='unchanged';$ttStatus='unchanged';$shError='';$ttError='';
            if($shTarget!==null)try{$current=$this->findShopee($this->shopee->product((int)$row['shopee_item_id']),(int)$row['shopee_model_id']);$shBefore=$current['stock'];if($shBefore!==$shTarget){$this->shopee->update((int)$row['shopee_item_id'],(int)$row['shopee_model_id'],$shTarget,$user);$shStatus='updated';}}catch(Throwable $e){$shStatus='failed';$shError=$e->getMessage();}
            if($ttTarget!==null)try{$current=$this->findTikTok($this->tiktok->product((string)$row['tiktok_product_id']),(string)$row['tiktok_sku_id']);$ttBefore=$current['stock'];if($ttBefore!==$ttTarget){$this->tiktok->update((string)$row['tiktok_product_id'],(string)$row['tiktok_sku_id'],$ttTarget,$user);$ttStatus='updated';}}catch(Throwable $e){$ttStatus='failed';$ttError=$e->getMessage();}
            $ok=$shStatus!=='failed'&&$ttStatus!=='failed';$success+=$ok?1:0;$failed+=$ok?0:1;$log=$this->db->prepare('INSERT INTO stock_management_run_items(run_id,management_item_id,shopee_before,shopee_target,shopee_status,shopee_error,tiktok_before,tiktok_target,tiktok_status,tiktok_error,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$log->execute([$runId,$id,$shBefore,$shTarget,$shStatus,mb_substr($shError,0,2000),$ttBefore,$ttTarget,$ttStatus,mb_substr($ttError,0,2000),time()]);$results[]=['id'=>$id,'shopee'=>['status'=>$shStatus,'error'=>$shError,'before'=>$shBefore,'target'=>$shTarget],'tiktok'=>['status'=>$ttStatus,'error'=>$ttError,'before'=>$ttBefore,'target'=>$ttTarget]];
        }
        $this->db->prepare('UPDATE stock_management_runs SET completed_at=?,success_items=?,failed_items=? WHERE id=?')->execute([time(),$success,$failed,$runId]);return['ok'=>$failed===0,'run_id'=>$runId,'success_items'=>$success,'failed_items'=>$failed,'items'=>$results];
    }

    private function rows(): array { return $this->db->query('SELECT * FROM stock_management_items ORDER BY label,id')->fetchAll(); }
    private function row(int $id): array {$stmt=$this->db->prepare('SELECT * FROM stock_management_items WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Produk terkelola tidak ditemukan.');return $row;}
    private function hydrate(array $row): array { $out=['id'=>(int)$row['id'],'label'=>$row['label'],'shopee'=>['item_id'=>$row['shopee_item_id'],'model_id'=>$row['shopee_model_id'],'name'=>$row['shopee_name'],'model_name'=>$row['shopee_model_name'],'stock'=>null,'error'=>''],'tiktok'=>['product_id'=>$row['tiktok_product_id'],'sku_id'=>$row['tiktok_sku_id'],'name'=>$row['tiktok_name'],'seller_sku'=>$row['tiktok_seller_sku'],'stock'=>null,'error'=>'']];try{$out['shopee']['stock']=$this->findShopee($this->shopee->product((int)$row['shopee_item_id']),(int)$row['shopee_model_id'])['stock'];}catch(Throwable $e){$out['shopee']['error']=$e->getMessage();}try{$out['tiktok']['stock']=$this->findTikTok($this->tiktok->product((string)$row['tiktok_product_id']),(string)$row['tiktok_sku_id'])['stock'];}catch(Throwable $e){$out['tiktok']['error']=$e->getMessage();}return $out; }
    private function findShopee(array $product,int $modelId): array {foreach($product['models'] as $model)if((int)$model['model_id']===$modelId)return $model+['item_name'=>$product['item_name']];throw new RuntimeException('Variasi Shopee tidak ditemukan.');}
    private function findTikTok(array $product,string $skuId): array {foreach($product['skus'] as $sku)if((string)$sku['sku_id']===$skuId)return $sku+['title'=>$product['title']];throw new RuntimeException('SKU TikTok tidak ditemukan.');}
    private function key(string $value): string {return preg_replace('/[^a-z0-9]+/','',strtolower(trim($value)))??'';}
    private function uniqueIndex(array $rows,string $field): array
    {
        $index=[];$duplicates=[];
        foreach($rows as $row){$key=$this->key((string)($row[$field]??''));if($key==='')continue;if(isset($index[$key]))$duplicates[$key]=true;else $index[$key]=$row;}
        foreach($duplicates as $key=>$_)unset($index[$key]);
        return $index;
    }
}
