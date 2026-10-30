<?php
require __DIR__.'/../src/OrderThumbnailService.php';
$catalog=['item'=>['image'=>['image_url_list'=>['https://down-id.img.susercontent.com/main']]],'models'=>['model'=>[['model_id'=>42,'tier_index'=>[1,0]]],'tier_variation'=>[['option_list'=>[['image'=>['image_url'=>'wrong']],['image'=>['image_url'=>'https://down-id.img.susercontent.com/variant']]]]]]];
if(OrderThumbnailService::selectImage($catalog,'42')!==['url'=>'https://down-id.img.susercontent.com/variant','kind'=>'variant'])throw new RuntimeException('Wrong variation image');
foreach(['0','99'] as $id)if(OrderThumbnailService::selectImage($catalog,$id)['kind']!=='main')throw new RuntimeException('Missing main fallback');
foreach(['http://127.0.0.1/test','https://susercontent.com.evil.test/a','https://user@susercontent.com/a','https://susercontent.com:444/a'] as $url)if(OrderThumbnailService::allowedUrl($url))throw new RuntimeException('Unsafe image URL accepted');
if(!OrderThumbnailService::allowedUrl('https://down-id.img.susercontent.com/a'))throw new RuntimeException('Shopee CDN rejected');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE product_inventory(item_key TEXT,model_sku TEXT,item_sku TEXT,no_ref TEXT);
CREATE TABLE data_mappings(id INTEGER,sku_id TEXT);
CREATE TABLE mapping_aliases(alias_key TEXT,mapping_id INTEGER);
INSERT INTO product_inventory VALUES ('LKU2DGRIA5H20','LKU2DGRIA5H20','','LKU2DGRIA5H20'),('LKU2CGRIA5H20','LKU2CGRIA5H20','','LKU2CGRIA5H20');
INSERT INTO data_mappings VALUES (1,'LKU2DGRIA5H20'),(2,'LKU2CGRIA5H20');
INSERT INTO mapping_aliases VALUES ('shopee:57215609713:421335186418',1),('shopee:57215609713:421335186406',2);");
$directory=sys_get_temp_dir().'/paperbell-thumbnail-'.bin2hex(random_bytes(6));
mkdir($directory);$files=[];
try{
    foreach(['421335186418','421335186406'] as $modelId){
        $key=hash('sha256','57215609713:'.$modelId);
        foreach(['image','json'] as $extension)$files[]=$directory.'/'.$key.'.'.$extension;
        file_put_contents($directory.'/'.$key.'.image',$modelId);
        file_put_contents($directory.'/'.$key.'.json',json_encode(['mime'=>'image/jpeg','kind'=>'variant','saved_at'=>time()]));
    }
    $service=new OrderThumbnailService($db,static fn()=>throw new RuntimeException('Unexpected catalog request'),$directory);
    // No order tables: an audited identity must work without guessing from historical SKU text.
    foreach(['LKU2DGRIA5H20'=>'421335186418','LKU2CGRIA5H20'=>'421335186406'] as $sku=>$modelId){
        $image=$service->forInventory($sku);
        if(!$image||file_get_contents($image['path'])!==$modelId)throw new RuntimeException('Wrong identity image: '.$sku);
    }
}finally{foreach($files as $file)if(is_file($file))unlink($file);rmdir($directory);}
echo "Order thumbnail selection, identities and URL tests passed\n";
