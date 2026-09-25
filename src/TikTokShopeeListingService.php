<?php
declare(strict_types=1);

/** Creates a TikTok Shop draft from one Shopee item. It deliberately never lists live products. */
final class TikTokShopeeListingService
{
    public function __construct(private MarketplaceOAuthService $oauth) {}

    public function createDraft(int $itemId): array
    {
        if ($itemId < 1) throw new InvalidArgumentException('ID produk Shopee tidak valid.');
        $shopee=$this->oauth->credentials('shopee');$item=$this->shopeeItem($itemId,$shopee);
        $tiktok=$this->oauth->credentials('tiktok');$template=$this->stickerTemplate($tiktok);
        $images=[];foreach(array_slice($this->imageUrls($item),0,9) as $url)$images[]=['uri'=>$this->uploadImage($url,$tiktok)];
        if(!$images)throw new RuntimeException('Produk Shopee tidak memiliki gambar yang dapat diunggah ke TikTok.');$title=$this->title((string)($item['item_name']??''));$description=$this->description((string)($item['description']??''));$categoryId=$this->categoryV2($tiktok,$title,$description,$images);
        $models=$this->shopeeModels($itemId,$shopee);if(!$models)$models=[[]];$model=$models[0];$skus=[];$templateInventory=$template['skus'][0]['inventory']??[];foreach($models as $index=>$model){$price=$this->price($model['price_info'][0]['original_price']??$item['price_info'][0]['original_price']??null);if($price===null)throw new RuntimeException('Harga salah satu variasi Shopee tidak tersedia.');$stock=max(0,(int)($model['stock_info_v2']['seller_stock'][0]['stock']??$model['stock_info_v2']['summary_info']['total_available_stock']??$model['stock_info_v2']['summary_info']['total_reserved_stock']??0));$sku=trim((string)($model['model_sku']??$item['item_sku']??''));if($sku==='')$sku='SHOPEE-'.$itemId.'-'.($index+1);$variant=trim((string)($model['model_name']??''));$skus[]=['seller_sku'=>mb_substr($sku,0,100),'sales_attributes'=>[['name'=>'Specification','value_name'=>mb_substr($variant!==''?$variant:'Variant '.($index+1),0,50)]],'price'=>['amount'=>(string)$price,'currency'=>'IDR'],'inventory'=>$this->inventory($templateInventory,$stock)];}
        $body=['save_mode'=>'AS_DRAFT','idempotency_key'=>'shopee-'.$itemId,'category_id'=>$categoryId,'category_version'=>'v2','listing_platforms'=>['TIKTOK_SHOP','TOKOPEDIA'],'title'=>$title,'description'=>$description,'main_images'=>$images,'package_weight'=>$this->weight($item,$model),'package_dimensions'=>$this->dimensions($item,$model),'is_cod_allowed'=>(bool)($template['is_cod_allowed']??false),'skus'=>$skus];
        $json=$this->tiktok('POST','/product/202309/products',[],$body,$tiktok);$data=$json['data']??[];
        return ['ok'=>true,'source_item_id'=>$itemId,'product_id'=>(string)($data['product_id']??''),'status'=>'draft','title'=>$body['title'],'message'=>'Draf TikTok berhasil dibuat dan menunggu pemeriksaan.'];
    }

    public function activate(string $productId): array
    {
        $productId=trim($productId);if($productId==='')throw new InvalidArgumentException('ID produk TikTok wajib diisi.');$auth=$this->oauth->credentials('tiktok');
        $this->tiktok('POST','/product/202309/products/activate',[],['product_ids'=>[$productId],'listing_platforms'=>['TIKTOK_SHOP']],$auth);
        $detail=$this->tiktok('GET','/product/202309/products/'.rawurlencode($productId),[],null,$auth)['data']??[];$product=$detail['product']??$detail;
        return ['ok'=>true,'product_id'=>$productId,'status'=>(string)($product['status']??$product['product_status']??'PENDING'),'message'=>'Produk dikirim untuk ditayangkan dan sedang mengikuti pemeriksaan TikTok.'];
    }

