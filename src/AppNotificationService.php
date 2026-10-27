<?php
declare(strict_types=1);
require_once __DIR__.'/OrderStallAlertService.php';

final class AppNotificationService
{
    private ShippingFeeAlertService $shipping;
    private OrderStallAlertService $stalls;
    public function __construct(private PDO $db,private string $reader,private string $sampleStatePath,private string $cacheDirectory)
    {
        $this->shipping=new ShippingFeeAlertService($db,$reader);
        $this->stalls=new OrderStallAlertService($db);
        $this->db->exec("CREATE TABLE IF NOT EXISTS sample_notification_reads (
            reader_key VARCHAR(100) NOT NULL,notification_id VARCHAR(64) NOT NULL,read_at BIGINT NOT NULL,
            PRIMARY KEY(reader_key,notification_id)
        ) ENGINE=InnoDB");
    }

    private function cachePath(): string {return $this->cacheDirectory.'/'.hash('sha256',$this->reader).'.json';}
    public function invalidate(): void {if(is_file($this->cachePath()))unlink($this->cachePath());}

    public function feed(): array
    {
        // Cheap source checks avoid parsing every order's JSON on every request.
        $stamp=$this->db->query('SELECT COUNT(*) count,MAX(synced_at) latest FROM shopee_escrow_details')->fetch();
        $readStmt=$this->db->prepare('SELECT order_sn,estimated,actual FROM shipping_fee_notification_reads WHERE reader_key=? ORDER BY order_sn');
        $readStmt->execute([$this->reader]);$shippingRead=$readStmt->fetchAll();
        $readStmt=$this->db->prepare('SELECT notification_id FROM sample_notification_reads WHERE reader_key=? ORDER BY notification_id');
        $readStmt->execute([$this->reader]);$sampleRead=$readStmt->fetchAll(PDO::FETCH_COLUMN);
        $sampleRaw=is_file($this->sampleStatePath)?file_get_contents($this->sampleStatePath):'';
        if($sampleRaw===false)throw new RuntimeException('Data sampel gratis belum dapat dibaca.');
        $stalls=$this->stalls->notifications($this->reader);
        $version=hash('sha256',json_encode([$stamp,$shippingRead,$sampleRead,$stalls,date_default_timezone_get()]).$sampleRaw);
        $path=$this->cachePath();
        $cache=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        if(is_array($cache)&&($cache['version']??'')===$version&&($cache['expires']??0)>time())return $cache['data'];
        $samples=$this->samples($sampleRaw);
        $shipping=$this->shipping->listing(1,'',false,true);
        $items=array_merge($stalls['items'],$samples['items']);
        foreach($shipping['items'] as $item)$items[]=$item+['id'=>'shipping:'.$item['order_sn'],'type'=>'shipping'];
        usort($items,static function(array $a,array $b): int {
            $unread=(int)$b['unread']<=>(int)$a['unread'];
            if($unread!==0)return $unread;
            if($a['type']!==$b['type'])return ['stall'=>0,'sample'=>1,'shipping'=>2][$a['type']]<=>['stall'=>0,'sample'=>1,'shipping'=>2][$b['type']];
            if($a['type']==='stall')return $b['businessHours']<=>$a['businessHours'];
            return $a['type']==='sample'?($b['createdAt']<=>$a['createdAt']):(abs($b['difference'])<=>abs($a['difference']));
        });
        $data=['items'=>array_slice($items,0,25),'summary'=>[
            'unread'=>$shipping['summary']['unread']+$samples['unread']+$stalls['unread'],
            'total'=>$shipping['summary']['total']+count($samples['items'])+$stalls['total'],
            'stalls'=>['total'=>$stalls['total'],'unread'=>$stalls['unread']],
            'shipping'=>$shipping['summary'],'samples'=>['total'=>count($samples['items']),'unread'=>$samples['unread']],
        ],'sampleCheckedAt'=>$samples['checkedAt'],'cachedAt'=>time()];
        if(!is_dir($this->cacheDirectory)&&!mkdir($this->cacheDirectory,0700,true)&&!is_dir($this->cacheDirectory))return $data;
        // Cache failure must not prevent notifications from being shown.
        $tmp=tempnam($this->cacheDirectory,'feed-');
        if($tmp!==false){if(file_put_contents($tmp,json_encode(['version'=>$version,'expires'=>time()+300,'data'=>$data]),LOCK_EX)!==false)rename($tmp,$path);if(is_file($tmp))unlink($tmp);}
        return $data;
    }

