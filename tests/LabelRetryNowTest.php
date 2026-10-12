<?php
declare(strict_types=1);

// Exercise the actual endpoint with an isolated queue, without marketplace calls.
final class RetryResponse extends RuntimeException {
    public function __construct(public array $data, public int $status) { parent::__construct(); }
}
function body(): array { return ['order_sn'=>$GLOBALS['orderSn']]; }
function respond(array $data, int $status=200): never { throw new RetryResponse($data,$status); }
function check(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
$source=file_get_contents(__DIR__.'/../api.php');
$start=strpos($source,"    if (\$action === 'retry_label_now')");
$end=strpos($source,"    if (\$action === 'fetch_label')",$start);
$endpoint=substr($source,$start,$end-$start);
$mysql=new PDO('sqlite::memory:');$mysql->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$mysql->exec('CREATE TABLE label_fetch_jobs(order_sn TEXT PRIMARY KEY,status TEXT,attempts INTEGER,available_at INTEGER,message TEXT)');
$future=time()+1800;
$insert=$mysql->prepare("INSERT INTO label_fetch_jobs VALUES(?,?,?,?,'Original')");
foreach([['waiting','queued',2],['processing','processing',2],['initial','queued',0],['done','completed',2],['failed','failed',5]] as $row)$insert->execute([...$row,$future]);
$action='retry_label_now';
foreach(['waiting','processing','initial','done','failed','missing',''] as $orderSn){
    try{eval($endpoint);throw new RuntimeException('Endpoint did not respond');}
    catch(RetryResponse $response){
        check($response->status===($orderSn===''?422:($orderSn==='missing'?404:200)),'Unexpected response status');
    }
}
foreach($mysql->query('SELECT * FROM label_fetch_jobs')->fetchAll(PDO::FETCH_ASSOC) as $job){
    if($job['order_sn']==='waiting'){
        check((int)$job['available_at']<=$future-1700,'Waiting retry was not brought forward');
        check($job['status']==='queued'&&(int)$job['attempts']===2,'Retry changed ownership or reset attempt count');
    }else check((int)$job['available_at']===$future&&$job['message']==='Original','Non-waiting job was changed');
}
echo "Label retry endpoint tests passed\n";
