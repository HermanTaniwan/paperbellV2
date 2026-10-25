<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
foreach (['Database','OAuthVault','MarketplaceOAuthService','ShopeeReviewService'] as $class) require_once $root.'/src/'.$class.'.php';
date_default_timezone_set('Asia/Jakarta');
$directory = $root.'/storage/shopee-review-replies';
if (!is_dir($directory)) mkdir($directory,0700,true);
$lock = fopen($directory.'/worker.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);
$path = $directory.'/state.json';
$save = static function(array $state) use ($path): void {
    $tmp = $path.'.tmp';
    if (file_put_contents($tmp,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX) === false || !rename($tmp,$path)) throw new RuntimeException('Gagal menyimpan status balasan.');
};
$log = static function(array $event) use ($directory): void {
    $event = ['time'=>date(DATE_ATOM)] + $event;
    file_put_contents($directory.'/events.jsonl',json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
    echo json_encode($event,JSON_UNESCAPED_UNICODE).PHP_EOL;
};
try {
    if (in_array('--init',$argv,true)) {
        if (!is_file($path)) $save(['started_at'=>time(),'checkpoint'=>time(),'groups'=>[],'reviews'=>[]]);
        $log(['status'=>'initialized']); exit(0);
    }
    if (!is_file($path)) throw new RuntimeException('Jalankan --init sebelum mengaktifkan task.');
    $state = json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $config = require $root.'/config.php';
    $oauth = new MarketplaceOAuthService(Database::mysql($config['mysql']),new OAuthVault($config['oauth']['key_file']),$config['oauth']);
    $service = new ShopeeReviewService($oauth);
    $dry = in_array('--dry-run',$argv,true);
    $since = max((int)$state['started_at'],(int)$state['checkpoint']-86400);
    $started = time(); $cursor = ''; $seen = []; $reviews = []; $complete = false;
    for ($page=0;$page<20;$page++) {
        $response = $service->comments(['cursor'=>$cursor]);
        $rows = $response['item_comment_list'] ?? [];
        foreach ($rows as $row) $reviews[(string)$row['comment_id']] = $row;
        if (empty($response['more']) || ($rows && min(array_column($rows,'create_time')) < $since)) { $complete = true; break; }
        $next = (string)($response['next_cursor'] ?? '');
        if ($next === '' || isset($seen[$next])) break;
        $seen[$next] = true; $cursor = $next;
    }
    if (!$complete) throw new RuntimeException('Paginasi review tidak lengkap; tidak ada balasan dikirim.');
    // An existing reply on any product of this order suppresses the other reviews.
    foreach ($reviews as $review) if (!empty($review['comment_reply']['reply'])) $state['groups'][ShopeeReviewService::group($review)] = 'already_replied';
    uasort($reviews,static fn($a,$b)=>(int)$b['create_time']<=>(int)$a['create_time']);
    $sent = 0; $planned = 0;
    foreach ($reviews as $id=>$review) {
        $id = (string)$id; $group = ShopeeReviewService::group($review);
        if (!ShopeeReviewService::eligible($review,$since) || isset($state['groups'][$group]) || isset($state['reviews'][$id])) continue;
        $fresh = $service->find($id);
        if (!ShopeeReviewService::eligible($fresh,$since)) continue;
        $draft = ShopeeReviewService::draft($fresh); $planned++;
        if ($dry) { $log(['status'=>'draft','comment_id'=>$id,'buyer'=>$fresh['buyer_username']??'','rating'=>$fresh['rating_star'],'comment'=>$fresh['comment'],'draft'=>$draft]); $state['groups'][$group] = 'dry_run'; continue; }
        // Persist before sending: an uncertain response must never trigger an automatic resend.
        $state['reviews'][$id] = ['status'=>'pending','reply'=>$draft,'time'=>time()];
        $state['groups'][$group] = $id; $save($state);
        $service->reply($id,$draft);
        $after = $service->find($id);
        if (($after['comment_reply']['reply'] ?? '') !== $draft) throw new RuntimeException('Balasan belum terverifikasi; status pending dipertahankan: '.$id);
        $state['reviews'][$id]['status'] = 'verified'; $save($state); $sent++;
        $log(['status'=>'verified','comment_id'=>$id,'buyer'=>$fresh['buyer_username']??'','reply'=>$draft]);
    }
    if (!$dry) { $state['checkpoint'] = $started; $save($state); }
    $log(['status'=>$dry?'dry_run_complete':'complete','scanned'=>count($reviews),'planned'=>$planned,'sent'=>$sent]);
} catch (Throwable $e) { $log(['status'=>'error','message'=>$e->getMessage()]); exit(1); }
