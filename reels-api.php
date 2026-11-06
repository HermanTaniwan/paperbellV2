<?php
declare(strict_types=1);
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$config=require __DIR__.'/config.php';
function reelsResponse(array $data,int $code=200): never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if (($config['auth']['enabled']??true) && !isset($_SESSION['paperbell_user'])) reelsResponse(['error'=>'Sesi login diperlukan.'],401);
$_SESSION['reels_csrf']??=bin2hex(random_bytes(32));$csrf=$_SESSION['reels_csrf'];
session_write_close();
require __DIR__.'/src/OAuthVault.php';
require __DIR__.'/src/InstagramReelsService.php';
$action=(string)($_GET['action']??'list');$method=$_SERVER['REQUEST_METHOD']??'GET';
try {
    $directory=__DIR__.'/storage/instagram-reels';
    $service=new InstagramReelsService($directory);
    if ($method==='GET' && $action==='list') reelsResponse($service->overview()+['csrf'=>$csrf]);
    if ($method==='GET' && $action==='media') {
        $path=$service->mediaPath((int)($_GET['id']??0));if (!$path) reelsResponse(['error'=>'Video tidak ditemukan.'],404);
        $size=filesize($path);$start=0;$end=$size-1;
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$m) || ($m[1]==='' && $m[2]==='')) {header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
            if ($m[1]==='') $start=max(0,$size-(int)$m[2]);else {$start=(int)$m[1];if ($m[2]!=='') $end=min($end,(int)$m[2]);}
            if ($start>$end || $start>=$size) {header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
            http_response_code(206);header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Type: video/mp4');header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));
        $f=fopen($path,'rb');fseek($f,$start);$left=$end-$start+1;
        while ($left>0 && !feof($f)) {$bytes=fread($f,min(65536,$left));echo $bytes;$left-=strlen($bytes);}fclose($f);exit;
    }
    if ($method!=='POST') reelsResponse(['error'=>'Metode tidak didukung.'],405);
    if (!hash_equals($csrf,(string)($_SERVER['HTTP_X_REELS_CSRF']??''))) reelsResponse(['error'=>'Sesi formulir kedaluwarsa. Muat ulang halaman.'],403);
    $lock=fopen($directory.'/worker.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) reelsResponse(['error'=>'Worker sedang memproses konten. Coba lagi sebentar.'],409);
    $input=$action==='upload'?$_POST:json_decode((string)file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Data formulir tidak valid.');
    switch ($action) {
        case 'save': $id=$service->save($input);reelsResponse(['ok'=>true,'id'=>$id]);
        case 'upload': $service->upload((int)($input['id']??0),$_FILES['video']??[]);break;
        case 'delete': $service->delete((int)($input['id']??0));break;
        case 'connect': $service->connect($input);break;
        case 'disconnect': $service->disconnect();break;
        default: reelsResponse(['error'=>'Aksi tidak dikenal.'],404);
    }
    reelsResponse(['ok'=>true]);
} catch (InvalidArgumentException|JsonException $e) {reelsResponse(['error'=>$e->getMessage()],422);}
catch (Throwable $e) {reelsResponse(['error'=>$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Penyimpanan Reels bermasalah. Periksa log server.'],500);}
