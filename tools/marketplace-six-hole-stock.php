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

$options = getopt('', ['apply', 'provider::', 'output::', 'restore:']);
$apply = array_key_exists('apply', $options);
$restorePath = trim((string)($options['restore'] ?? ''));
$provider = strtolower(trim((string)($options['provider'] ?? 'all')));
if (!in_array($provider, ['all', 'shopee', 'tiktok'], true)) {
    fwrite(STDERR, "Provider harus all, shopee, atau tiktok.\n");
    exit(2);
}

/** Match exactly 6 holes, but never the 6 inside 26. */
function isSixHole(string $value): bool
{
    return preg_match('/(?:^|\D)6\s*(?:lubang|hole)(?:\D|$)/iu', $value) === 1;
}

function curlJson(string $method, string $url, array $headers = [], ?array $body = null): array
{
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Marketplace connection failed: ' . $error);
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException("Marketplace returned invalid JSON (HTTP {$status}).");
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Marketplace HTTP {$status}: " . (string)($json['message'] ?? $json['error'] ?? 'rejected'));
    }
    return $json;
}

function shopeeRequest(string $method, string $path, array $query, ?array $body, array $auth): array
{
    $cfg = $auth['config'];
    $partner = (string)($cfg['partner_id'] ?? '');
    $token = (string)$auth['access_token'];
    $shop = (string)$auth['account_id'];
    $timestamp = time();
    $query = array_merge([
        'partner_id' => $partner,
        'timestamp' => $timestamp,
        'sign' => hash_hmac('sha256', $partner . $path . $timestamp . $token . $shop, (string)($cfg['partner_key'] ?? '')),
        'access_token' => $token,
        'shop_id' => $shop,
    ], $query);
    $url = rtrim((string)($cfg['api_host'] ?? 'https://partner.shopeemobile.com'), '/') . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $json = curlJson($method, $url, $body === null ? [] : ['Content-Type: application/json'], $body);
    $error = (string)($json['error'] ?? '');
    if ($error !== '' && $error !== '0') throw new RuntimeException('Shopee API: ' . (string)($json['message'] ?? $error) . " [{$error}]");
    return $json;
}

