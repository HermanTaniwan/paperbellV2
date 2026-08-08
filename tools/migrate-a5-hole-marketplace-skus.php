<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$config = require $root . '/config.php';
date_default_timezone_set((string)$config['app']['timezone']);
require $root . '/src/Database.php';
require $root . '/src/OAuthVault.php';
require $root . '/src/MarketplaceOAuthService.php';

$options = getopt('', ['manifest:', 'provider:', 'mode::', 'product-id::', 'batch-size::', 'batch-offset::', 'apply', 'output::']);
$provider = strtolower(trim((string)($options['provider'] ?? '')));
$mode = strtolower(trim((string)($options['mode'] ?? 'inspect')));
$apply = array_key_exists('apply', $options);
if (!in_array($provider, ['shopee', 'tiktok'], true)) throw new InvalidArgumentException('--provider harus shopee atau tiktok.');
if (!in_array($mode, ['inspect', 'canary', 'batch', 'verify', 'rollback'], true)) throw new InvalidArgumentException('--mode tidak valid.');
if (in_array($mode, ['canary', 'batch', 'rollback'], true) && !$apply) throw new InvalidArgumentException("--mode={$mode} memerlukan --apply.");

function loadManifest(array $options, string $provider): array
{
    $path = trim((string)($options['manifest'] ?? ''));
    if ($path === '' || !is_file($path)) throw new InvalidArgumentException('File --manifest tidak ditemukan.');
    $rows = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows)) throw new RuntimeException('Manifest tidak valid.');
    return array_values(array_filter($rows, static fn(array $row): bool => ($row['platform'] ?? '') === $provider));
}

function curlJson(string $method, string $url, array $headers = [], ?array $body = null): array
{
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Marketplace connection failed: ' . $error);
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException("Marketplace returned invalid JSON (HTTP {$status}).");
    if ($status < 200 || $status >= 300) throw new RuntimeException("Marketplace HTTP {$status}: " . (string)($json['message'] ?? $json['error'] ?? 'rejected'));
    return $json;
}

function shopeeRequest(string $method, string $path, array $query, ?array $body, array $auth): array
{
    $cfg = $auth['config'];
    $partner = (string)($cfg['partner_id'] ?? '');
    $token = (string)$auth['access_token'];
    $shop = (string)$auth['account_id'];
    $timestamp = time();
    $query = array_merge(['partner_id' => $partner, 'timestamp' => $timestamp, 'sign' => hash_hmac('sha256', $partner . $path . $timestamp . $token . $shop, (string)($cfg['partner_key'] ?? '')), 'access_token' => $token, 'shop_id' => $shop], $query);
    $url = rtrim((string)($cfg['api_host'] ?? 'https://partner.shopeemobile.com'), '/') . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $json = curlJson($method, $url, $body === null ? [] : ['Content-Type: application/json'], $body);
    $error = (string)($json['error'] ?? '');
    if ($error !== '' && $error !== '0') throw new RuntimeException('Shopee API: ' . (string)($json['message'] ?? $error) . " [{$error}]");
    return $json;
}

function tiktokRequest(string $method, string $path, array $query, ?array $body, array $auth): array
{
    $cfg = $auth['config'];
    $query['app_key'] = (string)($cfg['app_key'] ?? '');
    $query['shop_cipher'] = (string)($cfg['shop_cipher'] ?? '');
    $query['timestamp'] = (string)time();
    ksort($query, SORT_STRING);
    $bodyText = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $source = $path;
    foreach ($query as $key => $value) $source .= $key . $value;
    $source .= $bodyText;
    $secret = (string)($cfg['app_secret'] ?? '');
    $query['sign'] = hash_hmac('sha256', $secret . $source . $secret, $secret);
    $base = rtrim((string)($cfg['api_base'] ?? 'https://open-api.tiktokglobalshop.com'), '/');
    $json = curlJson($method, $base . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), ['Content-Type: application/json', 'x-tts-access-token: ' . (string)$auth['access_token']], $body);
    if ((int)($json['code'] ?? -1) !== 0) throw new RuntimeException('TikTok API ' . (string)($json['code'] ?? '?') . ': ' . (string)($json['message'] ?? 'unknown error'));
    return $json;
}

function isSixHole(string $value): bool
{
    return preg_match('/(?:^|\D)6\s*(?:lubang|hole)(?:\D|$)/iu', $value) === 1;
}

