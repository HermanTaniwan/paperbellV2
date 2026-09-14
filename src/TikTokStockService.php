<?php
declare(strict_types=1);

/** Reads and updates one explicitly selected TikTok Shop SKU's warehouse inventory. */
final class TikTokStockService
{
    public function __construct(private PDO $db, private MarketplaceOAuthService $oauth)
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS marketplace_stock_updates (id BIGINT AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(20) NOT NULL, item_id VARCHAR(80) NOT NULL, model_id VARCHAR(80) NOT NULL DEFAULT '0', item_name VARCHAR(500) NOT NULL, model_name VARCHAR(500) NOT NULL, stock_before INT NULL, stock_after INT NOT NULL, status VARCHAR(20) NOT NULL, error TEXT NOT NULL, created_by VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, INDEX ix_marketplace_stock_updates_created(created_at)) ENGINE=InnoDB");
    }

    public function product(string $productId): array
    {
        $product = $this->detail($productId, $this->oauth->credentials('tiktok'));
        return ['product_id'=>$productId, 'title'=>(string)($product['title'] ?? ''), 'status'=>(string)($product['status'] ?? $product['product_status'] ?? ''), 'skus'=>array_map(fn(array $sku): array => ['sku_id'=>(string)($sku['id'] ?? ''), 'seller_sku'=>(string)($sku['seller_sku'] ?? ''), 'stock'=>$this->stock($sku['inventory'] ?? []), 'inventory'=>$sku['inventory'] ?? []], $product['skus'] ?? [])];
    }

    public function update(string $productId, string $skuId, int $quantity, string $user): array
    {
        if (!preg_match('/^\d+$/', $productId) || !preg_match('/^\d+$/', $skuId) || $quantity < 0) throw new InvalidArgumentException('ID produk, ID SKU, atau stok TikTok tidak valid.');
        $auth = $this->oauth->credentials('tiktok'); $product = $this->detail($productId, $auth); $sku = null;
        foreach (($product['skus'] ?? []) as $candidate) if ((string)($candidate['id'] ?? '') === $skuId) {$sku = $candidate; break;}
        if ($sku === null) throw new RuntimeException('SKU TikTok tidak ditemukan pada produk yang terhubung.');
        $inventory = $sku['inventory'] ?? [];
        if (!is_array($inventory) || count($inventory) !== 1 || !is_array($inventory[0] ?? null) || empty($inventory[0]['warehouse_id'])) throw new RuntimeException('Produk memiliki konfigurasi gudang TikTok yang tidak dapat diperbarui otomatis.');
        $before = $this->stock($inventory); $inventory[0]['quantity'] = $quantity;
        try {
            $this->tiktok('POST', '/product/202309/products/'.rawurlencode($productId).'/inventory/update', [], ['skus'=>[['id'=>$skuId, 'inventory'=>$inventory]]], $auth);
            $this->audit($productId, $skuId, (string)($product['title'] ?? ''), (string)($sku['seller_sku'] ?? ''), $before, $quantity, 'updated', '', $user);
            return ['ok'=>true, 'product_id'=>$productId, 'sku_id'=>$skuId, 'stock_before'=>$before, 'stock_after'=>$quantity, 'title'=>(string)($product['title'] ?? '')];
        } catch (Throwable $e) {
            $this->audit($productId, $skuId, (string)($product['title'] ?? ''), (string)($sku['seller_sku'] ?? ''), $before, $quantity, 'failed', $e->getMessage(), $user); throw $e;
        }
    }

    /** Search selectable TikTok SKUs. TikTok/Tokopedia inventory is shared by the connected shop. */
    public function search(string $query, int $limit=30): array
    {
        $query=mb_strtolower(trim($query)); if($query==='') return ['items'=>[]];
        $auth=$this->oauth->credentials('tiktok');$page=$this->tiktok('POST','/product/202309/products/search',['page_size'=>100],[],$auth)['data']['products']??[];$items=[];
        foreach($page as $summary){$title=(string)($summary['title']??$summary['name']??'');if(!str_contains(mb_strtolower($title),$query))continue;$id=(string)($summary['id']??'');if($id==='')continue;$product=$this->product($id);foreach($product['skus'] as $sku){$hay=mb_strtolower($title.' '.(string)($sku['seller_sku']??''));if(!str_contains($hay,$query))continue;$items[]=['product_id'=>$id,'sku_id'=>$sku['sku_id'],'title'=>$product['title'],'seller_sku'=>$sku['seller_sku'],'stock'=>$sku['stock']];if(count($items)>=$limit)return['items'=>$items];}}
        return ['items'=>$items];
    }

    private function detail(string $productId, array $auth): array
    {
        if (!preg_match('/^\d+$/', $productId)) throw new InvalidArgumentException('ID produk TikTok tidak valid.');
        $data = $this->tiktok('GET', '/product/202309/products/'.rawurlencode($productId), [], null, $auth)['data'] ?? [];
        $product = $data['product'] ?? $data; if (!$product) throw new RuntimeException('Produk TikTok tidak ditemukan.'); return $product;
    }

    private function stock(array $inventory): ?int { $quantities=array_filter(array_map(fn($row)=>is_numeric($row['quantity'] ?? null)?(int)$row['quantity']:null, $inventory), fn($v)=>$v!==null); return $quantities ? array_sum($quantities) : null; }
    private function audit(string $productId,string $skuId,string $title,string $sku,?int $before,int $after,string $status,string $error,string $user): void { $stmt=$this->db->prepare('INSERT INTO marketplace_stock_updates(provider,item_id,model_id,item_name,model_name,stock_before,stock_after,status,error,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute(['tiktok',$productId,$skuId,mb_substr($title,0,500),mb_substr($sku,0,500),$before,$after,$status,mb_substr($error,0,2000),mb_substr($user,0,100),time()]); }

    private function tiktok(string $method,string $path,array $query,?array $body,array $auth): array
    {
        $cfg=$auth['config']; $query=['app_key'=>(string)($cfg['app_key'] ?? ''),'shop_cipher'=>(string)($cfg['shop_cipher'] ?? ''),'timestamp'=>(string)time()]+$query; ksort($query,SORT_STRING); $payload=$body===null?'':json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); $source=$path; foreach($query as $key=>$value)$source.=$key.$value; $secret=(string)($cfg['app_secret'] ?? ''); $query['sign']=hash_hmac('sha256',$secret.$source.$payload.$secret,$secret);
        $ch=curl_init(rtrim((string)($cfg['api_base'] ?? 'https://open-api.tiktokglobalshop.com'),'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986)); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-tts-access-token: '.(string)$auth['access_token']]]); if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$payload); $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($raw===false)throw new RuntimeException('Koneksi TikTok gagal: '.$error);$json=json_decode($raw,true);if(!is_array($json))throw new RuntimeException('Respons TikTok bukan JSON valid.');if($http<200||$http>=300)throw new RuntimeException('TikTok HTTP '.$http.': '.($json['message']??'rejected'));if((int)($json['code']??-1)!==0)throw new RuntimeException('TikTok API '.($json['code']??'?').': '.($json['message']??'unknown error'));return $json;
    }
}