function shopeeModelName(array $model, array $tiers): string
{
    $parts = [];
    foreach (($model['tier_index'] ?? []) as $tierPosition => $optionPosition) {
        $tier = $tiers[$tierPosition] ?? [];
        $option = $tier['option_list'][(int)$optionPosition] ?? [];
        $value = trim((string)($option['option'] ?? $option['option_name'] ?? $option['name'] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return trim((string)($model['model_name'] ?? (implode(', ', $parts))));
}

function shopeeAvailableStock(array $model): int
{
    if (isset($model['stock_info_v2']['summary_info']['total_available_stock'])) {
        return (int)$model['stock_info_v2']['summary_info']['total_available_stock'];
    }
    $stock = 0;
    foreach (($model['stock_info_v2']['seller_stock'] ?? []) as $entry) $stock += (int)($entry['stock'] ?? 0);
    if ($stock > 0) return $stock;
    foreach (($model['stock_info'] ?? []) as $entry) $stock += (int)($entry['current_stock'] ?? $entry['normal_stock'] ?? $entry['stock'] ?? 0);
    return $stock;
}

function scanShopee(array $auth, bool $apply): array
{
    $itemIds = [];
    foreach (['NORMAL', 'UNLIST'] as $status) {
        $offset = 0;
        do {
            $json = shopeeRequest('GET', '/api/v2/product/get_item_list', ['offset' => $offset, 'page_size' => 100, 'item_status' => $status], null, $auth);
            $response = $json['response'] ?? [];
            $list = $response['item'] ?? $response['item_list'] ?? [];
            foreach ($list as $item) if (!empty($item['item_id'])) $itemIds[(string)$item['item_id']] = $status;
            $offset += count($list);
            $more = (bool)($response['has_next_page'] ?? false);
        } while ($more && $offset < 10000);
    }

    $baseById = [];
    foreach (array_chunk(array_keys($itemIds), 50) as $batch) {
        $json = shopeeRequest('GET', '/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $batch)], null, $auth);
        foreach (($json['response']['item_list'] ?? []) as $item) $baseById[(string)$item['item_id']] = $item;
    }

    $matches = [];
    $updates = [];
    $processed = 0;
    foreach ($itemIds as $itemId => $status) {
        $base = $baseById[$itemId] ?? [];
        $itemSku = (string)($base['item_sku'] ?? '');
        $itemName = (string)($base['item_name'] ?? '');
        $json = shopeeRequest('GET', '/api/v2/product/get_model_list', ['item_id' => $itemId], null, $auth);
        $response = $json['response'] ?? [];
        $tiers = $response['tier_variation'] ?? [];
        foreach (($response['model'] ?? []) as $model) {
            $modelId = (string)($model['model_id'] ?? '0');
            $modelSku = (string)($model['model_sku'] ?? '');
            $modelName = shopeeModelName($model, $tiers);
            if (!isSixHole($modelName)) continue;
            $stock = shopeeAvailableStock($model);
            $row = ['item_id' => $itemId, 'model_id' => $modelId, 'item_sku' => $itemSku, 'model_sku' => $modelSku, 'product_name' => $itemName, 'variant_name' => $modelName, 'status' => $status, 'stock_before' => $stock, 'matched_by' => 'variant_name'];
            $matches[] = $row;
            if ($stock > 0) $updates[$itemId][] = $row;
        }
        $processed++;
        if ($processed % 25 === 0) fwrite(STDERR, "Shopee: scanned {$processed}/" . count($itemIds) . " products\n");
    }

    $updated = [];
    $errors = [];
    if ($apply) {
        foreach ($updates as $itemId => $rows) {
            $stockList = array_map(fn(array $row): array => ['model_id' => (int)$row['model_id'], 'seller_stock' => [['stock' => 0]]], $rows);
            try {
                shopeeRequest('POST', '/api/v2/product/update_stock', [], ['item_id' => (int)$itemId, 'stock_list' => $stockList], $auth);
                foreach ($rows as $row) $updated[] = $row;
            } catch (Throwable $first) {
                try {
                    $legacy = array_map(fn(array $row): array => ['model_id' => (int)$row['model_id'], 'normal_stock' => 0], $rows);
                    shopeeRequest('POST', '/api/v2/product/update_stock', [], ['item_id' => (int)$itemId, 'stock_list' => $legacy], $auth);
                    foreach ($rows as $row) $updated[] = $row;
                } catch (Throwable $second) {
                    $errors[] = ['item_id' => $itemId, 'message' => $second->getMessage(), 'first_attempt' => $first->getMessage()];
                }
            }
        }
    }
    return ['products_scanned' => count($itemIds), 'matches' => $matches, 'pending_updates' => array_sum(array_map('count', $updates)), 'updated' => $updated, 'errors' => $errors];
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

function tiktokVariantName(array $sku): string
{
    $parts = [];
    foreach (($sku['sales_attributes'] ?? []) as $attribute) {
        $value = trim((string)($attribute['value_name'] ?? $attribute['value'] ?? $attribute['name'] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return trim((string)($sku['sku_name'] ?? $sku['name'] ?? implode(', ', $parts)));
}

function scanTikTok(array $auth, bool $apply): array
{
    $products = [];
    $pageToken = '';
    do {
        $query = ['page_size' => 100];
        if ($pageToken !== '') $query['page_token'] = $pageToken;
        $json = tiktokRequest('POST', '/product/202309/products/search', $query, [], $auth);
        foreach (($json['data']['products'] ?? []) as $product) if (!empty($product['id'])) $products[(string)$product['id']] = $product;
        $pageToken = (string)($json['data']['next_page_token'] ?? '');
    } while ($pageToken !== '' && count($products) < 10000);

    $matches = [];
    $updates = [];
    $processed = 0;
    foreach ($products as $productId => $summary) {
        $json = tiktokRequest('GET', '/product/202309/products/' . rawurlencode((string)$productId), [], null, $auth);
        $product = $json['data']['product'] ?? $json['data'] ?? [];
        $productName = (string)($product['title'] ?? $product['product_name'] ?? $summary['name'] ?? '');
        foreach (($product['skus'] ?? []) as $sku) {
            $skuId = (string)($sku['id'] ?? '');
            $sellerSku = (string)($sku['seller_sku'] ?? '');
            $variantName = tiktokVariantName($sku);
            if (!isSixHole($variantName)) continue;
            $inventory = [];
            $stock = 0;
            foreach (($sku['inventory'] ?? []) as $entry) {
                $warehouseId = (string)($entry['warehouse_id'] ?? '');
                if ($warehouseId === '') continue;
                $quantity = (int)($entry['quantity'] ?? 0);
                $inventory[] = ['warehouse_id' => $warehouseId, 'quantity' => $quantity];
                $stock += $quantity;
            }
            $row = ['product_id' => $productId, 'sku_id' => $skuId, 'seller_sku' => $sellerSku, 'product_name' => $productName, 'variant_name' => $variantName, 'status' => (string)($product['status'] ?? $summary['status'] ?? ''), 'stock_before' => $stock, 'inventory_before' => $inventory, 'matched_by' => 'variant_name'];
            $matches[] = $row;
            if ($stock > 0 && $inventory !== []) $updates[$productId][] = $row;
        }
        $processed++;
        if ($processed % 25 === 0) fwrite(STDERR, "TikTok: scanned {$processed}/" . count($products) . " products\n");
    }

    $updated = [];
    $errors = [];
    if ($apply) {
        foreach ($updates as $productId => $rows) {
            $skus = [];
            foreach ($rows as $row) {
                $inventory = array_map(fn(array $entry): array => ['warehouse_id' => $entry['warehouse_id'], 'quantity' => 0], $row['inventory_before']);
                $skus[] = ['id' => $row['sku_id'], 'inventory' => $inventory];
            }
            try {
                tiktokRequest('POST', '/product/202309/products/' . rawurlencode((string)$productId) . '/inventory/update', [], ['skus' => $skus], $auth);
                foreach ($rows as $row) $updated[] = $row;
            } catch (Throwable $error) {
                $errors[] = ['product_id' => $productId, 'message' => $error->getMessage()];
            }
        }
    }
    return ['products_scanned' => count($products), 'matches' => $matches, 'pending_updates' => array_sum(array_map('count', $updates)), 'updated' => $updated, 'errors' => $errors];
}

function snapshotRows(array $providerReport): array
{
    $rows = $providerReport['updated'] ?? $providerReport['matches'] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function restoreShopee(array $auth, array $providerReport): array
{
    $groups = [];
    foreach (snapshotRows($providerReport) as $row) {
        $itemId = (string)($row['item_id'] ?? '');
        $modelId = (string)($row['model_id'] ?? '');
        $quantity = max(0, (int)($row['stock_before'] ?? 0));
        if ($itemId === '' || $modelId === '' || $quantity < 1) continue;
        $groups[$itemId][] = $row + ['restore_quantity' => $quantity];
    }

    $restored = [];
    $errors = [];
    foreach ($groups as $itemId => $rows) {
        $stockList = array_map(fn(array $row): array => [
            'model_id' => (int)$row['model_id'],
            'seller_stock' => [['stock' => (int)$row['restore_quantity']]],
        ], $rows);
        try {
            shopeeRequest('POST', '/api/v2/product/update_stock', [], ['item_id' => (int)$itemId, 'stock_list' => $stockList], $auth);
            foreach ($rows as $row) $restored[] = $row;
        } catch (Throwable $first) {
            try {
                $legacy = array_map(fn(array $row): array => [
                    'model_id' => (int)$row['model_id'],
                    'normal_stock' => (int)$row['restore_quantity'],
                ], $rows);
                shopeeRequest('POST', '/api/v2/product/update_stock', [], ['item_id' => (int)$itemId, 'stock_list' => $legacy], $auth);
                foreach ($rows as $row) $restored[] = $row;
            } catch (Throwable $second) {
                $errors[] = ['item_id' => $itemId, 'message' => $second->getMessage(), 'first_attempt' => $first->getMessage()];
            }
        }
    }
    return ['products' => count($groups), 'requested' => array_sum(array_map('count', $groups)), 'restored' => $restored, 'errors' => $errors];
}

function restoreTikTok(array $auth, array $providerReport): array
{
    $groups = [];
    foreach (snapshotRows($providerReport) as $row) {
        $productId = (string)($row['product_id'] ?? '');
        $skuId = (string)($row['sku_id'] ?? '');
        $inventory = [];
        foreach (($row['inventory_before'] ?? []) as $entry) {
            $warehouseId = (string)($entry['warehouse_id'] ?? '');
            if ($warehouseId !== '') $inventory[] = ['warehouse_id' => $warehouseId, 'quantity' => max(0, (int)($entry['quantity'] ?? 0))];
        }
        if ($productId === '' || $skuId === '' || $inventory === []) continue;
        $row['restore_inventory'] = $inventory;
        $groups[$productId][] = $row;
    }

    $restored = [];
    $errors = [];
    foreach ($groups as $productId => $rows) {
        $skus = array_map(fn(array $row): array => ['id' => (string)$row['sku_id'], 'inventory' => $row['restore_inventory']], $rows);
        try {
            tiktokRequest('POST', '/product/202309/products/' . rawurlencode((string)$productId) . '/inventory/update', [], ['skus' => $skus], $auth);
            foreach ($rows as $row) $restored[] = $row;
        } catch (Throwable $error) {
            $errors[] = ['product_id' => $productId, 'message' => $error->getMessage()];
        }
    }
    return ['products' => count($groups), 'requested' => array_sum(array_map('count', $groups)), 'restored' => $restored, 'errors' => $errors];
}

try {
    $db = Database::mysql($config['mysql']);
    $oauth = new MarketplaceOAuthService($db, new OAuthVault($config['oauth']['key_file']), $config['oauth']);
    if ($restorePath !== '') {
        $snapshotText = file_get_contents($restorePath);
        if ($snapshotText === false) throw new RuntimeException('Cannot read restore snapshot: ' . $restorePath);
        $snapshot = json_decode($snapshotText, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !isset($snapshot['providers']) || !is_array($snapshot['providers'])) throw new RuntimeException('Restore snapshot has an invalid structure.');
        $restoreReport = ['mode' => 'restore', 'generated_at' => date(DATE_ATOM), 'source' => realpath($restorePath) ?: $restorePath, 'providers' => []];
        if (isset($snapshot['providers']['shopee'])) $restoreReport['providers']['shopee'] = restoreShopee($oauth->credentials('shopee'), $snapshot['providers']['shopee']);
        if (isset($snapshot['providers']['tiktok'])) $restoreReport['providers']['tiktok'] = restoreTikTok($oauth->credentials('tiktok'), $snapshot['providers']['tiktok']);
        if ($restoreReport['providers'] === []) throw new RuntimeException('Restore snapshot does not contain a supported provider.');
        $defaultDirectory = $root . '/output/stock-emergency';
        if (!is_dir($defaultDirectory) && !mkdir($defaultDirectory, 0775, true) && !is_dir($defaultDirectory)) throw new RuntimeException('Cannot create report directory.');
        $output = trim((string)($options['output'] ?? ''));
        if ($output === '') $output = $defaultDirectory . '/six-hole-restore-' . date('Ymd-His') . '.json';
        $encoded = json_encode($restoreReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($output, $encoded . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Cannot write restore report file.');
        $summary = ['ok' => true, 'mode' => 'restore', 'report' => $output, 'providers' => []];
        foreach ($restoreReport['providers'] as $name => $data) $summary['providers'][$name] = ['products' => $data['products'], 'requested' => $data['requested'], 'restored' => count($data['restored']), 'errors' => count($data['errors'])];
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    $report = [
        'mode' => $apply ? 'apply' : 'dry-run',
        'generated_at' => date(DATE_ATOM),
        'match_rule' => 'exact 6 lubang/6 hole in current marketplace variant name',
        'providers' => [],
    ];
    if ($provider === 'all' || $provider === 'shopee') $report['providers']['shopee'] = scanShopee($oauth->credentials('shopee'), $apply);
    if ($provider === 'all' || $provider === 'tiktok') $report['providers']['tiktok'] = scanTikTok($oauth->credentials('tiktok'), $apply);

    $defaultDirectory = $root . '/output/stock-emergency';
    if (!is_dir($defaultDirectory) && !mkdir($defaultDirectory, 0775, true) && !is_dir($defaultDirectory)) throw new RuntimeException('Cannot create report directory.');
    $output = trim((string)($options['output'] ?? ''));
    if ($output === '') $output = $defaultDirectory . '/six-hole-' . ($apply ? 'apply-' : 'dry-run-') . date('Ymd-His') . '.json';
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($output, $encoded . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Cannot write report file.');
    $summary = ['ok' => true, 'mode' => $report['mode'], 'report' => $output, 'providers' => []];
    foreach ($report['providers'] as $name => $data) {
        $summary['providers'][$name] = ['products_scanned' => $data['products_scanned'], 'matches' => count($data['matches']), 'pending_updates' => $data['pending_updates'], 'updated' => count($data['updated']), 'errors' => count($data['errors'])];
    }
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
