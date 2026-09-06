<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PrintService.php';

$path = tempnam(sys_get_temp_dir(), 'paperbell-file-cache-');
if ($path === false) throw new RuntimeException('Gagal membuat file cache sementara.');

try {
    $savedAt = time() - 120;
    file_put_contents($path, json_encode([
        'saved_at' => $savedAt,
        'paths' => [
            '/legacy/ready.pdf' => true,
            '/current/missing.pdf' => ['available' => false, 'checked_at' => $savedAt + 30],
        ],
    ], JSON_THROW_ON_ERROR));

    $service = (new ReflectionClass(PrintService::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PrintService::class, 'fileAvailabilityCacheFile');
    $property->setValue($service, $path);
    $method = new ReflectionMethod(PrintService::class, 'fileAvailabilityCache');
    $cache = $method->invoke($service);
    $checkMethod = new ReflectionMethod(PrintService::class, 'checkFileAvailability');
    $availability = $checkMethod->invoke($service, [$path, $path . '.missing']);

    $reflection = new ReflectionClass(PrintService::class);
    assert($reflection->getConstant('AVAILABLE_FILE_CACHE_TTL') === 604800);
    assert($reflection->getConstant('MISSING_FILE_CACHE_TTL') === 900);
    assert($cache['/legacy/ready.pdf'] === ['available' => true, 'checked_at' => $savedAt]);
    assert($cache['/current/missing.pdf'] === ['available' => false, 'checked_at' => $savedAt + 30]);
    assert($availability[$path] === true);
    assert($availability[$path . '.missing'] === false);
    echo "PrintService file cache tests passed\n";
} finally {
    @unlink($path);
}
