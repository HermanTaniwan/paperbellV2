<?php
declare(strict_types=1);
require __DIR__.'/../src/ShopeeSkuIdentity.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE data_mappings(id INTEGER,sku_id TEXT);
CREATE TABLE mapping_aliases(alias_key TEXT PRIMARY KEY,mapping_id INTEGER);
INSERT INTO data_mappings VALUES (1,'LKU2CGRIA5H20'),(2,'LKU2DGRIA5H20');
INSERT INTO mapping_aliases VALUES ('shopee:57215609713:421335186406',1),('shopee:57215609713:421335186418',2);");
foreach ([
    ['57215609713:421335186406','LKU2DGRIA5H20','LKU2CGRIA5H20'],
    ['57215609713:421335186418','LKU2DGRIA5H20','LKU2DGRIA5H20'],
    ['OTHER:421335186406','UNCHANGED','UNCHANGED'],
    ['57215609713:unknown','UNCHANGED','UNCHANGED'],
] as [$id,$raw,$expected]) {
    if (ShopeeSkuIdentity::itemKey($db,$id,$raw) !== $expected) throw new RuntimeException('Incorrect identity: '.$id);
}
echo "Shopee SKU identity tests passed\n";