function shopeeModelName(array $model, array $tiers): string
{
    $parts = [];
    foreach (($model['tier_index'] ?? []) as $tierPosition => $optionPosition) {
        $option = $tiers[$tierPosition]['option_list'][(int)$optionPosition] ?? [];
        $value = trim((string)($option['option'] ?? $option['option_name'] ?? $option['name'] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return trim((string)($model['model_name'] ?? implode(', ', $parts)));
}

function tiktokVariantName(array $sku): string
{
    $parts = [];
    foreach (($sku['sales_attributes'] ?? []) as $attribute) {
        $value = trim((string)($attribute['value_name'] ?? $attribute['value'] ?? $attribute['name'] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return trim((string)($sku['sku_name'] ?? $sku['name'] ?? implode(', ', $parts)));
}

function groupedTargets(array $manifest, string $productId, int $limit, int $offset = 0): array
{
    $groups = [];
    foreach ($manifest as $row) $groups[(string)$row['product_id']][] = $row;
    if ($productId !== '') {
        if (!isset($groups[$productId])) throw new RuntimeException("Product ID {$productId} tidak ada dalam manifest.");
        return [$productId => $groups[$productId]];
    }
    uasort($groups, static fn(array $a, array $b): int => count($a) <=> count($b));
    return $limit > 0 ? array_slice($groups, $offset, $limit, true) : array_slice($groups, $offset, null, true);
}

function migrateShopee(array $auth, array $groups, string $mode): array
{
    $results = [];
    foreach ($groups as $productId => $targets) {
        $beforeJson = shopeeRequest('GET', '/api/v2/product/get_model_list', ['item_id' => $productId], null, $auth);
        $before = $beforeJson['response'] ?? [];
        $modelsById = [];
        foreach (($before['model'] ?? []) as $model) $modelsById[(string)$model['model_id']] = $model;
        $updates = [];
        $rollback = [];
        $pending = [];
        foreach ($targets as $target) {
            $skuId = (string)$target['sku_id'];
            $model = $modelsById[$skuId] ?? null;
            if (!$model) throw new RuntimeException("Shopee model {$skuId} tidak ditemukan pada item {$productId}.");
            $name = shopeeModelName($model, $before['tier_variation'] ?? []);
            if (!isSixHole($name)) throw new RuntimeException("Shopee model {$skuId} tidak lagi bernama 6 lubang.");
            $current = strtoupper(trim((string)($model['model_sku'] ?? '')));
            $new = strtoupper((string)$target['new_variant_sku']);
            if ($current === $new) continue;
            if ($mode !== 'rollback' && $current !== strtoupper((string)$target['old_variant_sku'])) throw new RuntimeException("Shopee SKU {$skuId} berubah di luar manifest: {$current}.");
            $desired = $mode === 'rollback' ? strtoupper((string)$target['old_variant_sku']) : $new;
            $updates[] = ['model_id' => (int)$skuId, 'model_sku' => $desired];
            $rollback[] = ['model_id' => (int)$skuId, 'model_sku' => $current];
            $pending[$skuId] = $desired;
        }
        if ($mode === 'inspect') {
            $results[] = ['product_id' => $productId, 'targets' => count($targets), 'models' => array_values($modelsById)];
            continue;
        }
        if ($mode === 'verify') {
            $results[] = ['product_id' => $productId, 'targets' => count($targets), 'verified' => count($targets) - count($updates), 'pending' => count($updates)];
            continue;
        }
        if ($updates !== []) shopeeRequest('POST', '/api/v2/product/update_model', [], ['item_id' => (int)$productId, 'model' => $updates], $auth);
        $afterJson = shopeeRequest('GET', '/api/v2/product/get_model_list', ['item_id' => $productId], null, $auth);
        $afterModels = [];
        foreach (($afterJson['response']['model'] ?? []) as $model) $afterModels[(string)$model['model_id']] = $model;
        foreach ($pending as $skuId => $desired) {
            if (strtoupper(trim((string)($afterModels[$skuId]['model_sku'] ?? ''))) !== $desired) throw new RuntimeException("Verifikasi Shopee gagal untuk model {$skuId}.");
        }
        $results[] = ['product_id' => $productId, 'targets' => count($targets), 'updated' => count($updates), 'verified' => count($pending), 'rollback' => $rollback];
    }
    return $results;
}

function inputSalesAttributes(array $attributes): array
{
    $allowed = ['id', 'name', 'value_id', 'value_name'];
    $result = [];
    foreach ($attributes as $attribute) {
        $copy = [];
        foreach ($allowed as $key) if (array_key_exists($key, $attribute) && $attribute[$key] !== null && $attribute[$key] !== '') $copy[$key] = $attribute[$key];
        if (!empty($attribute['sku_img']['uri'])) $copy['sku_img'] = ['uri' => (string)$attribute['sku_img']['uri']];
        if (!empty($attribute['supplementary_sku_images']) && is_array($attribute['supplementary_sku_images'])) {
            $images = [];
            foreach ($attribute['supplementary_sku_images'] as $image) if (!empty($image['uri'])) $images[] = ['uri' => (string)$image['uri']];
            if ($images !== []) $copy['supplementary_sku_images'] = $images;
        }
        $result[] = $copy;
    }
    return $result;
}

function inputTikTokSku(array $sku, string $sellerSku): array
{
    $result = ['id' => (string)$sku['id'], 'seller_sku' => $sellerSku, 'sales_attributes' => inputSalesAttributes($sku['sales_attributes'] ?? [])];
    foreach (['price', 'inventory', 'identifier_code', 'external_sku_id', 'pre_sale', 'combined_skus', 'sku_unit_count', 'list_price'] as $key) {
        if (array_key_exists($key, $sku) && $sku[$key] !== null && $sku[$key] !== [] && $sku[$key] !== '') $result[$key] = $sku[$key];
    }
    return $result;
}

function migrateTikTok(array $auth, array $groups, string $mode): array
{
    $results = [];
    foreach ($groups as $productId => $targets) {
        $beforeJson = tiktokRequest('GET', '/product/202309/products/' . rawurlencode((string)$productId), [], null, $auth);
        $product = $beforeJson['data']['product'] ?? $beforeJson['data'] ?? [];
        $targetsById = [];
        foreach ($targets as $target) $targetsById[(string)$target['sku_id']] = $target;
        $skus = [];
        $pending = [];
        foreach (($product['skus'] ?? []) as $sku) {
            $skuId = (string)($sku['id'] ?? '');
            $current = strtoupper(trim((string)($sku['seller_sku'] ?? '')));
            $desired = $current;
            if (isset($targetsById[$skuId])) {
                if (!isSixHole(tiktokVariantName($sku))) throw new RuntimeException("TikTok SKU {$skuId} tidak lagi bernama 6 lubang.");
                $target = $targetsById[$skuId];
                $new = strtoupper((string)$target['new_sku']);
                if ($mode === 'rollback') $desired = strtoupper((string)$target['old_sku']);
                elseif ($current !== $new) {
                    if ($current !== strtoupper((string)$target['old_sku'])) throw new RuntimeException("TikTok SKU {$skuId} berubah di luar manifest: {$current}.");
                    $desired = $new;
                }
                if ($desired !== $current) $pending[$skuId] = $desired;
            }
            $skus[] = inputTikTokSku($sku, $desired);
        }
        if (count(array_intersect_key($targetsById, array_column($product['skus'] ?? [], null, 'id'))) !== count($targetsById)) throw new RuntimeException("Sebagian SKU TikTok tidak ditemukan pada product {$productId}.");
        if ($mode === 'inspect') {
            $results[] = ['product_id' => $productId, 'status' => $product['status'] ?? '', 'targets' => count($targets), 'sku_payload' => $skus];
            continue;
        }
        if ($mode === 'verify') {
            $results[] = ['product_id' => $productId, 'status' => $product['status'] ?? '', 'targets' => count($targets), 'verified' => count($targets) - count($pending), 'pending' => count($pending)];
            continue;
        }
        if ($pending !== []) tiktokRequest('POST', '/product/202509/products/' . rawurlencode((string)$productId) . '/partial_edit', [], ['save_mode' => 'LISTING', 'skus' => $skus], $auth);
        $afterJson = tiktokRequest('GET', '/product/202309/products/' . rawurlencode((string)$productId), [], null, $auth);
        $after = $afterJson['data']['product'] ?? $afterJson['data'] ?? [];
        $afterById = [];
        foreach (($after['skus'] ?? []) as $sku) $afterById[(string)$sku['id']] = $sku;
        foreach ($pending as $skuId => $desired) {
            if (strtoupper(trim((string)($afterById[$skuId]['seller_sku'] ?? ''))) !== $desired) throw new RuntimeException("Verifikasi TikTok gagal untuk SKU {$skuId}.");
        }
        $results[] = ['product_id' => $productId, 'status_before' => $product['status'] ?? '', 'status_after' => $after['status'] ?? '', 'targets' => count($targets), 'updated' => count($pending), 'verified' => count($pending)];
    }
    return $results;
}

try {
    $manifest = loadManifest($options, $provider);
    $productId = trim((string)($options['product-id'] ?? ''));
    $batchSize = max(0, (int)($options['batch-size'] ?? 0));
    $batchOffset = max(0, (int)($options['batch-offset'] ?? 0));
    $limit = $mode === 'canary' ? 1 : ($mode === 'batch' ? $batchSize : 0);
    $groups = groupedTargets($manifest, $productId, $limit, $mode === 'batch' ? $batchOffset : 0);
    $database = Database::mysql($config['mysql']);
    $oauth = new MarketplaceOAuthService($database, new OAuthVault($config['oauth']['key_file']), $config['oauth']);
    $auth = $oauth->credentials($provider);
    $results = $provider === 'shopee' ? migrateShopee($auth, $groups, $mode) : migrateTikTok($auth, $groups, $mode);
    $report = ['ok' => true, 'provider' => $provider, 'mode' => $mode, 'generated_at' => date(DATE_ATOM), 'products' => $results];
    $directory = $root . '/output/sku-migration';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('Folder output tidak dapat dibuat.');
    $output = trim((string)($options['output'] ?? ''));
    if ($output === '') $output = $directory . "/{$provider}-{$mode}-" . date('Ymd-His') . '.json';
    file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    echo json_encode(['ok' => true, 'provider' => $provider, 'mode' => $mode, 'products' => count($results), 'report' => $output], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
