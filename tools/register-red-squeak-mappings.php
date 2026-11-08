<?php
declare(strict_types=1);

// Preview by default. Registers local print mappings without submitting print jobs.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);
$config = require $root.'/config.php';
foreach (['Database','OAuthVault','MarketplaceOAuthService','ShopeeStockService','ShopeeSkuIdentity','HostPathResolver','PrintService'] as $class) require_once $root.'/src/'.$class.'.php';
$db = Database::mysql($config['mysql']);
$oauth = new MarketplaceOAuthService($db, new OAuthVault($config['oauth']['key_file']), $config['oauth']);
$catalog = (new ShopeeStockService($db, $oauth))->imageCatalog(47971132094);
if (($catalog['item']['item_sku'] ?? '') !== 'UPRS' || !str_contains(strtoupper($catalog['item']['item_name'] ?? ''), 'RED SQUEAK')) throw new RuntimeException('Shopee product identity changed.');
$directory = 'G:/My Drive/Paperbell/Print/Planner/Undated/Red Squeak/';
// File IDs and sizes verified against the connected Drive folder; page ranges inspected locally.
$files = [
    '3MO'=>['UP - Red Squeak 3 mo.pdf','16tzFeSYyk3797dch_huj-2QU2oNFAg5_',13095687,52],
    '6MO'=>['UP - Red Squeak 6 mo.pdf','1rIUmcekU_MkDgKKNFkw70JVeDbHgY4cL',13152909,82],
    '1YR'=>['UP - Red Squeak 1 ye.pdf','1gbeqUX4JZYIR_p2KxGZWhRUi2JwbvspH',13235548,142],
    'notes'=>['UP - Red Squeak notes.pdf','1ansGE7-9GB_TKm5vOtLk32w4ISBWQYST',4768336,8],
];
$types = [
    '3MO'=>['Planner 3 Bulan','3MO',1,52,1],
    '6MO'=>['Planner 6 Bulan','6MO',1,82,1],
    '1YR'=>['Planner 1 Tahun','1YR',1,142,1],
    'BLA'=>['Notes polos','notes',1,2,20],
    'LIN'=>['Notes line/garis','notes',3,4,20],
    'DOT'=>['Notes dots/titik','notes',5,6,20],
    'GRI'=>['Notes grid/kotak','notes',7,8,20],
];
$fingerprints = [];
foreach ($files as $key => [$name,$driveId,$size]) {
    $path = $directory.$name;
    if (!is_file($path) || filesize($path) !== $size || file_get_contents($path, false, null, 0, 5) !== '%PDF-') throw new RuntimeException('Drive PDF missing or changed: '.$name);
    $fingerprints[$key] = ['drive_id'=>$driveId,'path'=>$path,'sha256'=>hash_file('sha256',$path)];
}
$template = $db->query("SELECT * FROM data_mappings WHERE sku_id='UPAS3MOA5A5'")->fetch();
if (!$template || $template['duplex'] !== 'L' || $template['paper'] !== 'A5') throw new RuntimeException('Existing undated planner print settings unavailable.');
$find = $db->prepare('SELECT * FROM data_mappings WHERE sku_id=?');
$aliasFind = $db->prepare('SELECT * FROM mapping_aliases WHERE alias_key=?');
$plan = []; $before = []; $seen = [];
foreach ($catalog['models']['model'] ?? [] as $model) {
    $sku = (string)$model['model_sku'];
    if (!preg_match('/^(3MO|6MO|1YR|BLA|LIN|DOT|GRI)A5(H6)?$/D', $sku, $match) || isset($seen[$sku])) throw new RuntimeException('Unexpected or duplicate model SKU: '.$sku);
    $seen[$sku] = true; $type = $match[1]; $holes = empty($match[2]) ? 20 : 6;
    [$label,$fileKey,$from,$to,$copies] = $types[$type];
    $name = $label.',A5 '.$holes.' Lubang';
    if ($model['model_name'] !== $name) throw new RuntimeException('Model name/SKU mismatch: '.$sku);
    $canonical = 'UPRS'.$sku; $identity = '47971132094:'.$model['model_id'];
    $find->execute([$canonical]); $existing = $find->fetch();
    if ($existing && ($existing['product_code'] !== 'RS' || $existing['variation_name'] !== $name)) throw new RuntimeException('SKU already used by another mapping: '.$canonical);
    // Model-only SKUs and the parent SKU are shared across variants; bind only unambiguous keys.
    $keys = array_map('strtolower', [$canonical,$sku.'UPRS','shopee:'.$identity]);
    $aliases = [];
    foreach ($keys as $key) {
        $aliasFind->execute([$key]); $previous = $aliasFind->fetch();
        if ($previous && (int)$previous['mapping_id'] !== (int)($existing['id'] ?? 0)) throw new RuntimeException('Alias already mapped elsewhere: '.$key);
        $aliases[] = $previous;
    }
    $before[] = ['sku'=>$canonical,'mapping'=>$existing,'aliases'=>$aliases];
    $plan[] = ['sku_id'=>$canonical,'product_name'=>$catalog['item']['item_name'],'variation_name'=>$name,'group_name'=>'U','product_code'=>'RS','variant_1'=>$type,'variant_2'=>'A5','duplex'=>$template['duplex'],'paper'=>'A5','page_from'=>$from,'page_to'=>$to,'copies'=>$copies,'file_path'=>$directory.$files[$fileKey][0],'parent_sku'=>'','variation'=>$name,'printer'=>$template['printer'],'search_product'=>'Red Squeak','search_variant'=>$label,'search_alias'=>'Red Squeak '.$name,'keys'=>$keys,'identity'=>$identity,'model_sku'=>$sku];
}
if (count($plan) !== 14) throw new RuntimeException('Expected all 14 Shopee variants.');
$apply = in_array('--apply', $argv, true);
echo json_encode(['apply'=>$apply,'mappings'=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['sku_id','variation_name','file_path','page_from','page_to','copies','printer'])), $plan)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
if (!$apply) exit;
$backupDir = $root.'/storage/sku-repairs';
if (!is_dir($backupDir) && !mkdir($backupDir,0775,true)) throw new RuntimeException('Cannot create backup directory.');
$backup = $backupDir.'/red-squeak-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
if (file_put_contents($backup,json_encode(['mappings'=>$before,'files'=>$fingerprints],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX) === false) throw new RuntimeException('Backup failed.');
$db->beginTransaction();
try {
    $bind = $db->prepare('INSERT IGNORE INTO mapping_aliases(alias_key,mapping_id) VALUES(?,?)');
    foreach ($plan as $row) {
        $keys=$row['keys']; unset($row['keys'],$row['identity'],$row['model_sku']);
        $find->execute([$row['sku_id']]); $existing=$find->fetch();
        if ($existing) {
            $sets=implode(',',array_map(static fn($field)=>$field.'=?',array_keys($row)));
            $db->prepare('UPDATE data_mappings SET '.$sets.' WHERE id=?')->execute([...array_values($row),$existing['id']]); $id=(int)$existing['id'];
        } else {
            $row['imported_at']=time();
            $db->prepare('INSERT INTO data_mappings('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row)); $id=(int)$db->lastInsertId();
        }
        foreach ($keys as $key) {
            $bind->execute([$key,$id]); $aliasFind->execute([$key]);
            if ((int)$aliasFind->fetch()['mapping_id'] !== $id) throw new RuntimeException('Alias verification failed: '.$key);
        }
    }
    foreach ($plan as $row) if (ShopeeSkuIdentity::itemKey($db,$row['identity'],'') !== $row['sku_id']) throw new RuntimeException('Shopee identity verification failed.');
    $db->commit();
} catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
foreach (['order-mapping-cache.json','order-file-availability-cache.json'] as $file) if (is_file($root.'/storage/'.$file)) unlink($root.'/storage/'.$file);
$choices=(new PrintService($db))->mappingPdfChoices('Red Squeak');
if (count($choices) !== 14) throw new RuntimeException('App PDF choices verification failed.');
echo json_encode(['ok'=>true,'verified_app_pdf_choices'=>count($choices),'backup'=>$backup],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