    private function samples(string $raw): array
    {
        $state=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($state))throw new RuntimeException('Data sampel gratis tidak valid.');
        $stmt=$this->db->prepare('SELECT notification_id FROM sample_notification_reads WHERE reader_key=?');
        $stmt->execute([$this->reader]);$read=array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN),true);
        $records=[];
        // Sent email entries retain only ID/time. They still belong in the bell,
        // with a generic description rather than invented request details.
        foreach(($state['sent']??[]) as $id=>$sent)$records[(string)$id]=['created_at'=>$sent['at']??0];
        foreach(($state['pending']??[]) as $id=>$item)$records[(string)$id]=$item;
        $items=[];$unread=0;
        foreach($records as $id=>$item){
            $id=(string)$id;if(!ctype_digit($id)||!is_array($item))continue;
            $at=is_numeric($item['created_at']??null)?(int)$item['created_at']:(int)strtotime((string)($item['created_at']??''));
            $url=(string)($item['url']??'');
            if(parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_HOST)!=='seller.shopee.co.id')$url='https://seller.shopee.co.id/portal/web-seller-affiliate/free_sample';
            $isUnread=!isset($read[$id]);if($isUnread)$unread++;
            $items[]=['id'=>'sample:'.$id,'notification_id'=>$id,'type'=>'sample','unread'=>$isUnread,
                'title'=>(string)($item['title']??'Permintaan sampel gratis Shopee'),
                'content'=>(string)($item['content']??'Ada pemberitahuan permintaan sampel gratis. Buka Seller Centre untuk melihat rincian dan status terbarunya.'),
                'url'=>$url,'createdAt'=>$at,'recordedAt'=>$at?date('d M Y H:i',$at):''];
        }
        return ['items'=>$items,'unread'=>$unread,'checkedAt'=>empty($state['last_checked'])?null:date('d M Y H:i',(int)$state['last_checked'])];
    }

    public function acknowledge(array $items): array
    {
        if(count($items)>25)throw new InvalidArgumentException('Maksimal 25 notifikasi.');
        if(!$items)return $this->feed();
        $shipping=[];$sampleIds=[];$episodes=[];
        foreach($items as $item){if(!is_array($item))continue;if(($item['type']??'')==='shipping')$shipping[]=$item;elseif(($item['type']??'')==='sample')$sampleIds[(string)($item['notification_id']??'')]=true;}
        foreach($items as $item)if(is_array($item)&&($item['type']??'')==='stall')$episodes[]=$item['episode_key']??'';
        if($episodes)$this->stalls->acknowledge($this->reader,$episodes);
        if($shipping)$this->shipping->acknowledge($shipping);
        $raw=is_file($this->sampleStatePath)?file_get_contents($this->sampleStatePath):'';
        if($raw===false)throw new RuntimeException('Data sampel gratis belum dapat dibaca.');
        $known=$this->samples($raw)['items'];
        $stmt=$this->db->prepare('INSERT INTO sample_notification_reads VALUES(?,?,?) ON DUPLICATE KEY UPDATE read_at=VALUES(read_at)');
        foreach($known as $item)if(isset($sampleIds[$item['notification_id']]))$stmt->execute([$this->reader,$item['notification_id'],time()]);
        $this->invalidate();
        return $this->feed();
    }
}
