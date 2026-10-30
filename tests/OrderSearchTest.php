<?php
declare(strict_types=1);
require __DIR__ . '/../src/OrderSearch.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE orders(order_sn TEXT, buyer_username TEXT);
CREATE TABLE order_resi(order_sn TEXT, tracking_number TEXT);
CREATE TABLE order_process(order_sn TEXT, item_name TEXT, model_name TEXT, model_sku TEXT, item_sku TEXT);
INSERT INTO orders VALUES ("ORDER-1", "pembeli satu"), ("ORDER-2", "pembeli dua"), ("ORDER-3", "pembeli tiga");
INSERT INTO order_resi VALUES ("ORDER-1", "RESI-123");
INSERT INTO order_process VALUES
("ORDER-1", "Buku Gambar Kucing", "Paket 2 Buku", "CAT-2", "BUKU"),
("ORDER-2", "Buku Gambar Kucing", "1 Buku", "CAT-1", "BUKU"),
("ORDER-2", "Buku Anjing", "2 Buku", "DOG-2", "BUKU"),
("ORDER-3", NULL, NULL, "SKU_100%", NULL);');

function matches(string $query, array $expected): void
{
    global $db;
    $search = OrderSearch::condition($query);
    $stmt = $db->prepare('SELECT o.order_sn FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn WHERE ' . $search['sql'] . ' ORDER BY o.order_sn');
    $stmt->execute($search['params']);
    $actual = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($actual !== $expected) throw new RuntimeException($query . ': ' . json_encode($actual));
}

matches('kucing 2 buku', ['ORDER-1']);
matches(' BUKU   2 KUCING ', ['ORDER-1']);
matches("kucing\t2\nbuku", ['ORDER-1']);
matches('kucing CAT-2', ['ORDER-1']);
matches('kucing 3 buku', []);
matches('ORDER-2', ['ORDER-2']);
matches('pembeli satu', ['ORDER-1']);
matches('RESI-123', ['ORDER-1']);
matches('SKU_100%', ['ORDER-3']);
matches('%', ['ORDER-3']);
matches("' OR 1=1 --", []);
matches('', ['ORDER-1', 'ORDER-2', 'ORDER-3']);
echo "OrderSearch tests passed\n";
