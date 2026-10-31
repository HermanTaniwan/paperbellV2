<?php
declare(strict_types=1);

// Preview by default. --apply registers the local mapping; never submits print jobs.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);
$config = require $root.'/config.php';
foreach (['Database','HostPathResolver','DataMappingService','ShopeeSkuIdentity'] as $class) require_once $root.'/src/'.$class.'.php';
$db = Database::mysql($config['mysql']);
$resolver = new HostPathResolver($config['paths'] ?? []);
$sku = 'WHSK2L1+L2+L3A5A5';
$identity = '48659135764:326630374010';
$variation = 'Paket L1+L2+L3,A5 (20 Lubang)';
$components = [];
$find = $db->prepare('SELECT * FROM data_mappings WHERE sku_id=?');
foreach (['WHSK2L1A5A5','WHSK2L2A5A5','WHSK2L3A5A5'] as $componentSku) {
    $find->execute([$componentSku]);
    $component = $find->fetch();
    if (!$component) throw new RuntimeException('Missing component: '.$componentSku);
    $component['file_path'] = $resolver->resolve($component['file_path']);
    if (!is_file($component['file_path']) || $component['paper'] !== 'A5' || $component['duplex'] !== 'L' || (int)$component['copies'] !== 1) throw new RuntimeException('Unexpected component print settings: '.$componentSku);
    if ($components && $component['printer'] !== $components[0]['printer']) throw new RuntimeException('Component printers differ.');
    $components[] = $component;
}
$linesQuery = $db->prepare("SELECT id,order_sn,order_item_id,item_key,model_name,printed,printed_at FROM order_process WHERE order_sn NOT LIKE 'TIKTOK:%' AND order_item_id=?");
$linesQuery->execute([$identity]);
$lines = $linesQuery->fetchAll();
foreach ($lines as $line) if ($line['model_name'] !== $variation) throw new RuntimeException('Variant identity/name mismatch.');
$aliasQuery = $db->prepare('SELECT mapping_id FROM mapping_aliases WHERE alias_key=?');
$aliasQuery->execute(['shopee:'.$identity]);
$previousAlias = $aliasQuery->fetchColumn();
$find->execute([$sku]);
$existing = $find->fetch();
if ($previousAlias !== false && (int)$previousAlias !== (int)($existing['id'] ?? 0)) throw new RuntimeException('Variant is already mapped elsewhere.');
$dir = $root.'/storage/bundles';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create bundle directory.');
$request = $dir.'/hsk-l123-request.json';
file_put_contents($request, json_encode($components, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES), LOCK_EX);
$fingerprint = hash('sha256', json_encode(array_map(static fn($c) => [$c['sku_id'], hash_file('sha256', $c['file_path']), $c['page_from'], $c['page_to'], $c['copies']], $components), JSON_THROW_ON_ERROR));
$output = $dir.'/HSK2-L1-L2-L3-A5-'.substr($fingerprint, 0, 16).'.pdf';
$pipes = [];
$process = proc_open([$config['printing']['python'], $root.'/tools/build_bundle_pdf.py', $request, $output], [1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
if (!is_resource($process)) throw new RuntimeException('Cannot start PDF builder.');
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process) !== 0) throw new RuntimeException('Bundle PDF failed: '.$err);
$pdf = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
$mapping = $components[0];
unset($mapping['id']);
$mapping = array_replace($mapping, ['sku_id'=>$sku,'product_name'=>'HSK 2 Paket L1+L2+L3 A5','variation_name'=>$variation,'variant_1'=>'L1+L2+L3','parent_sku'=>'','variation'=>$variation,'file_path'=>$output,'page_from'=>1,'page_to'=>$pdf['pages'],'search_alias'=>'HSK 2 Paket L1+L2+L3 A5 20 Lubang']);
if ($existing) {
    if ($existing['product_name'] !== $mapping['product_name'] || $existing['variation_name'] !== $variation) throw new RuntimeException('Bundle SKU already used by another mapping.');
    $mapping['id'] = $existing['id'];
}
echo json_encode(['sku'=>$sku,'variant'=>$variation,'pdf'=>$pdf,'affected_lines'=>count($lines),'apply'=>in_array('--apply',$argv,true)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
if (!in_array('--apply', $argv, true)) exit;
$backup = $dir.'/hsk-l123-backup-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
if (file_put_contents($backup, json_encode(['mapping'=>$existing,'alias'=>$previousAlias,'lines'=>$lines], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Backup failed.');
$saved = (new DataMappingService($db, $root, $resolver))->save($mapping);
$db->beginTransaction();
try {
    $db->prepare('INSERT IGNORE INTO mapping_aliases(alias_key,mapping_id) VALUES(?,?)')->execute(['shopee:'.$identity, $saved['id']]);
    if (ShopeeSkuIdentity::itemKey($db, $identity, '') !== $sku) throw new RuntimeException('Identity verification failed.');
    $update = $db->prepare('UPDATE order_process SET item_key=? WHERE id=? AND item_key=?');
    foreach ($lines as $line) $update->execute([$sku,$line['id'],$line['item_key']]);
    $db->commit();
} catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
foreach (['order-mapping-cache.json','order-file-availability-cache.json'] as $file) if (is_file($root.'/storage/'.$file)) unlink($root.'/storage/'.$file);
echo json_encode(['ok'=>true,'mapping_id'=>$saved['id'],'backup'=>$backup], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
