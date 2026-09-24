<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/HostPathResolver.php';
require_once __DIR__ . '/../src/PrintService.php';

// Fail immediately if listing touches the remote filesystem, even on a cold cache.
class OrderItemsRemoteStream
{
    public function url_stat(string $path, int $flags): array|false
    {
        throw new RuntimeException('Order listing accessed remote PDF: ' . $path);
    }
}
stream_wrapper_register('orderitemsremote', OrderItemsRemoteStream::class);

class OrderItemsStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}

class OrderItemsDatabase extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM order_process')) {
            $base = ['order_sn'=>'test-order','model_sku'=>'','item_sku'=>'','item_name'=>'Test','model_name'=>'','qty'=>2,'printed_odd'=>0,'printed_even'=>0,'printed_at'=>null];
            return new OrderItemsStatement([
                $base + ['id'=>1,'item_key'=>'mapped','printed'=>0],
                array_replace($base, ['id'=>2,'item_key'=>'mapped','printed'=>1,'printed_at'=>123]),
                $base + ['id'=>3,'item_key'=>'unmapped','printed'=>0],
            ]);
        }
        if (str_contains($query, 'FROM product_inventory')) return new OrderItemsStatement([]);
        throw new RuntimeException('Unexpected query: ' . $query);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        if (str_contains($query, 'FROM print_jobs')) return new OrderItemsStatement([]);
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}

$cache = tempnam(sys_get_temp_dir(), 'order-items-mapping-');
try {
    file_put_contents($cache, json_encode(['by_key'=>['mapped'=>[
        'sku_id'=>'mapped','parent_sku'=>'','file_path'=>'orderitemsremote://drive/product.pdf',
        'printer'=>'test-printer','page_from'=>1,'page_to'=>0,'copies'=>1,'duplex'=>'simplex','paper'=>'A5',
    ]]], JSON_THROW_ON_ERROR));
    $service = new PrintService(new OrderItemsDatabase());
    (new ReflectionProperty(PrintService::class, 'orderMappingCacheFile'))->setValue($service, $cache);
    (new ReflectionProperty(PrintService::class, 'settingsCache'))->setValue($service, [
        'visible'=>['test-printer'],'override_brother'=>'','override_l3210'=>'',
    ]);
    $lines = $service->listOrderItems(['test-order'])['test-order'];
    assert(count($lines) === 3);
    assert($lines[0]['print_ready'] && $lines[0]['has_pdf'] && !$lines[0]['printed']);
    assert($lines[1]['printed'] && $lines[1]['printed_at'] === 123);
    assert(!$lines[2]['print_ready'] && $lines[2]['print_reason'] === 'Mapping tidak ditemukan');
    assert($lines[0]['print_options']['copies'] === 2);
    echo "Order items load without remote PDF checks: passed\n";
} finally {
    unlink($cache);
    stream_wrapper_unregister('orderitemsremote');
}
