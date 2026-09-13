<?php
declare(strict_types=1);

/** Reads and updates one explicitly selected Shopee SKU's normal stock. */
final class ShopeeStockService
{
    public function __construct(private PDO $db, private MarketplaceOAuthService $oauth)
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS marketplace_stock_updates (id BIGINT AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(20) NOT NULL, item_id VARCHAR(80) NOT NULL, model_id VARCHAR(80) NOT NULL DEFAULT '0', item_name VARCHAR(500) NOT NULL, model_name VARCHAR(500) NOT NULL, stock_before INT NULL, stock_after INT NOT NULL, status VARCHAR(20) NOT NULL, error TEXT NOT NULL, created_by VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, INDEX ix_marketplace_stock_updates_created(created_at)) ENGINE=InnoDB");
    }

    public function product(int $itemId): array
    {
        if ($itemId < 1) throw new InvalidArgumentException('ID produk Shopee tidak valid.');
        $auth = $this->oauth->credentials('shopee');
        $item = $this->item($itemId, $auth);
        $models = $this->models($itemId, $auth);
        if (!$models) $models = [[
            'model_id' => 0,
            'model_name' => '',
            'model_sku' => (string)($item['item_sku'] ?? ''),
            'stock_info_v2' => $item['stock_info_v2'] ?? [],
        ]];
        return [
            'item_id' => (string)$itemId,
            'item_name' => (string)($item['item_name'] ?? ''),
            'item_status' => (string)($item['item_status'] ?? ''),
            'models' => array_map(fn(array $model): array => [
                'model_id' => (string)($model['model_id'] ?? 0),
                'model_name' => (string)($model['model_name'] ?? ''),
                'model_sku' => (string)($model['model_sku'] ?? ''),
                'stock' => $this->stock($model['stock_info_v2'] ?? []),
            ], $models),
        ];
    }

    public function update(int $itemId, int $modelId, int $quantity, string $user): array
    {
        if ($itemId < 1 || $modelId < 0 || $quantity < 0) throw new InvalidArgumentException('ID produk, ID variasi, atau stok Shopee tidak valid.');
        $auth = $this->oauth->credentials('shopee');
        $item = $this->item($itemId, $auth);
        $models = $this->models($itemId, $auth);
        $model = null;
        foreach ($models as $candidate) if ((int)($candidate['model_id'] ?? 0) === $modelId) {$model = $candidate; break;}
        if ($modelId === 0 && !$models) $model = ['model_id'=>0, 'model_name'=>'', 'stock_info_v2'=>$item['stock_info_v2'] ?? []];
        if ($model === null) throw new RuntimeException('Variasi Shopee tidak ditemukan pada produk yang terhubung.');
        $before = $this->stock($model['stock_info_v2'] ?? []);

        try {
            $this->shopee('POST', '/api/v2/product/update_stock', [], [
                'item_id' => $itemId,
                'stock_list' => [['model_id' => $modelId, 'normal_stock' => $quantity]],
            ], $auth);
            $updated = $this->product($itemId);
            $confirmed = null;
            foreach ($updated['models'] as $row) if ((int)$row['model_id'] === $modelId) {$confirmed = $row['stock']; break;}
            $this->audit($itemId, $modelId, (string)($item['item_name'] ?? ''), (string)($model['model_name'] ?? ''), $before, $quantity, 'updated', '', $user);
            return ['ok'=>true, 'item_id'=>(string)$itemId, 'model_id'=>(string)$modelId, 'stock_before'=>$before, 'stock_after'=>$quantity, 'stock_confirmed'=>$confirmed, 'item_name'=>(string)($item['item_name'] ?? ''), 'model_name'=>(string)($model['model_name'] ?? '')];
        } catch (Throwable $e) {
            $this->audit($itemId, $modelId, (string)($item['item_name'] ?? ''), (string)($model['model_name'] ?? ''), $before, $quantity, 'failed', $e->getMessage(), $user);
            throw $e;
        }
    }

    private function item(int $itemId, array $auth): array
    {
        $json = $this->shopee('GET', '/api/v2/product/get_item_base_info', ['item_id_list'=>(string)$itemId], null, $auth);
        $item = $json['response']['item_list'][0] ?? [];
        if (!$item) throw new RuntimeException('Produk Shopee tidak ditemukan di toko yang terhubung.');
        return $item;
    }

    private function models(int $itemId, array $auth): array
    {
        return $this->shopee('GET', '/api/v2/product/get_model_list', ['item_id'=>$itemId], null, $auth)['response']['model'] ?? [];
    }

    private function stock(array $info): ?int
    {
        $seller = $info['seller_stock'][0]['stock'] ?? null;
        if (is_numeric($seller)) return (int)$seller;
        $total = $info['summary_info']['total_available_stock'] ?? null;
        return is_numeric($total) ? (int)$total : null;
    }

    private function audit(int $itemId, int $modelId, string $itemName, string $modelName, ?int $before, int $after, string $status, string $error, string $user): void
    {
        $stmt = $this->db->prepare('INSERT INTO marketplace_stock_updates(provider,item_id,model_id,item_name,model_name,stock_before,stock_after,status,error,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute(['shopee', (string)$itemId, (string)$modelId, mb_substr($itemName, 0, 500), mb_substr($modelName, 0, 500), $before, $after, $status, mb_substr($error, 0, 2000), mb_substr($user, 0, 100), time()]);
    }

    private function shopee(string $method, string $path, array $extra, ?array $body, array $auth): array
    {
        $cfg = $auth['config']; $partner = (string)($cfg['partner_id'] ?? ''); $token = (string)$auth['access_token']; $shop = (string)$auth['account_id']; $timestamp = time();
        $query = array_merge(['partner_id'=>$partner, 'timestamp'=>$timestamp, 'sign'=>hash_hmac('sha256', $partner.$path.$timestamp.$token.$shop, (string)($cfg['partner_key'] ?? '')), 'access_token'=>$token, 'shop_id'=>$shop], $extra);
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $ch = curl_init(rtrim((string)($cfg['api_host'] ?? 'https://partner.shopeemobile.com'), '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>60, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$payload === null ? [] : ['Content-Type: application/json']]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
        if ($raw === false) throw new RuntimeException('Koneksi Shopee gagal: '.$error);
        $json = json_decode($raw, true);
        if (!is_array($json)) throw new RuntimeException('Respons Shopee bukan JSON valid.');
        if ($http < 200 || $http >= 300) throw new RuntimeException('Shopee HTTP '.$http.': '.($json['message'] ?? $json['error'] ?? 'rejected'));
        if (($apiError=(string)($json['error'] ?? '')) !== '' && $apiError !== '0') throw new RuntimeException('Shopee API: '.($json['message'] ?? $apiError).' ['.$apiError.']');
        return $json;
    }
}
