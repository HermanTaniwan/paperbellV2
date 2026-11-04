<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
if($argc!==2){fwrite(STDERR,"Usage: php tools/import-shopee-product-traffic.php monthly-traffic.json\n");exit(1);}
require __DIR__.'/../src/ShopeeProductTrafficService.php';
$rows=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if(!is_array($rows)||!array_is_list($rows)||!$rows)throw new InvalidArgumentException('Data impor kosong atau tidak valid.');
$seen=[];$values=[];
foreach($rows as $row){
    $month=(string)($row['month']??'');$code=(string)($row['code']??'');
    if(!ShopeeProductTrafficService::validMonth($month)||!preg_match('/^\d{1,50}$/D',$code))throw new InvalidArgumentException('Bulan/kode produk tidak valid.');
    if(isset($seen[$month.':'.$code]))throw new InvalidArgumentException('Produk duplikat dalam bulan yang sama.');
    $seen[$month.':'.$code]=true;$entry=[$month.'-01',$code];
    foreach(['name'=>1000,'sku'=>255] as $key=>$limit){if(!isset($row[$key])||!is_string($row[$key])||strlen($row[$key])>$limit)throw new InvalidArgumentException('Nama/SKU tidak valid.');$entry[]=$row[$key];}
    foreach(['impressions','clicks','visitors','carts','orders'] as $key){if(!array_key_exists($key,$row)||($row[$key]!==null&&(!is_int($row[$key])||$row[$key]<0||$row[$key]>2147483647)))throw new InvalidArgumentException('Metrik tidak valid: '.$key);$entry[]=$row[$key];}
    if(empty($row['source_file'])||!is_string($row['source_file'])||strlen($row['source_file'])>255)throw new InvalidArgumentException('Sumber tidak valid.');
    $entry[]=$row['source_file'];$entry[]=time();$values[]=$entry;
}
$config=require __DIR__.'/../config.php';require __DIR__.'/../src/Database.php';$db=Database::mysql($config['mysql']);
$db->exec('CREATE TABLE IF NOT EXISTS shopee_product_traffic_monthly (month_start DATE NOT NULL,product_code VARCHAR(50) NOT NULL,product_name VARCHAR(1000) NOT NULL,parent_sku VARCHAR(255) NOT NULL,impressions INT NULL,clicks INT NULL,visitors INT NULL,carts INT NULL,orders INT NULL,source_file VARCHAR(255) NOT NULL,imported_at BIGINT NOT NULL,PRIMARY KEY(month_start,product_code)) ENGINE=InnoDB');
$db->beginTransaction();
try{$stmt=$db->prepare('INSERT INTO shopee_product_traffic_monthly (month_start,product_code,product_name,parent_sku,impressions,clicks,visitors,carts,orders,source_file,imported_at) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE product_name=VALUES(product_name),parent_sku=VALUES(parent_sku),impressions=VALUES(impressions),clicks=VALUES(clicks),visitors=VALUES(visitors),carts=VALUES(carts),orders=VALUES(orders),source_file=VALUES(source_file),imported_at=VALUES(imported_at)');foreach($values as $entry)$stmt->execute($entry);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}
echo json_encode(['rows'=>count($values),'products'=>count(array_unique(array_column($values,1))),'months'=>count(array_unique(array_column($values,0)))]),"\n";
