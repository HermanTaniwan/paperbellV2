<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/src/Database.php';

try {
    $database = Database::mysql($config['mysql']);
    $rows = $database->query("SELECT sku_id,file_path,paper,page_from,page_to,copies,duplex,printer FROM data_mappings WHERE sku_id LIKE '%H6'")->fetchAll();
    $lookup = $database->prepare('SELECT sku_id,file_path,paper,page_from,page_to,copies,duplex,printer FROM data_mappings WHERE sku_id=?');
    $missingBase = [];
    $mismatches = [];
    foreach ($rows as $row) {
        $baseSku = substr((string)$row['sku_id'], 0, -2);
        $lookup->execute([$baseSku]);
        $base = $lookup->fetch();
        if (!$base) {
            $missingBase[] = (string)$row['sku_id'];
            continue;
        }
        foreach (['file_path', 'paper', 'page_from', 'page_to', 'copies', 'duplex', 'printer'] as $field) {
            if ((string)$row[$field] !== (string)$base[$field]) {
                $mismatches[] = ['sku' => $row['sku_id'], 'base_sku' => $baseSku, 'field' => $field, 'base' => $base[$field], 'h6' => $row[$field]];
            }
        }
    }
    $duplicate = $database->query("SELECT sku_id,COUNT(*) total FROM data_mappings GROUP BY sku_id HAVING COUNT(*)>1")->fetchAll();
    $invalidPaper = array_values(array_filter($rows, static fn(array $row): bool => strtoupper((string)$row['paper']) !== 'A5'));
    $result = [
        'ok' => count($rows) === 391 && $missingBase === [] && $mismatches === [] && $duplicate === [] && $invalidPaper === [],
        'h6_count' => count($rows),
        'missing_base_count' => count($missingBase),
        'pair_mismatch_count' => count($mismatches),
        'duplicate_sku_count' => count($duplicate),
        'invalid_paper_count' => count($invalidPaper),
        'sample' => array_values(array_filter($rows, static fn(array $row): bool => in_array($row['sku_id'], ['PBEIXXBUNA5H6', 'LMATDBLAA5H6'], true))),
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['ok'] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