    public function imageSyncCandidates(int $itemId): array
    {
        if ($itemId < 1) throw new InvalidArgumentException('ID produk Shopee tidak valid.');
        $item=$this->shopeeItem($itemId,$this->oauth->credentials('shopee'));$title=trim((string)($item['item_name']??''));$needle=$this->normalizedTitle($title);
        $auth=$this->oauth->credentials('tiktok');$token='';$candidates=[];$scanned=0;
        do {
            $query=['page_size'=>100];if($token!=='')$query['page_token']=$token;
            $json=$this->tiktok('POST','/product/202309/products/search',$query,[],$auth);
            foreach(($json['data']['products']??[]) as $row){$scanned++;$candidateTitle=trim((string)($row['title']??$row['name']??''));$normalized=$this->normalizedTitle($candidateTitle);similar_text($needle,$normalized,$score);if($normalized!==$needle&&$score<80)continue;$id=(string)($row['id']??'');if($id==='')continue;$detail=$this->tiktok('GET','/product/202309/products/'.rawurlencode($id),[],null,$auth)['data']??[];$product=$detail['product']??$detail;$candidates[]=['product_id'=>$id,'title'=>$candidateTitle,'status'=>(string)($product['status']??$product['product_status']??''),'score'=>round($score,1),'exact_title'=>$normalized===$needle,'image_count'=>count($product['main_images']??[])];}
            $token=(string)($json['data']['next_page_token']??'');
        } while($token!==''&&$scanned<10000);
        usort($candidates,fn(array $a,array $b): int=>($b['exact_title']<=>$a['exact_title'])?:($b['score']<=>$a['score']));
        return ['source_item_id'=>$itemId,'source_title'=>$title,'source_image_count'=>count($this->imageUrls($item)),'scanned'=>$scanned,'candidates'=>$candidates];
    }

    public function syncImages(int $itemId,string $productId): array
    {
        if($itemId<1||!preg_match('/^\d+$/',$productId))throw new InvalidArgumentException('ID produk Shopee atau TikTok tidak valid.');
        $item=$this->shopeeItem($itemId,$this->oauth->credentials('shopee'));$auth=$this->oauth->credentials('tiktok');$detail=$this->tiktok('GET','/product/202309/products/'.rawurlencode($productId),[],null,$auth)['data']??[];$product=$detail['product']??$detail;
        $shopeeTitle=trim((string)($item['item_name']??''));$tiktokTitle=trim((string)($product['title']??$product['name']??''));similar_text($this->normalizedTitle($shopeeTitle),$this->normalizedTitle($tiktokTitle),$titleScore);if($titleScore<95)throw new RuntimeException('Judul produk Shopee dan TikTok tidak cukup mirip; pembaruan gambar dibatalkan.');
        $images=[];foreach(array_slice($this->imageUrls($item),0,9) as $url)$images[]=['uri'=>$this->uploadImage($url,$auth)];if(!$images)throw new RuntimeException('Produk Shopee tidak memiliki gambar yang dapat diunggah ke TikTok.');
        $this->tiktok('POST','/product/202509/products/'.rawurlencode($productId).'/partial_edit',[],['save_mode'=>'LISTING','main_images'=>$images],$auth);
        $afterData=$this->tiktok('GET','/product/202309/products/'.rawurlencode($productId),[],null,$auth)['data']??[];$after=$afterData['product']??$afterData;$actual=array_values(array_filter(array_map(fn($row)=>(string)($row['uri']??''),$after['main_images']??[])));$expected=array_column($images,'uri');if($actual!==$expected)throw new RuntimeException('TikTok menerima permintaan, tetapi URI gambar hasil verifikasi belum sama.');
        return ['ok'=>true,'source_item_id'=>$itemId,'product_id'=>$productId,'title'=>$tiktokTitle,'image_count'=>count($images),'status'=>(string)($after['status']??$after['product_status']??''),'message'=>'Gambar produk TikTok berhasil disinkronkan dari Shopee.'];
    }

