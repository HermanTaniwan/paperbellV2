<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if ($argc !== 2) { fwrite(STDERR, "Usage: php tools/import-shopee-product-clicks.php daily-clicks.csv\nColumns: date,clicks,source_file\n"); exit(1); }
require __DIR__.'/../src/ShopeeProductClicksService.php';
$file = fopen($argv[1], 'rb');
if ($file === false) throw new RuntimeException('CSV tidak bisa dibuka.');
$header = fgetcsv($file, escape: '');
if ($header) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
if ($header !== ['date','clicks','source_file']) throw new InvalidArgumentException('Kolom CSV harus date,clicks,source_file.');
$rows = [];
while (($row = fgetcsv($file, escape: '')) !== false) {
    if ($row === [null]) continue;
    if (count($row) !== 3 || !ShopeeProductClicksService::validDate($row[0]) || !ctype_digit($row[1]) || (float)$row[1] > 2147483647 || trim($row[2]) === '' || strlen($row[2]) > 255) {
        throw new InvalidArgumentException('Baris CSV tidak valid: '.(count($rows)+2));
    }
    if (isset($rows[$row[0]])) throw new InvalidArgumentException('Tanggal duplikat: '.$row[0]);
    $rows[$row[0]] = [$row[0], (int)$row[1], $row[2], time()];
}
fclose($file);
if (!$rows) throw new InvalidArgumentException('CSV kosong.');
$config = require __DIR__.'/../config.php';
require __DIR__.'/../src/Database.php';
$db = Database::mysql($config['mysql']);
$db->exec('CREATE TABLE IF NOT EXISTS shopee_product_clicks_daily (stat_date DATE PRIMARY KEY, clicks INT NOT NULL, source_file VARCHAR(255) NOT NULL, imported_at BIGINT NOT NULL) ENGINE=InnoDB');
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO shopee_product_clicks_daily (stat_date,clicks,source_file,imported_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE clicks=VALUES(clicks),source_file=VALUES(source_file),imported_at=VALUES(imported_at)');
    foreach ($rows as $row) $stmt->execute($row);
    $db->commit();
} catch (Throwable $e) { $db->rollBack(); throw $e; }
echo json_encode(['days'=>count($rows),'clicks'=>array_sum(array_column($rows,1)),'from'=>min(array_keys($rows)),'to'=>max(array_keys($rows))], JSON_PRETTY_PRINT), "\n";
