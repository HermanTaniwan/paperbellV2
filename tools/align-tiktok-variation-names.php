<?php
declare(strict_types=1);

// CLI maintenance: use the same signed transport and encrypted credentials as listing sync.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
$config = require $root . '/config.php';
foreach (['Database', 'OAuthVault', 'MarketplaceOAuthService', 'TikTokShopeeListingService'] as $class) require $root . '/src/' . $class . '.php';
require $root . '/src/VariationNameAlignment.php';
date_default_timezone_set((string)$config['app']['timezone']);
$oauth = new MarketplaceOAuthService(Database::mysql($config['mysql']), new OAuthVault($config['oauth']['key_file']), $config['oauth']);
$service = new TikTokShopeeListingService($oauth);
function listingCall(string $method, mixed ...$args): array {
    global $service;
    return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
}
function saveReport(string $path, array $data): void {
    if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Cannot save audit report.');
}
$directory = $root . '/output/variation-names';
if (!is_dir($directory)) mkdir($directory, 0777, true);
$sourcePath = $directory . '/catalog.json';
$sh = $oauth->credentials('shopee');
$tt = $oauth->credentials('tiktok');
$mapping = [];
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--mapping=')) $mapping = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents(substr($argument, 10))), true, 512, JSON_THROW_ON_ERROR);
}
function modelIds(array $mapping, string $id, string $sid, array $product): array {
    $entry = $mapping[$id] ?? [];
    if ((string)($entry['shopee_id'] ?? '') !== $sid) return [];
    $skus = array_column($product['skus'] ?? [], null, 'id'); $ids = [];
    foreach ($entry['rows'] ?? [] as $row) {
        $skuId = (string)$row['tiktok_sku_id'];
        if (isset($skus[$skuId]) && ($skus[$skuId]['seller_sku'] ?? '') === ($row['new_sku'] ?? null)) $ids[$skuId] = (string)$row['shopee_model_id'];
    }
    return $ids;
}
if (!in_array('--cached', $argv, true)) {
    $catalog = ['shopee' => [], 'tiktok' => [], 'scanned_at' => date(DATE_ATOM)];
    $ids = [];
    foreach (['NORMAL', 'UNLIST'] as $status) {
        $offset = 0;
        do {
            $r = listingCall('shopee', 'GET', '/api/v2/product/get_item_list', ['offset' => $offset, 'page_size' => 100, 'item_status' => $status], null, $sh)['response'] ?? [];
            $rows = $r['item'] ?? $r['item_list'] ?? [];
            foreach ($rows as $row) $ids[(string)$row['item_id']] = true;
            $offset += count($rows);
            if (!$rows && !empty($r['has_next_page'])) throw new RuntimeException('Empty Shopee page with more results.');
        } while (!empty($r['has_next_page']));
    }
    foreach (array_chunk(array_keys($ids), 50) as $batch) {
        $r = listingCall('shopee', 'GET', '/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $batch)], null, $sh);
        foreach ($r['response']['item_list'] ?? [] as $item) $catalog['shopee'][(string)$item['item_id']] = $item;
    }
    foreach ($catalog['shopee'] as $id => &$item) {
        $item['model_response'] = listingCall('shopee', 'GET', '/api/v2/product/get_model_list', ['item_id' => $id], null, $sh)['response'] ?? [];
    }
    unset($item);
    fwrite(STDERR, 'Shopee products: ' . count($catalog['shopee']) . "\n");
    $token = ''; $seen = [];
    do {
        $query = ['page_size' => 100];
        if ($token !== '') $query['page_token'] = $token;
        $r = listingCall('tiktok', 'POST', '/product/202309/products/search', $query, [], $tt)['data'] ?? [];
        foreach ($r['products'] ?? [] as $row) {
            $id = (string)$row['id'];
            $d = listingCall('tiktok', 'GET', '/product/202309/products/' . $id, [], null, $tt)['data'] ?? [];
            $catalog['tiktok'][$id] = $d['product'] ?? $d;
        }
        $token = (string)($r['next_page_token'] ?? '');
        if ($token !== '' && isset($seen[$token])) throw new RuntimeException('Repeated TikTok page token.');
        $seen[$token] = true;
    } while ($token !== '');
    saveReport($sourcePath, $catalog);
} else {
    $catalog = json_decode(file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
}
echo json_encode(['shopee_products' => count($catalog['shopee']), 'tiktok_products' => count($catalog['tiktok']), 'catalog' => $sourcePath]) . "\n";
$report = ['scanned_at' => $catalog['scanned_at'], 'products' => [], 'skipped' => []];
foreach ($catalog['tiktok'] as $id => $product) {
    $candidates = [];
    foreach ($catalog['shopee'] as $sid => $item) {
        $codes = [];
        foreach ($item['model_response']['model'] ?? [] as $model) {
            $code = VariationNameAlignment::code((string)($model['model_sku'] ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
                $codes[VariationNameAlignment::code((string)($item['item_sku'] ?? '') . $code)] = true;
            }
        }
        $overlap = 0;
        foreach ($product['skus'] ?? [] as $sku) if (isset($codes[VariationNameAlignment::code((string)($sku['seller_sku'] ?? ''))])) $overlap++;
        if ($overlap > 0) $candidates[(string)$sid] = $overlap;
    }
    arsort($candidates);
    $explicitSource = (string)($mapping[$id]['shopee_id'] ?? '');
    if (!$candidates && isset($catalog['shopee'][$explicitSource]) && count($product['skus'] ?? []) > 0 && count(modelIds($mapping, (string)$id, $explicitSource, $product)) === count($product['skus'])) $candidates[$explicitSource] = count($product['skus']);
    $scores = array_values($candidates);
    if (!$scores || (isset($scores[1]) && $scores[0] === $scores[1])) {
        $report['skipped'][$id] = ['title' => $product['title'] ?? '', 'reason' => 'No unique source product by SKU.'];
        continue;
    }
    $sid = (string)array_key_first($candidates);
    try {
        $ids = modelIds($mapping, (string)$id, $sid, $product);
        $plan = VariationNameAlignment::plan($catalog['shopee'][$sid], $product, $ids);
        if ($plan['changes']) $report['products'][$id] = ['title' => $product['title'], 'source_item_id' => $sid] + $plan;
    } catch (Throwable $error) {
        $report['skipped'][$id] = ['title' => $product['title'] ?? '', 'reason' => $error->getMessage()];
    }
}
saveReport($directory . '/plan.json', $report);
if (!in_array('--apply', $argv, true) && !in_array('--verify', $argv, true)) {
    foreach ($report['products'] as $id => $plan) echo json_encode(['product_id' => $id, 'title' => $plan['title'], 'changes' => count($plan['changes']), 'examples' => array_slice($plan['changes'], 0, 2)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
echo json_encode(['products_to_update' => count($report['products']), 'names_to_update' => array_sum(array_map(fn($p) => count($p['changes']), $report['products'])), 'skipped_products' => count($report['skipped'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
$journalPath = $directory . '/journal.json';
$journal = is_file($journalPath) ? json_decode(file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR) : [];
if (in_array('--apply', $argv, true)) {
    $only = '';
    foreach ($argv as $argument) if (str_starts_with($argument, '--only=')) $only = substr($argument, 7);
    foreach ($report['products'] as $id => $plan) {
        $id = (string)$id;
        if ($only !== '' && $id !== $only) continue;
        if (isset($journal[$id])) { echo "Already attempted {$id}; inspect journal before retry.\n"; continue; }
        try {
            $sid = (int)$plan['source_item_id'];
            $item = listingCall('shopeeItem', $sid, $sh);
            $item['model_response'] = listingCall('shopee', 'GET', '/api/v2/product/get_model_list', ['item_id' => $sid], null, $sh)['response'] ?? [];
            $d = listingCall('tiktok', 'GET', '/product/202309/products/' . $id, [], null, $tt)['data'] ?? [];
            $before = $d['product'] ?? $d;
            if (!in_array($before['status'] ?? '', ['ACTIVATE', 'SELLER_DEACTIVATED', 'DRAFT'], true)) throw new RuntimeException('Unsupported listing status.');
            $fresh = VariationNameAlignment::plan($item, $before, modelIds($mapping, $id, (string)$sid, $before));
            if ($fresh !== ['skus' => $plan['skus'], 'changes' => $plan['changes']]) throw new RuntimeException('Source or target labels changed since scan. Rescan first.');
            $byId = array_column($before['skus'], null, 'id');
            foreach ($fresh['skus'] as &$input) {
                foreach (['sku_dimensions', 'sku_weight'] as $field) if (isset($byId[$input['id']][$field])) $input[$field] = $byId[$input['id']][$field];
            }
            unset($input);
            $body = ['save_mode' => $before['status'] === 'DRAFT' ? 'AS_DRAFT' : 'LISTING', 'skus' => $fresh['skus']];
            if (isset($before['is_cod_allowed'])) $body['is_cod_allowed'] = $before['is_cod_allowed'];
            $journal[$id] = ['before' => $before, 'source' => $item, 'request' => $body, 'changes' => $fresh['changes'], 'started_at' => date(DATE_ATOM)];
            saveReport($journalPath, $journal);
            try {
                $journal[$id]['accepted'] = listingCall('tiktok', 'POST', '/product/202509/products/' . $id . '/partial_edit', [], $body, $tt);
                saveReport($journalPath, $journal);
            } finally {
                if ($before['status'] === 'SELLER_DEACTIVATED') {
                    $journal[$id]['status_restore'] = listingCall('tiktok', 'POST', '/product/202309/products/deactivate', [], ['product_ids' => [$id]], $tt);
                    saveReport($journalPath, $journal);
                }
            }
            echo "Accepted {$id}: " . count($fresh['changes']) . " names\n";
        } catch (Throwable $error) {
            $journal[$id]['error'] = $error->getMessage();
            saveReport($journalPath, $journal);
            echo "ERROR {$id}: " . $error->getMessage() . "\n";
            break;
        }
    }
}
if (in_array('--verify', $argv, true)) {
    $verification = [];
    foreach ($journal as $id => $entry) {
        if (!isset($entry['before'])) continue;
        $id = (string)$id;
        $d = listingCall('tiktok', 'GET', '/product/202309/products/' . $id, [], null, $tt)['data'] ?? [];
        $after = $d['product'] ?? $d;
        $actual = array_column($after['skus'] ?? [], null, 'id');
        $original = array_column($entry['before']['skus'], null, 'id');
        $issues = [];
        foreach ($entry['request']['skus'] as $expected) {
            $sku = $actual[$expected['id']] ?? [];
            if (($sku['seller_sku'] ?? null) !== $expected['seller_sku'] || array_column($sku['sales_attributes'] ?? [], 'value_name') !== array_column($expected['sales_attributes'], 'value_name')) $issues[] = 'SKU ' . $expected['id'] . ' differs';
            foreach (['sku_dimensions', 'sku_weight'] as $field) if (($sku[$field] ?? null) != ($original[$expected['id']][$field] ?? null)) $issues[] = $expected['id'] . ': ' . $field . ' changed';
        }
        if (count($actual) !== count($entry['before']['skus'])) $issues[] = 'SKU count changed';
        if (($after['status'] ?? '') !== $entry['before']['status']) $issues[] = 'Status differs: ' . ($after['status'] ?? '');
        foreach (['is_cod_allowed', 'title', 'description', 'package_weight', 'package_dimensions'] as $field) if (($after[$field] ?? null) != ($entry['before'][$field] ?? null)) $issues[] = $field . ' changed';
        if (count($after['main_images'] ?? []) !== count($entry['before']['main_images'] ?? [])) $issues[] = 'Image count changed';
        if (($after['video']['id'] ?? null) !== ($entry['before']['video']['id'] ?? null)) $issues[] = 'Video changed';
        $verification[$id] = ['verified_names' => $issues ? 0 : count($entry['changes']), 'issues' => $issues, 'after' => $after];
        saveReport($directory . '/verification.json', $verification);
        echo json_encode(['product_id' => $id, 'verified_names' => $verification[$id]['verified_names'], 'issues' => $issues]) . "\n";
    }
}
