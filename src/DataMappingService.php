<?php
declare(strict_types=1);

final class DataMappingService
{
    public function __construct(private PDO $db, private array $config, private string $root) {}

    public function overview(string $query = '', int $page = 1, int $size = 30): array
    {
        $page=max(1,$page);$offset=($page-1)*$size;$where='';$params=[];
        if(trim($query)!==''){$where='WHERE sku_id LIKE ? OR parent_sku LIKE ? OR product_name LIKE ? OR variation_name LIKE ? OR search_alias LIKE ?';$term='%'.trim($query).'%';$params=array_fill(0,5,$term);}
        $count=$this->db->prepare("SELECT COUNT(*) FROM data_mappings {$where}");$count->execute($params);$total=(int)$count->fetchColumn();
        $stmt=$this->db->prepare("SELECT id,sku_id,parent_sku,product_name,variation_name,group_name,duplex,paper,page_from,page_to,copies,file_path,printer,imported_at FROM data_mappings {$where} ORDER BY product_name,variation_name LIMIT {$size} OFFSET {$offset}");$stmt->execute($params);$items=$stmt->fetchAll();
        foreach($items as &$item){$item['file_exists']=is_file((string)$item['file_path'])||is_dir((string)$item['file_path']);$item['file_name']=basename((string)$item['file_path']);unset($item['file_path']);}
        $stats=$this->db->query("SELECT COUNT(*) total,SUM(file_path='') empty_path FROM data_mappings")->fetch();$missing=0;foreach($this->db->query('SELECT file_path FROM data_mappings')->fetchAll(PDO::FETCH_COLUMN) as $path)if($path===''||(!is_file($path)&&!is_dir($path)))$missing++;
        $last=(int)($this->meta('mapping_last_sync_at')?:0);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'stats'=>['total'=>(int)($stats['total']??0),'missing_files'=>$missing,'last_sync_at'=>$last,'last_sync_source'=>$this->meta('mapping_last_sync_source')]];
    }

    public function syncFromGoogle(string $user): array
    {
        $id=trim((string)($this->config['spreadsheet_id']??''));$gid=trim((string)($this->config['gid']??'0'));
        if($id==='')throw new RuntimeException('Spreadsheet ID Data Mapping belum dikonfigurasi.');
        $url="https://docs.google.com/spreadsheets/d/{$id}/export?format=xlsx&gid=".rawurlencode($gid);
        $dir=$this->root.'/storage/imports';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Folder import tidak dapat dibuat.');
        $xlsx=$dir.'/PaperbellDataMap.sync-'.bin2hex(random_bytes(6)).'.xlsx';$json=$dir.'/PaperbellDataMap.sync-'.bin2hex(random_bytes(6)).'.json';
        try{$this->download($url,$xlsx);$this->xlsxToJson($xlsx,$json);$rows=json_decode((string)file_get_contents($json),true,512,JSON_THROW_ON_ERROR);$result=$this->importRows($rows);$stable=$dir.'/PaperbellDataMap.json';file_put_contents($stable,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$now=time();$this->setMeta('mapping_last_sync_at',(string)$now);$this->setMeta('mapping_last_sync_source','Google Sheets oleh '.$user);return $result+['ok'=>true,'synced_at'=>$now];}
        finally{if(is_file($xlsx))@unlink($xlsx);if(is_file($json))@unlink($json);}
    }

    public function importRows(array $rows): array
    {
        if(count($rows)<2)throw new RuntimeException('Data Mapping kosong.');$headers=array_map(fn($v)=>trim((string)$v),array_shift($rows));$idx=array_flip($headers);
        foreach(['SKU ID','Nama Produk','File Path'] as $required)if(!array_key_exists($required,$idx))throw new RuntimeException("Kolom wajib {$required} tidak ditemukan.");
        $now=time();$this->db->beginTransaction();try{$this->db->exec('DELETE FROM mapping_aliases');$this->db->exec('DELETE FROM data_mappings');$insert=$this->db->prepare('INSERT INTO data_mappings(sku_id,product_name,variation_name,group_name,product_code,variant_1,variant_2,duplex,paper,page_from,page_to,copies,file_path,parent_sku,variation,printer,search_product,search_variant,search_alias,imported_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$alias=$this->db->prepare('INSERT IGNORE INTO mapping_aliases(alias_key,mapping_id) VALUES(?,?)');$count=0;$aliases=0;$missing=0;
            foreach($rows as $row){$sku=$this->value($row,$idx,'SKU ID');if($sku==='')continue;[$from,$to]=$this->pages($row[$idx['Page']??-1]??'');$parent=$this->valueAny($row,$idx,['SKU Inti','SKU Induk','Parent SKU']);$productCode=$this->value($row,$idx,'Product Code');$v1=$this->value($row,$idx,'Variant-1');$v2=$this->value($row,$idx,'Variant-2');$paper=$this->value($row,$idx,'Size');$duplex=$this->value($row,$idx,'Duplex');$path=$this->value($row,$idx,'File Path');if($path===''||(!is_file($path)&&!is_dir($path)))$missing++;
                $insert->execute([$sku,$this->value($row,$idx,'Nama Produk'),$this->value($row,$idx,'Nama Variasi'),$this->value($row,$idx,'Group'),$productCode,$v1,$v2,$duplex,$paper,$from,$to,max(1,(int)$this->value($row,$idx,'Copies')),$path,$parent,$this->value($row,$idx,'Variasi'),$this->value($row,$idx,'Printer Name'),$this->value($row,$idx,'Search Product'),$this->value($row,$idx,'Search Variant'),$this->value($row,$idx,'Search Alias'),$now]);$mappingId=(int)$this->db->lastInsertId();$keys=[$sku.$parent,$parent,$sku,$v1.$v2.$paper.$duplex.$productCode,$parent.$sku];foreach(array_unique(array_filter(array_map([$this,'normalize'],$keys))) as $key){$alias->execute([$key,$mappingId]);$aliases+=$alias->rowCount();}$count++;}
            $this->db->commit();return ['count'=>$count,'aliases'=>$aliases,'missing_files'=>$missing];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function download(string $url,string $target):void{$fp=fopen($target,'wb');if(!$fp)throw new RuntimeException('Tidak dapat membuat file sementara mapping.');$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>120,CURLOPT_USERAGENT=>'PaperbellWeb/1.0']);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);fclose($fp);if(!$ok||$status<200||$status>=300||filesize($target)<100)throw new RuntimeException('Gagal mengunduh Data Mapping: '.($error?:"HTTP {$status}"));}
    private function xlsxToJson(string $xlsx,string $json):void{$python=(string)($this->config['python']??'python');$script=$this->root.'/tools/xlsx_to_json.py';$pipes=[];$process=proc_open([$python,$script,$xlsx,$json],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('Python parser Data Mapping tidak dapat dijalankan.');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!is_file($json))throw new RuntimeException('Gagal membaca XLSX Data Mapping: '.trim($err?:$out));}
    private function value(array $row,array $idx,string $key):string{return trim((string)($row[$idx[$key]??-1]??''));}
    private function valueAny(array $row,array $idx,array $keys):string{foreach($keys as $key){$v=$this->value($row,$idx,$key);if($v!=='')return$v;}return'';}
    public function normalize(string $value):string{return strtolower(str_replace(' ','',trim($value)));}
    private function pages(mixed $raw):array{if(is_numeric($raw)&&(float)$raw>500){$days=(int)$raw;$date=(new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->modify("+{$days} days");$a=(int)$date->format('n');$b=(int)$date->format('j');return[min($a,$b),max($a,$b)];}$s=trim((string)$raw);if(preg_match('/^(\d+)?\s*(?:-\s*(\d+)?)?$/',$s,$m)){$from=max(1,(int)($m[1]??1));if(!str_contains($s,'-'))return[$from,$from];return[$from,isset($m[2])&&$m[2]!==''?(int)$m[2]:0];}return[1,1];}
    private function meta(string $key):string{$stmt=$this->db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?');$stmt->execute([$key]);return(string)($stmt->fetchColumn()?:'');}
    private function setMeta(string $key,string $value):void{$stmt=$this->db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');$stmt->execute([$key,$value]);}
}
