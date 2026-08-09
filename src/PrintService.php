<?php
declare(strict_types=1);

final class PrintService
{
    private ?array $installedCache = null;
    private ?array $settingsCache = null;
    private string $installedCacheFile;

    public function __construct(private PDO $db, private string $fallbackLabelPrinter = 'EPSON L3210 Series')
    {
        $this->installedCacheFile = dirname(__DIR__) . '/storage/printer-list-cache.json';
    }

    public function installedPrinters(): array
    {
        if($this->installedCache!==null)return $this->installedCache;
        $cached=$this->readInstalledCache();
        if($cached!==null && (int)($cached['saved_at']??0)>=time()-60)
            return $this->installedCache=$cached['printers'];
        $script = "\$printers=@(Get-CimInstance Win32_Printer -ErrorAction Stop | Where-Object { -not \$_.WorkOffline -and ([int]\$_.PrinterStatus -notin 6,7) } | Select-Object -ExpandProperty Name); ConvertTo-Json -InputObject \$printers -Compress";
        $command = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command ' . escapeshellarg($script);
        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '')
            return $this->installedCache=$cached['printers']??[];
        $decoded = json_decode(trim($output), true);
        $printers = is_array($decoded) ? $decoded : [$decoded];
        $printers = array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$printers))));
        natcasesort($printers);
        $printers=array_values($printers);
        $this->writeInstalledCache($printers);
        return $this->installedCache=$printers;
    }

    private function readInstalledCache(): ?array
    {
        if(!is_file($this->installedCacheFile))return null;
        $data=json_decode((string)@file_get_contents($this->installedCacheFile),true);
        return is_array($data)&&is_array($data['printers']??null)?$data:null;
    }

    private function writeInstalledCache(array $printers): void
    {
        @file_put_contents($this->installedCacheFile,json_encode(['saved_at'=>time(),'printers'=>$printers],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
    }

    public function printerSettings(): array
    {
        if($this->settingsCache!==null)return $this->settingsCache;
        $installed=$this->installedPrinters();$saved=$this->settingRows();
        $visible=json_decode((string)($saved['visible_printers']??''),true);
        if(!is_array($visible))$visible=$installed;
        $visible=array_values(array_filter($visible,fn($p)=>in_array((string)$p,$installed,true)));
        $default=trim((string)($saved['default_label_printer']??$this->fallbackLabelPrinter));
        if(!in_array($default,$visible,true))$default=$visible[0]??'';
        $brother=trim((string)($saved['override_brother']??''));
        $l3210=trim((string)($saved['override_l3210']??''));
        if($brother!==''&&!in_array($brother,$visible,true))$brother='';
        if($l3210!==''&&!in_array($l3210,$visible,true))$l3210='';
        return $this->settingsCache=['installed'=>$installed,'visible'=>$visible,'default_label_printer'=>$default,'override_brother'=>$brother,'override_l3210'=>$l3210];
    }

    public function configuredPrinters(): array { return $this->printerSettings()['visible']; }
    public function defaultLabelPrinter(): string { return $this->printerSettings()['default_label_printer']; }
    public function labelPrinters(): array
    {
        $printers=$this->configuredPrinters();$default=$this->defaultLabelPrinter();
        if($default!==''&&in_array($default,$printers,true)){
            $printers=array_values(array_filter($printers,fn($printer)=>(string)$printer!==$default));
            array_unshift($printers,$default);
        }
        return$printers;
    }

    public function savePrinterSettings(array $input): array
    {
        $installed=$this->installedPrinters();$visible=array_values(array_unique(array_map('strval',is_array($input['visible']??null)?$input['visible']:[])));
        if(!$visible)throw new InvalidArgumentException('Pilih minimal satu printer yang ditampilkan.');
        foreach($visible as $printer)if(!in_array($printer,$installed,true))throw new InvalidArgumentException('Printer tidak terpasang di host: '.$printer);
        $default=trim((string)($input['default_label_printer']??''));$brother=trim((string)($input['override_brother']??''));$l3210=trim((string)($input['override_l3210']??''));
        if($default===''||!in_array($default,$visible,true))throw new InvalidArgumentException('Printer default label harus termasuk printer yang ditampilkan.');
        foreach([$brother,$l3210] as $override)if($override!==''&&!in_array($override,$visible,true))throw new InvalidArgumentException('Printer override harus termasuk printer yang ditampilkan.');
        $values=['visible_printers'=>json_encode($visible,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'default_label_printer'=>$default,'override_brother'=>$brother,'override_l3210'=>$l3210];
        $stmt=$this->db->prepare('INSERT INTO printer_settings(setting_key,setting_value,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)');
        $this->db->beginTransaction();try{foreach($values as $key=>$value)$stmt->execute([$key,$value,time()]);$this->db->commit();}catch(Throwable $e){$this->db->rollBack();throw $e;}
        $this->settingsCache=null;
        return $this->printerSettings();
    }

    public function previewOrder(string $orderSn): array
    {
        $stmt=$this->db->prepare("SELECT id,order_sn,item_key,model_sku,item_sku,item_name,model_name,qty,printed,printed_odd,printed_even,status FROM order_process WHERE order_sn=? ORDER BY id");$stmt->execute([$orderSn]);$items=[];
        $installed=$this->configuredPrinters();
        while($line=$stmt->fetch()){$mapping=$this->resolveMapping($line);$defaultPrinter=$mapping?$this->resolveMappedPrinter((string)$mapping['printer']):'';$printOptions=$mapping?$this->normalizePrintOptions($mapping,[]):null;if($printOptions)$printOptions['copies']=max(1,(int)$line['qty'])*(int)$printOptions['copies'];$items[]=['line'=>$line,'mapping'=>$mapping,'print_options'=>$printOptions,'ready'=>$mapping!==null&&is_file($mapping['file_path']),'reason'=>$mapping===null?'Mapping tidak ditemukan':(!is_file($mapping['file_path'])?'File PDF tidak ditemukan':'Siap'),'file_name'=>$mapping?basename((string)$mapping['file_path']):'','default_printer'=>$defaultPrinter,'printer_available'=>$defaultPrinter!==''&&in_array($defaultPrinter,$installed,true)];}
        return $items;
    }

    public function listOrderItems(array $orderSns): array
    {
        $orderSns=array_values(array_unique(array_filter(array_map('strval',$orderSns))));if(!$orderSns)return[];
        $marks=implode(',',array_fill(0,count($orderSns),'?'));
        $stmt=$this->db->prepare("SELECT id,order_sn,item_key,model_sku,item_sku,item_name,model_name,qty,printed,printed_odd,printed_even,printed_at FROM order_process WHERE order_sn IN ($marks) ORDER BY order_sn,id");$stmt->execute($orderSns);
        $mappingByKey=[];$mappingById=[];
        foreach($this->db->query('SELECT * FROM data_mappings')->fetchAll() as $mapping){$mappingById[(string)$mapping['id']]=$mapping;$key=$this->norm((string)$mapping['sku_id']);if($key!=='')$mappingByKey[$key]=$mapping;}
        foreach($this->db->query('SELECT alias_key,mapping_id FROM mapping_aliases')->fetchAll() as $alias){$mapping=$mappingById[(string)$alias['mapping_id']]??null;if($mapping)$mappingByKey[$this->norm((string)$alias['alias_key'])]=$mapping;}
        $inventory=[];
        foreach($this->db->query('SELECT item_key,qty FROM product_inventory')->fetchAll() as $stock)$inventory[$this->norm((string)$stock['item_key'])]=(int)$stock['qty'];
        $activeLineIds=[];
        $active=$this->db->query("SELECT order_process_id FROM print_jobs WHERE job_type='product' AND order_process_id IS NOT NULL AND status IN ('queued','processing')");
        foreach($active->fetchAll(PDO::FETCH_COLUMN) as $lineId)$activeLineIds[(int)$lineId]=true;
        $printers=$this->configuredPrinters();$result=[];
        while($line=$stmt->fetch()){
            $mapping=$this->specialPdfMapping((string)$line['item_key']);
            if($mapping===null)foreach($this->lineKeys($line) as $key)if(isset($mappingByKey[$key])){$mapping=$mappingByKey[$key];break;}
            $inventoryQty=null;
            $inventoryKeys=array_values(array_unique(array_filter([$this->norm((string)$line['item_key']),$this->norm((string)($mapping['sku_id']??''))])));
            foreach($inventoryKeys as $inventoryKey)if(array_key_exists($inventoryKey,$inventory)){$inventoryQty=$inventory[$inventoryKey];break;}
            $requiredQty=max(1,(int)$line['qty']);
            $ready=$mapping!==null&&is_file((string)$mapping['file_path']);$defaultPrinter=$mapping?$this->resolveMappedPrinter((string)$mapping['printer']):'';$options=$mapping?$this->normalizePrintOptions($mapping,[]):['page_from'=>1,'page_to'=>0,'parity'=>'all','duplex'=>'simplex','paper'=>'DEFAULT','copies'=>1];$options['copies']=$requiredQty*max(1,(int)$options['copies']);$result[(string)$line['order_sn']][]=['id'=>(int)$line['id'],'order_sn'=>$line['order_sn'],'item_name'=>$line['item_name'],'model_name'=>$line['model_name'],'qty'=>(int)$line['qty'],'printed'=>(bool)$line['printed'],'printed_odd'=>(bool)$line['printed_odd'],'printed_even'=>(bool)$line['printed_even'],'printed_at'=>$line['printed_at']!==null?(int)$line['printed_at']:null,'sku_id'=>$mapping['sku_id']??$line['item_key'],'sku_inti'=>$mapping['parent_sku']??$line['item_sku'],'file_name'=>$mapping?basename((string)$mapping['file_path']):'','has_pdf'=>$ready,'print_ready'=>$ready,'print_reason'=>$mapping===null?'Mapping tidak ditemukan':(!$ready?'File PDF tidak ditemukan':'Siap'),'default_printer'=>$defaultPrinter,'printer_available'=>$defaultPrinter!==''&&in_array($defaultPrinter,$printers,true),'print_options'=>$options,'inventory_qty'=>$inventoryQty??0,'has_inventory'=>$inventoryQty!==null&&$inventoryQty>=$requiredQty,'queued'=>isset($activeLineIds[(int)$line['id']])];
        }
        return$result;
    }

    public function productPdf(int $lineId): array
    {
        if($lineId<=0)throw new InvalidArgumentException('ID item tidak valid.');$stmt=$this->db->prepare('SELECT order_sn FROM order_process WHERE id=?');$stmt->execute([$lineId]);$orderSn=(string)($stmt->fetchColumn()?:'');if($orderSn==='')throw new RuntimeException('Item order tidak ditemukan.');
        foreach($this->previewOrder($orderSn) as $item)if((int)$item['line']['id']===$lineId){if(!$item['ready'])throw new RuntimeException((string)$item['reason']);return['path'=>(string)$item['mapping']['file_path'],'name'=>$item['file_name']?:'produk.pdf'];}
        throw new RuntimeException('Item order tidak ditemukan.');
    }

    public function queueOrder(string $orderSn,string $user,array $printerOverrides=[],array $optionOverrides=[]): array
    {
        $items=$this->previewOrder($orderSn);if(!$items)throw new RuntimeException('Order tidak ditemukan.');$queued=[];$blocked=[];$installed=$this->configuredPrinters();
        foreach($items as $item){$line=$item['line'];if((int)$line['printed']===1)continue;if(strtoupper(trim((string)$line['status']))==='CANCELLED'){$blocked[]=['id'=>$line['id'],'reason'=>'Order dibatalkan'];continue;}if(!$item['ready']){$blocked[]=['id'=>$line['id'],'reason'=>$item['reason']];continue;}$m=$item['mapping'];$printer=trim((string)($printerOverrides[(string)$line['id']]??$item['default_printer']));if($printer===''||!in_array($printer,$installed,true)){$blocked[]=['id'=>$line['id'],'reason'=>'Printer tidak tersedia: '.($printer?:'(kosong)')];continue;}try{$requested=is_array($optionOverrides[(string)$line['id']]??null)?$optionOverrides[(string)$line['id']]:[];$options=$this->normalizePrintOptions($m,array_replace($item['print_options']??[],$requested));$m['printer']=$printer;$copies=(int)$options['copies'];$settings=$this->productSettings($m,$copies,$options);$queued[]=$this->insertJob('product',$orderSn,(int)$line['id'],$m['file_path'],$printer,$settings,$copies,$user);}catch(InvalidArgumentException $e){$blocked[]=['id'=>$line['id'],'reason'=>$e->getMessage()];}}
        return ['queued'=>$queued,'blocked'=>$blocked];
    }

    public function queueOrderItem(string $orderSn,int $lineId,string $printer,string $user,array $requestedOptions=[]): array
    {
        $items=$this->previewOrder($orderSn);if(!$items)throw new RuntimeException('Order tidak ditemukan.');$item=null;
        foreach($items as $candidate)if((int)$candidate['line']['id']===$lineId){$item=$candidate;break;}
        if($item===null)throw new RuntimeException('Item tidak ditemukan pada order ini.');
        $line=$item['line'];if(strtoupper(trim((string)$line['status']))==='CANCELLED')throw new RuntimeException('Item pada order yang dibatalkan tidak dapat dicetak.');
        if(!$item['ready'])throw new RuntimeException((string)$item['reason']);
        $printer=trim($printer!==''?$printer:(string)$item['default_printer']);
        if($printer===''||!in_array($printer,$this->configuredPrinters(),true))throw new RuntimeException('Printer tidak tersedia atau dinonaktifkan: '.($printer?:'(kosong)'));
        $mapping=$item['mapping'];$mapping['printer']=$printer;$options=$this->normalizePrintOptions($mapping,array_replace($item['print_options']??[],$requestedOptions));$copies=(int)$options['copies'];
        $id=$this->insertJob('product',$orderSn,$lineId,(string)$mapping['file_path'],$printer,$this->productSettings($mapping,$copies,$options),$copies,$user);
        return ['ok'=>true,'id'=>$id,'copies'=>$copies,'printer'=>$printer,'options'=>$options];
    }

    public function queueLabel(string $orderSn,string $printer,string $user): int
    {
        $stmt=$this->db->prepare('SELECT pdf_path FROM order_resi WHERE order_sn=?');$stmt->execute([$orderSn]);$path=(string)($stmt->fetchColumn()?:'');if($path===''||!is_file($path))throw new RuntimeException('PDF label belum tersedia.');
        if(trim($printer)==='')$printer=$this->defaultLabelPrinter();
        if(!in_array($printer,$this->configuredPrinters(),true))throw new RuntimeException('Printer tidak tersedia atau dinonaktifkan di konfigurasi: '.$printer);
        return $this->insertJob('label',$orderSn,null,$path,$printer,$this->labelSettings($printer),1,$user);
    }

    public function queueFile(string $type,string $file,string $printer,string $user,array $input=[]): array
    {
        if(!in_array($type,['manual','random'],true))throw new InvalidArgumentException('Jenis PDF manual tidak valid.');if(!is_file($file)||strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='pdf')throw new RuntimeException('File PDF tidak ditemukan.');
        if($printer===''||!in_array($printer,$this->configuredPrinters(),true))throw new RuntimeException('Printer tidak tersedia atau dinonaktifkan.');$mapping=['page_from'=>1,'page_to'=>0,'copies'=>1,'duplex'=>'simplex','paper'=>'DEFAULT','printer'=>$printer];$options=$this->normalizePrintOptions($mapping,$input);$settings=$this->productSettings($mapping,(int)$options['copies'],$options);$id=$this->insertJob($type,'',null,$file,$printer,$settings,(int)$options['copies'],$user);return['ok'=>true,'id'=>$id,'options'=>$options];
    }

    public function mappingPdfChoices(string $query): array
    {
        $query=trim($query);$params=[];$where="file_path<>''";
        if($query!==''){$like='%'.$query.'%';$where.=' AND (sku_id LIKE ? OR parent_sku LIKE ? OR product_name LIKE ? OR variation_name LIKE ? OR search_alias LIKE ? OR file_path LIKE ?)';$params=array_fill(0,6,$like);}
        $stmt=$this->db->prepare("SELECT * FROM data_mappings WHERE {$where} ORDER BY product_name,variation_name LIMIT 100");$stmt->execute($params);$items=[];$printers=$this->configuredPrinters();
        while($mapping=$stmt->fetch()){
            $path=(string)$mapping['file_path'];if(!is_file($path)||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='pdf')continue;
            $printer=$this->resolveMappedPrinter((string)$mapping['printer']);$options=$this->normalizePrintOptions($mapping,[]);
            $items[]=['id'=>(int)$mapping['id'],'sku_id'=>(string)$mapping['sku_id'],'parent_sku'=>(string)$mapping['parent_sku'],'product_name'=>(string)$mapping['product_name'],'variation_name'=>(string)$mapping['variation_name'],'file_name'=>basename($path),'default_printer'=>$printer,'printer_available'=>$printer!==''&&in_array($printer,$printers,true),'settings'=>$options];
            if(count($items)>=30)break;
        }
        return $items;
    }

    public function queueMappingFile(int $mappingId,string $printer,string $user,array $input=[],string $jobType='manual',string $reference=''): array
    {
        if(!in_array($jobType,['manual','stock'],true))throw new InvalidArgumentException('Jenis job mapping tidak valid.');
        if($mappingId<=0)throw new InvalidArgumentException('Pilih PDF dari Data Mapping terlebih dahulu.');$stmt=$this->db->prepare('SELECT * FROM data_mappings WHERE id=?');$stmt->execute([$mappingId]);$mapping=$stmt->fetch();
        if(!$mapping)throw new RuntimeException('Data Mapping tidak ditemukan. Silakan pilih ulang setelah sinkronisasi.');$file=(string)$mapping['file_path'];if(!is_file($file)||strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='pdf')throw new RuntimeException('File PDF pada Data Mapping tidak ditemukan.');
        $printer=trim($printer!==''?$printer:$this->resolveMappedPrinter((string)$mapping['printer']));if($printer===''||!in_array($printer,$this->configuredPrinters(),true))throw new RuntimeException('Printer tidak tersedia atau dinonaktifkan.');
        $mapping['printer']=$printer;$options=$this->normalizePrintOptions($mapping,$input);$copies=(int)$options['copies'];$id=$this->insertJob($jobType,$reference,null,$file,$printer,$this->productSettings($mapping,$copies,$options),$copies,$user);
        return['ok'=>true,'id'=>$id,'copies'=>$copies,'printer'=>$printer,'options'=>$options];
    }

    private function insertJob(string $type,string $sn,?int $lineId,string $file,string $printer,string $settings,int $copies,string $user): int
    {
        $dup=$this->db->prepare("SELECT id FROM print_jobs WHERE job_type=? AND order_sn=? AND COALESCE(order_process_id,0)=COALESCE(?,0) AND file_path=? AND status IN ('queued','processing') LIMIT 1");$dup->execute([$type,$sn,$lineId,$file]);$id=$dup->fetchColumn();if($id!==false)return(int)$id;
        $stmt=$this->db->prepare("INSERT INTO print_jobs(job_type,order_sn,order_process_id,file_path,printer,print_settings,copies,status,message,error,created_by,created_at) VALUES(?,?,?,?,?,?,?,'queued','Menunggu worker printer','',?,?)");$stmt->execute([$type,$sn,$lineId,$file,$printer,$settings,$copies,$user,time()]);return(int)$this->db->lastInsertId();
    }

    private function resolveMapping(array $line): ?array
    {
        if($special=$this->specialPdfMapping((string)($line['item_key']??'')))return$special;
        $keys=$this->lineKeys($line);
        $exact=$this->db->prepare("SELECT * FROM data_mappings WHERE LOWER(REPLACE(sku_id,' ',''))=? LIMIT 1");foreach($keys as $key){$exact->execute([$key]);if($m=$exact->fetch())return$m;}
        $stmt=$this->db->prepare('SELECT m.* FROM mapping_aliases a JOIN data_mappings m ON m.id=a.mapping_id WHERE a.alias_key=? LIMIT 1');foreach($keys as $key){$stmt->execute([$key]);if($m=$stmt->fetch())return$m;}return null;
    }

    private function specialPdfMapping(string $itemKey): ?array
    {
        if(!preg_match('/^RANDOMPDF:(\d+)(?::(A5|B5))?$/i',trim($itemKey),$match))return null;$stmt=$this->db->prepare("SELECT id,file_path FROM manual_pdfs WHERE id=? AND source_type='random'");$stmt->execute([(int)$match[1]]);$document=$stmt->fetch();if(!$document)return null;
        return['id'=>0,'sku_id'=>$itemKey,'parent_sku'=>'','file_path'=>(string)$document['file_path'],'printer'=>'','page_from'=>1,'page_to'=>0,'copies'=>1,'duplex'=>'simplex','paper'=>strtoupper((string)($match[2]??'DEFAULT'))];
    }

    private function norm(string $value): string{return strtolower(str_replace(' ','',trim($value)));}
    private function lineKeys(array $line): array{return array_values(array_unique(array_filter([$this->norm((string)$line['item_key']),$this->norm((string)$line['model_sku'].(string)$line['item_sku']),$this->norm((string)$line['item_sku'].(string)$line['model_sku']),$this->norm((string)$line['model_sku']),$this->norm((string)$line['item_sku'])])));}

    private function settingRows(): array
    {
        $rows=[];foreach($this->db->query('SELECT setting_key,setting_value FROM printer_settings')->fetchAll() as $row)$rows[(string)$row['setting_key']]=$row['setting_value'];return $rows;
    }

    public function resolveMappedPrinter(string $printer): string
    {
        $settings=$this->printerSettings();$name=trim($printer);
        if(stripos($name,'brother')!==false&&$settings['override_brother']!=='')return $settings['override_brother'];
        if(stripos($name,'l3210')!==false&&$settings['override_l3210']!=='')return $settings['override_l3210'];
        return $name;
    }

    private function normalizePrintOptions(array $mapping,array $input): array
    {
        $from=(int)($input['page_from']??$mapping['page_from']??1);$to=(int)($input['page_to']??$mapping['page_to']??0);$copies=(int)($input['copies']??$mapping['copies']??1);
        $parity=strtolower(trim((string)($input['parity']??'all')));$duplex=strtolower(trim((string)($input['duplex']??$this->mappingDuplex((string)($mapping['duplex']??'')))));$paper=strtoupper(trim((string)($input['paper']??$mapping['paper']??'DEFAULT')));if($paper===''||$paper==='AUTO')$paper='DEFAULT';
        if($from<1)throw new InvalidArgumentException('Halaman awal minimal 1.');if($to<0||($to>0&&$to<$from))throw new InvalidArgumentException('Halaman akhir harus kosong/0 atau tidak kurang dari halaman awal.');if($copies<1)throw new InvalidArgumentException('Copies minimal 1.');
        if(!in_array($parity,['all','odd','even'],true))throw new InvalidArgumentException('Pilihan halaman tidak valid.');if(!in_array($duplex,['simplex','duplexlong','duplexshort'],true))throw new InvalidArgumentException('Mode duplex tidak valid.');if(!in_array($paper,['DEFAULT','A4','A5','A6','B5'],true))throw new InvalidArgumentException('Ukuran kertas tidak valid.');if($parity!=='all')$duplex='simplex';
        return ['page_from'=>$from,'page_to'=>$to,'parity'=>$parity,'duplex'=>$duplex,'paper'=>$paper,'copies'=>$copies];
    }

    private function productSettings(array $mapping,int $copies,array $options=[]): string
    {
        $o=$this->normalizePrintOptions($mapping,$options);$from=$o['page_from'];$to=$o['page_to'];$range=$to<=0?"{$from}-":($to===$from?(string)$from:"{$from}-{$to}");$parts=[$range];if($o['parity']!=='all')$parts[]=$o['parity'];$parts[]=$o['duplex'];$parts[]='noscale';$printer=strtoupper((string)$mapping['printer']);if(str_contains($printer,'WF'))$parts[]='bin=7';if(in_array($o['paper'],['A4','A5','A6'],true))$parts[]='paper='.$o['paper'];elseif($o['paper']==='B5')$parts[]=str_contains($printer,'BROTHER')?'paper=B5':'paperkind=13';if($copies>1)$parts[]="{$copies}x";return implode(',',$parts);
    }

    private function labelSettings(string $printer): string
    {
        $parts=['1-','simplex','noscale'];
        if(stripos($printer,'Brother DCP')!==false)$parts[]='bin=258';
        elseif(stripos($printer,'WF')!==false)$parts[]='bin=261';
        $parts[]='monochrome';$parts[]='paper=A6';
        return implode(',',$parts);
    }

    private function mappingDuplex(string $value): string
    {
        $value=strtolower(trim($value));
        if(in_array($value,['n','no','none','0','-','simplex'],true)||$value==='')return 'simplex';
        if(in_array($value,['l','long'],true)||str_contains($value,'longedge')||str_contains($value,'duplexlong'))return 'duplexlong';
        if(in_array($value,['s','short'],true)||str_contains($value,'shortedge')||str_contains($value,'duplexshort'))return 'duplexshort';
        if(str_contains($value,'duplex')||str_contains($value,'double'))return 'duplexlong';
        return 'simplex';
    }
}
