<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$config = require $root . '/config.php';
date_default_timezone_set((string)$config['app']['timezone']);
$options = getopt('', ['datamap:', 'shopee-report:', 'tiktok-report:', 'output-dir::']);

function requiredPath(array $options, string $key): string
{
    $path = trim((string)($options[$key] ?? ''));
    if ($path === '' || !is_file($path)) throw new InvalidArgumentException("File --{$key} tidak ditemukan.");
    $resolved = realpath($path);
    if ($resolved === false) throw new RuntimeException("Path --{$key} tidak dapat dibaca.");
    return $resolved;
}

function loadJson(string $path): array
{
    $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException("JSON tidak valid: {$path}");
    return $value;
}

function writeCsv(string $path, array $rows, array $headers): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) throw new RuntimeException("CSV tidak dapat dibuat: {$path}");
    try {
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (is_bool($value)) $value = $value ? 'true' : 'false';
                $values[] = (string)$value;
            }
            fputcsv($handle, $values);
        }
    } finally {
        fclose($handle);
    }
}

function compactCode(string $value): string
{
    return strtoupper((string)preg_replace('/\s+/u', '', trim($value)));
}

function fingerprint(string $value): string
{
    $value = strtoupper($value);
    $value = (string)preg_replace('/(?:^|\D)(?:6|20)\s*(?:LUBANG|HOLE)(?:\D|$)/iu', ' ', $value);
    $value = (string)preg_replace('/\bA\s*5\b/iu', ' ', $value);
    return (string)preg_replace('/[^A-Z0-9]+/u', '', $value);
}

function variantKey(string $value): string
{
    $value = strtoupper($value);
    $value = (string)preg_replace('/\bA\s*(?=6\s*(?:LUBANG|HOLE))/iu', ' ', $value);
    $value = (string)preg_replace('/(?:^|\D)(?:6|20)\s*(?:LUBANG|HOLE)(?:\D|$)/iu', ' ', $value);
    $value = (string)preg_replace('/\bA\s*5\b/iu', ' ', $value);
    $value = (string)preg_replace('/\b(?:FLOWER|MATCHA|CAT|BOHO|LOVE)\b/iu', ' ', $value);
    $value = (string)preg_replace('/\bGRID\s*\/\s*TITIK\b/iu', ' GRID ', $value);
    $value = (string)preg_replace('/\b(?:DOTS?|TITIK)\b/iu', ' DOT ', $value);
    $value = (string)preg_replace('/\b(?:GARIS|LINE)\b/iu', ' LINE ', $value);
    $value = (string)preg_replace('/\b(?:POLOS|BLANK)\b/iu', ' BLANK ', $value);
    $value = (string)preg_replace('/[^A-Z0-9]+/u', '', $value);
    return (string)preg_replace('/(?:DOT){2,}/u', 'DOT', $value);
}

function productFingerprint(string $value): string
{
    return (string)preg_replace('/[^A-Z0-9]+/u', '', strtoupper($value));
}

function dataMapRows(array $sheet): array
{
    if (count($sheet) < 2) throw new RuntimeException('Datamap kosong.');
    $headers = array_map(static fn($value): string => trim((string)$value), $sheet[0]);
    $index = array_flip($headers);
    foreach (['SKU ID', 'Nama Produk', 'Nama Variasi', 'Size', 'File Path'] as $required) {
        if (!array_key_exists($required, $index)) throw new RuntimeException("Kolom datamap {$required} tidak ditemukan.");
    }
    $rows = [];
    foreach (array_slice($sheet, 1, null, true) as $zeroBased => $row) {
        $sku = compactCode((string)($row[$index['SKU ID']] ?? ''));
        if ($sku === '') continue;
        $size = strtoupper(trim((string)($row[$index['Size']] ?? '')));
        if ($size !== 'A5') continue;
        $rows[] = [
            'sheet_row' => $zeroBased + 1,
            'sku' => $sku,
            'product_name' => trim((string)($row[$index['Nama Produk']] ?? '')),
            'variation_name' => trim((string)($row[$index['Nama Variasi']] ?? '')),
            'file_path' => trim((string)($row[$index['File Path']] ?? '')),
            'product_fp' => productFingerprint((string)($row[$index['Nama Produk']] ?? '')),
            'variation_fp' => fingerprint((string)($row[$index['Nama Variasi']] ?? '')),
        ];
    }
    return $rows;
}

