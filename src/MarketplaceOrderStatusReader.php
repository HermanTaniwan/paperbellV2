<?php
declare(strict_types=1);

// Read-only API calls: no label fetching, print jobs or order fulfillment.
final class MarketplaceOrderStatusReader
{
    public function __construct(private MarketplaceOAuthService $oauth) {}
    public function read(array $sns): array
    {
        $groups=['shopee'=>[],'tiktok'=>[]];foreach($sns as $sn)$groups[str_starts_with($sn,'TIKTOK:')?'tiktok':'shopee'][]=$sn;
        $found=[];
        foreach($groups as $provider=>$orders){
            if(!$orders)continue;$auth=$this->oauth->credentials($provider);$cfg=$auth['config'];$token=(string)$auth['access_token'];
            foreach(array_chunk($orders,50) as $batch){
                if($provider==='shopee'){
                    $path='/api/v2/order/get_order_detail';$ts=time();$partner=(string)$cfg['partner_id'];$shop=(string)$auth['account_id'];
                    $q=['partner_id'=>$partner,'timestamp'=>$ts,'access_token'=>$token,'shop_id'=>$shop,'sign'=>hash_hmac('sha256',$partner.$path.$ts.$token.$shop,$cfg['partner_key']),'order_sn_list'=>implode(',',$batch),'response_optional_fields'=>'order_status,create_time,update_time'];
                    $url=rtrim($cfg['api_host']??'https://partner.shopeemobile.com','/').$path.'?'.http_build_query($q);$headers=[];
                }else{
                    $path='/order/202507/orders';$secret=(string)$cfg['app_secret'];$q=['app_key'=>$cfg['app_key'],'shop_cipher'=>$cfg['shop_cipher'],'timestamp'=>(string)time(),'ids'=>implode(',',array_map(static fn($sn)=>substr($sn,7),$batch))];ksort($q,SORT_STRING);$source=$path;foreach($q as $k=>$v)$source.=$k.$v;$q['sign']=hash_hmac('sha256',$secret.$source.$secret,$secret);
                    $url='https://open-api.tiktokglobalshop.com'.$path.'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);$headers=['Content-Type: application/json','x-tts-access-token: '.$token];
                }
                $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headers]);$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
                if($raw===false||$http!==200)throw new RuntimeException($provider.' status request failed.');
                $json=json_decode($raw,true,512,JSON_BIGINT_AS_STRING|JSON_THROW_ON_ERROR);
                if(($provider==='shopee'&&!empty($json['error']))||($provider==='tiktok'&&(int)($json['code']??-1)!==0))throw new RuntimeException($provider.' rejected status request.');
                $rows=$provider==='shopee'?($json['response']['order_list']??null):($json['data']['orders']??null);if(!is_array($rows))throw new RuntimeException('Invalid status response.');
                foreach($rows as $order){$sn=$provider==='shopee'?(string)($order['order_sn']??''):'TIKTOK:'.(string)($order['id']??$order['order_id']??'');if(!in_array($sn,$batch,true))continue;$status=(string)($order['order_status']??$order['status']??'');if($status==='')throw new RuntimeException('Missing order status.');$found[$sn]=['status'=>$status,'update_time'=>(int)($order['update_time']??0)];}
                usleep(120000);
            }
        }
        return $found;
    }
}
