<?php
declare(strict_types=1);

final class MarketplaceLabelService
{
    private string $trackingNumber='';

    public function __construct(private PDO $db,private MarketplaceOAuthService $oauth,private string $storageDir)
    {
        if(!is_dir($storageDir)&&!mkdir($storageDir,0770,true)&&!is_dir($storageDir))throw new RuntimeException('Folder label tidak dapat dibuat.');
    }

    public function fetch(string $orderSn): array
    {
        $orderSn=trim($orderSn);if($orderSn==='')throw new InvalidArgumentException('Order SN wajib diisi.');
        $this->trackingNumber='';
        $stmt=$this->db->prepare("SELECT o.order_sn,o.raw_json,o.status,IFNULL(r.pdf_path,'') existing_pdf FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn WHERE o.order_sn=?");$stmt->execute([$orderSn]);$order=$stmt->fetch();
        if(!$order)throw new RuntimeException('Order tidak ditemukan di MySQL.');
        $reusedExisting=false;
        try{$bytes=str_starts_with(strtoupper($orderSn),'TIKTOK:')?$this->fetchTikTok($orderSn,(string)$order['raw_json']):$this->fetchShopee($orderSn);}
        catch(Throwable $e){$existing=(string)$order['existing_pdf'];if(!str_contains(strtolower($e->getMessage()),'parcel has been shipped')||$existing===''||!is_file($existing))throw$e;$bytes=(string)file_get_contents($existing);$reusedExisting=true;}
        $this->assertPdf($bytes);
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','_',$orderSn)?:'label';$path=$this->storageDir.DIRECTORY_SEPARATOR.$safe.'_resi.pdf';$tmp=$path.'.'.bin2hex(random_bytes(5)).'.tmp';
        if(file_put_contents($tmp,$bytes,LOCK_EX)===false)throw new RuntimeException('PDF label gagal ditulis ke host.');
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('PDF label gagal dipindahkan ke penyimpanan final.');}
        $upsert=$this->db->prepare('INSERT INTO order_resi(order_sn,pdf_path,tracking_number,fetched_at,resi_printed,resi_printed_at) VALUES(?,?,?,?,0,NULL) ON DUPLICATE KEY UPDATE pdf_path=VALUES(pdf_path),tracking_number=IF(VALUES(tracking_number)=\'\',tracking_number,VALUES(tracking_number)),fetched_at=VALUES(fetched_at)');
        $upsert->execute([$orderSn,$path,$this->trackingNumber,time()]);
        return ['ok'=>true,'order_sn'=>$orderSn,'provider'=>str_starts_with(strtoupper($orderSn),'TIKTOK:')?'tiktok':'shopee','tracking_number'=>$this->trackingNumber,'bytes'=>strlen($bytes),'fetched_at'=>time(),'reused_existing'=>$reusedExisting,'message'=>$reusedExisting?'Paket sudah dikirim; PDF tersimpan digunakan kembali.':'PDF berhasil diambil dari marketplace.'];
    }

    private function fetchTikTok(string $orderSn,string $rawJson): string
    {
        $raw=json_decode($rawJson,true);if(!is_array($raw))throw new RuntimeException('Raw JSON TikTok tidak valid. Jalankan sync order TikTok ulang.');
        $this->trackingNumber=$this->findFirst($raw,['tracking_number','trackingNumber','tracking_id','trackingId','waybill_number','waybillNumber']);
        $packageId=$this->findFirst($raw,['package_id','packageId','package_number','packageNumber']);
        if($packageId==='')throw new RuntimeException('Package ID TikTok tidak ditemukan pada order. Jalankan sync order TikTok ulang.');
        $auth=$this->oauth->credentials('tiktok');$cfg=$auth['config'];$secret=(string)($cfg['app_secret']??'');$appKey=(string)($cfg['app_key']??'');$shopCipher=(string)($cfg['shop_cipher']??'');
        if($shopCipher==='')throw new RuntimeException('Shop cipher TikTok belum dikonfigurasi.');
        $path='/fulfillment/202309/packages/'.rawurlencode($packageId).'/shipping_documents';
        try{$json=$this->tiktokJson('GET',$path,['document_type'=>'SHIPPING_LABEL_AND_PACKING_SLIP','document_size'=>'A6','document_format'=>'PDF'],$appKey,$secret,$shopCipher,(string)$auth['access_token']);}
        catch(Throwable $e){$json=$this->tiktokJson('GET',$path,['document_type'=>'SHIPPING_LABEL','document_size'=>'A6','document_format'=>'PDF'],$appKey,$secret,$shopCipher,(string)$auth['access_token']);}
        $base64=$this->findFirst($json,['file_base64']);if($base64!==''){$decoded=base64_decode($base64,true);if($decoded===false)throw new RuntimeException('Base64 label TikTok tidak valid.');return $decoded;}
        $url=$this->findFirst($json,['doc_url','download_url','file_url','document_url','shipping_document_url','url']);if($url==='')throw new RuntimeException('Respons TikTok tidak berisi URL atau data PDF.');
        return $this->download($url);
    }

    private function tiktokJson(string $method,string $path,array $query,string $appKey,string $secret,string $shopCipher,string $token): array
    {
        $query['app_key']=$appKey;$query['shop_cipher']=$shopCipher;$query['timestamp']=(string)time();ksort($query,SORT_STRING);$source=$path;foreach($query as $k=>$v){if($k!=='sign'&&$k!=='access_token')$source.=$k.$v;}$query['sign']=hash_hmac('sha256',$secret.$source.$secret,$secret);
        return $this->jsonRequest($method,'https://open-api.tiktokglobalshop.com'.$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),['Content-Type: application/json','x-tts-access-token: '.$token],null,'tiktok');
    }

    private function fetchShopee(string $orderSn): string
    {
        $auth=$this->oauth->credentials('shopee');$cfg=$auth['config'];$shopId=(string)$auth['account_id'];if($shopId==='')throw new RuntimeException('Shop ID Shopee tidak tersimpan. Lakukan otorisasi ulang.');
        $detail=$this->shopeeJson('GET','/api/v2/order/get_order_detail',['order_sn_list'=>$orderSn,'response_optional_fields'=>'package_list'],null,$cfg,(string)$auth['access_token'],$shopId);
        $this->assertShopeeOk($detail);$package=$this->extractShopeePackage($detail,$orderSn);if($package==='')throw new RuntimeException('Shopee tidak memberikan package_number. Pastikan pengiriman sudah diatur.');
        $tracking=$this->fetchShopeeTrackingNumber($orderSn,$package,$cfg,(string)$auth['access_token'],$shopId);$this->trackingNumber=$tracking;$docType='THERMAL_AIR_WAYBILL';
        $item=['order_sn'=>$orderSn,'package_number'=>$package,'shipping_document_type'=>$docType];if($tracking!=='')$item['tracking_number']=$tracking;
        $this->createAndPollShopee($orderSn,$package,$docType,$item,$cfg,(string)$auth['access_token'],$shopId);
        try{return $this->downloadShopee($orderSn,$package,$docType,$cfg,(string)$auth['access_token'],$shopId);}catch(Throwable $e){if(!str_contains(strtolower($e->getMessage()),'shipping_document_should_print_first'))throw $e;$this->createAndPollShopee($orderSn,$package,$docType,$item,$cfg,(string)$auth['access_token'],$shopId);return $this->downloadShopee($orderSn,$package,$docType,$cfg,(string)$auth['access_token'],$shopId);}
    }

    private function fetchShopeeTrackingNumber(string $orderSn,string $package,array $cfg,string $token,string $shopId): string
    {
        try{
            $mass=$this->shopeeJson('POST','/api/v2/logistics/get_mass_tracking_number',[],['package_list'=>[['package_number'=>$package]],'response_optional_fields'=>'first_mile_tracking_number'],$cfg,$token,$shopId);
            if(in_array((string)($mass['error']??''),['','0'],true)){$tracking=$this->findFirst($mass,['tracking_number','first_mile_tracking_number']);if($tracking!=='')return$tracking;}
        }catch(Throwable){}
        try{
            $single=$this->shopeeJson('GET','/api/v2/logistics/get_tracking_number',['order_sn'=>$orderSn,'package_number'=>$package,'response_optional_fields'=>'first_mile_tracking_number'],null,$cfg,$token,$shopId);
            if(in_array((string)($single['error']??''),['','0'],true))return$this->findFirst($single,['tracking_number','first_mile_tracking_number']);
        }catch(Throwable){}
        return'';
    }

    private function createAndPollShopee(string $orderSn,string $package,string $docType,array $item,array $cfg,string $token,string $shopId): void
    {
        $create=$this->shopeeJson('POST','/api/v2/logistics/create_shipping_document',[],['order_list'=>[$item]],$cfg,$token,$shopId);$this->assertShopeeOk($create);
        $body=['order_list'=>[['order_sn'=>$orderSn,'package_number'=>$package,'shipping_document_type'=>$docType]]];
        for($i=0;$i<45;$i++){usleep(600000);$result=$this->shopeeJson('POST','/api/v2/logistics/get_shipping_document_result',[],$body,$cfg,$token,$shopId);$this->assertShopeeOk($result);$status=strtoupper($this->findFirst($result,['status']));if($status==='READY')return;if($status==='FAILED')throw new RuntimeException('Shopee gagal membuat shipping document: '.$this->findFirst($result,['fail_message']));}
        throw new RuntimeException('Dokumen Shopee belum siap setelah 27 detik. Coba lagi.');
    }

    private function downloadShopee(string $orderSn,string $package,string $docType,array $cfg,string $token,string $shopId): string
    {
        $path='/api/v2/logistics/download_shipping_document';$body=['order_list'=>[['order_sn'=>$orderSn,'package_number'=>$package,'shipping_document_type'=>$docType]]];$url=$this->shopeeUrl($path,[],$cfg,$token,$shopId);$raw=$this->request('POST',$url,['Content-Type: application/json'],json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if(str_starts_with($raw,'%PDF-'))return $raw;$json=json_decode($raw,true);if(!is_array($json))throw new RuntimeException('Respons download Shopee bukan PDF/JSON valid.');$this->assertShopeeOk($json);$b64=$this->findFirst($json,['file_base64']);if($b64!==''){$decoded=base64_decode($b64,true);if($decoded===false)throw new RuntimeException('Base64 PDF Shopee tidak valid.');return $decoded;}$downloadUrl=$this->findFirst($json,['url','file_url','shipping_document_url','document_url','pdf_url']);if($downloadUrl==='')throw new RuntimeException('Respons Shopee tidak berisi URL/PDF.');return $this->download($downloadUrl);
    }

    private function shopeeJson(string $method,string $path,array $query,?array $body,array $cfg,string $token,string $shopId): array
    {
        $url=$this->shopeeUrl($path,$query,$cfg,$token,$shopId);return $this->jsonRequest($method,$url,$body===null?[]:['Content-Type: application/json'],$body===null?null:json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'shopee');
    }

    private function shopeeUrl(string $path,array $query,array $cfg,string $token,string $shopId): string
    {
        $ts=time();$partner=(string)($cfg['partner_id']??'');$query=array_merge(['partner_id'=>$partner,'timestamp'=>$ts,'sign'=>hash_hmac('sha256',$partner.$path.$ts.$token.$shopId,(string)($cfg['partner_key']??'')),'access_token'=>$token,'shop_id'=>$shopId],$query);
        return rtrim((string)($cfg['api_host']??'https://partner.shopeemobile.com'),'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }

    private function jsonRequest(string $method,string $url,array $headers,?string $body,string $provider): array
    {
        $raw=$this->request($method,$url,$headers,$body);$json=json_decode($raw,true);if(!is_array($json))throw new RuntimeException('Respons '.$provider.' bukan JSON valid.');if($provider==='tiktok'&&(int)($json['code']??-1)!==0)throw new RuntimeException('TikTok API '.($json['code']??'?').': '.($json['message']??'unknown error'));return $json;
    }

    private function request(string $method,string $url,array $headers=[],?string $body=null): string
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('Koneksi marketplace gagal: '.$error);if($status<200||$status>=300){$j=json_decode($raw,true);throw new RuntimeException('Marketplace HTTP '.$status.': '.($this->findFirst(is_array($j)?$j:[],['message','error','fail_message'])?:'request rejected'));}return $raw;
    }

    private function download(string $url): string
    {
        if(!preg_match('#^https://#i',$url))throw new RuntimeException('URL download label tidak aman.');
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('Download label gagal: '.$error);if($status<200||$status>=300)throw new RuntimeException('Download label ditolak (HTTP '.$status.').');return $raw;
    }
    private function assertPdf(string $bytes): void{if(strlen($bytes)<100||!str_starts_with(ltrim($bytes),'%PDF-'))throw new RuntimeException('File label yang diterima bukan PDF valid.');}
    private function assertShopeeOk(array $json,bool $allowTrackingInvalid=false): void{$error=(string)($json['error']??'');if($error===''||$error==='0')return;$message=(string)($json['message']??$error);if($allowTrackingInvalid&&str_contains(strtolower(json_encode($json)?:''),'tracking_number_invalid'))return;$details=$this->findFirst($json['response']['result_list']??$json['result_list']??[],['fail_message','message','fail_error','error']);throw new RuntimeException('Shopee API: '.$message.($details!==''?' - '.$details:'').' ['.$error.']');}
    private function extractShopeePackage(array $json,string $orderSn): string{$response=$json['response']??[];foreach(($response['order_list']??[]) as $order){if(isset($order['order_sn'])&&(string)$order['order_sn']!==$orderSn)continue;foreach(($order['package_list']??[]) as $pkg)if(!empty($pkg['package_number']))return(string)$pkg['package_number'];}return $this->findFirst($response,['package_number']);}
    private function findFirst(mixed $value,array $keys): string{if(!is_array($value))return'';foreach($keys as $key)if(isset($value[$key])&&(is_string($value[$key])||is_numeric($value[$key]))&&trim((string)$value[$key])!=='')return trim((string)$value[$key]);foreach($value as $child){if(is_array($child)){if(($found=$this->findFirst($child,$keys))!=='')return $found;}}return'';}
}
