<?php
declare(strict_types=1);

final class DataMappingService
{
    public function __construct(private PDO $db, private string $root, private ?HostPathResolver $pathResolver = null) {$this->pathResolver??=new HostPathResolver();}

    public function overview(string $query = '', int $page = 1, int $size = 30): array
    {
        $page=max(1,$page);$offset=($page-1)*$size;$where='';$params=[];
        if(trim($query)!==''){$where='WHERE sku_id LIKE ? OR parent_sku LIKE ? OR product_name LIKE ? OR variation_name LIKE ? OR search_alias LIKE ?';$term='%'.trim($query).'%';$params=array_fill(0,5,$term);}
        $count=$this->db->prepare("SELECT COUNT(*) FROM data_mappings {$where}");$count->execute($params);$total=(int)$count->fetchColumn();
        $stmt=$this->db->prepare("SELECT * FROM data_mappings {$where} ORDER BY product_name,variation_name LIMIT {$size} OFFSET {$offset}");$stmt->execute($params);$items=$stmt->fetchAll();
        foreach($items as &$item){$path=$this->pathResolver->resolve((string)$item['file_path']);$item['file_exists']=is_file($path)||is_dir($path);$item['file_name']=basename($path);}
        $stats=$this->db->query("SELECT COUNT(*) total,SUM(file_path='') empty_path FROM data_mappings")->fetch();$missing=0;foreach($this->db->query('SELECT file_path FROM data_mappings')->fetchAll(PDO::FETCH_COLUMN) as $path){$path=$this->pathResolver->resolve((string)$path);if($path===''||(!is_file($path)&&!is_dir($path)))$missing++;}
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'stats'=>['total'=>(int)($stats['total']??0),'missing_files'=>$missing]];
    }

    public function save(array $input): array
    {
        $id=(int)($input['id']??0);
        $fields=['sku_id','product_name','variation_name','group_name','product_code','variant_1','variant_2','duplex','paper','file_path','parent_sku','variation','printer','search_product','search_variant','search_alias'];
        $data=[];foreach($fields as $field)$data[$field]=trim((string)($input[$field]??''));
        if($data['sku_id']===''||$data['product_name']==='')throw new InvalidArgumentException('SKU dan nama produk wajib diisi.');
        foreach(['sku_id'=>255,'parent_sku'=>255,'group_name'=>50,'product_code'=>100,'variant_1'=>100,'variant_2'=>100,'duplex'=>30,'paper'=>30,'variation'=>255,'printer'=>255,'search_product'=>255,'search_variant'=>255,'search_alias'=>255] as $field=>$limit){if(mb_strlen($data[$field])>$limit)throw new InvalidArgumentException("{$field} terlalu panjang.");}
        $from=filter_var($input['page_from']??1,FILTER_VALIDATE_INT);$to=filter_var($input['page_to']??1,FILTER_VALIDATE_INT);$copies=filter_var($input['copies']??1,FILTER_VALIDATE_INT);
        if($from===false||$from<1||$to===false||$to<0||($to!==0&&$to<$from)||$copies===false||$copies<1||$copies>999)throw new InvalidArgumentException('Aturan halaman atau jumlah salinan tidak valid.');
        $data['page_from']=$from;$data['page_to']=$to;$data['copies']=$copies;
        $this->db->beginTransaction();
        try{
            if($id>0){$check=$this->db->prepare('SELECT id FROM data_mappings WHERE id=? FOR UPDATE');$check->execute([$id]);if(!$check->fetchColumn())throw new InvalidArgumentException('Data Mapping tidak ditemukan.');}
            $duplicate=$this->db->prepare('SELECT id FROM data_mappings WHERE sku_id=? AND id<>? LIMIT 1');$duplicate->execute([$data['sku_id'],$id]);if($duplicate->fetchColumn())throw new InvalidArgumentException('SKU sudah ada di Data Mapping.');
            if($id>0){$sets=implode(',',array_map(static fn($field)=>"{$field}=?",array_keys($data)));$stmt=$this->db->prepare("UPDATE data_mappings SET {$sets} WHERE id=?");$stmt->execute([...array_values($data),$id]);}
            else{$columns=implode(',',array_keys($data));$marks=implode(',',array_fill(0,count($data),'?'));$stmt=$this->db->prepare("INSERT INTO data_mappings({$columns},imported_at) VALUES({$marks},?)");$stmt->execute([...array_values($data),time()]);$id=(int)$this->db->lastInsertId();}
            $this->db->prepare('DELETE FROM mapping_aliases WHERE mapping_id=?')->execute([$id]);
            $keys=[$data['sku_id'].$data['parent_sku'],$data['parent_sku'],$data['sku_id'],$data['variant_1'].$data['variant_2'].$data['paper'].$data['duplex'].$data['product_code'],$data['parent_sku'].$data['sku_id']];
            $alias=$this->db->prepare('INSERT IGNORE INTO mapping_aliases(alias_key,mapping_id) VALUES(?,?)');
            foreach(array_unique(array_filter(array_map([$this,'normalize'],$keys))) as $key)$alias->execute([$key,$id]);
            $this->db->commit();$this->invalidateOrderCaches();return ['ok'=>true,'id'=>$id];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function importRows(array $rows): array
    {
        if(count($rows)<2)throw new RuntimeException('Data Mapping kosong.');$headers=array_map(fn($v)=>trim((string)$v),array_shift($rows));$idx=array_flip($headers);
        foreach(['SKU ID','Nama Produk','File Path'] as $required)if(!array_key_exists($required,$idx))throw new RuntimeException("Kolom wajib {$required} tidak ditemukan.");
        $now=time();$this->db->beginTransaction();try{$this->db->exec('DELETE FROM mapping_aliases');$this->db->exec('DELETE FROM data_mappings');$insert=$this->db->prepare('INSERT INTO data_mappings(sku_id,product_name,variation_name,group_name,product_code,variant_1,variant_2,duplex,paper,page_from,page_to,copies,file_path,parent_sku,variation,printer,search_product,search_variant,search_alias,imported_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$alias=$this->db->prepare('INSERT IGNORE INTO mapping_aliases(alias_key,mapping_id) VALUES(?,?)');$count=0;$aliases=0;$missing=0;
            foreach($rows as $row){$sku=$this->value($row,$idx,'SKU ID');if($sku==='')continue;[$from,$to]=$this->pages($row[$idx['Page']??-1]??'');$parent=$this->valueAny($row,$idx,['SKU Inti','SKU Induk','Parent SKU']);$productCode=$this->value($row,$idx,'Product Code');$v1=$this->value($row,$idx,'Variant-1');$v2=$this->value($row,$idx,'Variant-2');$paper=$this->value($row,$idx,'Size');$duplex=$this->value($row,$idx,'Duplex');$path=$this->value($row,$idx,'File Path');$resolvedPath=$this->pathResolver->resolve($path);if($path===''||(!is_file($resolvedPath)&&!is_dir($resolvedPath)))$missing++;
                $insert->execute([$sku,$this->value($row,$idx,'Nama Produk'),$this->value($row,$idx,'Nama Variasi'),$this->value($row,$idx,'Group'),$productCode,$v1,$v2,$duplex,$paper,$from,$to,max(1,(int)$this->value($row,$idx,'Copies')),$path,$parent,$this->value($row,$idx,'Variasi'),$this->value($row,$idx,'Printer Name'),$this->value($row,$idx,'Search Product'),$this->value($row,$idx,'Search Variant'),$this->value($row,$idx,'Search Alias'),$now]);$mappingId=(int)$this->db->lastInsertId();$keys=[$sku.$parent,$parent,$sku,$v1.$v2.$paper.$duplex.$productCode,$parent.$sku];foreach(array_unique(array_filter(array_map([$this,'normalize'],$keys))) as $key){$alias->execute([$key,$mappingId]);$aliases+=$alias->rowCount();}$count++;}
            $this->db->commit();$this->invalidateOrderCaches();return ['count'=>$count,'aliases'=>$aliases,'missing_files'=>$missing];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function value(array $row,array $idx,string $key):string{return trim((string)($row[$idx[$key]??-1]??''));}
    private function valueAny(array $row,array $idx,array $keys):string{foreach($keys as $key){$v=$this->value($row,$idx,$key);if($v!=='')return$v;}return'';}
    private function invalidateOrderCaches():void{foreach(['order-mapping-cache.json','order-file-availability-cache.json'] as $file)@unlink($this->root.'/storage/'.$file);}
    public function normalize(string $value):string{return strtolower(str_replace(' ','',trim($value)));}
    private function pages(mixed $raw):array{if(is_numeric($raw)&&(float)$raw>500){$days=(int)$raw;$date=(new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->modify("+{$days} days");$a=(int)$date->format('n');$b=(int)$date->format('j');return[min($a,$b),max($a,$b)];}$s=trim((string)$raw);if(preg_match('/^(\d+)?\s*(?:-\s*(\d+)?)?$/',$s,$m)){$from=max(1,(int)($m[1]??1));if(!str_contains($s,'-'))return[$from,$from];return[$from,isset($m[2])&&$m[2]!==''?(int)$m[2]:0];}return[1,1];}
}
