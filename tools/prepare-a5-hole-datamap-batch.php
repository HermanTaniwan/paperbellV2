<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$options = getopt('', ['manifest:', 'datamap:', 'chunk::', 'chunk-size::', 'finalize', 'plan-only']);

function requiredJson(array $options, string $key): array
{
    $path = trim((string)($options[$key] ?? ''));
    if ($path === '' || !is_file($path)) throw new InvalidArgumentException("File --{$key} tidak ditemukan.");
    $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException("JSON tidak valid: {$path}");
    return $value;
}

function canonicalVariation(string $value): string
{
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    $value = (string)preg_replace('/\b6\s*hole\b/iu', '6 Lubang', $value);
    $value = (string)preg_replace('/\bA\s+6\s*Lubang\b/iu', 'A5 (6 Lubang)', $value);
    $value = (string)preg_replace('/\bA\s*5\s*\(?\s*6\s*Lubang\s*\)?/iu', 'A5 (6 Lubang)', $value);
    $value = (string)preg_replace('/\(\s*6\s*Lubang\s*\)/iu', '(6 Lubang)', $value);
    return $value;
}

function stringCell(string $value): array
{
    return ['userEnteredValue' => ['stringValue' => $value]];
}

try {
    $manifest = requiredJson($options, 'manifest');
    $datamap = requiredJson($options, 'datamap');
    if (count($datamap) < 2) throw new RuntimeException('Datamap kosong.');

    $headers = array_map(static fn($value): string => trim((string)$value), $datamap[0]);
    $headerIndex = array_flip($headers);
    foreach (['Search Alias'] as $required) {
        if (!array_key_exists($required, $headerIndex)) throw new RuntimeException("Kolom {$required} tidak ditemukan.");
    }

    $byRow = [];
    foreach ($manifest as $item) {
        if (($item['platform'] ?? '') !== 'shopee') continue;
        $row = (int)($item['datamap_row'] ?? 0);
        if ($row < 2) throw new RuntimeException('Manifest berisi nomor baris datamap tidak valid.');
        $byRow[$row] ??= $item;
    }
    krsort($byRow, SORT_NUMERIC);

    $lastUsed = 1;
    foreach (array_slice($datamap, 1, null, true) as $zeroBased => $row) {
        if (implode('', array_map(static fn($value): string => trim((string)$value), $row)) !== '') $lastUsed = $zeroBased + 1;
    }
    $finalLastUsed = $lastUsed + count($byRow);

    if (array_key_exists('finalize', $options)) {
        $requests = [
            [
                'repeatCell' => [
                    'range' => ['sheetId' => 0, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 15, 'endColumnIndex' => 16],
                    'cell' => stringCell('Hole Code'),
                    'fields' => 'userEnteredValue',
                ],
            ],
            [
                'repeatCell' => [
                    'range' => ['sheetId' => 0, 'startRowIndex' => 1, 'endRowIndex' => $finalLastUsed, 'startColumnIndex' => 0, 'endColumnIndex' => 1],
                    'cell' => ['userEnteredValue' => ['formulaValue' => '=D2&E2&F2&G2&H2&P2']],
                    'fields' => 'userEnteredValue',
                ],
            ],
            [
                'autoResizeDimensions' => [
                    'dimensions' => ['sheetId' => 0, 'dimension' => 'COLUMNS', 'startIndex' => 15, 'endIndex' => 16],
                ],
            ],
        ];
        echo json_encode(['requests' => $requests, 'final_last_used' => $finalLastUsed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit(0);
    }

    $chunkSize = max(1, (int)($options['chunk-size'] ?? 50));
    $chunk = max(0, (int)($options['chunk'] ?? 0));
    $rows = array_values($byRow);
    $slice = array_slice($rows, $chunk * $chunkSize, $chunkSize);
    $requests = [];
    $plannedRows = [];
    foreach ($slice as $item) {
        $sourceRow = (int)$item['datamap_row'];
        $source = $datamap[$sourceRow - 1] ?? null;
        if (!is_array($source)) throw new RuntimeException("Baris datamap {$sourceRow} tidak ditemukan.");
        $alias = trim((string)($source[$headerIndex['Search Alias']] ?? ''));
        if (!preg_match('/(?:^|\s)6\s*Lubang(?:\s|$)/iu', $alias)) $alias = trim($alias . ' 6 Lubang');
        $variation = canonicalVariation((string)($item['variation'] ?? ''));
        if (!preg_match('/(?:^|\D)6\s*Lubang(?:\D|$)/iu', $variation)) throw new RuntimeException("Variasi sumber tidak terdeteksi sebagai 6 lubang pada baris {$sourceRow}.");

        $insertIndex = $sourceRow;
        $requests[] = [
            'insertDimension' => [
                'range' => ['sheetId' => 0, 'dimension' => 'ROWS', 'startIndex' => $insertIndex, 'endIndex' => $insertIndex + 1],
                'inheritFromBefore' => true,
            ],
        ];
        $requests[] = [
            'copyPaste' => [
                'source' => ['sheetId' => 0, 'startRowIndex' => $sourceRow - 1, 'endRowIndex' => $sourceRow, 'startColumnIndex' => 0, 'endColumnIndex' => 21],
                'destination' => ['sheetId' => 0, 'startRowIndex' => $insertIndex, 'endRowIndex' => $insertIndex + 1, 'startColumnIndex' => 0, 'endColumnIndex' => 21],
                'pasteType' => 'PASTE_NORMAL',
                'pasteOrientation' => 'NORMAL',
            ],
        ];
        foreach ([[2, $variation], [13, $alias], [15, 'H6']] as [$column, $value]) {
            $requests[] = [
                'repeatCell' => [
                    'range' => ['sheetId' => 0, 'startRowIndex' => $insertIndex, 'endRowIndex' => $insertIndex + 1, 'startColumnIndex' => $column, 'endColumnIndex' => $column + 1],
                    'cell' => stringCell($value),
                    'fields' => 'userEnteredValue',
                ],
            ];
        }
        $plannedRows[] = ['source_row' => $sourceRow, 'variation' => $variation, 'search_alias' => $alias, 'new_sku' => (string)$item['new_sku']];
    }

    echo json_encode([
        'chunk' => $chunk,
        'chunk_size' => $chunkSize,
        'total_rows' => count($byRow),
        'chunk_count' => (int)ceil(count($byRow) / $chunkSize),
        'rows' => $plannedRows,
        'requests' => array_key_exists('plan-only', $options) ? [] : $requests,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
