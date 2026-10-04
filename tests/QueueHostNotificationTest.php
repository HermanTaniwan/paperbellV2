<?php
declare(strict_types=1);

require_once __DIR__.'/../src/PrintQueueService.php';
if(PHP_OS_FAMILY!=='Windows'){echo "Windows notification test skipped\n";exit;}

// A sleeping child reproduces balloon lifetime without displaying a real alert.
$path=tempnam(sys_get_temp_dir(),'paperbell-notification-').'.ps1';
$marker=$path.'.started';
file_put_contents($path,"Set-Content -LiteralPath '".str_replace("'","''",$marker)."' -Value 'started'\nStart-Sleep -Seconds 4\n");
try{
    $service=(new ReflectionClass(PrintQueueService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(PrintQueueService::class,'notificationScript'))->setValue($service,$path);
    $start=microtime(true);
    $result=(new ReflectionMethod(PrintQueueService::class,'notifyWindows'))->invoke($service,'Test','Test');
    $elapsed=microtime(true)-$start;
    if(!$result||$elapsed>=4)throw new RuntimeException('Queue request waited for notification lifetime: '.$elapsed);
    echo "Detached notification passed (".round($elapsed,2)." seconds)\n";
    $deadline=microtime(true)+20;
    while(!is_file($marker)&&microtime(true)<$deadline)usleep(100000);
    if(!is_file($marker))throw new RuntimeException('Detached notification did not start.');
}finally{
    // Allow the detached test process to finish before removing its source.
    sleep(5);
    unlink($path);
    unlink(substr($path,0,-4));
    if(is_file($marker))unlink($marker);
}
