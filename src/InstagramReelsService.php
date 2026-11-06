<?php
declare(strict_types=1);

/** One-account MVP. All dates are Unix seconds; form input is explicitly WIB. */
final class InstagramReelsService
{
    private PDO $db;
    private OAuthVault $vault;
    public function __construct(private string $directory, private $transport = null)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Penyimpanan Reels gagal dibuat.');
        $this->vault = new OAuthVault($directory.'/key');
        $this->db = new PDO('sqlite:'.$directory.'/reels.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, encrypted TEXT NOT NULL)');
        $this->db->exec("CREATE TABLE IF NOT EXISTS reels (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, caption TEXT NOT NULL, video_url TEXT NOT NULL, upload TEXT NOT NULL DEFAULT '', scheduled_at INTEGER, status TEXT NOT NULL, container_id TEXT, media_id TEXT, error TEXT, updated_at INTEGER NOT NULL)");
    }
    private function settings(): array
    {
        $raw = $this->db->query('SELECT encrypted FROM settings WHERE id=1')->fetchColumn();
        return $raw ? json_decode($this->vault->decrypt($raw), true, 512, JSON_THROW_ON_ERROR) : [];
    }
    public function overview(): array
    {
        $s = $this->settings();
        return ['items'=>$this->db->query('SELECT * FROM reels ORDER BY COALESCE(scheduled_at,updated_at) DESC,id DESC')->fetchAll(), 'account'=>['connected'=>!empty($s['token']), 'username'=>$s['username']??'', 'user_id'=>$s['user_id']??'', 'version'=>$s['version']??'v25.0'], 'worker'=>is_file($this->directory.'/heartbeat') ? (int)file_get_contents($this->directory.'/heartbeat') : null];
    }
    public function connect(array $input): void
    {
        $s = ['user_id'=>trim((string)($input['user_id']??'')), 'token'=>trim((string)($input['token']??'')), 'version'=>trim((string)($input['version']??'v25.0'))];
        if (!preg_match('/^\d+$/',$s['user_id']) || !preg_match('/^v\d+\.\d+$/',$s['version']) || $s['token']==='') throw new InvalidArgumentException('Isi Instagram User ID, versi Graph API, dan Page Access Token.');
        $old=$this->settings();
        if (($old['user_id']??$s['user_id'])!==$s['user_id'] && $this->db->query("SELECT COUNT(*) FROM reels WHERE status IN ('scheduled','creating','processing','publishing','review')")->fetchColumn()>0) throw new InvalidArgumentException('Selesaikan antrean akun lama sebelum mengganti akun Instagram.');
        $profile = $this->request($s, 'GET', $s['user_id'], ['fields'=>'id,username']);
        if (empty($profile['id']) || empty($profile['username'])) throw new RuntimeException('Akun Instagram tidak dapat diverifikasi.');
        $s['username'] = (string)$profile['username'];
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO settings(id,encrypted) VALUES(1,?)');
        $stmt->execute([$this->vault->encrypt(json_encode($s,JSON_THROW_ON_ERROR))]);
    }
    public function disconnect(): void
    {
        $this->db->beginTransaction();
        $this->db->exec("UPDATE reels SET status='draft',scheduled_at=NULL WHERE status='scheduled'");
        $s=$this->settings();$s['token']='';
        $q=$this->db->prepare('INSERT OR REPLACE INTO settings(id,encrypted) VALUES(1,?)');$q->execute([$this->vault->encrypt(json_encode($s,JSON_THROW_ON_ERROR))]);
        $this->db->commit();
    }
    public static function publicVideoUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme']??'')!=='https' || !empty($p['user']) || !empty($p['pass'])) return false;
        $host = strtolower($p['host']??'');
        if (!str_contains($host,'.') || preg_match('/(^localhost$|\.local$|\.test$|\.internal$)/',$host)) return false;
        if (filter_var($host,FILTER_VALIDATE_IP) && !filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        return filter_var($url,FILTER_VALIDATE_URL)!==false;
    }
    public function save(array $input): int
    {
        $id = (int)($input['id']??0);
        $title = trim((string)($input['title']??'')); $caption = trim((string)($input['caption']??''));
        $url = trim((string)($input['video_url']??'')); $status = ($input['status']??'draft')==='scheduled'?'scheduled':'draft';
        if ($title==='' || mb_strlen($title)>160 || mb_strlen($caption)>2200) throw new InvalidArgumentException('Judul wajib diisi (maks. 160 karakter); caption maks. 2.200 karakter.');
        if ($url!=='' && !self::publicVideoUrl($url)) throw new InvalidArgumentException('Gunakan URL video HTTPS publik, bukan alamat lokal.');
        $at = null;
        if ($status==='scheduled') {
            if (empty($this->settings()['token'])) throw new InvalidArgumentException('Hubungkan akun Instagram sebelum menjadwalkan.');
            if ($url==='') throw new InvalidArgumentException('URL video publik diperlukan untuk posting. Upload lokal hanya untuk preview.');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($input['schedule']??''), new DateTimeZone('Asia/Jakarta'));
            if (!$date || $date->format('Y-m-d\TH:i')!==($input['schedule']??'') || $date->getTimestamp()<=time()) throw new InvalidArgumentException('Pilih jadwal WIB di masa depan.');
            $at = $date->getTimestamp();
        }
        if ($id) {
            $q=$this->db->prepare("UPDATE reels SET title=?,caption=?,video_url=?,scheduled_at=?,status=?,error=NULL,updated_at=? WHERE id=? AND status IN ('draft','scheduled','failed')");
            $q->execute([$title,$caption,$url,$at,$status,time(),$id]);
            if (!$q->rowCount()) throw new InvalidArgumentException('Konten sudah diproses atau tidak ditemukan.');
        } else {
            $q=$this->db->prepare('INSERT INTO reels(title,caption,video_url,scheduled_at,status,updated_at) VALUES(?,?,?,?,?,?)');
            $q->execute([$title,$caption,$url,$at,$status,time()]);$id=(int)$this->db->lastInsertId();
        }
        return $id;
    }
    public function upload(int $id, array $file): void
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Upload gagal. Periksa batas upload PHP/XAMPP.');
        if (($file['size']??0)<1 || $file['size']>100*1024*1024) throw new InvalidArgumentException('Video maksimal 100 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime,['video/mp4','video/quicktime'],true)) throw new InvalidArgumentException('Upload video MP4 atau MOV.');
        $name=bin2hex(random_bytes(16)).'.mp4';
        if (!move_uploaded_file($file['tmp_name'],$this->directory.'/'.$name)) throw new RuntimeException('Video gagal disimpan.');
        $q=$this->db->prepare("UPDATE reels SET upload=?,updated_at=? WHERE id=? AND status IN ('draft','scheduled','failed')");$old=$this->mediaPath($id);
        $q->execute([$name,time(),$id]);
        if (!$q->rowCount()) {unlink($this->directory.'/'.$name);throw new InvalidArgumentException('Konten tidak dapat diubah.');}
        if ($old) unlink($old);
    }
    public function mediaPath(int $id): ?string
    {
        $q=$this->db->prepare('SELECT upload FROM reels WHERE id=?');$q->execute([$id]);$name=$q->fetchColumn();
        return $name && preg_match('/^[a-f0-9]{32}\.mp4$/',$name) && is_file($this->directory.'/'.$name) ? $this->directory.'/'.$name : null;
    }
    public function delete(int $id): void
    {
        $path=$this->mediaPath($id);$q=$this->db->prepare("DELETE FROM reels WHERE id=? AND status IN ('draft','scheduled','failed')");$q->execute([$id]);
        if (!$q->rowCount()) throw new InvalidArgumentException('Hanya draft, jadwal, atau konten gagal yang dapat dihapus.');
        if ($path) unlink($path);
    }
    private function update(int $id,string $status,?string $container=null,?string $media=null,?string $error=null): void
    {
        $q=$this->db->prepare('UPDATE reels SET status=?,container_id=COALESCE(?,container_id),media_id=COALESCE(?,media_id),error=?,updated_at=? WHERE id=?');$q->execute([$status,$container,$media,$error,time(),$id]);
    }
    /** One bounded pass. Persist before network calls; uncertain publishes never retry automatically. */
    public function tick(): void
    {
        $lock=fopen($this->directory.'/worker.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) return;
        try {
            file_put_contents($this->directory.'/heartbeat',(string)time(),LOCK_EX);
            $s=$this->settings();if (empty($s['token'])) return;
            $this->db->exec("UPDATE reels SET status='review',error='Worker terhenti saat mengirim. Periksa Instagram sebelum membuat jadwal baru.' WHERE status IN ('creating','publishing')");
            $q=$this->db->prepare("SELECT * FROM reels WHERE (status='scheduled' AND scheduled_at<=?) OR status='processing' ORDER BY scheduled_at LIMIT 5");$q->execute([time()]);
            foreach($q->fetchAll() as $row) {
                $id=(int)$row['id'];$stage=$row['status'];
                try {
                    if ($stage==='scheduled') {
                        $claim=$this->db->prepare("UPDATE reels SET status='creating',updated_at=? WHERE id=? AND status='scheduled'");$claim->execute([time(),$id]);if (!$claim->rowCount()) continue;
                        $stage='creating';
                        $r=$this->request($s,'POST',$s['user_id'].'/media',['media_type'=>'REELS','video_url'=>$row['video_url'],'caption'=>$row['caption'],'share_to_feed'=>'true']);
                        if (empty($r['id'])) throw new RuntimeException('Meta tidak mengembalikan ID container.');
                        $this->update($id,'processing',(string)$r['id']);continue;
                    }
                    $r=$this->request($s,'GET',$row['container_id'],['fields'=>'status_code']);
                    $code=$r['status_code']??'';
                    if ($code==='PUBLISHED') {$this->update($id,'published');continue;}
                    if (in_array($code,['ERROR','EXPIRED'],true)) throw new RuntimeException('Pemrosesan video Meta gagal atau kedaluwarsa.');
                    if ($code!=='FINISHED') {
                        if (time()-(int)$row['updated_at']>86400) throw new RuntimeException('Pemrosesan video melebihi 24 jam.');
                        continue;
                    }
                    $this->update($id,'publishing');$stage='publishing';
                    $r=$this->request($s,'POST',$s['user_id'].'/media_publish',['creation_id'=>$row['container_id']]);
                    if (empty($r['id'])) throw new RuntimeException('Meta tidak mengembalikan ID posting.');
                    $this->update($id,'published',null,(string)$r['id']);
                } catch (Throwable $e) {
                    $uncertain=in_array($stage,['creating','publishing'],true);
                    $this->update($id,$uncertain?'review':'failed',null,null,($uncertain?'Periksa Instagram sebelum membuat jadwal baru. ':'').$e->getMessage());
                }
            }
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private function request(array $s,string $method,string $path,array $data): array
    {
        if ($this->transport) return ($this->transport)($method,$path,$data);
        $url='https://graph.facebook.com/'.$s['version'].'/'.$path;
        if ($method==='GET') $url.='?'.http_build_query($data);
        $ch=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$s['token']]];
        if ($method==='POST') {$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($data);}
        curl_setopt_array($ch,$options);$raw=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if ($raw===false) throw new RuntimeException('Koneksi Meta gagal; periksa jaringan dan sertifikat PHP.');
        $r=json_decode($raw,true);
        // Never expose raw Meta responses: they may contain credentials or request URLs.
        if ($http>=400 || !is_array($r) || isset($r['error'])) throw new RuntimeException('Meta menolak permintaan (kode '.(int)($r['error']['code']??$http).'). Periksa token, izin, dan format video.');
        return $r;
    }
}
