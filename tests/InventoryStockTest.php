<?php
declare(strict_types=1);

require_once __DIR__.'/../src/Database.php';
require_once __DIR__.'/../src/InventoryStock.php';

function checkInventory(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$raw = InventoryStock::unpunchedKey('TESTA5');
checkInventory($raw === InventoryStock::unpunchedKey('testA5H6'), '6 and 20 holes share unpunched stock');
checkInventory($raw !== InventoryStock::unpunchedKey('TESTB5'), 'B5 is separate');
checkInventory($raw !== InventoryStock::unpunchedKey('OTHERA5'), 'Different designs are separate');
$keys = InventoryStock::candidates(['item_key'=>'TESTA5H6'], ['sku_id'=>'TESTA5H6']);
checkInventory($keys === ['TESTA5H6', $raw], 'Finished stock precedes shared stock, without duplicates');
checkInventory(InventoryStock::candidates(['item_key'=>'testa5h6'], ['sku_id'=>'TESTA5H6']) === $keys, 'Case variants do not count stock twice');
checkInventory(count(InventoryStock::candidates(['item_key'=>'unknown'], null)) === 1, 'Unmapped items cannot borrow shared stock');
checkInventory(InventoryStock::quantities($keys, ['testa5h6'=>1, strtolower($raw)=>4]) === ['ready'=>1,'unpunched'=>4,'total'=>5], 'Availability combines finished and shared stock');

// Connection-local temporary tables shadow the live tables. No live stock,
// orders, movements, or printer queues are changed by this integration test.
$config = require __DIR__.'/../config.php';
$db = Database::mysql($config['mysql']);
$db->exec("CREATE TEMPORARY TABLE product_inventory (item_key VARCHAR(255) PRIMARY KEY, model_sku VARCHAR(255) DEFAULT '', item_sku VARCHAR(255) DEFAULT '', item_name TEXT, model_name TEXT, no_ref VARCHAR(255) DEFAULT '', sku_induk VARCHAR(255) DEFAULT '', qty INT NOT NULL, updated_at BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
$db->exec('CREATE TEMPORARY TABLE inventory_movements (item_key VARCHAR(255), movement_type VARCHAR(30), qty_delta INT, qty_after INT, order_sn VARCHAR(100), note VARCHAR(500), created_by VARCHAR(100), created_at BIGINT) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE data_mappings (sku_id VARCHAR(255) PRIMARY KEY) ENGINE=InnoDB');
try {
    $put = $db->prepare('INSERT INTO product_inventory(item_key,qty) VALUES(?,?)');
    foreach (['TESTA5'=>2, 'TESTA5H6'=>1, $raw=>4, 'TESTB5'=>9] as $key=>$qty) $put->execute([$key,$qty]);
    $db->beginTransaction();
    $result = InventoryStock::consume($db, $keys, 3, 'test-six', 'test', 'A5 (6 Lubang)');
    checkInventory($result === ['remaining'=>2,'unpunchedUsed'=>2], 'Consume finished six-hole first, then two shared packs');
    $db->commit();
    $db->beginTransaction();
    $result = InventoryStock::consume($db, InventoryStock::candidates(['item_key'=>'TESTA5'], ['sku_id'=>'TESTA5']), 3, 'test-twenty', 'test', 'A5 (20 Lubang)');
    checkInventory($result === ['remaining'=>1,'unpunchedUsed'=>1], 'Twenty-hole order draws from the same remaining pool');
    $db->commit();
    $stocks = $db->query('SELECT item_key,qty FROM product_inventory')->fetchAll(PDO::FETCH_KEY_PAIR);
    checkInventory((int)$stocks[$raw] === 1 && (int)$stocks['TESTB5'] === 9, 'Shared stock deducted once; B5 untouched');
    checkInventory((int)$db->query('SELECT SUM(qty_delta) FROM inventory_movements')->fetchColumn() === -6, 'Movements account for every consumed pack');
    $db->beginTransaction();
    try {
        InventoryStock::consume($db, $keys, 2, 'test-insufficient', 'test', '6 lubang');
        throw new RuntimeException('Insufficient stock was accepted');
    } catch (InvalidArgumentException $e) {
        $db->rollBack();
    }
    checkInventory((int)$db->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn() === 4, 'Insufficient stock creates no movements');
    $db->beginTransaction();
    InventoryStock::consume($db, $keys, 1, 'test-rollback', 'test', '6 lubang');
    $db->rollBack();
    $get = $db->prepare('SELECT qty FROM product_inventory WHERE item_key=?');$get->execute([$raw]);
    checkInventory((int)$get->fetchColumn() === 1, 'Order transaction rollback restores shared stock');
    $put->execute(['OLDA5H6',5]);
    $db->exec("UPDATE product_inventory SET item_name='Old stock',item_sku='',sku_induk='' WHERE item_key='OLDA5H6'");
    $db->exec("INSERT INTO data_mappings VALUES ('OLDA5H6')");
    $beforeTotal = (int)$db->query('SELECT SUM(qty) FROM product_inventory')->fetchColumn();
    $db->beginTransaction();
    InventoryStock::markUnpunched($db, 'OLDA5H6', 3, 'test');
    $db->commit();
    $stocks = $db->query('SELECT item_key,qty FROM product_inventory')->fetchAll(PDO::FETCH_KEY_PAIR);
    checkInventory((int)$stocks['OLDA5H6'] === 2 && (int)$stocks['UNPUNCHED:OLDA5'] === 3, 'Partial reclassification preserves ready stock');
    checkInventory((int)$db->query('SELECT SUM(qty) FROM product_inventory')->fetchColumn() === $beforeTotal, 'Reclassification preserves the total physical stock');
    checkInventory((int)$db->query("SELECT SUM(qty_delta) FROM inventory_movements WHERE movement_type='reclassify'")->fetchColumn() === 0, 'Transfer has balanced history entries');
    $db->beginTransaction();
    try {
        InventoryStock::markUnpunched($db, 'OLDA5H6', 3, 'test');
        throw new RuntimeException('Oversized transfer was accepted');
    } catch (InvalidArgumentException $e) {
        $db->rollBack();
    }
    echo "Inventory stock integration checks passed.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('DROP TEMPORARY TABLE IF EXISTS inventory_movements');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS product_inventory');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS data_mappings');
}