function candidateRows(array $mappings, string $oldSku, string $parentSku, string $productName, string $variationName): array
{
    $oldSku = compactCode($oldSku);
    $baseSku = str_ends_with($oldSku, 'H6') ? substr($oldSku, 0, -2) : $oldSku;
    $variationFp = fingerprint($variationName);
    $parent = compactCode($parentSku);
    $productFp = productFingerprint($productName);
    $variantKey = variantKey($variationName);

    if ($productFp !== '' && $variantKey !== '') {
        $semantic = array_values(array_filter($mappings, static fn(array $row): bool => $row['product_fp'] === $productFp && variantKey($row['variation_name']) === $variantKey));
        if (count($semantic) === 1) return $semantic;
    }

    if ($baseSku !== '') {
        $baseSkus = array_values(array_unique([$baseSku, (string)preg_replace('/A5A5$/', 'A5', $baseSku)]));
        $exact = array_values(array_filter($mappings, static fn(array $row): bool => in_array($row['sku'], $baseSkus, true)));
        if ($exact !== []) return $exact;
    }

    $pool = $mappings;
    if ($parent !== '') {
        $prefixed = array_values(array_filter($pool, static fn(array $row): bool => str_starts_with($row['sku'], $parent)));
        if ($prefixed !== []) $pool = $prefixed;
    }
    if ($productFp !== '') {
        $sameProduct = array_values(array_filter($pool, static fn(array $row): bool => $row['product_fp'] === $productFp));
        if ($sameProduct !== []) {
            $pool = $sameProduct;
        } elseif ($parent === '') {
            return [];
        }
    }
    if ($variationFp !== '') {
        $sameVariation = array_values(array_filter($pool, static fn(array $row): bool => $row['variation_fp'] === $variationFp));
        if ($sameVariation !== []) return $sameVariation;
    }
    return [];
}

function addMarketplaceRows(string $platform, array $matches, array $mappings, array &$manifest, array &$exceptions): void
{
    foreach ($matches as $source) {
        if ($platform === 'shopee') {
            $productId = (string)($source['item_id'] ?? '');
            $skuId = (string)($source['model_id'] ?? '');
            $parentSku = (string)($source['item_sku'] ?? '');
            $variantSku = (string)($source['model_sku'] ?? '');
            $oldSku = compactCode($parentSku . $variantSku);
        } else {
            $productId = (string)($source['product_id'] ?? '');
            $skuId = (string)($source['sku_id'] ?? '');
            $parentSku = '';
            $variantSku = (string)($source['seller_sku'] ?? '');
            $oldSku = compactCode($variantSku);
        }
        $productName = trim((string)($source['product_name'] ?? ''));
        $variationName = trim((string)($source['variant_name'] ?? ''));
        $candidates = candidateRows($mappings, $oldSku, $parentSku, $productName, $variationName);
        if ($platform === 'shopee' && count($candidates) !== 1 && compactCode($variantSku) !== '') {
            $candidates = candidateRows($mappings, $variantSku, '', $productName, $variationName);
        }
        if (count($candidates) !== 1) {
            $exceptions[] = [
                'platform' => $platform,
                'product_id' => $productId,
                'sku_id' => $skuId,
                'product_name' => $productName,
                'variation' => $variationName,
                'old_sku' => $oldSku,
                'reason' => count($candidates) === 0 ? 'datamap_not_found' : 'datamap_ambiguous',
                'candidate_rows' => array_map(static fn(array $row): array => ['row' => $row['sheet_row'], 'sku' => $row['sku'], 'variation' => $row['variation_name']], $candidates),
            ];
            continue;
        }
        $mapping = $candidates[0];
        if ($mapping['file_path'] === '') {
            $exceptions[] = [
                'platform' => $platform,
                'product_id' => $productId,
                'sku_id' => $skuId,
                'product_name' => $productName,
                'variation' => $variationName,
                'old_sku' => $oldSku,
                'reason' => 'datamap_file_path_empty',
                'candidate_rows' => [['row' => $mapping['sheet_row'], 'sku' => $mapping['sku'], 'variation' => $mapping['variation_name']]],
            ];
            continue;
        }
        $newSku = $mapping['sku'] . 'H6';
        $newVariantSku = $newSku;
        if ($platform === 'shopee' && $parentSku !== '' && str_starts_with($newSku, compactCode($parentSku))) {
            $newVariantSku = substr($newSku, strlen(compactCode($parentSku)));
        }
        $alreadyMigrated = $platform === 'shopee'
            ? compactCode($variantSku) === compactCode($newVariantSku)
            : $oldSku === $newSku;
        $manifest[] = [
            'platform' => $platform,
            'product_id' => $productId,
            'sku_id' => $skuId,
            'product_name' => $productName,
            'variation' => $variationName,
            'old_sku' => $oldSku,
            'old_variant_sku' => compactCode($variantSku),
            'new_sku' => $newSku,
            'new_variant_sku' => $newVariantSku,
            'datamap_row' => $mapping['sheet_row'],
            'datamap_sku' => $mapping['sku'],
            'datamap_variation' => $mapping['variation_name'],
            'file_path' => $mapping['file_path'],
            'already_migrated' => $alreadyMigrated,
        ];
    }
}

