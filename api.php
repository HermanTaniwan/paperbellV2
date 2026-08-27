<?php
declare(strict_types=1);

session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])]);
session_start();
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone']);
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/PrintService.php';
require __DIR__ . '/src/OAuthVault.php';
require __DIR__ . '/src/MarketplaceOAuthService.php';
require __DIR__ . '/src/LabelPdfPreparer.php';
require __DIR__ . '/src/MarketplaceLabelService.php';
require __DIR__ . '/src/MarketplaceOrderSyncService.php';
require __DIR__ . '/src/ShopeeEscrowService.php';
require __DIR__ . '/src/ShopeeShopStatsService.php';
require __DIR__ . '/src/DataMappingService.php';
require __DIR__ . '/src/PrintQueueService.php';
require __DIR__ . '/src/PdfToolsService.php';
require __DIR__ . '/src/ScannerService.php';

function respond(mixed $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $raw = file_get_contents('php://input'); return $raw ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?: []) : []; }
function unixText(int|string|null $unix): string { $value=(int)$unix; return $value > 0 ? date('d M Y H:i', $value) : '-'; }
function refreshOrderPrintSummary(PDO $db,string $orderSn): void { $stmt=$db->prepare('UPDATE orders o LEFT JOIN (SELECT order_sn,COUNT(*) line_count,COALESCE(SUM(qty),0) item_qty,SUM(printed=0) pending,MAX(printed_at) printed_at FROM order_process WHERE order_sn=? GROUP BY order_sn) s ON s.order_sn=o.order_sn SET o.print_line_count=COALESCE(s.line_count,0),o.print_item_qty=COALESCE(s.item_qty,0),o.unprinted_lines=COALESCE(s.pending,0),o.last_printed_at=s.printed_at WHERE o.order_sn=?');$stmt->execute([$orderSn,$orderSn]); }
function appBaseUrl(array $config): string { if(($config['app']['public_url']??'')!=='')return rtrim($config['app']['public_url'],'/');$host=(string)($_SERVER['HTTP_HOST']??'localhost');if(!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/',$host))throw new RuntimeException('Host URL tidak valid.');$scheme=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http';return $scheme.'://'.$host.rtrim((string)$config['app']['base_path'],'/'); }
function storeHolidays(PDO $db): array {
    $stmt=$db->prepare("SELECT meta_value FROM app_meta WHERE meta_key='store_holidays'");$stmt->execute();$decoded=json_decode((string)($stmt->fetchColumn()?:'[]'),true);$items=[];
    foreach(is_array($decoded)?$decoded:[] as $value){$date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);if($date&&$date->format('Y-m-d')===$value)$items[]=$value;}
    $items=array_values(array_unique($items));sort($items);return $items;
}
function isShippingWorkday(DateTimeImmutable $date,array $holidayLookup): bool {
    return (int)$date->format('N') < 6 && !isset($holidayLookup[$date->format('Y-m-d')]);
}
function nextShippingWorkday(DateTimeImmutable $date,array $holidayLookup): DateTimeImmutable {
    do {$date=$date->modify('+1 day');} while(!isShippingWorkday($date,$holidayLookup));
    return $date;
}
function shippingDeadline(int|string|null $createdAt,array $holidayLookup): array {
    $timestamp=(int)$createdAt;
    if($timestamp<1)return ['date'=>'','dueToday'=>false];
    $timezone=new DateTimeZone(date_default_timezone_get());
    $created=(new DateTimeImmutable('@'.$timestamp))->setTimezone($timezone);
    $orderDate=$created->setTime(0,0);
    $deadline=isShippingWorkday($orderDate,$holidayLookup) && (int)$created->format('G') < 12
        ? $orderDate
        : nextShippingWorkday($orderDate,$holidayLookup);
    $today=(new DateTimeImmutable('today',$timezone))->setTime(0,0);
    return ['date'=>$deadline->format('Y-m-d'),'dueToday'=>$deadline->format('Y-m-d')===$today->format('Y-m-d')];
}
function streamPdf(string $path, string $downloadName): never {
    $size = filesize($path);
    if ($size === false || $size < 1) respond(['error'=>'File PDF kosong atau tidak dapat dibaca.'], 404);

    $start = 0;
    $end = $size - 1;
    $partial = false;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            exit;
        }
        if ($matches[1] === '') {
            $suffix = (int)$matches[2];
            if ($suffix < 1) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }
            $start = max(0, $size - $suffix);
        } else {
            $start = (int)$matches[1];
            if ($matches[2] !== '') $end = min($end, (int)$matches[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            exit;
        }
        $partial = true;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($downloadName)) ?: 'document.pdf';
    $length = $end - $start + 1;
    http_response_code($partial ? 206 : 200);
    header('Content-Type: application/pdf');
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="'.$safeName.'"');
    header('Content-Length: '.$length);
    if ($partial) header("Content-Range: bytes {$start}-{$end}/{$size}");
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') exit;

    $handle = fopen($path, 'rb');
    if ($handle === false) respond(['error'=>'File PDF tidak dapat dibuka.'], 500);
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}

function preparedLabelPdf(string $sourcePath, array $printingConfig): string {
    $script = __DIR__ . '/tools/prepare_label_pdf.py';
    $banner = __DIR__ . '/assets/label-unboxing.jpeg';
    foreach ([$sourcePath, $script, $banner] as $required) {
        if (!is_file($required)) throw new RuntimeException('Bahan PDF label tidak lengkap: ' . basename($required));
    }

    $cacheDir = __DIR__ . '/storage/print-labels/previews';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Folder cache preview label tidak dapat dibuat.');
    }
    $fingerprint = implode('|', [
        realpath($sourcePath) ?: $sourcePath,
        (string)filemtime($sourcePath),
        (string)filesize($sourcePath),
        (string)filemtime($script),
        (string)filemtime($banner),
    ]);
    $output = $cacheDir . '/label-preview-' . hash('sha256', $fingerprint) . '.pdf';
    if (is_file($output) && filesize($output) > 0) return $output;

    $temporary = $output . '.' . bin2hex(random_bytes(5)) . '.tmp';
    $command = [
        (string)($printingConfig['python'] ?? 'python'),
        $script,
        $sourcePath,
        $temporary,
    ];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Python penyiapan preview label tidak dapat dijalankan.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || !is_file($temporary) || filesize($temporary) < 1) {
        @unlink($temporary);
        $detail = trim((string)$stderr) ?: trim((string)$stdout);
        throw new RuntimeException('Preview label gagal disiapkan.' . ($detail !== '' ? ' ' . $detail : ''));
    }
    if (!@rename($temporary, $output)) {
        if (is_file($output) && filesize($output) > 0) @unlink($temporary);
        else {
            @unlink($temporary);
            throw new RuntimeException('Preview label siap pakai tidak dapat disimpan.');
        }
    }
    return $output;
}

