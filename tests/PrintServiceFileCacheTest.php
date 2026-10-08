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

    $settingsMethod = new ReflectionMethod(PrintService::class, 'productSettings');
    $wfMapping = ['printer' => 'EPSON_WF-C5390', 'page_from' => 1, 'page_to' => 0, 'copies' => 1, 'duplex' => 'simplex', 'paper' => 'DEFAULT'];
    assert(str_contains($settingsMethod->invoke($service, $wfMapping, 1, ['paper' => 'B5']), 'bin=261'));
    assert(str_contains($settingsMethod->invoke($service, $wfMapping, 1, ['paper' => 'A5']), 'bin=1'));
    $brotherMapping = array_replace($wfMapping, ['printer' => 'Brother DCP-T830DW Printer', 'paper' => 'A5']);
    assert($settingsMethod->invoke($service, $brotherMapping, 1) === '1-,simplex,noscale,bin=1,paper=A5');
    assert($settingsMethod->invoke($service, $brotherMapping, 2, ['paper' => 'B5', 'page_from' => 3, 'page_to' => 4, 'duplex' => 'duplexlong']) === '3-4,duplexlong,noscale,bin=258,paper=B5,2x');
    $coverMapping = array_replace($wfMapping, ['printer' => 'Cover Binder A4 Borderless High', 'page_from' => 4, 'page_to' => 6]);
    assert($settingsMethod->invoke($service, $coverMapping, 1) === '4-6,simplex,fit,paper=A4 (Borderless) (210 x 297 mm)');
    assert($settingsMethod->invoke($service, $coverMapping, 2, ['paper' => 'A4']) === '4-6,simplex,fit,paper=A4 (Borderless) (210 x 297 mm),2x');
    assert($settingsMethod->invoke($service, $coverMapping, 1, ['paper' => 'A5']) === '4-6,simplex,noscale,paper=A5');
    echo "PrintService file cache tests passed\n";
} finally {
    @unlink($path);
}
