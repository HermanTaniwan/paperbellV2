<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root=dirname(__DIR__);
$config=require $root.'/config.php';
foreach(['Database','OAuthVault','MarketplaceOAuthService','ShopeeStockService'] as $class)require_once $root.'/src/'.$class.'.php';
date_default_timezone_set($config['app']['timezone']);
$db=Database::mysql($config['mysql']);
$oauth=new MarketplaceOAuthService($db,new OAuthVault($config['oauth']['key_file']),$config['oauth']);
$service=new ShopeeStockService($db,$oauth);
$itemId=57215609713;
$before=$service->imageCatalog($itemId);
if(($before['item']['item_sku']??'')!=='LKU2'||count($before['models']['model']??[])!==72)throw new RuntimeException('Product identity/count changed.');
$allowedChanges=[
    '421335186406'=>['DGRIA5H20','CGRIA5H20'],
    '421335186410'=>['DGRIA5H6','CGRIA5H6'],
    '421335186414'=>['DGRIB5','CGRIB5'],
    '421335186429'=>['EADOTA5H20','EDOTA5H20'],
    '421335186433'=>['EADOTA5H6','EDOTA5H6'],
    '421335186437'=>['EADOTB5','EDOTB5'],
    '421335186434'=>['EGRIA5H','EGRIA5H6'],
    '421335186446'=>['FGRIA5H','FGRIA5H6'],
];
$models=[];$updates=[];$changes=[];$identities=[];$seen=[];
$find=$db->prepare('SELECT * FROM data_mappings WHERE sku_id=?');
$getAlias=$db->prepare('SELECT mapping_id FROM mapping_aliases WHERE alias_key=?');
foreach($before['models']['model'] as $model){
    $id=(string)$model['model_id'];$name=(string)$model['model_name'];
    if(!preg_match('/^Kucing 2([A-F])-[^,]+,(A5|B5) (polos|garis|dots|grid) \((20|6|26) Lubang\)$/D',$name,$match))throw new RuntimeException('Unexpected variant name: '.$name);
    $desired=$match[1].['polos'=>'BLA','garis'=>'LIN','dots'=>'DOT','grid'=>'GRI'][$match[3]].$match[2].($match[2]==='A5'?'H'.$match[4]:'');
    if(isset($seen[$desired]))throw new RuntimeException('Canonical SKU collision: '.$desired);
    $seen[$desired]=true;
    $find->execute(['LKU2'.$desired]);$mapping=$find->fetch();
    if(!$mapping||!is_file($mapping['file_path'])||stripos($mapping['variation_name'],'Kucing '.$match[1].',')!==0||!str_ends_with($mapping['file_path'],' '.$match[1].'.pdf'))throw new RuntimeException('Mapping/PDF mismatch: LKU2'.$desired);
    $alias='shopee:'.$itemId.':'.$id;$getAlias->execute([$alias]);$existing=$getAlias->fetchColumn();
    if($existing!==false&&(int)$existing!==(int)$mapping['id'])throw new RuntimeException('Identity already bound elsewhere: '.$alias);
    $identities[$id]=['alias'=>$alias,'mapping_id'=>(int)$mapping['id'],'sku'=>'LKU2'.$desired];
    $models[$id]=$model;
    if($model['model_sku']===$desired)continue;
    if(!isset($allowedChanges[$id])||$allowedChanges[$id]!==[$model['model_sku'],$desired])throw new RuntimeException('Unreviewed SKU difference: '.$id);
    $updates[]=['model_id'=>(int)$id,'model_sku'=>$desired];
    $changes[]=['model_id'=>$id,'variant'=>$name,'old_sku'=>'LKU2'.$model['model_sku'],'new_sku'=>'LKU2'.$desired];
}
$lineStmt=$db->prepare("SELECT id,order_item_id,item_key,model_sku,item_sku,printed,printed_at FROM order_process WHERE order_sn NOT LIKE 'TIKTOK:%' AND order_item_id LIKE ?");
$lineStmt->execute([$itemId.':%']);$lines=$lineStmt->fetchAll();$lineChanges=[];
foreach($lines as $line){$id=substr($line['order_item_id'],strlen($itemId.':'));if(!isset($identities[$id]))throw new RuntimeException('Unknown historical model.');$sku=$identities[$id]['sku'];if($line['item_key']!==$sku)$lineChanges[]=['id'=>(int)$line['id'],'old_key'=>$line['item_key'],'new_key'=>$sku,'printed'=>(bool)$line['printed']];}
$plan=['item_id'=>$itemId,'checked_variants'=>count($models),'sku_changes'=>$changes,'historical_line_changes'=>$lineChanges,'identity_bindings'=>count($identities)];
echo json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
if(!in_array('--apply',$argv,true))exit;

