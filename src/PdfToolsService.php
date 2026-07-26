<?php
declare(strict_types=1);

final class PdfToolsService
{
    public function __construct(private PDO $db,private array $config,private string $root) {}

    public function listDocuments():array
    {
        $rows=$this->db->query('SELECT id,original_name,file_size,page_count,source_type,summary,created_by,created_at FROM manual_pdfs ORDER BY id DESC LIMIT 100')->fetchAll();foreach($rows as &$row){$row['createdText']=date('d M Y H:i',(int)$row['created_at']);$row['file_size_text']=$this->bytes((int)$row['file_size']);}return$rows;
    }

    public function upload(array $file,string $user):array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Upload PDF gagal.');if((int)($file['size']??0)<=0||(int)$file['size']>150*1024*1024)throw new RuntimeException('Ukuran PDF harus antara 1 byte dan 150 MB.');
        $tmp=(string)($file['tmp_name']??'');$name=trim((string)($file['name']??'dokumen.pdf'));if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf')throw new RuntimeException('Hanya file PDF yang diperbolehkan.');$header=(string)file_get_contents($tmp,false,null,0,5);if($header!=='%PDF-')throw new RuntimeException('Isi file bukan PDF yang valid.');
        $dir=$this->root.'/storage/manual-pdfs';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Folder PDF manual tidak dapat dibuat.');$safe=preg_replace('/[^A-Za-z0-9._-]+/','_',pathinfo($name,PATHINFO_FILENAME))?:'document';$target=$dir.'/'.date('Ymd_His').'_'.$safe.'_'.bin2hex(random_bytes(4)).'.pdf';if(!move_uploaded_file($tmp,$target))throw new RuntimeException('PDF gagal dipindahkan ke penyimpanan host.');
        try{$pages=$this->pageCount($target);$stmt=$this->db->prepare("INSERT INTO manual_pdfs(original_name,file_path,file_size,page_count,source_type,summary,created_by,created_at) VALUES(?,?,?,?, 'manual','',?,?)");$stmt->execute([$name,$target,filesize($target),$pages,$user,time()]);return['ok'=>true,'id'=>(int)$this->db->lastInsertId(),'name'=>$name,'pages'=>$pages];}catch(Throwable $e){@unlink($target);throw$e;}
    }

    public function document(int $id):array{$stmt=$this->db->prepare('SELECT * FROM manual_pdfs WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();if(!$row||!is_file((string)$row['file_path']))throw new RuntimeException('PDF tidak ditemukan.');return$row;}
    public function delete(int $id):void{$stmt=$this->db->prepare('SELECT * FROM manual_pdfs WHERE id=?');$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Data PDF tidak ditemukan.');$active=$this->db->prepare("SELECT COUNT(*) FROM print_jobs WHERE file_path=? AND status IN ('queued','processing')");$active->execute([$row['file_path']]);if((int)$active->fetchColumn()>0)throw new RuntimeException('PDF masih memiliki job cetak aktif.');$stmt=$this->db->prepare('DELETE FROM manual_pdfs WHERE id=?');$stmt->execute([$id]);if(is_file((string)$row['file_path']))@unlink((string)$row['file_path']);}

    public function randomPool():array
    {
        $rows=$this->db->query("SELECT group_name,paper,file_path,sku_id,parent_sku,variation,search_alias FROM data_mappings WHERE UPPER(LEFT(TRIM(group_name),1)) IN ('P','L') AND file_path<>''")->fetchAll();$result=['planner'=>[],'loose'=>[]];$seen=[];foreach($rows as $row){$kind=strtoupper(substr(trim((string)$row['group_name']),0,1));$paths=[];$raw=(string)$row['file_path'];if(is_file($raw))$paths[]=$raw;elseif(is_dir($raw))$paths=glob(rtrim($raw,'/\\').'/*.pdf')?:[];foreach($paths as $path){$key=strtolower($path);if(isset($seen[$kind][$key]))continue;$seen[$kind][$key]=true;$target=$kind==='L'?'loose':'planner';$result[$target][]=['path'=>$path,'file_name'=>basename($path),'paper'=>strtoupper((string)$row['paper']),'search'=>implode(' | ',array_filter([$row['sku_id'],$row['parent_sku'],$row['variation'],$row['search_alias'],basename($path)]))];}}
        return['counts'=>['planner'=>count($result['planner']),'loose'=>count($result['loose'])],'items'=>$result];
    }

    public function generateRandom(array $input,string $user):array
    {
        $mode=($input['mode']??'planner')==='loose'?'loose':'planner';$paper=strtoupper((string)($input['paper']??'A5'));if(!in_array($paper,['A5','B5'],true))throw new InvalidArgumentException('Ukuran kertas Random Pages tidak valid.');$count=max(1,min(100,(int)($input['count']??5)));$exclude=preg_split('/[,;\r\n]+/',strtolower((string)($input['exclude']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[];$pool=$this->randomPool()['items'][$mode];$paths=[];foreach($pool as $item){$file=strtoupper((string)$item['file_name']);$opposite=$paper==='A5'?'B5':'A5';if(str_contains($file,$opposite)&&!str_contains($file,$paper))continue;$hay=strtolower((string)$item['search']);$blocked=false;foreach($exclude as $token)if(str_contains($hay,trim($token))){$blocked=true;break;}if(!$blocked)$paths[]=$item['path'];}if(!$paths)throw new RuntimeException('Tidak ada PDF tersisa setelah filter Random Pages.');
        $dir=$this->root.'/storage/random-pages';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Folder Random Pages tidak dapat dibuat.');$stamp=date('Ymd_His');$output=$dir."/random_{$mode}_{$paper}_{$stamp}_".bin2hex(random_bytes(3)).'.pdf';$request=$dir.'/request_'.bin2hex(random_bytes(5)).'.json';file_put_contents($request,json_encode(['mode'=>$mode,'count'=>$count,'paths'=>$paths],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        try{$result=$this->runPython($this->root.'/tools/random_pages.py',[$request,$output]);$parsed=json_decode(trim($result),true,512,JSON_THROW_ON_ERROR);if(!is_file($output))throw new RuntimeException('PDF Random Pages tidak terbentuk.');$name="Random ".($mode==='loose'?'Loose Leaf':'Planner')." {$paper} {$stamp}.pdf";$stmt=$this->db->prepare("INSERT INTO manual_pdfs(original_name,file_path,file_size,page_count,source_type,summary,created_by,created_at) VALUES(?,?,?,?, 'random',?,?,?)");$stmt->execute([$name,$output,filesize($output),(int)$parsed['pages'],(string)$parsed['summary'],$user,time()]);return['ok'=>true,'id'=>(int)$this->db->lastInsertId(),'name'=>$name,'pages'=>(int)$parsed['pages'],'summary'=>$parsed['summary'],'skipped'=>$parsed['skipped']??[]];}
        catch(Throwable $e){if(is_file($output))@unlink($output);throw$e;}finally{if(is_file($request))@unlink($request);}
    }

    private function pageCount(string $path):int{$out=$this->runPython($this->root.'/tools/pdf_info.py',[$path]);$data=json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);return max(1,(int)($data['pages']??0));}
    private function runPython(string $script,array $args):string{$python=(string)($this->config['python']??'python');$pipes=[];$process=proc_open(array_merge([$python,$script],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('Python PDF worker tidak dapat dijalankan.');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Proses PDF gagal: '.trim($err?:$out));return$out;}
    private function bytes(int $bytes):string{if($bytes>=1048576)return number_format($bytes/1048576,1).' MB';if($bytes>=1024)return number_format($bytes/1024,1).' KB';return$bytes.' B';}
}