try {
    $datamapPath = requiredPath($options, 'datamap');
    $shopeePath = requiredPath($options, 'shopee-report');
    $tiktokPath = requiredPath($options, 'tiktok-report');
    $mappings = dataMapRows(loadJson($datamapPath));
    $shopee = loadJson($shopeePath);
    $tiktok = loadJson($tiktokPath);
    $manifest = [];
    $exceptions = [];
    addMarketplaceRows('shopee', $shopee['providers']['shopee']['matches'] ?? [], $mappings, $manifest, $exceptions);
    addMarketplaceRows('tiktok', $tiktok['providers']['tiktok']['matches'] ?? [], $mappings, $manifest, $exceptions);

    $duplicates = [];
    $byPlatformSku = [];
    foreach ($manifest as $row) $byPlatformSku[$row['platform'] . ':' . $row['new_sku']][] = $row;
    foreach ($byPlatformSku as $key => $rows) {
        $uniqueTargets = array_unique(array_map(static fn(array $row): string => $row['product_id'] . ':' . $row['sku_id'], $rows));
        if (count($uniqueTargets) > 1) {
            $duplicates[] = ['key' => $key, 'targets' => array_values($uniqueTargets)];
        }
    }

    $outputDir = trim((string)($options['output-dir'] ?? ($root . '/output/sku-migration')));
    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) throw new RuntimeException('Folder output tidak dapat dibuat.');
    $stamp = date('Ymd-His');
    $manifestPath = rtrim($outputDir, '/\\') . "/manifest-{$stamp}.json";
    $exceptionPath = rtrim($outputDir, '/\\') . "/exceptions-{$stamp}.json";
    $summaryPath = rtrim($outputDir, '/\\') . "/summary-{$stamp}.json";
    $manifestCsvPath = rtrim($outputDir, '/\\') . "/manifest-{$stamp}.csv";
    $exceptionCsvPath = rtrim($outputDir, '/\\') . "/exceptions-{$stamp}.csv";
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    file_put_contents($exceptionPath, json_encode(['mapping_exceptions' => $exceptions, 'duplicate_new_skus' => $duplicates], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    writeCsv($manifestCsvPath, $manifest, ['platform', 'product_id', 'sku_id', 'product_name', 'variation', 'old_sku', 'old_variant_sku', 'new_sku', 'new_variant_sku', 'datamap_row', 'datamap_sku', 'datamap_variation', 'file_path', 'already_migrated']);
    writeCsv($exceptionCsvPath, $exceptions, ['platform', 'product_id', 'sku_id', 'product_name', 'variation', 'old_sku', 'reason', 'candidate_rows']);

    $summary = [
        'ok' => $exceptions === [] && $duplicates === [],
        'generated_at' => date(DATE_ATOM),
        'manifest' => $manifestPath,
        'manifest_csv' => $manifestCsvPath,
        'exceptions' => $exceptionPath,
        'exceptions_csv' => $exceptionCsvPath,
        'targets' => [
            'shopee' => count(array_filter($manifest, static fn(array $row): bool => $row['platform'] === 'shopee')),
            'tiktok' => count(array_filter($manifest, static fn(array $row): bool => $row['platform'] === 'tiktok')),
            'unique_datamap_rows' => count(array_unique(array_column($manifest, 'datamap_row'))),
            'already_migrated' => count(array_filter($manifest, static fn(array $row): bool => $row['already_migrated'])),
        ],
        'mapping_exceptions' => count($exceptions),
        'duplicate_new_skus' => count($duplicates),
        'hard_stop' => $exceptions !== [] || $duplicates !== [],
    ];
    file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    echo json_encode($summary + ['summary' => $summaryPath], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($summary['ok'] ? 0 : 3);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
