<?php
require __DIR__.'/../src/OrderThumbnailService.php';
$catalog=['item'=>['image'=>['image_url_list'=>['https://down-id.img.susercontent.com/main']]],'models'=>['model'=>[['model_id'=>42,'tier_index'=>[1,0]]],'tier_variation'=>[['option_list'=>[['image'=>['image_url'=>'wrong']],['image'=>['image_url'=>'https://down-id.img.susercontent.com/variant']]]]]]];
if(OrderThumbnailService::selectImage($catalog,'42')!==['url'=>'https://down-id.img.susercontent.com/variant','kind'=>'variant'])throw new RuntimeException('Wrong variation image');
foreach(['0','99'] as $id)if(OrderThumbnailService::selectImage($catalog,$id)['kind']!=='main')throw new RuntimeException('Missing main fallback');
foreach(['http://127.0.0.1/test','https://susercontent.com.evil.test/a','https://user@susercontent.com/a','https://susercontent.com:444/a'] as $url)if(OrderThumbnailService::allowedUrl($url))throw new RuntimeException('Unsafe image URL accepted');
if(!OrderThumbnailService::allowedUrl('https://down-id.img.susercontent.com/a'))throw new RuntimeException('Shopee CDN rejected');
echo "Order thumbnail selection and URL tests passed\n";
