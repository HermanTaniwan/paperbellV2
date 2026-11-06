<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/OAuthVault.php';
require dirname(__DIR__).'/src/InstagramReelsService.php';
$dir=dirname(__DIR__).'/.tmp/reels-test-'.bin2hex(random_bytes(6));
$calls=[];$failPublish=false;
$transport=static function(string $method,string $path,array $data) use (&$calls,&$failPublish): array {
    $calls[]=[$method,$path,$data];
    if (isset($data['fields']) && $data['fields']==='id,username') return ['id'=>'123','username'=>'test'];
    if (str_ends_with($path,'/media_publish')) {if ($failPublish) throw new RuntimeException('timeout');return ['id'=>'posted-456'];}
    if (str_ends_with($path,'/media')) return ['id'=>'container-789'];
    return ['status_code'=>'FINISHED'];
};
function check(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
function rejects(callable $fn): void {try {$fn();} catch (InvalidArgumentException $e) {return;}throw new RuntimeException('Expected validation rejection.');}
try {
    $s=new InstagramReelsService($dir,$transport);
    check(!$s->overview()['account']['connected'],'Starts disconnected');
    $draft=['title'=>'Planner baru','caption'=>'Caption','video_url'=>'https://cdn.example.com/video.mp4'];
    $schedule=(new DateTimeImmutable('+1 hour',new DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i');
    rejects(fn()=>$s->save($draft+['status'=>'scheduled','schedule'=>$schedule]));
    rejects(fn()=>$s->save(['title'=>'']));
    check(!InstagramReelsService::publicVideoUrl('https://127.0.0.1/a.mp4'),'Reject private URL');
    check(!InstagramReelsService::publicVideoUrl('https://user:pass@cdn.example.com/a.mp4'),'Reject URL credentials');
    $s->connect(['user_id'=>'123','token'=>'secret-test-token']);
    $db=new PDO('sqlite:'.$dir.'/reels.sqlite');
    check(!str_contains((string)$db->query('SELECT encrypted FROM settings')->fetchColumn(),'secret-test-token'),'Credentials encrypted');
    check(!str_contains(json_encode($s->overview()),'secret-test-token'),'Token absent from API response');
    rejects(fn()=>$s->save($draft+['status'=>'scheduled','schedule'=>'2020-01-01T10:00']));
    $id=$s->save($draft+['status'=>'scheduled','schedule'=>$schedule]);
    $row=$s->overview()['items'][0];
    check((int)$row['scheduled_at']===(new DateTimeImmutable($schedule,new DateTimeZone('Asia/Jakarta')))->getTimestamp(),'WIB timestamp');
    $before=count($calls);$s->tick();check(count($calls)===$before,'Future schedule untouched');
    $db->exec('UPDATE reels SET scheduled_at=1');$s->tick();check($s->overview()['items'][0]['status']==='processing','Container saved before publish');
    rejects(fn()=>$s->delete($id));
    $s->tick();check($s->overview()['items'][0]['status']==='published','Publication succeeds');
    $before=count($calls);$s->tick();check(count($calls)===$before,'No duplicate publication');
    $id2=$s->save($draft+['status'=>'scheduled','schedule'=>$schedule]);$db->exec("UPDATE reels SET scheduled_at=1 WHERE id=$id2");
    $s->tick();$failPublish=true;$s->tick();
    $row=$db->query("SELECT * FROM reels WHERE id=$id2")->fetch(PDO::FETCH_ASSOC);check($row['status']==='review','Uncertain publish requires review');
    $before=count($calls);$s->tick();check(count($calls)===$before,'Uncertain publish never retries');
    rejects(fn()=>$s->connect(['user_id'=>'999','token'=>'other-token']));
    $id3=$s->save($draft+['status'=>'scheduled','schedule'=>$schedule]);$db->exec("UPDATE reels SET status='publishing' WHERE id=$id3");$s->tick();
    check($db->query("SELECT status FROM reels WHERE id=$id3")->fetchColumn()==='review','Interrupted worker requires review');
    $id4=$s->save($draft+['status'=>'scheduled','schedule'=>$schedule]);$s->disconnect();
    check(!$s->overview()['account']['connected'],'Disconnected');
    check($db->query("SELECT status FROM reels WHERE id=$id4")->fetchColumn()==='draft','Disconnect cancels pending schedules');
    $before=count($calls);$s->tick();check(count($calls)===$before,'No requests while disconnected');
    echo "InstagramReelsService: all checks passed.\n";
} finally {
    unset($s,$db);
    // Remove only files created by this test, never runtime data.
    foreach (glob($dir.'/*')?:[] as $file) unlink($file);
    if (is_dir($dir)) rmdir($dir);
}
