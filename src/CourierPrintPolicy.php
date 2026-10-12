<?php
declare(strict_types=1);

final class CourierPrintPolicy
{
    private const KEYS = ['shipping_carrier','shipping_provider_name','shipping_provider','courier_name','courier','logistics_service_name','logistics_service','delivery_service_name','delivery_service'];

    public static function names(string $rawJson): array
    {
        $raw=json_decode($rawJson,true);
        if(!is_array($raw))return [];
        $names=[];
        $visit=function(array $value)use(&$visit,&$names):void{
            foreach(self::KEYS as $key)if(isset($value[$key])&&(is_string($value[$key])||is_numeric($value[$key]))&&trim((string)$value[$key])!=='')$names[]=trim((string)$value[$key]);
            foreach($value as $child)if(is_array($child))$visit($child);
        };
        $visit($raw);
        return array_values(array_unique($names));
    }

    public static function warning(string $rawJson): string
    {
        foreach(self::names($rawJson) as $name){
            $normalized=preg_replace('/[^a-z0-9]/','',strtolower($name));
            $brand=str_contains($normalized,'jnt')||str_contains($normalized,'jt')?'J&T':(str_contains($normalized,'spx')||str_contains($normalized,'shopeeexpress')||str_contains($normalized,'shopeexpress')?'SPX':'');
            if($brand!=='')return 'Kurir '.$brand.' terdeteksi. Cetak item dan resi diblokir.';
        }
        return '';
    }

    public static function assertOrderAllowed(PDO $db,string $orderSn): void
    {
        $stmt=$db->prepare('SELECT raw_json FROM orders WHERE order_sn=?');
        $stmt->execute([$orderSn]);
        $warning=self::warning((string)($stmt->fetchColumn()?:''));
        if($warning!=='')throw new RuntimeException($warning);
    }

    public static function assertJobAllowed(PDO $db,array $job): void
    {
        if(in_array($job['job_type']??'',['product','label'],true)&&trim((string)($job['order_sn']??''))!=='')self::assertOrderAllowed($db,(string)$job['order_sn']);
    }
}
