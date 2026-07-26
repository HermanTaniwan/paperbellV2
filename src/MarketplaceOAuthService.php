<?php
declare(strict_types=1);

final class MarketplaceOAuthService
{
    private const PROVIDERS = ['shopee', 'tiktok'];

    public function __construct(private PDO $db, private OAuthVault $vault, private array $config)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS oauth_connections (provider VARCHAR(30) PRIMARY KEY,config_encrypted LONGTEXT NOT NULL,access_token_encrypted LONGTEXT NOT NULL,refresh_token_encrypted LONGTEXT NOT NULL,account_id VARCHAR(255) NOT NULL DEFAULT '',account_name VARCHAR(255) NOT NULL DEFAULT '',metadata_encrypted LONGTEXT NOT NULL,access_expires_at BIGINT NOT NULL DEFAULT 0,refresh_expires_at BIGINT NOT NULL DEFAULT 0,status VARCHAR(30) NOT NULL DEFAULT 'disconnected',last_error TEXT NOT NULL,connected_at BIGINT NOT NULL DEFAULT 0,updated_at BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
        $this->db->exec("CREATE TABLE IF NOT EXISTS oauth_states (state_hash CHAR(64) PRIMARY KEY,provider VARCHAR(30) NOT NULL,redirect_uri TEXT NOT NULL,created_by VARCHAR(100) NOT NULL DEFAULT '',expires_at BIGINT NOT NULL,created_at BIGINT NOT NULL,INDEX ix_oauth_states_expiry(expires_at)) ENGINE=InnoDB");
        $insert = $this->db->prepare("INSERT IGNORE INTO oauth_connections(provider,config_encrypted,access_token_encrypted,refresh_token_encrypted,metadata_encrypted,last_error,updated_at) VALUES(?,?,?,?,?,'',?)");
        foreach (self::PROVIDERS as $provider) $insert->execute([$provider, '', '', '', '', time()]);
    }

    public function statuses(string $baseUrl): array
    {
        $items = [];
        foreach (self::PROVIDERS as $provider) {
            try { $this->refreshIfNeeded($provider); } catch (Throwable $e) { $this->recordError($provider, $e->getMessage()); }
            $row = $this->row($provider);
            $cfg = $this->decodeJson($row['config_encrypted']);
            $items[$provider] = [
                'provider' => $provider,
                'configured' => $this->isConfigured($provider, $cfg),
                'connected' => $row['status'] === 'connected' && $row['access_token_encrypted'] !== '',
                'status' => $row['status'],
                'account_id' => $row['account_id'],
                'account_name' => $row['account_name'],
                'access_expires_at' => (int)$row['access_expires_at'],
                'refresh_expires_at' => (int)$row['refresh_expires_at'],
                'last_error' => $row['last_error'],
                'updated_at' => (int)$row['updated_at'],
                'callback_url' => $this->callbackBase($provider, $baseUrl),
                'config' => $provider === 'shopee'
                    ? ['partner_id'=>$cfg['partner_id'] ?? '', 'has_partner_key'=>!empty($cfg['partner_key']), 'api_host'=>$cfg['api_host'] ?? 'https://partner.shopeemobile.com']
                    : ['app_key'=>$cfg['app_key'] ?? '', 'has_app_secret'=>!empty($cfg['app_secret']), 'service_id'=>$cfg['service_id'] ?? '', 'market'=>$cfg['market'] ?? 'row', 'shop_id'=>$cfg['shop_id'] ?? '', 'shop_cipher'=>$cfg['shop_cipher'] ?? ''],
            ];
        }
        return ['items'=>$items, 'auto_refresh'=>true];
    }

