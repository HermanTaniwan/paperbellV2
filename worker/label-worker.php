<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli')exit(1);

$root=dirname(__DIR__);
$config=require $root.'/config.php';
date_default_timezone_set($config['app']['timezone']);
require $root.'/src/Database.php';
require $root.'/src/OAuthVault.php';
require $root.'/src/MarketplaceOAuthService.php';
require $root.'/src/LabelPdfPreparer.php';
require $root.'/src/MarketplaceLabelService.php';
$once=in_array('--once',$argv,true);

function labelLog(string $message):void
{
    global $root;
    file_put_contents($root.'/storage/label-worker.log','['.date('c')."] {$message}\n",FILE_APPEND|LOCK_EX);
}

function labelDatabase():PDO
{
    global $config;
    $lastError='';
    while(true){
        try{return Database::mysql($config['mysql']);}
        catch(Throwable $e){if($e->getMessage()!==$lastError){labelLog('Database belum tersedia, mencoba lagi: '.$e->getMessage());$lastError=$e->getMessage();}Database::resetMysql();sleep(2);}
    }
}

function labelService(PDO $db):MarketplaceLabelService
{
    global $config,$root;
    $oauth=new MarketplaceOAuthService($db,new OAuthVault($config['oauth']['key_file']),$config['oauth']);
    $preparer=new LabelPdfPreparer($config['printing'],$root);
    return new MarketplaceLabelService($db,$oauth,$root.'/storage/labels',$preparer,(string)$config['printing']['default_label_printer']);
}

$db=labelDatabase();
$db->exec("CREATE TABLE IF NOT EXISTS label_fetch_jobs(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_sn VARCHAR(100) NOT NULL,provider VARCHAR(30) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',message TEXT NOT NULL,error TEXT NOT NULL,created_by VARCHAR(100) NOT NULL DEFAULT '',created_at BIGINT NOT NULL,available_at BIGINT NOT NULL,started_at BIGINT NULL,completed_at BIGINT NULL,attempts INT NOT NULL DEFAULT 0,UNIQUE KEY uq_label_fetch_order(order_sn),INDEX ix_label_fetch_queue(status,available_at,id)) ENGINE=InnoDB");
$recover=$db->prepare("UPDATE label_fetch_jobs SET status='queued',message='Mengulang job worker yang terputus',started_at=NULL,available_at=? WHERE status='processing' AND started_at<?");
$recover->execute([time(),time()-600]);
$labels=labelService($db);

do{
    $job=null;
    try{
        $heartbeat=$db->prepare("INSERT INTO app_meta(meta_key,meta_value) VALUES('label_worker_heartbeat',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $heartbeat->execute([(string)time()]);
        $recover=$db->prepare("UPDATE label_fetch_jobs SET status='queued',message='Mengulang job worker yang terputus',started_at=NULL,available_at=? WHERE status='processing' AND started_at<?");
        $recover->execute([time(),time()-600]);
        $db->beginTransaction();
        $stmt=$db->prepare("SELECT * FROM label_fetch_jobs WHERE status='queued' AND available_at<=? ORDER BY id LIMIT 1 FOR UPDATE");
        $stmt->execute([time()]);$job=$stmt->fetch();
        if(!$job){$db->commit();if($once)break;usleep(500000);continue;}
        $claim=$db->prepare("UPDATE label_fetch_jobs SET status='processing',message='Mengambil resi dari marketplace',started_at=?,attempts=attempts+1 WHERE id=? AND status='queued'");
        $claim->execute([time(),$job['id']]);$db->commit();

        $result=$labels->fetch((string)$job['order_sn']);
        $prepared=(bool)($result['prepared_label']??false);$preparationError=trim((string)($result['preparation_error']??''));
        $message=$prepared?'PDF resi dan file siap cetak berhasil dibuat':'PDF resi berhasil diambil; file siap cetak akan dibuat saat diperlukan';
        $done=$db->prepare("UPDATE label_fetch_jobs SET status='completed',message=?,error=?,completed_at=? WHERE id=? AND status='processing'");
        $done->execute([$message,$prepared?'':mb_substr($preparationError,0,1500),time(),$job['id']]);
        labelLog('Resi '.$job['order_sn'].' tersimpan ('.(int)($result['bytes']??0).' bytes, persiapan '.(int)($result['preparation_ms']??0).'ms'.($prepared?', siap cetak':', fallback saat cetak').')');
    }catch(Throwable $e){
        try{if($db->inTransaction())$db->rollBack();}catch(Throwable){}
        if($e instanceof PDOException){Database::resetMysql();$db=labelDatabase();$labels=labelService($db);}
        if(is_array($job)&&isset($job['id'])){
            try{
                $attempt=(int)$job['attempts']+1;
                if($attempt<5){$delays=[60,180,600,1800];$retryAt=time()+$delays[min($attempt-1,count($delays)-1)];$retry=$db->prepare("UPDATE label_fetch_jobs SET status='queued',message='Resi belum siap, akan dicoba lagi',error=?,available_at=?,started_at=NULL WHERE id=?");$retry->execute([mb_substr($e->getMessage(),0,1500),$retryAt,$job['id']]);}
                else{$fail=$db->prepare("UPDATE label_fetch_jobs SET status='failed',message='Pengambilan resi gagal',error=?,completed_at=? WHERE id=?");$fail->execute([mb_substr($e->getMessage(),0,1500),time(),$job['id']]);}
            }catch(Throwable $updateError){labelLog('Status job gagal disimpan: '.$updateError->getMessage());}
        }
        labelLog('ERROR '.(string)($job['order_sn']??'-').': '.$e->getMessage());
    }
    if($once)break;
}while(true);
