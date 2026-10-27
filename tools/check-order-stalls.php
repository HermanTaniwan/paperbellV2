<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__);foreach(['Database','OAuthVault','MarketplaceOAuthService','OrderStallAlertService','MarketplaceOrderStatusReader'] as $class)require_once $root.'/src/'.$class.'.php';
date_default_timezone_set('Asia/Jakarta');
$lock=null;$db=null;
try{
    $config=require $root.'/config.php';$db=Database::mysql($config['mysql']);
    if((int)$db->query("SELECT GET_LOCK('paperbell_order_stalls',0)")->fetchColumn()!==1){echo "Another order-stall check is running.\n";exit;}
    $lock=true;$stmt=$db->query("SELECT meta_value FROM app_meta WHERE meta_key='store_holidays'");$dates=json_decode((string)($stmt->fetchColumn()?:'[]'),true);$holidays=array_fill_keys(is_array($dates)?$dates:[],true);
    $service=new OrderStallAlertService($db);
    $reader=static function(array $sns)use($db,$config): array{
        if(!$sns)return [];
        $oauth=new MarketplaceOAuthService($db,new OAuthVault($config['oauth']['key_file']),$config['oauth']);
        return (new MarketplaceOrderStatusReader($oauth))->read($sns);
    };
    echo json_encode($service->scan($reader,time(),$holidays),JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Order-stall check failed ('.get_class($e).'); previous snapshot preserved.'.PHP_EOL);exit(1);}
finally{if($lock&&$db)$db->query("SELECT RELEASE_LOCK('paperbell_order_stalls')");}