    public function saveConfig(string $provider, array $input): void
    {
        $this->assertProvider($provider);
        $row = $this->row($provider);
        $old = $this->decodeJson($row['config_encrypted']);
        if ($provider === 'shopee') {
            $partnerId = trim((string)($input['partner_id'] ?? $old['partner_id'] ?? ''));
            if ($partnerId !== '' && !ctype_digit($partnerId)) throw new InvalidArgumentException('Shopee Partner ID harus berupa angka.');
            $cfg = ['partner_id'=>$partnerId, 'partner_key'=>trim((string)($input['partner_key'] ?? '')) ?: ($old['partner_key'] ?? ''), 'api_host'=>rtrim(trim((string)($input['api_host'] ?? $old['api_host'] ?? 'https://partner.shopeemobile.com')), '/')];
        } else {
            $market = strtolower(trim((string)($input['market'] ?? $old['market'] ?? 'row')));
            if (!in_array($market, ['row','us'], true)) throw new InvalidArgumentException('Market TikTok tidak valid.');
            $cfg = ['app_key'=>trim((string)($input['app_key'] ?? $old['app_key'] ?? '')), 'app_secret'=>trim((string)($input['app_secret'] ?? '')) ?: ($old['app_secret'] ?? ''), 'service_id'=>trim((string)($input['service_id'] ?? $old['service_id'] ?? '')), 'market'=>$market, 'shop_id'=>trim((string)($input['shop_id'] ?? $old['shop_id'] ?? '')), 'shop_cipher'=>trim((string)($input['shop_cipher'] ?? $old['shop_cipher'] ?? '')), 'api_base'=>rtrim((string)($old['api_base'] ?? 'https://open-api.tiktokglobalshop.com'), '/')];
        }
        $stmt=$this->db->prepare('UPDATE oauth_connections SET config_encrypted=?,last_error=?,updated_at=? WHERE provider=?');
        $stmt->execute([$this->vault->encrypt(json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)), '', time(), $provider]);
    }

    public function connectUrl(string $provider, string $baseUrl, string $user): string
    {
        $this->assertProvider($provider);
        $row=$this->row($provider); $cfg=$this->decodeJson($row['config_encrypted']);
        if (!$this->isConfigured($provider,$cfg)) throw new RuntimeException('Simpan kredensial '.$this->label($provider).' terlebih dahulu.');
        if ($provider === 'tiktok' && empty($cfg['service_id'])) throw new RuntimeException('TikTok Service ID wajib diisi untuk membuat link otorisasi.');
        $state=bin2hex(random_bytes(24));
        $callback=$this->callbackBase($provider,$baseUrl) . '&state=' . rawurlencode($state);
        $this->db->prepare('DELETE FROM oauth_states WHERE expires_at<?')->execute([time()]);
        $this->db->prepare('INSERT INTO oauth_states(state_hash,provider,redirect_uri,created_by,expires_at,created_at) VALUES(?,?,?,?,?,?)')->execute([hash('sha256',$state),$provider,$callback,$user,time()+1800,time()]);
        if ($provider === 'shopee') {
            $path='/api/v2/shop/auth_partner'; $ts=time();
            $sign=hash_hmac('sha256',(string)$cfg['partner_id'].$path.$ts,(string)$cfg['partner_key']);
            return $cfg['api_host'].$path.'?'.http_build_query(['partner_id'=>$cfg['partner_id'],'timestamp'=>$ts,'sign'=>$sign,'redirect'=>$callback],'','&',PHP_QUERY_RFC3986);
        }
        $authBase=$cfg['market']==='us'?'https://services.tiktokshops.us/open/authorize':'https://services.tiktokshop.com/open/authorize';
        return $authBase.'?'.http_build_query(['service_id'=>$cfg['service_id'],'state'=>$state],'','&',PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $provider, string $state, array $query): array
    {
        $this->assertProvider($provider);
        if ($state === '') throw new RuntimeException('State OAuth tidak ditemukan. Ulangi proses Connect dari Paperbell.');
        $stmt=$this->db->prepare('SELECT * FROM oauth_states WHERE state_hash=? AND provider=? LIMIT 1');
        $stmt->execute([hash('sha256',$state),$provider]); $saved=$stmt->fetch();
        if (!$saved || (int)$saved['expires_at'] < time()) throw new RuntimeException('Sesi OAuth tidak valid atau sudah kedaluwarsa.');
        $this->db->prepare('DELETE FROM oauth_states WHERE state_hash=?')->execute([hash('sha256',$state)]);
        if (!empty($query['error']) || empty($query['code']) || $query['code']==='null') throw new RuntimeException('Otorisasi ditolak atau kode OAuth tidak diterima.');
        $cfg=$this->decodeJson($this->row($provider)['config_encrypted']);
        $data=$provider==='shopee'?$this->exchangeShopee($cfg,(string)$query['code'],(string)($query['shop_id']??'')):$this->exchangeTikTok($cfg,(string)$query['code']);
        $this->storeTokens($provider,$data);
        return ['provider'=>$provider,'account_name'=>$data['account_name'] ?? '','account_id'=>$data['account_id'] ?? ''];
    }

    public function disconnect(string $provider): void
    {
        $this->assertProvider($provider);
        $this->db->prepare("UPDATE oauth_connections SET access_token_encrypted='',refresh_token_encrypted='',account_id='',account_name='',metadata_encrypted='',access_expires_at=0,refresh_expires_at=0,status='disconnected',last_error='',updated_at=? WHERE provider=?")->execute([time(),$provider]);
    }

    /** Data rahasia khusus service server-side; jangan pernah dikirim melalui endpoint API. */
    public function credentials(string $provider): array
    {
        $this->assertProvider($provider);
        $this->refreshIfNeeded($provider);
        $row=$this->row($provider);
        if($row['status']!=='connected'||$row['access_token_encrypted']==='')throw new RuntimeException($this->label($provider).' belum terhubung. Buka Koneksi Marketplace lalu lakukan otorisasi.');
        return [
            'config'=>$this->decodeJson($row['config_encrypted']),
            'access_token'=>$this->vault->decrypt($row['access_token_encrypted']),
            'refresh_token'=>$this->vault->decrypt($row['refresh_token_encrypted']),
            'account_id'=>$row['account_id'],
            'metadata'=>$this->decodeJson($row['metadata_encrypted']),
        ];
    }

    private function exchangeShopee(array $cfg,string $code,string $callbackShopId): array
    {
        $path='/api/v2/auth/token/get';$ts=time();$sign=hash_hmac('sha256',(string)$cfg['partner_id'].$path.$ts,(string)$cfg['partner_key']);
        $json=$this->request('POST',$cfg['api_host'].$path.'?'.http_build_query(['partner_id'=>$cfg['partner_id'],'timestamp'=>$ts,'sign'=>$sign]),['Content-Type: application/json'],json_encode(['code'=>$code],JSON_THROW_ON_ERROR));
        if (!empty($json['error'])) throw new RuntimeException('Shopee: '.($json['message']??$json['error']));
        $shopId=(string)($callbackShopId ?: ($json['shop_id_list'][0]??''));
        return ['access_token'=>(string)($json['access_token']??''),'refresh_token'=>(string)($json['refresh_token']??''),'access_expires_at'=>time()+(int)($json['expire_in']??14400),'refresh_expires_at'=>0,'account_id'=>$shopId,'account_name'=>$shopId?'Shopee Shop '.$shopId:'Shopee Shop','metadata'=>['shop_id_list'=>$json['shop_id_list']??[]]];
    }

    private function exchangeTikTok(array $cfg,string $code): array
    {
        $json=$this->request('GET','https://auth.tiktok-shops.com/api/v2/token/get?'.http_build_query(['app_key'=>$cfg['app_key'],'app_secret'=>$cfg['app_secret'],'auth_code'=>$code,'grant_type'=>'authorized_code']));
        if ((int)($json['code']??-1)!==0) throw new RuntimeException('TikTok: '.($json['message']??'token exchange gagal'));
        $d=$json['data']??[];
        return ['access_token'=>(string)($d['access_token']??''),'refresh_token'=>(string)($d['refresh_token']??''),'access_expires_at'=>(int)($d['access_token_expire_in']??0),'refresh_expires_at'=>(int)($d['refresh_token_expire_in']??0),'account_id'=>(string)($d['open_id']??''),'account_name'=>(string)($d['seller_name']??'TikTok Shop'),'metadata'=>['open_id'=>$d['open_id']??'','seller_base_region'=>$d['seller_base_region']??'','granted_scopes'=>$d['granted_scopes']??($d['granted_permissions']??[])]];
    }

    private function refreshIfNeeded(string $provider): void
    {
        $row=$this->row($provider); if ($row['status']!=='connected'||$row['refresh_token_encrypted']===''||(int)$row['access_expires_at']>time()+600)return;
        if ((int)$row['refresh_expires_at']>0&&(int)$row['refresh_expires_at']<=time())throw new RuntimeException('Refresh token '.$this->label($provider).' kedaluwarsa. Hubungkan ulang akun.');
        $cfg=$this->decodeJson($row['config_encrypted']);$refresh=$this->vault->decrypt($row['refresh_token_encrypted']);
        if ($provider==='shopee') {
            $path='/api/v2/auth/access_token/get';$ts=time();$sign=hash_hmac('sha256',(string)$cfg['partner_id'].$path.$ts,(string)$cfg['partner_key']);
            $json=$this->request('POST',$cfg['api_host'].$path.'?'.http_build_query(['partner_id'=>$cfg['partner_id'],'timestamp'=>$ts,'sign'=>$sign]),['Content-Type: application/json'],json_encode(['partner_id'=>(int)$cfg['partner_id'],'shop_id'=>(int)$row['account_id'],'refresh_token'=>$refresh],JSON_THROW_ON_ERROR));
            if (!empty($json['error']))throw new RuntimeException('Shopee refresh: '.($json['message']??$json['error']));
            $data=['access_token'=>$json['access_token']??'','refresh_token'=>$json['refresh_token']??$refresh,'access_expires_at'=>time()+(int)($json['expire_in']??14400),'refresh_expires_at'=>(int)$row['refresh_expires_at'],'account_id'=>$row['account_id'],'account_name'=>$row['account_name'],'metadata'=>$this->decodeJson($row['metadata_encrypted'])];
        } else {
            $json=$this->request('GET','https://auth.tiktok-shops.com/api/v2/token/refresh?'.http_build_query(['app_key'=>$cfg['app_key'],'app_secret'=>$cfg['app_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token']));
            if ((int)($json['code']??-1)!==0)throw new RuntimeException('TikTok refresh: '.($json['message']??'gagal'));
            $d=$json['data']??[];$data=['access_token'=>$d['access_token']??'','refresh_token'=>$d['refresh_token']??$refresh,'access_expires_at'=>(int)($d['access_token_expire_in']??0),'refresh_expires_at'=>(int)($d['refresh_token_expire_in']??$row['refresh_expires_at']),'account_id'=>$d['open_id']??$row['account_id'],'account_name'=>$d['seller_name']??$row['account_name'],'metadata'=>['open_id'=>$d['open_id']??$row['account_id'],'seller_base_region'=>$d['seller_base_region']??'','granted_scopes'=>$d['granted_scopes']??[]]];
        }
        $this->storeTokens($provider,$data);
    }

    private function storeTokens(string $provider,array $data): void
    {
        if (empty($data['access_token']))throw new RuntimeException('Respons '.$this->label($provider).' tidak berisi access token.');
        $stmt=$this->db->prepare("UPDATE oauth_connections SET access_token_encrypted=?,refresh_token_encrypted=?,account_id=?,account_name=?,metadata_encrypted=?,access_expires_at=?,refresh_expires_at=?,status='connected',last_error='',connected_at=IF(connected_at=0,?,connected_at),updated_at=? WHERE provider=?");
        $stmt->execute([$this->vault->encrypt((string)$data['access_token']),$this->vault->encrypt((string)($data['refresh_token']??'')),(string)($data['account_id']??''),(string)($data['account_name']??''),$this->vault->encrypt(json_encode($data['metadata']??[],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),(int)($data['access_expires_at']??0),(int)($data['refresh_expires_at']??0),time(),time(),$provider]);
    }

    private function request(string $method,string $url,array $headers=[],?string $body=null): array
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($response===false)throw new RuntimeException('Koneksi OAuth gagal: '.$error);$json=json_decode($response,true);if(!is_array($json))throw new RuntimeException('Respons OAuth tidak valid (HTTP '.$status.').');if($status<200||$status>=300)throw new RuntimeException('Server OAuth menolak permintaan (HTTP '.$status.'): '.(string)($json['message']??$json['error']??'unknown error'));return $json;
    }

    private function callbackBase(string $provider,string $baseUrl): string{return rtrim($baseUrl,'/').'/oauth-callback.php?provider='.rawurlencode($provider);}
    private function row(string $provider): array{$stmt=$this->db->prepare('SELECT * FROM oauth_connections WHERE provider=?');$stmt->execute([$provider]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Konfigurasi OAuth tidak ditemukan.');return $row;}
    private function decodeJson(string $encrypted): array{if($encrypted==='')return[];$decoded=json_decode($this->vault->decrypt($encrypted),true);return is_array($decoded)?$decoded:[];}
    private function isConfigured(string $provider,array $cfg): bool{return $provider==='shopee'?!empty($cfg['partner_id'])&&!empty($cfg['partner_key']):!empty($cfg['app_key'])&&!empty($cfg['app_secret']);}
    private function assertProvider(string $provider): void{if(!in_array($provider,self::PROVIDERS,true))throw new InvalidArgumentException('Provider OAuth tidak didukung.');}
    private function label(string $provider): string{return $provider==='shopee'?'Shopee':'TikTok Shop';}
    private function recordError(string $provider,string $message): void{$this->db->prepare("UPDATE oauth_connections SET status='error',last_error=?,updated_at=? WHERE provider=?")->execute([mb_substr($message,0,1000),time(),$provider]);}
}
