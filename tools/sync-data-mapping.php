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
require $root . '/src/DataMappingService.php';

try {
    $database = Database::mysql($config['mysql']);
    $service = new DataMappingService($database, $config['mapping'] + ['python' => $config['printing']['python']], $root);
    $result = $service->syncFromGoogle('SKU A5 H6 migration');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