$action = $_GET['action'] ?? 'dashboard';
try {
    $authEnabled = (bool)($config['auth']['enabled'] ?? true);
    if ($authEnabled && $action === 'login') {
        $input = body();
        $ok = hash_equals($config['auth']['username'], (string)($input['username'] ?? '')) && hash_equals($config['auth']['password'], (string)($input['password'] ?? ''));
        if (!$ok) respond(['error' => 'Username atau password salah.'], 401);
        session_regenerate_id(true); $_SESSION['paperbell_user'] = $config['auth']['username']; respond(['ok' => true, 'user' => $_SESSION['paperbell_user']]);
    }
    if ($authEnabled && $action === 'logout') { session_destroy(); respond(['ok' => true]); }
    if (!$authEnabled && in_array($action, ['login', 'logout'], true)) respond(['error' => 'Login sedang dinonaktifkan.'], 404);
    if ($authEnabled && !isset($_SESSION['paperbell_user'])) respond(['error' => 'Sesi login diperlukan.'], 401);
    if (!$authEnabled) $_SESSION['paperbell_user'] = (string)($config['auth']['username'] ?? 'local');

    // API requests only read the user after authentication. Release PHP's
    // session-file lock now so page data and printer status can load in parallel.
    session_write_close();

    $mysql = Database::mysql($config['mysql']);
    $printing = new PrintService($mysql,$config['printing']['default_label_printer']);
    $oauthService = static function() use ($mysql,$config): MarketplaceOAuthService { static $service=null;return $service??=new MarketplaceOAuthService($mysql,new OAuthVault($config['oauth']['key_file']),$config['oauth']); };
    $labelService = static function() use ($mysql,$config,$oauthService): MarketplaceLabelService { static $service=null;return $service??=new MarketplaceLabelService($mysql,$oauthService(),__DIR__.'/storage/labels',new LabelPdfPreparer($config['printing'],__DIR__),(string)$config['printing']['default_label_printer']); };
    $mappingService = new DataMappingService($mysql,$config['mapping']+['python'=>$config['printing']['python']],__DIR__);
    $queueService = static function() use ($mysql): PrintQueueService { static $service=null;return $service??=new PrintQueueService($mysql); };
    $pdfTools = new PdfToolsService($mysql,$config['printing'],__DIR__);
    $scannerService = new ScannerService($config['scanner']??[],__DIR__);

    if ($action === 'scanner_overview') respond($scannerService->overview());
    if ($action === 'scanner_start') respond($scannerService->start(body(),(string)$_SESSION['paperbell_user']),202);
    if ($action === 'scanner_job') respond($scannerService->job(trim((string)($_GET['id']??''))));
    if ($action === 'scanner_cancel') {$input=body();respond($scannerService->cancel(trim((string)($input['id']??''))));}
    if ($action === 'scanner_file') {
        $file=$scannerService->file(trim((string)($_GET['id']??'')),trim((string)($_GET['type']??'')),(int)($_GET['page']??0));
        $size=filesize($file['path']);if($size===false)throw new RuntimeException('Ukuran file scanner tidak dapat dibaca.');
        header('Content-Type: '.$file['mime']);header('Content-Length: '.$size);header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: '.(($_GET['type']??'')==='report'?'attachment':'inline').'; filename="'.preg_replace('/[^A-Za-z0-9._-]+/','_',basename($file['name'])).'"');
        readfile($file['path']);exit;
    }

    if ($action === 'save_store_holiday') {
        $input=body();$dateText=trim((string)($input['date']??''));$operation=(string)($input['operation']??'add');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
        if(!$date||$date->format('Y-m-d')!==$dateText)respond(['error'=>'Tanggal libur tidak valid.'],422);
        if(!in_array($operation,['add','remove'],true))respond(['error'=>'Operasi tanggal libur tidak valid.'],422);
        $mysql->beginTransaction();
        try{$mysql->exec("INSERT INTO app_meta(meta_key,meta_value) VALUES('store_holidays','[]') ON DUPLICATE KEY UPDATE meta_key=VALUES(meta_key)");$stmt=$mysql->query("SELECT meta_value FROM app_meta WHERE meta_key='store_holidays' FOR UPDATE");$items=json_decode((string)($stmt->fetchColumn()?:'[]'),true);$items=is_array($items)?array_values(array_filter(array_map('strval',$items))):[];$lookup=array_fill_keys($items,true);if($operation==='add')$lookup[$dateText]=true;else unset($lookup[$dateText]);$items=array_keys($lookup);sort($items);$update=$mysql->prepare("UPDATE app_meta SET meta_value=? WHERE meta_key='store_holidays'");$update->execute([json_encode($items,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$mysql->commit();respond(['ok'=>true,'holidays'=>$items]);}catch(Throwable $e){if($mysql->inTransaction())$mysql->rollBack();throw $e;}
    }

    if ($action === 'mapping') respond($mappingService->overview((string)($_GET['q']??''),(int)($_GET['page']??1)));
    if ($action === 'sync_mapping') respond($mappingService->syncFromGoogle((string)$_SESSION['paperbell_user']));
    if ($action === 'manual_pdfs') respond(['items'=>$pdfTools->listDocuments(),'printers'=>$printing->configuredPrinters()]);
    if ($action === 'manual_mapping_pdfs') respond(['items'=>$printing->mappingPdfChoices((string)($_GET['q']??''))]);
    if ($action === 'upload_manual_pdf') respond($pdfTools->upload($_FILES['pdf']??[],(string)$_SESSION['paperbell_user']));
    if ($action === 'delete_manual_pdf') {$input=body();$pdfTools->delete((int)($input['id']??0));respond(['ok'=>true]);}
    if ($action === 'print_manual_pdf') {$input=body();$doc=$pdfTools->document((int)($input['id']??0));respond($printing->queueFile((string)$doc['source_type'],(string)$doc['file_path'],trim((string)($input['printer']??'')),(string)$_SESSION['paperbell_user'],is_array($input['settings']??null)?$input['settings']:[]));}
    if ($action === 'print_mapping_pdf') {$input=body();respond($printing->queueMappingFile((int)($input['mapping_id']??0),trim((string)($input['printer']??'')),(string)$_SESSION['paperbell_user'],is_array($input['settings']??null)?$input['settings']:[]));}
    if ($action === 'print_stock_product') {
        $input=body();$mappingId=(int)($input['mapping_id']??0);$packs=(int)($input['packs']??0);$printer=trim((string)($input['printer']??''));
        if($packs<1)respond(['error'=>'Qty cetak minimal 1 pak.'],422);$stmt=$mysql->prepare('SELECT * FROM data_mappings WHERE id=?');$stmt->execute([$mappingId]);$map=$stmt->fetch();if(!$map)respond(['error'=>'Data Mapping tidak ditemukan.'],404);$key=trim((string)$map['sku_id']);if($key==='')respond(['error'=>'SKU ID mapping kosong.'],422);
        $now=time();$reference='STOCK-'.$mappingId.'-'.$now.'-'.bin2hex(random_bytes(3));$mysql->beginTransaction();
        try{$print=$printing->queueMappingFile($mappingId,$printer,(string)$_SESSION['paperbell_user'],['copies'=>$packs*20],'stock',$reference);$up=$mysql->prepare('INSERT INTO product_inventory(item_key,model_sku,item_sku,item_name,model_name,no_ref,sku_induk,qty,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE model_sku=VALUES(model_sku),item_sku=VALUES(item_sku),item_name=VALUES(item_name),model_name=VALUES(model_name),no_ref=VALUES(no_ref),sku_induk=VALUES(sku_induk),qty=qty+VALUES(qty),updated_at=VALUES(updated_at)');$up->execute([$key,$map['sku_id'],$map['parent_sku'],$map['search_alias']?:$map['product_name'],$map['variation']?:$map['variation_name'],$map['sku_id'],$map['parent_sku'],$packs,$now]);$afterStmt=$mysql->prepare('SELECT qty FROM product_inventory WHERE item_key=?');$afterStmt->execute([$key]);$after=(int)$afterStmt->fetchColumn();$log=$mysql->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,note,created_by,created_at) VALUES(?,'add',?,?,?, ?,?)");$log->execute([$key,$packs,$after,'Tambah otomatis dari cetak rekomendasi stok #'.$print['id'],(string)$_SESSION['paperbell_user'],$now]);$mysql->commit();respond($print+['packs'=>$packs,'sheets'=>$packs*20,'inventoryQty'=>$after]);}catch(Throwable $e){if($mysql->inTransaction())$mysql->rollBack();throw$e;}
    }
    if ($action === 'random_pool') {$pool=$pdfTools->randomPool();respond(['counts'=>$pool['counts']]);}
    if ($action === 'generate_random') respond($pdfTools->generateRandom(body(),(string)$_SESSION['paperbell_user']));
    if ($action === 'generate_random_order') {
        $input=body();$generated=$pdfTools->generateRandom($input,(string)$_SESSION['paperbell_user']);$document=$pdfTools->document((int)$generated['id']);$now=time();$orderSn='RANDOM-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));$paper=strtoupper((string)($input['paper']??'A5'));if(!in_array($paper,['A5','B5'],true))$paper='A5';$mode=($input['mode']??'planner')==='loose'?'Loose Leaf':'Planner';
        $mysql->beginTransaction();try{$order=$mysql->prepare('INSERT INTO orders(order_sn,status,create_time,update_time,buyer_username,raw_json) VALUES(?,?,?,?,?,?)');$order->execute([$orderSn,'PROCESSED',$now,$now,'Random Pages',json_encode(['source'=>'random','manual_pdf_id'=>(int)$generated['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$line=$mysql->prepare('INSERT INTO order_process(order_sn,order_item_id,item_key,model_sku,item_sku,item_name,model_name,qty,status,create_time,saved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$line->execute([$orderSn,'random-'.(int)$generated['id'],'RANDOMPDF:'.(int)$generated['id'].':'.$paper,'',$paper,(string)$document['original_name'],$mode.' · '.(string)($generated['summary']??''),1,'PROCESSED',$now,$now]);refreshOrderPrintSummary($mysql,$orderSn);$mysql->commit();}catch(Throwable $e){if($mysql->inTransaction())$mysql->rollBack();try{$pdfTools->delete((int)$generated['id']);}catch(Throwable){}throw$e;}
        respond($generated+['order_sn'=>$orderSn]);
    }
    if ($action === 'print_job_action') {$input=body();respond($queueService()->appAction((int)($input['id']??0),trim((string)($input['operation']??''))));}
    if ($action === 'acknowledge_printer_incident') {$input=body();respond($queueService()->acknowledgeIncident((int)($input['id']??0),(string)$_SESSION['paperbell_user']));}
    if ($action === 'clear_completed_jobs') respond(['ok'=>true,'deleted'=>$queueService()->clearCompleted()]);
    if ($action === 'spooler_action') {$input=body();respond($queueService()->spoolerAction(trim((string)($input['printer']??'')),(int)($input['job_id']??0),trim((string)($input['operation']??''))));}
    if ($action === 'move_spooler_job') {$input=body();respond($queueService()->moveSpoolerJob(trim((string)($input['printer']??'')),(int)($input['job_id']??0),trim((string)($input['target_printer']??''))));}

    if ($action === 'oauth_status') respond($oauthService()->statuses(appBaseUrl($config)));
    if ($action === 'oauth_save_config') { $input=body();$provider=strtolower(trim((string)($input['provider']??'')));$oauthService()->saveConfig($provider,is_array($input['config']??null)?$input['config']:[]);respond(['ok'=>true,'data'=>$oauthService()->statuses(appBaseUrl($config))]); }
    if ($action === 'oauth_connect') { $input=body();$provider=strtolower(trim((string)($input['provider']??'')));respond(['ok'=>true,'url'=>$oauthService()->connectUrl($provider,appBaseUrl($config),(string)$_SESSION['paperbell_user'])]); }
    if ($action === 'oauth_disconnect') { $input=body();$oauthService()->disconnect(strtolower(trim((string)($input['provider']??''))));respond(['ok'=>true]); }
    if ($action === 'fetch_label') { $input=body();respond($labelService()->fetch(trim((string)($input['order_sn']??'')))); }
    if ($action === 'set_label_printed') { $input=body();$sn=trim((string)($input['order_sn']??''));$printed=(bool)($input['printed']??false);if($sn==='')respond(['error'=>'Order SN wajib diisi.'],422);$stmt=$mysql->prepare('INSERT INTO order_resi(order_sn,pdf_path,resi_printed,resi_printed_at) VALUES(?,\'\',?,?) ON DUPLICATE KEY UPDATE resi_printed=VALUES(resi_printed),resi_printed_at=VALUES(resi_printed_at)');$stmt->execute([$sn,$printed?1:0,$printed?time():null]);respond(['ok'=>true,'printed'=>$printed]); }
    if ($action === 'set_order_item_printed') { $input=body();$lineId=(int)($input['line_id']??0);$printed=(bool)($input['printed']??true);if($lineId<=0)respond(['error'=>'Item order tidak valid.'],422);$check=$mysql->prepare('SELECT order_sn FROM order_process WHERE id=?');$check->execute([$lineId]);$sn=(string)($check->fetchColumn()?:'');if($sn==='')respond(['error'=>'Item order tidak ditemukan.'],404);$stmt=$mysql->prepare('UPDATE order_process SET printed=?,printed_odd=?,printed_even=?,printed_at=? WHERE id=?');$stmt->execute([$printed?1:0,$printed?1:0,$printed?1:0,$printed?time():null,$lineId]);refreshOrderPrintSummary($mysql,$sn);$remaining=$mysql->prepare('SELECT unprinted_lines FROM orders WHERE order_sn=?');$remaining->execute([$sn]);respond(['ok'=>true,'printed'=>$printed,'order_sn'=>$sn,'remaining'=>(int)$remaining->fetchColumn()]); }
    if ($action === 'sync_marketplace') { $input=body();respond((new MarketplaceOrderSyncService($mysql,$oauthService()))->sync(strtolower(trim((string)($input['provider']??''))),(string)$_SESSION['paperbell_user'])); }

    if ($action === 'label_pdf') {
        $stmt=$mysql->prepare('SELECT pdf_path FROM order_resi WHERE order_sn=?');$stmt->execute([(string)($_GET['order_sn']??'')]);$path=(string)($stmt->fetchColumn()?:'');
        if($path===''||!is_file($path)||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='pdf')respond(['error'=>'PDF label belum tersedia.'],404);
        streamPdf(preparedLabelPdf($path, $config['printing']), basename($path));
    }
    if ($action === 'product_pdf') {
        $pdf=$printing->productPdf((int)($_GET['line_id']??0));$path=(string)$pdf['path'];if(!is_file($path)||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='pdf')respond(['error'=>'PDF produk tidak tersedia.'],404);streamPdf($path, (string)$pdf['name']);
    }
    if ($action === 'mapping_pdf') {
        $stmt=$mysql->prepare('SELECT sku_id,product_name,variation_name,file_path FROM data_mappings WHERE id=?');$stmt->execute([(int)($_GET['mapping_id']??0)]);$mapping=$stmt->fetch();
        if(!$mapping)respond(['error'=>'Data Mapping tidak ditemukan.'],404);$path=(string)$mapping['file_path'];if(!is_file($path)||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='pdf')respond(['error'=>'PDF produk tidak tersedia.'],404);
        streamPdf($path,basename($path));
    }
    if ($action === 'manual_pdf') {
        $doc=$pdfTools->document((int)($_GET['id']??0));$path=(string)$doc['file_path'];streamPdf($path, (string)$doc['original_name']);
    }

    if ($action === 'session') respond(['authenticated'=>true,'authEnabled'=>$authEnabled,'user'=>$_SESSION['paperbell_user'],'pollSeconds'=>$config['app']['poll_seconds']]);
    if ($action === 'sync') respond(['ok'=>true,'message'=>'MySQL adalah sumber data utama; gunakan sync_marketplace.']);

    if ($action === 'shopee_finance') {
        $today=new DateTimeImmutable('today');
        $from=DateTimeImmutable::createFromFormat('!Y-m-d',trim((string)($_GET['from']??'')))?:$today->setDate((int)$today->format('Y'),4,1);
        $to=DateTimeImmutable::createFromFormat('!Y-m-d',trim((string)($_GET['to']??'')))?:$today;
        respond((new ShopeeEscrowService($mysql,$oauthService()))->dashboard($from,$to));
    }
    if ($action === 'sync_shopee_finance') {
        $input=body();$today=new DateTimeImmutable('today');
        $from=DateTimeImmutable::createFromFormat('!Y-m-d',trim((string)($input['from']??'')))?:$today->setDate((int)$today->format('Y'),4,1);
        $to=DateTimeImmutable::createFromFormat('!Y-m-d',trim((string)($input['to']??'')))?:$today;
        respond((new ShopeeEscrowService($mysql,$oauthService()))->sync($from,$to));
    }

    if ($action === 'shopee_shop_stats') {
        respond((new ShopeeShopStatsService($mysql))->dashboard());
    }

    if ($action === 'shopee_shop_stats_comparison') {
        respond((new ShopeeShopStatsService($mysql))->comparison(
            trim((string)($_GET['from'] ?? '')),
            trim((string)($_GET['to'] ?? '')),
            trim((string)($_GET['granularity'] ?? 'daily'))
        ));
    }

    if ($action === 'dashboard') {
        $totals = $mysql->query("SELECT COUNT(*) total,SUM(UPPER(o.status)<>'CANCELLED' AND EXISTS(SELECT 1 FROM order_process pending WHERE pending.order_sn=o.order_sn AND pending.printed=0)) unprinted,SUM(UPPER(o.status)<>'CANCELLED' AND EXISTS(SELECT 1 FROM order_process done WHERE done.order_sn=o.order_sn) AND NOT EXISTS(SELECT 1 FROM order_process pending WHERE pending.order_sn=o.order_sn AND pending.printed=0)) printed,SUM(UPPER(o.status)='CANCELLED') cancelled FROM orders o")->fetch();
        $labels = $mysql->query("SELECT COUNT(*) total,SUM(resi_printed=0) unprinted,SUM(resi_printed=1) printed FROM order_resi")->fetch();
        $inventory = $mysql->query("SELECT COUNT(*) items,COALESCE(SUM(qty),0) qty,SUM(qty<=0) empty_items FROM product_inventory")->fetch();
        $lastSync = (int)($mysql->query("SELECT meta_value FROM app_meta WHERE meta_key='last_sync_at'")->fetchColumn() ?: 0);
        $queued = (int)$mysql->query("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','processing')")->fetchColumn();
        respond(['orders'=>$totals,'labels'=>$labels,'inventory'=>$inventory,'queued'=>$queued,'lastSync'=>$lastSync,'lastSyncText'=>unixText($lastSync)]);
    }

    if ($action === 'sales_contribution') {
        $today=new DateTimeImmutable('today');$defaultFrom=$today->modify('-29 days');
        $from=DateTimeImmutable::createFromFormat('!Y-m-d',trim((string)($_GET['from']??'')))?:$defaultFrom;
        $to=DateTimeImmutable::createFromFormat('!Y-m-d',trim((string)($_GET['to']??'')))?:$today;
        if($from>$to)respond(['error'=>'Tanggal mulai tidak boleh melewati tanggal akhir.'],422);
        if((int)$from->diff($to)->format('%a')>365)respond(['error'=>'Rentang kontribusi maksimal 366 hari.'],422);

        $normalize=static fn(string $value):string=>mb_strtolower(preg_replace('/\s+/','',trim($value)));
        $mappingPaper=[];
        foreach($mysql->query("SELECT sku_id,paper FROM data_mappings WHERE paper IN ('A5','B5') AND sku_id<>''")->fetchAll() as $mapping){$key=$normalize((string)$mapping['sku_id']);if($key!=='')$mappingPaper[$key]=strtoupper((string)$mapping['paper']);}
        foreach($mysql->query("SELECT a.alias_key,m.paper FROM mapping_aliases a JOIN data_mappings m ON m.id=a.mapping_id WHERE m.paper IN ('A5','B5')")->fetchAll() as $alias){$key=$normalize((string)$alias['alias_key']);if($key!=='')$mappingPaper[$key]=strtoupper((string)$alias['paper']);}

        $stmt=$mysql->prepare("SELECT DATE(FROM_UNIXTIME(o.create_time)) order_date,op.order_sn,op.item_key,op.model_sku,op.item_sku,op.item_name,op.model_name,op.qty FROM order_process op JOIN orders o ON o.order_sn=op.order_sn WHERE o.create_time>=? AND o.create_time<? AND op.qty>0 AND o.order_sn NOT LIKE 'MANUAL-%' AND o.order_sn NOT LIKE 'RANDOM-%' AND UPPER(o.status) NOT IN ('CANCELLED','CANCELED')");
        $stmt->execute([$from->getTimestamp(),$to->modify('+1 day')->getTimestamp()]);
        $categories=[
            'a5_20'=>['key'=>'a5_20','label'=>'A5 (20 Lubang)','shortLabel'=>'A5 20L','qty'=>0,'orders'=>[],'color'=>'#ef7558'],
            'a5_6'=>['key'=>'a5_6','label'=>'A5 (6 Lubang)','shortLabel'=>'A5 6L','qty'=>0,'orders'=>[],'color'=>'#e7b54a'],
            'b5'=>['key'=>'b5','label'=>'B5','shortLabel'=>'B5','qty'=>0,'orders'=>[],'color'=>'#4f8f78'],
        ];
        $uncategorizedQty=0;$allOrders=[];$daily=[];
        for($date=$from;$date<=$to;$date=$date->modify('+1 day'))$daily[$date->format('Y-m-d')]=['a5_20'=>0,'a5_6'=>0,'b5'=>0];
        foreach($stmt->fetchAll() as $line){
            $keys=array_values(array_unique(array_filter([
                $normalize((string)$line['item_key']),$normalize((string)$line['model_sku'].(string)$line['item_sku']),
                $normalize((string)$line['item_sku'].(string)$line['model_sku']),$normalize((string)$line['model_sku']),$normalize((string)$line['item_sku'])
            ])));
            $paper='';foreach($keys as $key)if(isset($mappingPaper[$key])){$paper=$mappingPaper[$key];break;}
            $description=(string)$line['item_name'].' '.(string)$line['model_name'];
            if($paper===''){
                if(preg_match('/(?:^|\D)B\s*5(?:\D|$)/i',$description))$paper='B5';
                elseif(preg_match('/(?:^|\D)A\s*5(?:\D|$)/i',$description))$paper='A5';
            }
            $six=preg_match('/(?:^|\D)6\s*(?:lubang|hole)(?:\D|$)/i',$description)===1;
            $category=$paper==='B5'?'b5':($paper==='A5'?($six?'a5_6':'a5_20'):'');$qty=(int)$line['qty'];
            if($category===''){$uncategorizedQty+=$qty;continue;}
            $categories[$category]['qty']+=$qty;$categories[$category]['orders'][(string)$line['order_sn']]=true;$allOrders[(string)$line['order_sn']]=true;
            $dateKey=(string)$line['order_date'];if(isset($daily[$dateKey]))$daily[$dateKey][$category]+=$qty;
        }
        $total=array_sum(array_column($categories,'qty'));$items=[];
        foreach($categories as $category){$orders=count($category['orders']);unset($category['orders']);$category['orders']=$orders;$category['share']=$total>0?round($category['qty']/$total*100,1):0.0;$items[]=$category;}
        usort($items,fn(array $a,array $b):int=>$b['qty']<=>$a['qty']);
        $series=[];foreach($daily as $date=>$values){$dayTotal=array_sum($values);$series[]=['date'=>$date,'label'=>(new DateTimeImmutable($date))->format('d M'),'total'=>$dayTotal,'qty'=>$values];}
        respond(['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'items'=>$items,'series'=>$series,'summary'=>['qty'=>$total,'orders'=>count($allOrders),'uncategorizedQty'=>$uncategorizedQty,'coverage'=>($total+$uncategorizedQty)>0?round($total/($total+$uncategorizedQty)*100,1):100.0]]);
    }

    if ($action === 'stock_recommendations') {
        $coverDays=max(7,min(30,(int)($_GET['cover_days']??14)));
        $query=mb_strtolower(trim((string)($_GET['q']??'')));
        $priorityFilter=trim((string)($_GET['priority']??'all'));
        $sort=trim((string)($_GET['sort']??'score'));
        $page=max(1,(int)($_GET['page']??1));$size=50;
        $today=strtotime('today');$day7=$today-6*86400;$day30=$today-29*86400;$day60=$today-59*86400;$day90=$today-89*86400;

        $mappingBySku=[];
        foreach($mysql->query("SELECT id,sku_id,parent_sku,product_name,variation_name,file_path,printer FROM data_mappings WHERE sku_id<>''")->fetchAll() as $mapping)$mappingBySku[mb_strtolower(preg_replace('/\s+/','',(string)$mapping['sku_id']))]=$mapping;
        $sales=$mysql->prepare("SELECT op.order_sn,op.item_key,op.model_sku,op.item_sku,op.item_name,op.model_name,op.qty,op.create_time FROM order_process op JOIN orders o ON o.order_sn=op.order_sn WHERE op.create_time>=? AND op.qty>0 AND o.order_sn NOT LIKE 'MANUAL-%' AND o.order_sn NOT LIKE 'RANDOM-%' AND UPPER(o.status) NOT IN ('CANCELLED','CANCELED')");
        $sales->execute([$day90]);$salesBySku=[];
        foreach($sales->fetchAll() as $line){
            $normalize=fn($value)=>mb_strtolower(preg_replace('/\s+/','',trim((string)$value)));
            $itemKey=$normalize($line['item_key']);$modelSku=$normalize($line['model_sku']);$itemSku=$normalize($line['item_sku']);
            $map=null;foreach(array_unique(array_filter([$itemKey,$itemSku.$modelSku,$modelSku.$itemSku,$modelSku,$itemSku])) as $candidate)if(isset($mappingBySku[$candidate])){$map=$mappingBySku[$candidate];break;}
            $sku=trim((string)($map['sku_id']??''))?:trim((string)$line['item_key'])?:trim((string)$line['model_sku'])?:trim((string)$line['item_sku']);if($sku==='')continue;$key=mb_strtolower($sku);
            if(!isset($salesBySku[$key]))$salesBySku[$key]=['sku'=>$sku,'product_name'=>(string)($map['product_name']??$line['item_name']),'variation_name'=>(string)($map['variation_name']??$line['model_name']),'sold_90'=>0,'sold_30'=>0,'sold_7'=>0,'previous_30'=>0,'orders_30_set'=>[],'active_days_30_set'=>[],'active_days_90_set'=>[],'last_sale_at'=>0];
            $qty=(int)$line['qty'];$created=(int)$line['create_time'];$salesBySku[$key]['sold_90']+=$qty;
            if($created>=$day30){$salesBySku[$key]['sold_30']+=$qty;$salesBySku[$key]['orders_30_set'][(string)$line['order_sn']]=true;$salesBySku[$key]['active_days_30_set'][date('Y-m-d',$created)]=true;}
            if($created>=$day7)$salesBySku[$key]['sold_7']+=$qty;if($created>=$day60&&$created<$day30)$salesBySku[$key]['previous_30']+=$qty;
            $salesBySku[$key]['active_days_90_set'][date('Y-m-d',$created)]=true;$salesBySku[$key]['last_sale_at']=max($salesBySku[$key]['last_sale_at'],$created);
        }
        foreach($salesBySku as &$row){$row['orders_30']=count($row['orders_30_set']);$row['active_days_30']=count($row['active_days_30_set']);$row['active_days_90']=count($row['active_days_90_set']);unset($row['orders_30_set'],$row['active_days_30_set'],$row['active_days_90_set']);}unset($row);
        $inventoryBySku=[];
        foreach($mysql->query('SELECT item_key,model_sku,item_sku,no_ref,qty FROM product_inventory')->fetchAll() as $stock){
            $keys=array_unique(array_filter(array_map(fn($value)=>mb_strtolower(trim((string)$value)),[$stock['item_key'],$stock['model_sku'],$stock['item_sku'],$stock['no_ref']])));
            foreach($keys as $key)$inventoryBySku[$key]=($inventoryBySku[$key]??0)+(int)$stock['qty'];
        }
        $items=[];$stockPrinters=$printing->configuredPrinters();
        foreach(array_values($salesBySku) as $row){
            $sku=trim((string)$row['sku']);$key=mb_strtolower($sku);$stock=(int)($inventoryBySku[$key]??0);
            $sold7=(int)$row['sold_7'];$sold30=(int)$row['sold_30'];$sold90=(int)$row['sold_90'];$previous30=(int)$row['previous_30'];
            $daily30=$sold30/30;$daily90=$sold90/90;$daily=round(($daily30*.65)+($daily90*.35),3);
            $trend=$previous30>0?round((($sold30-$previous30)/$previous30)*100):($sold30>0?100:0);
            $map=$mappingBySku[$key]??null;$name=trim((string)($row['product_name']??''));$variation=trim((string)($row['variation_name']??''));
            if($name===''&&$map)$name=(string)$map['product_name'];if($variation===''&&$map)$variation=(string)$map['variation_name'];
            $confidence=$sold90>=10&&(int)$row['active_days_90']>=5?'high':($sold90>=3?'medium':'low');
            $filePath=(string)($map['file_path']??'');$defaultPrinter=$map?$printing->resolveMappedPrinter((string)($map['printer']??'')):'';$items[]=['sku'=>$sku,'parentSku'=>(string)($map['parent_sku']??''),'productName'=>$name?:'Produk tanpa nama','variationName'=>$variation,'mappingId'=>(int)($map['id']??0),'fileName'=>$filePath!==''?basename($filePath):'','hasPdf'=>$filePath!==''&&is_file($filePath)&&strtolower(pathinfo($filePath,PATHINFO_EXTENSION))==='pdf','defaultPrinter'=>$defaultPrinter,'printerAvailable'=>$defaultPrinter!==''&&in_array($defaultPrinter,$stockPrinters,true),'stock'=>$stock,'sold7'=>$sold7,'sold30'=>$sold30,'sold90'=>$sold90,'orders30'=>(int)$row['orders_30'],'activeDays30'=>(int)$row['active_days_30'],'dailyVelocity'=>$daily,'trend'=>$trend,'confidence'=>$confidence,'lastSaleAt'=>(int)$row['last_sale_at'],'lastSaleText'=>unixText($row['last_sale_at'])];
        }
        $maxDaily=max(0.001,...array_column($items,'dailyVelocity'));$maxOrders=max(1,...array_column($items,'orders30'));
        foreach($items as &$item){
            $momentumBase=max(.03,$item['sold30']/30);$momentum=min(2,($item['sold7']/7)/$momentumBase);
            $recencyDays=max(0,(int)floor(($today-$item['lastSaleAt'])/86400));
            $item['starterScore']=(int)round(50*sqrt($item['dailyVelocity']/$maxDaily)+20*sqrt($item['orders30']/$maxOrders)+15*min(1,$item['activeDays30']/12)+10*($momentum/2)+5*max(0,1-$recencyDays/30));
        }unset($item);
        usort($items,fn($a,$b)=>[$b['starterScore'],$b['sold30'],$b['sold7']]<=>[$a['starterScore'],$a['sold30'],$a['sold7']]);
        $packSize=20;$summary=['skuTotal'=>count($items),'start'=>0,'next'=>0,'trial'=>0,'wait'=>0,'openingPacks'=>0,'openingSheets'=>0];
        foreach($items as $index=>&$item){
            if($index<12&&$item['sold30']>=5)$tier='start';elseif($index<30&&$item['sold30']>=3)$tier='next';elseif($index<60&&($item['sold30']>=2||$item['sold7']>0))$tier='trial';else$tier='wait';
            $base=max(2,(int)ceil($item['dailyVelocity']*($coverDays+3)));
            $openingQty=$tier==='start'?$base:($tier==='next'?max(2,(int)ceil($base*.7)):($tier==='trial'?max(2,(int)ceil($base*.4)):0));$openingSheets=$openingQty*$packSize;
            $item['priority']=$tier;$item['openingQty']=$openingQty;$item['openingSheets']=$openingSheets;$item['recommendedQty']=$openingQty;$item['rank']=$index+1;
            $item['reason']=$tier==='start'?($item['trend']>20?'Demand tinggi dan sedang naik.':'Demand tinggi dan konsisten.') : ($tier==='next'?'Layak ditambahkan setelah SKU utama stabil.':($tier==='trial'?'Tes dalam jumlah kecil untuk validasi demand.':'Belum perlu dijadikan stok awal.'));
            $summary[$tier]++;if($tier==='start'){$summary['openingPacks']+=$openingQty;$summary['openingSheets']+=$openingSheets;}
        }unset($item);
        $topPicks=array_slice($items,0,5);
        if($query!=='')$items=array_values(array_filter($items,fn($item)=>str_contains(mb_strtolower($item['sku'].' '.$item['parentSku'].' '.$item['productName'].' '.$item['variationName']),$query)));
        if(in_array($priorityFilter,['start','next','trial','wait'],true))$items=array_values(array_filter($items,fn($item)=>$item['priority']===$priorityFilter));
        usort($items,function($a,$b)use($sort){
            if($sort==='sales')return[$b['sold30'],$b['recommendedQty']]<=>[$a['sold30'],$a['recommendedQty']];
            if($sort==='trend')return[$b['trend'],$b['sold30']]<=>[$a['trend'],$a['sold30']];
            if($sort==='opening')return[$b['openingQty'],$b['starterScore']]<=>[$a['openingQty'],$a['starterScore']];
            return[$b['starterScore'],$b['sold30']]<=>[$a['starterScore'],$a['sold30']];
        });
        $total=count($items);$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$items=array_slice($items,($page-1)*$size,$size);
        respond(['items'=>$items,'topPicks'=>$topPicks,'summary'=>$summary,'printers'=>$stockPrinters,'page'=>$page,'pages'=>$pages,'total'=>$total,'settings'=>['coverDays'=>$coverDays,'lookbackDays'=>90,'packSize'=>$packSize,'generatedAt'=>time(),'generatedText'=>unixText(time())]]);
    }

    if ($action === 'dashboard_analytics') {
        $today = new DateTimeImmutable('today');
        $defaultFrom = $today->modify('-13 days');
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)($_GET['from'] ?? ''))) ?: $defaultFrom;
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)($_GET['to'] ?? ''))) ?: $today;
        if ($from > $to) respond(['error'=>'Tanggal mulai tidak boleh melewati tanggal akhir.'],422);
        if ((int)$from->diff($to)->format('%a') > 365) respond(['error'=>'Rentang analitik maksimal 366 hari.'],422);
        $stmt = $mysql->prepare("SELECT order_date,marketplace,COUNT(*) total,COALESCE(SUM(item_qty),0) item_total,COALESCE(SUM(order_amount),0) revenue_total,SUM(order_amount>0) priced_orders FROM (SELECT o.order_sn,DATE(FROM_UNIXTIME(o.create_time)) order_date,CASE WHEN o.order_sn LIKE 'TIKTOK:%' THEN 'tiktok' ELSE 'shopee' END marketplace,COALESCE(SUM(op.qty),0) item_qty,CASE WHEN o.order_sn LIKE 'TIKTOK:%' THEN COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.raw_json,'$.payment.total_amount')),'') AS DECIMAL(18,2)),0) ELSE COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.raw_json,'$.total_amount')),'') AS DECIMAL(18,2)),0) END order_amount FROM orders o LEFT JOIN order_process op ON op.order_sn=o.order_sn WHERE o.create_time>=? AND o.create_time<? AND o.order_sn NOT LIKE 'MANUAL-%' AND o.order_sn NOT LIKE 'RANDOM-%' AND UPPER(o.status) NOT IN ('CANCELLED','CANCELED') GROUP BY o.order_sn,o.create_time,o.raw_json) daily_orders GROUP BY order_date,marketplace ORDER BY order_date");
        $stmt->execute([$from->getTimestamp(),$to->modify('+1 day')->getTimestamp()]);
        $counts=[];
        foreach($stmt->fetchAll() as $row)$counts[(string)$row['order_date']][(string)$row['marketplace']]=['orders'=>(int)$row['total'],'items'=>(int)$row['item_total'],'revenue'=>(float)$row['revenue_total'],'pricedOrders'=>(int)$row['priced_orders']];
        $fallbackStmt=$mysql->prepare("SELECT DATE(FROM_UNIXTIME(create_time)) order_date,CASE WHEN order_sn LIKE 'TIKTOK:%' THEN 'tiktok' ELSE 'shopee' END marketplace,raw_json FROM orders WHERE create_time>=? AND create_time<? AND order_sn NOT LIKE 'MANUAL-%' AND order_sn NOT LIKE 'RANDOM-%' AND UPPER(status) NOT IN ('CANCELLED','CANCELED')");
        $fallbackStmt->execute([$from->getTimestamp(),$to->modify('+1 day')->getTimestamp()]);
        foreach($fallbackStmt->fetchAll() as $row){$raw=json_decode((string)$row['raw_json'],true);if(!is_array($raw))continue;$marketplace=(string)$row['marketplace'];$primary=$marketplace==='tiktok'?(float)($raw['payment']['total_amount']??0):(float)($raw['total_amount']??0);if($primary>0)continue;$fallback=0.0;if($marketplace==='tiktok'){$fallback=(float)($raw['payment']['sub_total']??0);if($fallback<=0)foreach(($raw['line_items']??[]) as $line)$fallback+=(float)($line['sale_price']??0)*max(1,(int)($line['quantity']??1));}else foreach(($raw['item_list']??[]) as $line)$fallback+=(float)($line['model_discounted_price']??$line['model_original_price']??0)*max(1,(int)($line['model_quantity_purchased']??1));if($fallback<=0)continue;$key=(string)$row['order_date'];$counts[$key][$marketplace]['revenue']=(float)($counts[$key][$marketplace]['revenue']??0)+$fallback;$counts[$key][$marketplace]['pricedOrders']=(int)($counts[$key][$marketplace]['pricedOrders']??0)+1;}
        $escrowStmt=$mysql->prepare("SELECT DATE(FROM_UNIXTIME(order_create_time)) order_date,COUNT(*) orders,COALESCE(SUM(payout_amount),0) payout,COALESCE(SUM(total_marketplace_fee),0) fees FROM shopee_escrow_details WHERE order_create_time>=? AND order_create_time<? GROUP BY order_date");
        $escrowStmt->execute([$from->getTimestamp(),$to->modify('+1 day')->getTimestamp()]);$escrowCounts=[];foreach($escrowStmt->fetchAll() as $row)$escrowCounts[(string)$row['order_date']]=['orders'=>(int)$row['orders'],'payout'=>(float)$row['payout'],'fees'=>(float)$row['fees']];
        $holidays=storeHolidays($mysql);$holidayLookup=array_fill_keys($holidays,true);$rangeHolidayDays=0;
        $items=[];$shopee=0;$tiktok=0;$itemTotal=0;$revenueTotal=0.0;$pricedOrders=0;$shopeePayout=0.0;$shopeeFees=0.0;$escrowOrders=0;
        for($date=$from;$date<=$to;$date=$date->modify('+1 day')){$key=$date->format('Y-m-d');$isHoliday=isset($holidayLookup[$key]);if($isHoliday)$rangeHolidayDays++;$dayShopee=(int)($counts[$key]['shopee']['orders']??0);$dayTiktok=(int)($counts[$key]['tiktok']['orders']??0);$dayItems=(int)($counts[$key]['shopee']['items']??0)+(int)($counts[$key]['tiktok']['items']??0);$dayShopeeRevenue=(float)($counts[$key]['shopee']['revenue']??0);$dayTiktokRevenue=(float)($counts[$key]['tiktok']['revenue']??0);$dayRevenue=$dayShopeeRevenue+$dayTiktokRevenue;$dayPriced=(int)($counts[$key]['shopee']['pricedOrders']??0)+(int)($counts[$key]['tiktok']['pricedOrders']??0);$dayPayout=(float)($escrowCounts[$key]['payout']??0);$dayFees=(float)($escrowCounts[$key]['fees']??0);$dayEscrowOrders=(int)($escrowCounts[$key]['orders']??0);$shopee+=$dayShopee;$tiktok+=$dayTiktok;$itemTotal+=$dayItems;$revenueTotal+=$dayRevenue;$pricedOrders+=$dayPriced;$shopeePayout+=$dayPayout;$shopeeFees+=$dayFees;$escrowOrders+=$dayEscrowOrders;$items[]=['date'=>$key,'label'=>$date->format('d M'),'isHoliday'=>$isHoliday,'shopee'=>$dayShopee,'tiktok'=>$dayTiktok,'total'=>$dayShopee+$dayTiktok,'items'=>$dayItems,'revenue'=>$dayRevenue,'shopeeRevenue'=>$dayShopeeRevenue,'tiktokRevenue'=>$dayTiktokRevenue,'shopeePayout'=>$dayPayout,'shopeeFees'=>$dayFees,'escrowOrders'=>$dayEscrowOrders,'pricedOrders'=>$dayPriced];}
        $orderTotal=$shopee+$tiktok;
        $rangeDays=(int)$from->diff($to)->format('%a')+1;
        $operatingDays=$rangeDays-$rangeHolidayDays;
        respond(['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'holidays'=>$holidays,'items'=>$items,'summary'=>['shopee'=>$shopee,'tiktok'=>$tiktok,'total'=>$orderTotal,'rangeDays'=>$rangeDays,'holidayDays'=>$rangeHolidayDays,'operatingDays'=>$operatingDays,'ordersPerDay'=>$operatingDays>0?round($orderTotal/$operatingDays,2):0,'items'=>$itemTotal,'itemsPerDay'=>$operatingDays>0?round($itemTotal/$operatingDays,2):0,'itemsPerOrder'=>$orderTotal>0?round($itemTotal/$orderTotal,2):0,'revenue'=>$revenueTotal,'pricedOrders'=>$pricedOrders,'shopeePayout'=>$shopeePayout,'shopeeFees'=>$shopeeFees,'escrowOrders'=>$escrowOrders]]);
    }

    if ($action === 'orders') {
        $q = trim((string)($_GET['q'] ?? '')); $filter = $_GET['filter'] ?? 'all'; $paperFilter=strtolower(trim((string)($_GET['paper']??'all')));if(!in_array($paperFilter,['all','a5_6','a5_20','b5'],true))$paperFilter='all';$page=max(1,(int)($_GET['page']??1)); $size=30; $offset=($page-1)*$size;
        $where=[]; $params=[];
        if ($q!=='') { $where[]='(o.order_sn LIKE ? OR o.buyer_username LIKE ? OR r.tracking_number LIKE ? OR EXISTS(SELECT 1 FROM order_process op WHERE op.order_sn=o.order_sn AND (op.item_name LIKE ? OR op.model_name LIKE ? OR op.model_sku LIKE ? OR op.item_sku LIKE ?)))'; $term="%{$q}%"; $params=array_fill(0,7,$term); }
        if($paperFilter!=='all'&&in_array($filter,['unprinted','printed'],true))$where[]="UPPER(o.status)<>'CANCELLED'";
        if ($paperFilter==='all'&&$filter==='unprinted') $where[]="UPPER(o.status)<>'CANCELLED' AND o.unprinted_lines>0";
        if ($paperFilter==='all'&&$filter==='printed') $where[]="UPPER(o.status)<>'CANCELLED' AND o.print_line_count>0 AND o.unprinted_lines=0";
        $sqlWhere=$where?'WHERE '.implode(' AND ',$where):'';
        $orderBy=$filter==='printed'?'COALESCE(o.last_printed_at,0) DESC,o.create_time DESC':'o.create_time DESC';
        $orderSelect="SELECT o.order_sn,o.status,o.create_time,o.buyer_username,o.packaged,IFNULL(r.tracking_number,'') tracking_number,IFNULL(r.pdf_path,'') label_pdf_path,IFNULL(r.resi_printed,0) resi_printed,IFNULL(lf.status,'') label_fetch_status,IFNULL(lf.message,'') label_fetch_message,IFNULL(lf.error,'') label_fetch_error,IFNULL(lf.attempts,0) label_fetch_attempts,o.last_printed_at printed_at,CASE WHEN o.order_sn LIKE 'TIKTOK:%' THEN COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.raw_json,'$.buyer_message')),'null'),'') ELSE COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.raw_json,'$.message_to_seller')),'null'),'') END customer_note,o.print_line_count line_count,o.print_item_qty item_qty,o.unprinted_lines FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn LEFT JOIN label_fetch_jobs lf ON lf.order_sn=o.order_sn {$sqlWhere} ORDER BY {$orderBy}";
        if($paperFilter==='all'){
            $count=$mysql->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn {$sqlWhere}");$count->execute($params);$total=(int)$count->fetchColumn();
            $pageOrderBy=$filter==='printed'?"COALESCE(o.last_printed_at,0) DESC,o.create_time DESC":"o.create_time DESC";
            $pageStmt=$mysql->prepare("SELECT o.order_sn FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn {$sqlWhere} ORDER BY {$pageOrderBy} LIMIT {$size} OFFSET {$offset}");$pageStmt->execute($params);$pageOrderSns=$pageStmt->fetchAll(PDO::FETCH_COLUMN);
            if($pageOrderSns){$pageMarks=implode(',',array_fill(0,count($pageOrderSns),'?'));$detailWhere="WHERE o.order_sn IN ({$pageMarks})";$detailSelect=str_replace(" {$sqlWhere} ORDER BY"," {$detailWhere} ORDER BY",$orderSelect);$stmt=$mysql->prepare($detailSelect);$stmt->execute($pageOrderSns);$items=$stmt->fetchAll();$itemOrder=array_flip($pageOrderSns);usort($items,static fn(array $a,array $b):int=>$itemOrder[(string)$a['order_sn']]<=>$itemOrder[(string)$b['order_sn']]);}else $items=[];
        }
        else{
            $stmt=$mysql->prepare($orderSelect);$stmt->execute($params);$candidates=$stmt->fetchAll();$matchingByOrder=[];
            if($candidates){
                $norm=static fn(string $value):string=>strtolower(str_replace(' ','',trim($value)));$mappingPaper=[];
                foreach($mysql->query('SELECT id,sku_id,paper FROM data_mappings')->fetchAll() as $mapping){$key=$norm((string)$mapping['sku_id']);if($key!=='')$mappingPaper[$key]=strtoupper((string)$mapping['paper']);}
                foreach($mysql->query('SELECT a.alias_key,m.paper FROM mapping_aliases a JOIN data_mappings m ON m.id=a.mapping_id')->fetchAll() as $alias)$mappingPaper[$norm((string)$alias['alias_key'])]=strtoupper((string)$alias['paper']);
                $orderSns=array_column($candidates,'order_sn');$marks=implode(',',array_fill(0,count($orderSns),'?'));$linesStmt=$mysql->prepare("SELECT id,order_sn,item_key,model_sku,item_sku,item_name,model_name,qty,printed FROM order_process WHERE order_sn IN ({$marks}) ORDER BY id");$linesStmt->execute($orderSns);
                foreach($linesStmt->fetchAll() as $line){$paper='DEFAULT';if(preg_match('/^RANDOMPDF:\d+:(A5|B5)$/i',trim((string)$line['item_key']),$match))$paper=strtoupper($match[1]);else{$keys=array_values(array_unique(array_filter([$norm((string)$line['item_key']),$norm((string)$line['model_sku'].(string)$line['item_sku']),$norm((string)$line['item_sku'].(string)$line['model_sku']),$norm((string)$line['model_sku']),$norm((string)$line['item_sku'])])));foreach($keys as $key)if(isset($mappingPaper[$key])){$paper=$mappingPaper[$key];break;}}$six=preg_match('/(?:^|\D)6\s*(?:lubang|hole)(?:\D|$)/i',(string)$line['item_name'].' '.(string)$line['model_name'])===1;$category=$paper==='B5'?'b5':($paper==='A5'?($six?'a5_6':'a5_20'):'');$statusMatches=$filter==='unprinted'?!$line['printed']:($filter==='printed'?(bool)$line['printed']:true);if($category===$paperFilter&&$statusMatches)$matchingByOrder[(string)$line['order_sn']][(int)$line['id']]=['qty'=>(int)$line['qty'],'printed'=>(bool)$line['printed']];}
            }
            $matching=[];foreach($candidates as $row)if(isset($matchingByOrder[(string)$row['order_sn']]))$matching[]=$row;$total=count($matching);$items=array_slice($matching,$offset,$size);
            foreach($items as &$row){$matchedLines=$matchingByOrder[(string)$row['order_sn']];$row['item_ids']=array_map('intval',array_keys($matchedLines));$row['line_count']=count($matchedLines);$row['item_qty']=array_sum(array_column($matchedLines,'qty'));$row['unprinted_lines']=count(array_filter($matchedLines,fn($line)=>!$line['printed']));}unset($row);
        }
        $holidayLookup=array_fill_keys(storeHolidays($mysql),true);
        foreach($items as &$row){$deadline=shippingDeadline($row['create_time'],$holidayLookup);$row['createdText']=unixText($row['create_time']);$row['shipping_deadline']=$deadline['date'];$row['shipping_due_today']=$deadline['dueToday']&&!str_starts_with((string)$row['order_sn'],'MANUAL-')&&!str_starts_with((string)$row['order_sn'],'RANDOM-')&&!in_array(strtoupper((string)$row['status']),['CANCELLED','CANCELED'],true);$row['packaged']=(bool)$row['packaged'];$row['has_label_pdf']=$row['label_pdf_path']!==''&&is_file($row['label_pdf_path']);$row['resi_printed']=(bool)$row['resi_printed'];unset($row['label_pdf_path']);}
        respond(['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'printers'=>$printing->configuredPrinters(),'labelPrinters'=>$printing->labelPrinters(),'defaultLabelPrinter'=>$printing->defaultLabelPrinter()]);
    }

    if ($action === 'order_items') {
        $input=body();$requested=is_array($input['orders']??null)?$input['orders']:[];if(count($requested)>50)respond(['error'=>'Maksimal 50 order per permintaan.'],422);
        $orders=[];foreach($requested as $entry){if(!is_array($entry))continue;$sn=trim((string)($entry['order_sn']??''));if($sn==='')continue;$ids=array_key_exists('item_ids',$entry)&&is_array($entry['item_ids'])?array_values(array_unique(array_filter(array_map('intval',$entry['item_ids']),fn($id)=>$id>0))):null;$orders[$sn]=$ids;}
        $items=$printing->listOrderItems(array_keys($orders));foreach($orders as $sn=>$ids)if($ids!==null){$allowed=array_fill_keys($ids,true);$items[$sn]=array_values(array_filter($items[$sn]??[],fn($line)=>isset($allowed[(int)$line['id']])));}respond(['items'=>$items]);
    }

    if ($action === 'order_detail') {
        $sn=(string)($_GET['order_sn']??'');
        $stmt=$mysql->prepare("SELECT * FROM orders WHERE order_sn=?");$stmt->execute([$sn]);$order=$stmt->fetch(); if(!$order)respond(['error'=>'Order tidak ditemukan.'],404);
        $stmt=$mysql->prepare("SELECT op.*,COALESCE(i.qty,0) inventory_qty FROM order_process op LEFT JOIN product_inventory i ON i.item_key=op.item_key WHERE op.order_sn=? ORDER BY op.id");$stmt->execute([$sn]);$lines=$stmt->fetchAll();$preview=$printing->previewOrder($sn);$byId=[];foreach($preview as $p)$byId[(string)$p['line']['id']]=$p;foreach($lines as &$line){$p=$byId[(string)$line['id']]??null;$mapping=$p['mapping']??null;$line['mapping_sku_id']=$mapping['sku_id']??$line['item_key'];$line['mapping_sku_inti']=$mapping['parent_sku']??$line['item_sku'];$line['print_ready']=$p['ready']??false;$line['print_reason']=$p['reason']??'Mapping tidak ditemukan';$line['file_name']=$p['file_name']??'';$line['default_printer']=$p['default_printer']??'';$line['printer_available']=$p['printer_available']??false;$line['print_options']=$p['print_options']??['page_from'=>1,'page_to'=>0,'parity'=>'all','duplex'=>'simplex','paper'=>'DEFAULT','copies'=>max(1,(int)$line['qty'])];}
        $order['createdText']=unixText($order['create_time']); $order['packaged']=(bool)$order['packaged']; respond(['order'=>$order,'lines'=>$lines,'printers'=>$printing->configuredPrinters()]);
    }

    if ($action === 'print_preview') { $sn=(string)($_GET['order_sn']??'');$items=$printing->previewOrder($sn);respond(['items'=>$items,'ready'=>count(array_filter($items,fn($x)=>$x['ready']&&(int)$x['line']['printed']===0)),'blocked'=>count(array_filter($items,fn($x)=>!$x['ready']))]); }
    if ($action === 'print_order') { $input=body();respond($printing->queueOrder(trim((string)($input['order_sn']??'')),(string)$_SESSION['paperbell_user'],is_array($input['printers']??null)?$input['printers']:[],is_array($input['settings']??null)?$input['settings']:[])); }
    if ($action === 'print_order_item') { $input=body();respond($printing->queueOrderItem(trim((string)($input['order_sn']??'')),(int)($input['line_id']??0),trim((string)($input['printer']??'')),(string)$_SESSION['paperbell_user'],is_array($input['settings']??null)?$input['settings']:[])); }
    if ($action === 'create_manual_order') {
        $input=body();$requested=is_array($input['items']??null)?$input['items']:[];if(!$requested)respond(['error'=>'Pilih minimal satu produk dari Data Mapping.'],422);
        $quantities=[];foreach($requested as $item){$mappingId=(int)($item['mapping_id']??0);$qty=(int)($item['qty']??0);if($mappingId<=0||$qty<1||$qty>999)respond(['error'=>'Produk atau quantity order manual tidak valid.'],422);$quantities[$mappingId]=min(999,($quantities[$mappingId]??0)+$qty);}
        $ids=array_keys($quantities);$marks=implode(',',array_fill(0,count($ids),'?'));$stmt=$mysql->prepare("SELECT * FROM data_mappings WHERE id IN ({$marks})");$stmt->execute($ids);$mappings=[];foreach($stmt->fetchAll() as $mapping)$mappings[(int)$mapping['id']]=$mapping;if(count($mappings)!==count($ids))respond(['error'=>'Sebagian Data Mapping sudah berubah. Silakan pilih ulang.'],422);
        foreach($mappings as $mapping)if(!is_file((string)$mapping['file_path'])||strtolower(pathinfo((string)$mapping['file_path'],PATHINFO_EXTENSION))!=='pdf')respond(['error'=>'File PDF mapping tidak ditemukan: '.((string)$mapping['sku_id']?:basename((string)$mapping['file_path']))],422);
        $now=time();$orderSn='MANUAL-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));$note=trim((string)($input['note']??''));if(mb_strlen($note)>500)$note=mb_substr($note,0,500);$user=(string)$_SESSION['paperbell_user'];
        $mysql->beginTransaction();try{$order=$mysql->prepare('INSERT INTO orders(order_sn,status,create_time,update_time,buyer_username,raw_json) VALUES(?,?,?,?,?,?)');$order->execute([$orderSn,'PROCESSED',$now,$now,'Order manual',json_encode(['source'=>'manual','created_by'=>$user,'message_to_seller'=>$note],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$line=$mysql->prepare('INSERT INTO order_process(order_sn,order_item_id,item_key,model_sku,item_sku,item_name,model_name,qty,status,create_time,saved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$position=0;foreach($ids as $mappingId){$mapping=$mappings[$mappingId];$line->execute([$orderSn,'manual-'.$mappingId.'-'.(++$position),(string)$mapping['sku_id'],(string)$mapping['sku_id'],(string)$mapping['parent_sku'],(string)$mapping['product_name'],(string)$mapping['variation_name'],$quantities[$mappingId],'PROCESSED',$now,$now]);}refreshOrderPrintSummary($mysql,$orderSn);$mysql->commit();}catch(Throwable $e){if($mysql->inTransaction())$mysql->rollBack();throw$e;}
        respond(['ok'=>true,'order_sn'=>$orderSn,'items'=>count($ids)]);
    }
    if ($action === 'print_label') { $input=body();$id=$printing->queueLabel(trim((string)($input['order_sn']??'')),trim((string)($input['printer']??'')),(string)$_SESSION['paperbell_user']);respond(['ok'=>true,'id'=>$id]); }
    if ($action === 'printer_settings') respond($printing->printerSettings(true));
    if ($action === 'save_printer_settings') { $input=body();respond(['ok'=>true,'settings'=>$printing->savePrinterSettings($input)]); }

    if ($action === 'toggle_packaged') {
        $input=body();$sn=trim((string)($input['order_sn']??'')); if($sn==='')respond(['error'=>'Order SN wajib diisi.'],422);
        $get=$mysql->prepare('SELECT packaged FROM orders WHERE order_sn=?');$get->execute([$sn]);$current=$get->fetchColumn();if($current===false)respond(['error'=>'Order tidak ditemukan.'],404);$next=(int)!((bool)$current);$now=time();$set=$mysql->prepare('UPDATE orders SET packaged=?,packaged_at=?,update_time=? WHERE order_sn=?');$set->execute([$next,$next?$now:null,$now,$sn]);respond(['ok'=>true,'packaged'=>(bool)$next]);
    }

    if ($action === 'inventory') {
        $q=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$size=30;$offset=($page-1)*$size;$where='';$params=[];
        if($q!==''){$where='WHERE item_key LIKE ? OR model_sku LIKE ? OR item_sku LIKE ? OR item_name LIKE ? OR model_name LIKE ? OR no_ref LIKE ?';$params=array_fill(0,6,"%{$q}%");}
        $count=$mysql->prepare("SELECT COUNT(*) FROM product_inventory {$where}");$count->execute($params);$total=(int)$count->fetchColumn();
        $stmt=$mysql->prepare("SELECT * FROM product_inventory {$where} ORDER BY updated_at DESC,item_name LIMIT {$size} OFFSET {$offset}");$stmt->execute($params);respond(['items'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size))]);
    }

    if ($action === 'set_inventory') {
        $input=body();$key=trim((string)($input['item_key']??''));$qty=max(0,(int)($input['qty']??0));$now=time();$mysql->beginTransaction();$get=$mysql->prepare('SELECT qty FROM product_inventory WHERE item_key=? FOR UPDATE');$get->execute([$key]);$before=$get->fetchColumn();if($before===false){$mysql->rollBack();respond(['error'=>'Item inventory tidak ditemukan.'],404);}$stmt=$mysql->prepare('UPDATE product_inventory SET qty=?,updated_at=? WHERE item_key=?');$stmt->execute([$qty,$now,$key]);$log=$mysql->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,note,created_by,created_at) VALUES(?,'adjust',?,?,?, ?,?)");$log->execute([$key,$qty-(int)$before,$qty,'Set stok dari halaman inventory',(string)$_SESSION['paperbell_user'],$now]);$mysql->commit();respond(['ok'=>true,'qty'=>$qty]);
    }

    if ($action === 'inventory_suggestions') {$q=trim((string)($_GET['q']??''));$term='%'.$q.'%';$stmt=$mysql->prepare('SELECT id,sku_id,parent_sku,product_name,variation_name,search_alias FROM data_mappings WHERE sku_id LIKE ? OR parent_sku LIKE ? OR product_name LIKE ? OR variation_name LIKE ? OR search_alias LIKE ? ORDER BY search_alias,product_name LIMIT 30');$stmt->execute(array_fill(0,5,$term));respond(['items'=>$stmt->fetchAll()]);}
    if ($action === 'inventory_order_lookup') {
        $code=trim((string)($_GET['code']??''));
        if($code==='')respond(['error'=>'Barcode atau nomor order wajib diisi.'],422);
        if(strlen($code)>500)respond(['error'=>'Isi barcode terlalu panjang.'],422);
        $candidates=[$code,preg_replace('/\s+/','',$code)];
        preg_match_all('/[A-Za-z0-9:_-]{8,80}/',$code,$matches);
        foreach($matches[0]??[] as $candidate)$candidates[]=$candidate;
        $candidates=array_values(array_unique(array_filter(array_map('trim',$candidates))));
        $clauses=[];$params=[];
        foreach($candidates as $candidate){
            $clauses[]='r.tracking_number=?';$params[]=$candidate;
            $clauses[]='o.order_sn=?';$params[]=$candidate;
            $clauses[]='o.raw_json LIKE ?';$params[]='%"'.addcslashes($candidate,'\\%_').'"%';
        }
        $sql='SELECT o.order_sn,o.status,o.buyer_username,o.create_time,COALESCE(r.tracking_number,\'\') tracking_number,COUNT(op.id) line_count,COALESCE(SUM(op.qty),0) item_qty FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn LEFT JOIN order_process op ON op.order_sn=o.order_sn WHERE '.implode(' OR ',$clauses).' GROUP BY o.order_sn ORDER BY (r.tracking_number=?) DESC,(o.order_sn=?) DESC,o.create_time DESC LIMIT 5';
        $params[]=$code;$params[]=$code;
        $stmt=$mysql->prepare($sql);$stmt->execute($params);$items=$stmt->fetchAll();
        $linesByOrder=[];
        if($items){
            $orderSns=array_column($items,'order_sn');
            $lineStmt=$mysql->prepare('SELECT id,order_sn,item_key,model_sku,item_sku,item_name,model_name,qty FROM order_process WHERE qty>0 AND order_sn IN ('.implode(',',array_fill(0,count($orderSns),'?')).') ORDER BY id');
            $lineStmt->execute($orderSns);
            foreach($lineStmt->fetchAll() as $line)$linesByOrder[$line['order_sn']][]=$line;
        }
        foreach($items as &$item){
            $item['createdText']=unixText((int)$item['create_time']);
            $item['lines']=$linesByOrder[$item['order_sn']]??[];
            $previewByLine=[];
            foreach($printing->previewOrder((string)$item['order_sn']) as $preview)$previewByLine[(int)$preview['line']['id']]=$preview;
            foreach($item['lines'] as &$line){
                $preview=$previewByLine[(int)$line['id']]??null;
                $line['has_pdf']=(bool)($preview['ready']??false);
                $line['file_name']=(string)($preview['file_name']??'');
                $line['pdf_reason']=(string)($preview['reason']??'Mapping PDF tidak ditemukan');
            }
            unset($line);
        }
        unset($item);
        respond(['items'=>$items,'code'=>$code]);
    }
    if ($action === 'inventory_add') {$input=body();$mappingId=(int)($input['mapping_id']??0);$qty=max(1,(int)($input['qty']??1));$stmt=$mysql->prepare('SELECT * FROM data_mappings WHERE id=?');$stmt->execute([$mappingId]);$map=$stmt->fetch();if(!$map)respond(['error'=>'Data Mapping tidak ditemukan.'],404);$key=trim((string)$map['sku_id']);if($key==='')respond(['error'=>'SKU ID mapping kosong.'],422);$now=time();$mysql->beginTransaction();$up=$mysql->prepare('INSERT INTO product_inventory(item_key,model_sku,item_sku,item_name,model_name,no_ref,sku_induk,qty,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE model_sku=VALUES(model_sku),item_sku=VALUES(item_sku),item_name=VALUES(item_name),model_name=VALUES(model_name),no_ref=VALUES(no_ref),sku_induk=VALUES(sku_induk),qty=qty+VALUES(qty),updated_at=VALUES(updated_at)');$up->execute([$key,$map['sku_id'],$map['parent_sku'],$map['search_alias']?:$map['product_name'],$map['variation']?:$map['variation_name'],$map['sku_id'],$map['parent_sku'],$qty,$now]);$afterStmt=$mysql->prepare('SELECT qty FROM product_inventory WHERE item_key=?');$afterStmt->execute([$key]);$after=(int)$afterStmt->fetchColumn();$log=$mysql->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,note,created_by,created_at) VALUES(?,'add',?,?,?, ?,?)");$log->execute([$key,$qty,$after,'Tambah manual dari Data Mapping',(string)$_SESSION['paperbell_user'],$now]);$mysql->commit();respond(['ok'=>true,'qty'=>$after]);}
    if ($action === 'inventory_add_order') {$input=body();$sn=trim((string)($input['order_sn']??''));if($sn==='')respond(['error'=>'Nomor order wajib diisi.'],422);$stmt=$mysql->prepare('SELECT * FROM order_process WHERE order_sn=? AND qty>0');$stmt->execute([$sn]);$lines=$stmt->fetchAll();if(!$lines)respond(['error'=>'Order tidak ditemukan atau tidak memiliki item.'],404);$now=time();$mysql->beginTransaction();$up=$mysql->prepare('INSERT INTO product_inventory(item_key,model_sku,item_sku,item_name,model_name,no_ref,sku_induk,qty,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE model_sku=VALUES(model_sku),item_sku=VALUES(item_sku),item_name=VALUES(item_name),model_name=VALUES(model_name),qty=qty+VALUES(qty),updated_at=VALUES(updated_at)');$get=$mysql->prepare('SELECT qty FROM product_inventory WHERE item_key=?');$log=$mysql->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,order_sn,note,created_by,created_at) VALUES(?,'return',?,?,?,?,?,?)");$added=0;foreach($lines as $line){$key=trim((string)$line['item_key']);if($key==='')continue;$up->execute([$key,$line['model_sku'],$line['item_sku'],$line['item_name'],$line['model_name'],$line['model_sku'],$line['item_sku'],(int)$line['qty'],$now]);$get->execute([$key]);$after=(int)$get->fetchColumn();$log->execute([$key,(int)$line['qty'],$after,$sn,'Tambah inventory dari order',(string)$_SESSION['paperbell_user'],$now]);$added++;}$mysql->commit();respond(['ok'=>true,'lines'=>$added]);}
    if ($action === 'inventory_delete') {$input=body();$key=trim((string)($input['item_key']??''));$mysql->beginTransaction();$get=$mysql->prepare('SELECT qty FROM product_inventory WHERE item_key=? FOR UPDATE');$get->execute([$key]);$qty=$get->fetchColumn();if($qty===false){$mysql->rollBack();respond(['error'=>'Item inventory tidak ditemukan.'],404);}$del=$mysql->prepare('DELETE FROM product_inventory WHERE item_key=?');$del->execute([$key]);$log=$mysql->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,note,created_by,created_at) VALUES(?,'delete',?,0,'Hapus item inventory',?,?)");$log->execute([$key,-(int)$qty,(string)$_SESSION['paperbell_user'],time()]);$mysql->commit();respond(['ok'=>true]);}
    if ($action === 'inventory_use') {$input=body();$lineId=(int)($input['line_id']??0);$mysql->beginTransaction();$stmt=$mysql->prepare('SELECT * FROM order_process WHERE id=? FOR UPDATE');$stmt->execute([$lineId]);$line=$stmt->fetch();if(!$line){$mysql->rollBack();respond(['error'=>'Item order tidak ditemukan.'],404);}if((int)$line['printed']===1){$mysql->rollBack();respond(['error'=>'Item order sudah tercetak.'],422);}$keys=[trim((string)$line['item_key'])];foreach($printing->previewOrder((string)$line['order_sn']) as $preview)if((int)$preview['line']['id']===$lineId&&isset($preview['mapping']['sku_id']))$keys[]=trim((string)$preview['mapping']['sku_id']);$keys=array_values(array_unique(array_filter($keys)));$inv=$mysql->prepare('SELECT item_key,qty FROM product_inventory WHERE item_key=? FOR UPDATE');$stock=false;$inventoryKey='';foreach($keys as $candidate){$inv->execute([$candidate]);if($found=$inv->fetch()){$inventoryKey=(string)$found['item_key'];$stock=(int)$found['qty'];break;}}$need=max(1,(int)$line['qty']);if($stock===false||(int)$stock<$need){$mysql->rollBack();respond(['error'=>"Stok inventory tidak cukup. Dibutuhkan {$need}, tersedia ".(int)$stock.'.'],422);}$after=(int)$stock-$need;$upd=$mysql->prepare('UPDATE product_inventory SET qty=?,updated_at=? WHERE item_key=?');$upd->execute([$after,time(),$inventoryKey]);$mark=$mysql->prepare('UPDATE order_process SET printed=1,printed_odd=1,printed_even=1,printed_at=? WHERE id=?');$mark->execute([time(),$lineId]);refreshOrderPrintSummary($mysql,(string)$line['order_sn']);$log=$mysql->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,order_sn,note,created_by,created_at) VALUES(?,'consume',?,?,?,?,?,?)");$log->execute([$inventoryKey,-$need,$after,$line['order_sn'],'Dipakai untuk memenuhi item order',(string)$_SESSION['paperbell_user'],time()]);$mysql->commit();respond(['ok'=>true,'remaining'=>$after,'order_sn'=>$line['order_sn']]);}
    if ($action === 'inventory_history') {$key=trim((string)($_GET['item_key']??''));$stmt=$mysql->prepare('SELECT * FROM inventory_movements WHERE item_key=? ORDER BY id DESC LIMIT 100');$stmt->execute([$key]);$rows=$stmt->fetchAll();foreach($rows as &$row)$row['createdText']=unixText($row['created_at']);respond(['items'=>$rows]);}

    if ($action === 'customer_history') {$buyer=trim((string)($_GET['buyer']??''));if($buyer==='')respond(['error'=>'Nama customer wajib diisi.'],422);$since=strtotime('-1 year');$stmt=$mysql->prepare('SELECT o.order_sn,o.create_time,op.item_name,op.model_name,op.qty FROM orders o JOIN order_process op ON op.order_sn=o.order_sn WHERE o.buyer_username=? AND o.create_time>=? ORDER BY o.create_time DESC,op.id');$stmt->execute([$buyer,$since]);$rows=$stmt->fetchAll();$orders=[];$totalQty=0;foreach($rows as &$row){$row['createdText']=unixText($row['create_time']);$orders[$row['order_sn']]=true;$totalQty+=(int)$row['qty'];}respond(['buyer'=>$buyer,'summary'=>['orders'=>count($orders),'lines'=>count($rows),'qty'=>$totalQty,'period'=>'1 tahun terakhir'],'items'=>$rows]);}

    if ($action === 'labels') {
        $q=trim((string)($_GET['q']??''));$filter=$_GET['filter']??'all';$page=max(1,(int)($_GET['page']??1));$size=30;$offset=($page-1)*$size;$where=["o.order_sn NOT LIKE 'MANUAL-%'","o.order_sn NOT LIKE 'RANDOM-%'"];$params=[];
        if($q!==''){$where[]='(o.order_sn LIKE ? OR r.tracking_number LIKE ?)';$params[]="%{$q}%";$params[]="%{$q}%";}if($filter==='unprinted')$where[]="IFNULL(r.resi_printed,0)=0 AND ((o.order_sn LIKE 'TIKTOK:%' AND UPPER(o.status) IN ('AWAITING_SHIPMENT','AWAITING_COLLECTION')) OR (o.order_sn NOT LIKE 'TIKTOK:%' AND UPPER(o.status) IN ('PROCESSED','READY_TO_SHIP')))";if($filter==='printed')$where[]='r.resi_printed=1';if($filter==='cancelled')$where[]="UPPER(o.status)='CANCELLED'";$sqlWhere=$where?'WHERE '.implode(' AND ',$where):'';
        $count=$mysql->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn {$sqlWhere}");$count->execute($params);$total=(int)$count->fetchColumn();
        $labelOrderBy=$filter==='printed'?'COALESCE(r.resi_printed_at,0) DESC,o.create_time DESC':'o.create_time DESC';
        $stmt=$mysql->prepare("SELECT o.order_sn,o.status,o.create_time,COALESCE(oq.item_qty,0) item_qty,IFNULL(r.pdf_path,'') pdf_path,IFNULL(r.tracking_number,'') tracking_number,IFNULL(r.resi_printed,0) resi_printed,r.resi_printed_at,IFNULL(lf.status,'') label_fetch_status,IFNULL(lf.message,'') label_fetch_message,IFNULL(lf.error,'') label_fetch_error,IFNULL(lf.attempts,0) label_fetch_attempts FROM orders o LEFT JOIN order_resi r ON r.order_sn=o.order_sn LEFT JOIN label_fetch_jobs lf ON lf.order_sn=o.order_sn LEFT JOIN (SELECT order_sn,COALESCE(SUM(qty),0) item_qty FROM order_process WHERE qty>0 AND UPPER(TRIM(status)) NOT IN ('CANCELLED','CANCELED') GROUP BY order_sn) oq ON oq.order_sn=o.order_sn {$sqlWhere} ORDER BY {$labelOrderBy} LIMIT {$size} OFFSET {$offset}");$stmt->execute($params);$items=$stmt->fetchAll();$holidayLookup=array_fill_keys(storeHolidays($mysql),true);foreach($items as &$row){$deadline=shippingDeadline($row['create_time'],$holidayLookup);$row['createdText']=unixText($row['create_time']);$row['shipping_deadline']=$deadline['date'];$row['shipping_due_today']=$deadline['dueToday']&&!in_array(strtoupper((string)$row['status']),['CANCELLED','CANCELED'],true);$row['item_qty']=(int)$row['item_qty'];$row['hasPdf']=$row['pdf_path']!==''&&is_file($row['pdf_path']);unset($row['pdf_path']);$row['resi_printed']=(bool)$row['resi_printed'];}respond(['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'printers'=>$printing->labelPrinters(),'defaultPrinter'=>$printing->defaultLabelPrinter()]);
    }

    if ($action === 'command') respond(['error'=>'Bridge desktop sudah dinonaktifkan. Gunakan endpoint native web.'],410);
    if ($action === 'commands') respond($queueService()->overview());
    respond(['error'=>'Endpoint tidak ditemukan.'],404);
} catch (Throwable $e) { respond(['error'=>$e->getMessage()],500); }