$backupDir=$root.'/storage/sku-repairs';
if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true)&&!is_dir($backupDir))throw new RuntimeException('Cannot create backup directory.');
$backup=$backupDir.'/kucing-part2-'.date('Ymd-His').'.json';
if(file_put_contents($backup,json_encode(['catalog'=>$before,'lines'=>$lines,'plan'=>$plan],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Backup failed.');
// Save local identity corrections first so stale order payloads cannot undo the repair.
$db->beginTransaction();
try{
    $bind=$db->prepare('INSERT IGNORE INTO mapping_aliases(alias_key,mapping_id) VALUES(?,?)');
    foreach($identities as $identity)$bind->execute([$identity['alias'],$identity['mapping_id']]);
    $update=$db->prepare('UPDATE order_process SET item_key=? WHERE id=? AND item_key=?');
    foreach($lineChanges as $line)$update->execute([$line['new_key'],$line['id'],$line['old_key']]);
    foreach($identities as $identity){$getAlias->execute([$identity['alias']]);if((int)$getAlias->fetchColumn()!==$identity['mapping_id'])throw new RuntimeException('Identity verification failed.');}
    $lineStmt->execute([$itemId.':%']);foreach($lineStmt->fetchAll() as $line){$id=substr($line['order_item_id'],strlen($itemId.':'));if($line['item_key']!==$identities[$id]['sku'])throw new RuntimeException('Historical line verification failed.');}
    $db->commit();
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
if($updates){
    $request=new ReflectionMethod(ShopeeStockService::class,'shopee');
    $request->invoke($service,'POST','/api/v2/product/update_model',[],['item_id'=>$itemId,'model'=>$updates],$oauth->credentials('shopee'));
}
$after=$service->imageCatalog($itemId);$afterModels=array_column($after['models']['model']??[],null,'model_id');
if(count($afterModels)!==count($models))throw new RuntimeException('Variant count changed after repair.');
foreach($models as $id=>$model){$expected=substr($identities[$id]['sku'],4);if(($afterModels[$id]['model_sku']??null)!==$expected||($afterModels[$id]['tier_index']??null)!==$model['tier_index'])throw new RuntimeException('Shopee verification failed: '.$id);}
// Replace this product's catalog cache; existing images remain tied to their stable model IDs.
$after['saved_at']=time();$catalogPath=$root.'/storage/order-thumbnails/'.$itemId.'.catalog.json';
if(is_dir(dirname($catalogPath)))file_put_contents($catalogPath,json_encode($after,JSON_THROW_ON_ERROR),LOCK_EX);
$mappingCache=$root.'/storage/order-mapping-cache.json';if(is_file($mappingCache))unlink($mappingCache);
$result=['verified_variants'=>count($afterModels),'updated_marketplace_skus'=>count($updates),'corrected_order_lines'=>count($lineChanges),'previously_printed_corrected_lines'=>count(array_filter($lineChanges,fn($line)=>$line['printed'])),'backup'=>$backup];
file_put_contents($backup.'.result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
