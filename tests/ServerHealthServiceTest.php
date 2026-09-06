<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/ServerHealthService.php';

$root=sys_get_temp_dir().'/paperbell-server-health-test-'.bin2hex(random_bytes(4));
$cacheDirectory=$root.'/storage/cache';
if(!mkdir($cacheDirectory,0775,true)&&!is_dir($cacheDirectory))throw new RuntimeException('Test cache directory could not be created.');

$checkedAt=time()-10;
$fixture=[
    'checked_at'=>$checkedAt,
    'cpu_percent'=>25.5,
    'memory_usage_percent'=>40.0,
    'disks'=>[['letter'=>'C:','usage_percent'=>50.0]],
    'physical_disks'=>[],
];
file_put_contents($cacheDirectory.'/server-health.json',json_encode($fixture,JSON_THROW_ON_ERROR));

$service=new ServerHealthService([
    'cache_seconds'=>60,
    'thresholds'=>[
        'offline_after_seconds'=>300,
        'cpu'=>['warning'=>80,'critical'=>95],
        'memory'=>['warning'=>80,'critical'=>90],
        'disk'=>['warning'=>80,'critical'=>90],
    ],
],$root);
$result=$service->overview();

if($result['status']!=='healthy')throw new RuntimeException('Fresh cached metrics should be healthy.');
if($result['cpu_percent']!==25.5)throw new RuntimeException('Cached CPU metric changed unexpectedly.');
if($result['age_seconds']<10||$result['age_seconds']>12)throw new RuntimeException('Cache age was not calculated correctly.');

$emptyRoot=sys_get_temp_dir().'/paperbell-server-health-empty-'.bin2hex(random_bytes(4));
$empty=(new ServerHealthService(['thresholds'=>['offline_after_seconds'=>300]],$emptyRoot))->overview();
if(PHP_OS_FAMILY==='Linux'){
    if(!is_numeric($empty['cpu_percent']))throw new RuntimeException('Linux CPU usage was not collected.');
    if(($empty['memory_total_bytes']??0)<=0)throw new RuntimeException('Linux memory was not collected.');
    if(($empty['uptime_seconds']??0)<=0)throw new RuntimeException('Linux uptime was not collected.');
    if(($empty['hostname']??'')==='')throw new RuntimeException('Linux hostname was not collected.');
}elseif(PHP_OS_FAMILY!=='Windows'){
    if($empty['status']!=='offline')throw new RuntimeException('Missing metrics should be reported offline.');
    if(($empty['collector_error']??'')==='')throw new RuntimeException('Missing metrics should explain why data is unavailable.');
}

echo "Server health service tests passed\n";