    private function stickerTemplate(array $auth): array
    {
        $list=$this->tiktok('POST','/product/202309/products/search',['page_size'=>100],[],$auth);foreach(($list['data']['products']??[]) as $row){$title=mb_strtolower((string)($row['title']??$row['name']??''));if(!str_contains($title,'stiker')&&!str_contains($title,'sticker'))continue;$id=(string)($row['id']??'');if($id==='')continue;$response=$this->tiktok('GET','/product/202309/products/'.rawurlencode($id),[],null,$auth);$product=$response['data']['product']??$response['data']??[];$chains=$product['category_chains']??[];$leaf=is_array($chains)&&$chains?end($chains):[];$category=(string)($product['category_id']??$product['category']['id']??$leaf['id']??$leaf['category_id']??'');if($category!==''){$product['category_id']=$category;return$product;}}
        throw new RuntimeException('Tidak ditemukan produk stiker TikTok yang dapat dipakai sebagai pembanding kategori.');
    }
    private function categoryV2(array $auth,string $title,string $description,array $images): string{$recommended=[];foreach(['TIKTOK_SHOP','TOKOPEDIA'] as $platform){$data=$this->tiktok('POST','/product/202309/categories/recommend',[],['category_version'=>'v2','listing_platform'=>$platform,'product_title'=>$title,'description'=>$description,'images'=>$images],$auth)['data']??[];$id=(string)($data['leaf_category_id']??$data['category_id']??'');if($id==='')foreach(($data['categories']??[]) as $category)if(!empty($category['is_leaf'])&&!empty($category['id'])){$id=(string)$category['id'];break;}if($id==='')throw new RuntimeException('TikTok tidak memberi kategori V2 untuk platform '.$platform.'.');$recommended[$platform]=$id;}if($recommended['TIKTOK_SHOP']!==$recommended['TOKOPEDIA'])throw new RuntimeException('Kategori rekomendasi TikTok Shop dan Tokopedia berbeda; pilih pemetaan kategori bersama sebelum membuat draf.');return$recommended['TIKTOK_SHOP'];}
    private function shopeeItem(int $id,array $auth): array{$json=$this->shopee('GET','/api/v2/product/get_item_base_info',['item_id_list'=>(string)$id],null,$auth);$item=$json['response']['item_list'][0]??[];if(!$item)throw new RuntimeException('Produk Shopee tidak ditemukan di toko yang terhubung.');return$item;}
    private function shopeeModels(int $id,array $auth): array{$json=$this->shopee('GET','/api/v2/product/get_model_list',['item_id'=>$id],null,$auth);return $json['response']['model']??[];}
    private function imageUrls(array $item): array{return array_values(array_filter(array_map('strval',$item['image']['image_url_list']??$item['image']['image_url']??[])));}
    private function normalizedTitle(string $title): string{$title=mb_strtolower($title);$title=preg_replace('/[^\pL\pN]+/u',' ',trim($title))??'';return preg_replace('/\s+/u',' ',$title)??$title;}
    private function title(string $title): string{$title=preg_replace('/\s+/u',' ',trim($title))??'';if(mb_strlen($title)<25)$title.=' - Paperbell';return mb_substr($title,0,255);}
    private function description(string $text): string{$text=trim(strip_tags($text));return '<p>'.nl2br(htmlspecialchars(mb_substr($text,0,9500),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>';}
    private function price(mixed $value): ?int{return is_numeric($value)&&(float)$value>0?(int)round((float)$value):null;}
    private function weight(array $item,array $model): array{$gram=(float)($model['weight']??$item['weight']??0);if($gram<=0)$gram=100;return['unit'=>'KILOGRAM','value'=>(string)max(0.001,$gram/1000)];}
    private function dimensions(array $item,array $model): array{$d=$model['dimension']??$item['dimension']??[];return['unit'=>'CENTIMETER','length'=>(string)max(1,(int)($d['package_length']??$d['length']??1)),'width'=>(string)max(1,(int)($d['package_width']??$d['width']??1)),'height'=>(string)max(1,(int)($d['package_height']??$d['height']??1))];}
    private function inventory(array $current,int $stock): array{$warehouse=(string)($current[0]['warehouse_id']??'');if($warehouse==='')throw new RuntimeException('Gudang TikTok belum tersedia pada produk stiker pembanding.');return[['warehouse_id'=>$warehouse,'quantity'=>$stock]];}
    private function uploadImage(string $url,array $auth): string{$tmp=tempnam(sys_get_temp_dir(),'paperbell_tiktok_');if($tmp===false)throw new RuntimeException('File sementara gambar tidak dapat dibuat.');try{$raw=$this->rawGet($url);if(file_put_contents($tmp,$raw)===false)throw new RuntimeException('Gambar Shopee tidak dapat disimpan.');$json=$this->tiktokMultipart('/product/202309/images/upload',['data'=>new CURLFile($tmp,'image/jpeg','product.jpg'),'use_case'=>'MAIN_IMAGE'],$auth);$uri=(string)($json['data']['uri']??'');if($uri==='')throw new RuntimeException('TikTok tidak mengembalikan URI gambar.');return$uri;}finally{@unlink($tmp);}}
    private function shopee(string $method,string $path,array $extra,?array $body,array $auth): array{$cfg=$auth['config'];$partner=(string)$cfg['partner_id'];$token=(string)$auth['access_token'];$shop=(string)$auth['account_id'];$ts=time();$q=array_merge(['partner_id'=>$partner,'timestamp'=>$ts,'sign'=>hash_hmac('sha256',$partner.$path.$ts.$token.$shop,(string)$cfg['partner_key']),'access_token'=>$token,'shop_id'=>$shop],$extra);$json=$this->json($method,rtrim((string)$cfg['api_host'],'/').$path.'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986),$body===null?[]:['Content-Type: application/json'],$body);if(!empty($json['error'])&&$json['error']!=='0')throw new RuntimeException('Shopee API: '.($json['message']??$json['error']));return$json;}
    private function tiktok(string $method,string $path,array $query,?array $body,array $auth): array{$cfg=$auth['config'];$query=['app_key'=>(string)$cfg['app_key'],'shop_cipher'=>(string)$cfg['shop_cipher'],'timestamp'=>(string)time()]+$query;ksort($query,SORT_STRING);$payload=$body===null?'':json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$source=$path;foreach($query as $k=>$v)$source.=$k.$v;$source.=$payload;$secret=(string)$cfg['app_secret'];$query['sign']=hash_hmac('sha256',$secret.$source.$secret,$secret);return $this->tiktokResponse($this->json($method,rtrim((string)$cfg['api_base'],'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),['Content-Type: application/json','x-tts-access-token: '.$auth['access_token']],$body));}
    private function tiktokMultipart(string $path,array $fields,array $auth): array{$cfg=$auth['config'];$q=['app_key'=>(string)$cfg['app_key'],'timestamp'=>(string)time()];ksort($q);$source=$path;foreach($q as $k=>$v)$source.=$k.$v;$secret=(string)$cfg['app_secret'];$q['sign']=hash_hmac('sha256',$secret.$source.$secret,$secret);$ch=curl_init(rtrim((string)$cfg['api_base'],'/').$path.'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_HTTPHEADER=>['x-tts-access-token: '.$auth['access_token']]]);$raw=curl_exec($ch);$err=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('Upload gambar TikTok gagal: '.$err);return $this->tiktokResponse(json_decode($raw,true)?:[]);}
    private function tiktokResponse(array $json): array{if((int)($json['code']??-1)!==0)throw new RuntimeException('TikTok API '.($json['code']??'?').': '.($json['message']??'unknown error'));return$json;}
    private function json(string $method,string $url,array $headers,?array $body): array{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$raw=curl_exec($ch);$err=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('Koneksi marketplace gagal: '.$err);$json=json_decode($raw,true);if(!is_array($json))throw new RuntimeException('Respons marketplace bukan JSON valid.');return$json;}
    private function rawGet(string $url): string{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false]);$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($raw===false||$code<200||$code>=300)throw new RuntimeException('Gambar Shopee tidak dapat diunduh: '.$err);return$raw;}
}
