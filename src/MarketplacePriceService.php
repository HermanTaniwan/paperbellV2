<?php
declare(strict_types=1);

final class MarketplacePriceService
{
    public function __construct(private PDO $db, private MarketplaceOAuthService $oauth)
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS marketplace_price_updates (id BIGINT AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(20) NOT NULL, product_id VARCHAR(80) NOT NULL, sku_id VARCHAR(80) NOT NULL DEFAULT '', product_name VARCHAR(500) NOT NULL, price_before BIGINT NOT NULL, price_after BIGINT NOT NULL, status VARCHAR(20) NOT NULL, error TEXT NOT NULL, created_by VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, INDEX ix_marketplace_price_updates_created(created_at)) ENGINE=InnoDB");
    }

    public function recentUpdates(int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        $rows=$this->db->query("SELECT provider,product_id,sku_id,product_name,price_before,price_after,status,error,created_at FROM marketplace_price_updates ORDER BY id DESC LIMIT {$limit}")->fetchAll();
        return ['items'=>array_reverse($rows)];
    }

    public function updateSummary(): array
    {
        return ['items'=>$this->db->query('SELECT provider,status,COUNT(*) variations,COUNT(DISTINCT product_id) products,MIN(created_at) first_at,MAX(created_at) last_at FROM marketplace_price_updates GROUP BY provider,status ORDER BY provider,status')->fetchAll()];
    }

    public function shopeeCatalogDiagnostics(): array
    {
        $auth=$this->oauth->credentials('shopee');$ids=[];
        foreach(['NORMAL','UNLIST'] as $status){$offset=0;do{$json=$this->shopee('GET','/api/v2/product/get_item_list',['offset'=>$offset,'page_size'=>100,'item_status'=>$status],null,$auth);$items=$json['response']['item']??$json['response']['item_list']??[];foreach($items as $item)if(!empty($item['item_id']))$ids[(string)$item['item_id']]=true;$offset+=count($items);$more=(bool)($json['response']['has_next_page']??false);}while($more&&$offset<10000);}
        $base=[];foreach(array_chunk(array_keys($ids),50) as $batch){$json=$this->shopee('GET','/api/v2/product/get_item_base_info',['item_id_list'=>implode(',',$batch)],null,$auth);foreach(($json['response']['item_list']??[]) as $item)$base[]=$item;}
        $sample=[];foreach($base as $item){$sample[]=['keys'=>array_keys($item),'title'=>(string)($item['item_name']??$item['name']??''),'sku'=>(string)($item['item_sku']??'')];if(count($sample)>=10)break;}
        return ['listed_items'=>count($ids),'base_items'=>count($base),'sample'=>$sample];
    }

    /** Increase every SKU belonging to a looseleaf or journal product. */
    public function raiseLooseleafAndJournalPrices(int $increment, string $user, ?string $onlyProvider = null): array
    {
        if ($increment !== 500) throw new InvalidArgumentException('Kenaikan harga dikunci Rp500 untuk operasi ini.');
        if ($onlyProvider !== null && !in_array($onlyProvider, ['shopee', 'tiktok'], true)) throw new InvalidArgumentException('Marketplace tidak didukung.');
        $report = ['ok'=>true, 'increment'=>$increment, 'providers'=>[]];
        foreach ($onlyProvider === null ? ['shopee', 'tiktok'] : [$onlyProvider] as $provider) {
            $lock = 'paperbell_price_'.$provider;
            if ((int)$this->db->query('SELECT GET_LOCK('.$this->db->quote($lock).',0)')->fetchColumn() !== 1) throw new RuntimeException('Update harga '.$provider.' sedang berjalan.');
            try { $report['providers'][$provider] = $provider === 'shopee' ? $this->raiseShopee($increment, $user) : $this->raiseTikTok($increment, $user); }
            finally { $this->db->query('SELECT RELEASE_LOCK('.$this->db->quote($lock).')'); }
        }
        return $report;
    }

    private function raiseShopee(int $increment, string $user): array
    {
        $auth=$this->oauth->credentials('shopee'); $ids=[];
        foreach (['NORMAL','UNLIST'] as $status) { $offset=0; do { $json=$this->shopee('GET','/api/v2/product/get_item_list',['offset'=>$offset,'page_size'=>100,'item_status'=>$status],null,$auth); $items=$json['response']['item'] ?? $json['response']['item_list'] ?? []; foreach($items as $item)if(!empty($item['item_id']))$ids[(string)$item['item_id']]=$item; $offset+=count($items); $more=(bool)($json['response']['has_next_page'] ?? false); } while($more && $offset<10000); }
        $base=[]; foreach(array_chunk(array_keys($ids),50) as $batch) { $json=$this->shopee('GET','/api/v2/product/get_item_base_info',['item_id_list'=>implode(',',$batch)],null,$auth); foreach(($json['response']['item_list'] ?? []) as $item)$base[(string)$item['item_id']]=$item; }
        $groups=[]; foreach(array_keys($ids) as $itemId) { $title=trim((string)($base[$itemId]['item_name'] ?? $ids[$itemId]['item_name'] ?? '')); if(!$this->matchesTitle($title))continue; $json=$this->shopee('GET','/api/v2/product/get_model_list',['item_id'=>$itemId],null,$auth); $models=$json['response']['model'] ?? []; if(!$models)$models=[['model_id'=>0,'price_info'=>$base[$itemId]['price_info'] ?? []]]; foreach($models as $model) { $before=$this->shopeePrice($model); if($before===null)continue; $groups[$itemId][]=['product_id'=>$itemId,'sku_id'=>(string)($model['model_id'] ?? 0),'product_name'=>$title,'price_before'=>$before,'price_after'=>$before+$increment]; } }
        return $this->applyShopee($groups,$auth,$user);
    }

    private function raiseTikTok(int $increment, string $user): array
    {
        $auth=$this->oauth->credentials('tiktok'); $products=[]; $token='';
        do { $query=['page_size'=>100]; if($token!=='')$query['page_token']=$token; $json=$this->tiktok('POST','/product/202309/products/search',$query,[],$auth); foreach(($json['data']['products'] ?? []) as $product)if(!empty($product['id']))$products[(string)$product['id']]=$product; $token=(string)($json['data']['next_page_token'] ?? ''); } while($token!=='' && count($products)<10000);
        $groups=[]; foreach($products as $productId=>$summary) { $productId=(string)$productId; $json=$this->tiktok('GET','/product/202309/products/'.rawurlencode($productId),[],null,$auth); $product=$json['data']['product'] ?? $json['data'] ?? []; $title=trim((string)($product['title'] ?? $product['product_name'] ?? $summary['name'] ?? '')); if(!$this->matchesTitle($title))continue; foreach(($product['skus'] ?? []) as $sku) { $before=$this->tiktokPrice($sku); $skuId=(string)($sku['id'] ?? ''); if($before===null||$skuId==='')continue; $groups[$productId][]=['product_id'=>$productId,'sku_id'=>$skuId,'product_name'=>$title,'currency'=>(string)($sku['price']['currency'] ?? 'IDR'),'price_before'=>$before,'price_after'=>$before+$increment]; } }
        return $this->applyTikTok($groups,$auth,$user);
    }

    private function applyShopee(array $groups,array $auth,string $user): array
    {
        $updated=[];$errors=[]; foreach($groups as $itemId=>$rows) { try { $this->shopee('POST','/api/v2/product/update_price',[],['item_id'=>(int)$itemId,'price_list'=>array_map(fn($row)=>['model_id'=>(int)$row['sku_id'],'original_price'=>$row['price_after']],$rows)],$auth); $this->audit('shopee',$rows,'updated','',$user); array_push($updated,...$rows); } catch(Throwable $e) { $this->audit('shopee',$rows,'failed',$e->getMessage(),$user); $errors[]=['product_id'=>$itemId,'message'=>$e->getMessage()]; } }
        return ['products_scanned'=>count($groups),'variations_matched'=>array_sum(array_map('count',$groups)),'updated'=>$updated,'errors'=>$errors];
    }
    private function applyTikTok(array $groups,array $auth,string $user): array
    {
        $updated=[];$errors=[]; foreach($groups as $productId=>$rows) { $productId=(string)$productId; try { $this->tiktok('POST','/product/202309/products/'.rawurlencode($productId).'/prices/update',[],['skus'=>array_map(fn($row)=>['id'=>$row['sku_id'],'price'=>['amount'=>(string)$row['price_after'],'currency'=>$row['currency']]],$rows)],$auth); $this->audit('tiktok',$rows,'updated','',$user); array_push($updated,...$rows); } catch(Throwable $e) { $this->audit('tiktok',$rows,'failed',$e->getMessage(),$user); $errors[]=['product_id'=>$productId,'message'=>$e->getMessage()]; } }
        return ['products_scanned'=>count($groups),'variations_matched'=>array_sum(array_map('count',$groups)),'updated'=>$updated,'errors'=>$errors];
    }
    private function audit(string $provider,array $rows,string $status,string $error,string $user): void
    {
        $stmt=$this->db->prepare('INSERT INTO marketplace_price_updates(provider,product_id,sku_id,product_name,price_before,price_after,status,error,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)'); $now=time(); foreach($rows as $row)$stmt->execute([$provider,$row['product_id'],$row['sku_id'],mb_substr($row['product_name'],0,500),$row['price_before'],$row['price_after'],$status,mb_substr($error,0,2000),mb_substr($user,0,100),$now]);
    }
    private function matchesTitle(string $title): bool { return preg_match('/loose\s*leaf|jurnal|journal/iu',$title)===1; }
    private function price(mixed $value): ?int { return is_numeric($value)&&(float)$value>0?(int)round((float)$value):null; }
    private function shopeePrice(array $model): ?int { foreach(['original_price','current_price'] as $key)if(($price=$this->price($model['price_info'][$key] ?? $model[$key] ?? null))!==null)return$price; return null; }
    private function tiktokPrice(array $sku): ?int { foreach(['original_price','sale_price','list_price'] as $key)if(($price=$this->price($sku['price'][$key] ?? $sku[$key] ?? null))!==null)return$price; return null; }
    private function shopee(string $method,string $path,array $extra,?array $body,array $auth): array
    {
        $cfg=$auth['config'];$partner=(string)($cfg['partner_id']??'');$token=(string)$auth['access_token'];$shop=(string)$auth['account_id'];$ts=time();$query=array_merge(['partner_id'=>$partner,'timestamp'=>$ts,'sign'=>hash_hmac('sha256',$partner.$path.$ts.$token.$shop,(string)($cfg['partner_key']??'')),'access_token'=>$token,'shop_id'=>$shop],$extra);$json=$this->json($method,rtrim((string)($cfg['api_host']??'https://partner.shopeemobile.com'),'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),$body===null?[]:['Content-Type: application/json'],$body);$error=(string)($json['error']??'');if($error!==''&&$error!=='0')throw new RuntimeException('Shopee API: '.($json['message']??$error).' ['.$error.']');return$json;
    }
    private function tiktok(string $method,string $path,array $query,?array $body,array $auth): array
    {
        $cfg=$auth['config'];$query['app_key']=(string)($cfg['app_key']??'');$query['shop_cipher']=(string)($cfg['shop_cipher']??'');$query['timestamp']=(string)time();ksort($query,SORT_STRING);$text=$body===null?'':json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$source=$path;foreach($query as $key=>$value)$source.=$key.$value;$source.=$text;$secret=(string)($cfg['app_secret']??'');$query['sign']=hash_hmac('sha256',$secret.$source.$secret,$secret);$json=$this->json($method,rtrim((string)($cfg['api_base']??'https://open-api.tiktokglobalshop.com'),'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),['Content-Type: application/json','x-tts-access-token: '.(string)$auth['access_token']],$body);if((int)($json['code']??-1)!==0)throw new RuntimeException('TikTok API '.($json['code']??'?').': '.($json['message']??'unknown error'));return$json;
    }
    private function json(string $method,string $url,array $headers,?array $body): array
    {
        $payload=$body===null?null:json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('Koneksi marketplace gagal: '.$error);$json=json_decode($raw,true);if(!is_array($json))throw new RuntimeException('Respons marketplace bukan JSON valid.');if($status<200||$status>=300)throw new RuntimeException('Marketplace HTTP '.$status.': '.($json['message']??$json['error']??'rejected'));return$json;
    }
}
