<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PrinterUsageService.php';
require_once __DIR__.'/../src/PrintQueueService.php';
if (getenv('PAPERBELL_TEST_MYSQL')!=='1') {echo "Skipped: set PAPERBELL_TEST_MYSQL=1 for disposable MySQL integration tests.\n";exit;}
$config=require __DIR__.'/../config.php';$c=$config['mysql'];
$db=new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$c['host'],$c['port']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='paperbell_usage_test_'.bin2hex(random_bytes(6));
$fixture=tempnam(sys_get_temp_dir(),'paperbell-usage-');
function verifyUsage(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
try {
    $db->exec("CREATE DATABASE `$name`");$db->exec("USE `$name`");
    $db->exec('CREATE TABLE app_meta(meta_key VARCHAR(100) PRIMARY KEY,meta_value LONGTEXT) ENGINE=InnoDB');
    $db->exec("CREATE TABLE print_jobs(id BIGINT AUTO_INCREMENT PRIMARY KEY,printer VARCHAR(255),status VARCHAR(30),attempts INT DEFAULT 1,print_settings TEXT,copies INT DEFAULT 1,file_path TEXT,started_at BIGINT NULL,submitted_at BIGINT NULL,spooler_job_id INT NULL,job_type VARCHAR(20) DEFAULT 'product',message TEXT,completed_at BIGINT NULL) ENGINE=InnoDB");
    $db->exec("INSERT INTO print_jobs(printer,status) VALUES('EPSON WF-C5390','completed')");
    $service=new PrinterUsageService($db);
    verifyUsage($service->summary()['points']===0,'Initial baseline excludes past jobs');
    $code='from pypdf import PdfWriter; import sys; w=PdfWriter(); w.add_blank_page(width=182*72/25.4,height=257*72/25.4); w.add_blank_page(width=182*72/25.4,height=257*72/25.4); w.write(sys.argv[1])';
    $proc=proc_open([$config['printing']['python'],'-c',$code,$fixture],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);verifyUsage(proc_close($proc)===0,'PDF fixture: '.$err);
    $job=['id'=>2,'printer'=>'EPSON WF-C5390','status'=>'completed','attempts'=>1,'print_settings'=>'1-,duplexlong,paperkind=13,3x','copies'=>3,'file_path'=>$fixture];
    $insert=$db->prepare('INSERT INTO print_jobs(id,printer,status,attempts,print_settings,copies,file_path) VALUES(?,?,?,?,?,?,?)');$insert->execute(array_values($job));
    $service->locked(fn()=>$service->recordCompleted($job));
    $service->locked(fn()=>$service->recordCompleted($job));
    $s=$service->summary(true);verifyUsage($s['b5']===6&&$s['points']===12&&count($s['events'])===1,'Duplex copies and exactly-once counting');
    $service->change(['operation'=>'limit','point_limit'=>24],'test');verifyUsage($service->summary()['needs_check'],'50 percent reminder');
    $db->exec('DELETE FROM print_jobs');verifyUsage($service->summary()['points']===12,'Queue cleanup preserves ledger');
    $service->change(['operation'=>'adjust','a5'=>5,'b5'=>-1,'note'=>'External print and failed side'],'test');verifyUsage($service->summary()['points']===15,'Manual correction');
    try{$service->change(['operation'=>'adjust','a5'=>-99,'b5'=>0,'note'=>'Invalid'],'test');throw new LogicException('Negative total accepted');}catch(InvalidArgumentException){}
    $db->exec("INSERT INTO print_jobs(printer,status) VALUES('EPSON WF-C5390','submitted')");
    try{$service->change(['operation'=>'refill'],'test');throw new LogicException('Active refill accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;verifyUsage(str_contains($e->getMessage(),'antrean'),'Active job refusal');}
    $db->exec('DELETE FROM print_jobs');$service->change(['operation'=>'refill'],'test');
    $s=$service->summary(true);verifyUsage($s['points']===0&&$s['point_limit']===24&&count($s['history'])===1&&$s['history'][0]['points']===15,'Refill archives total and carries limit');
    $job['id']=4;$job['printer']='EPSON WF-C5790';$service->locked(fn()=>$service->recordCompleted($job));verifyUsage($service->summary()['points']===0,'5790 ignored');
    $job['printer']='EPSON WF-C5390';$job['file_path']=$fixture.'.missing';$service->locked(fn()=>$service->recordCompleted($job));verifyUsage($service->summary()['unresolved_jobs']===1,'Unreadable PDF flagged, no fabricated count');
    $event=$service->summary(true)['events'][0];
    $service->change(['operation'=>'adjust','a5'=>2,'b5'=>0,'note'=>'Unreadable PDF checked manually'],'test');
    $service->change(['operation'=>'resolve','event_id'=>$event['id']],'test');verifyUsage($service->summary()['unresolved_jobs']===0,'Manual review clears warning');
    $job['id']=5;$job['file_path']=$fixture;$job['status']='submitted';$job['print_settings']='1-,duplexlong,paper=A5';$job['copies']=2;
    $insert->execute(array_values($job));
    $db->exec('UPDATE print_jobs SET submitted_at=1,spooler_job_id=99 WHERE id=5');
    $job['id']=6;$job['status']='failed';$insert->execute(array_values($job));
    $job['id']=7;$job['status']='cancelled';$insert->execute(array_values($job));
    $queue=(new ReflectionClass(PrintQueueService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(PrintQueueService::class,'db'))->setValue($queue,$db);
    (new ReflectionProperty(PrintQueueService::class,'usage'))->setValue($queue,$service);
    $reconcile=new ReflectionMethod(PrintQueueService::class,'reconcileSubmittedJobs');
    $reconcile->invoke($queue,['available'=>true,'jobs'=>[]]);
    $reconcile->invoke($queue,['available'=>true,'jobs'=>[]]);
    verifyUsage($service->summary()['points']===6,'Completion hook adds four A5 sides once, excluding failed and cancelled jobs');
    verifyUsage($db->query('SELECT status FROM print_jobs WHERE id=5')->fetchColumn()==='completed','Completion and ledger committed together');
    $queue->clearCompleted();verifyUsage($service->summary()['points']===6,'Actual queue cleanup retains usage');
    echo "PASS: real PDF, durable ledger, idempotency, corrections, refill history, reminder and excluded printer in disposable MySQL database.\n";
} finally {
    if($db->inTransaction())$db->rollBack();
    $db->exec("DROP DATABASE IF EXISTS `$name`");
    if(is_file($fixture))unlink($fixture);
}
