<?php
declare(strict_types=1);

final class EtsyOAuthService
{
    public const DEFAULT_CALLBACK = 'https://app.paperbell.id/etsy-callback.php';
    private const SCOPES = 'shops_r listings_r listings_w';
    private $transport;

    public function __construct(private PDO $db, private OAuthVault $vault, private string $credentialsFile, ?callable $transport = null)
    {
        $this->transport = $transport;
        $this->db->exec('CREATE TABLE IF NOT EXISTS etsy_oauth_connection (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS etsy_oauth_states (state_hash VARCHAR(64) PRIMARY KEY, ticket_hash VARCHAR(64) NOT NULL, browser_hash VARCHAR(64) NOT NULL, payload TEXT NOT NULL, expires_at BIGINT NOT NULL)');
    }

    private function config(): array
    {
        if (!is_file($this->credentialsFile)) return [];
        try { $data = json_decode((string)file_get_contents($this->credentialsFile), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new RuntimeException('File kredensial Etsy bukan JSON yang valid.'); }
        if (!is_array($data)) throw new RuntimeException('Format kredensial Etsy tidak valid.');
        return $data;
    }

    private function callback(array $config): string
    {
        $url = trim((string)($config['redirect_uri'] ?? self::DEFAULT_CALLBACK));
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !str_ends_with($parts['path'] ?? '', '/etsy-callback.php')) {
            throw new RuntimeException('Callback Etsy harus berupa URL HTTPS menuju etsy-callback.php tanpa query string.');
        }
        return $url;
    }

    public function status(): array
    {
        $cfg = $this->config();
        $data = $this->connection();
        $error = '';
        if (!empty($data['refresh_token']) && (int)($data['expires_at'] ?? 0) <= time() + 120) {
            try { $data = $this->credentials(); }
            catch (Throwable $e) { $error = $e->getMessage(); }
        }
        $connected = !empty($data['access_token']) && (int)($data['expires_at'] ?? 0) > time();
        return [
            'provider'=>'etsy', 'configured'=>!empty($cfg['keystring']) && !empty($cfg['shared_secret']),
            'connected'=>$connected, 'status'=>$error !== '' ? 'error' : ($connected ? 'connected' : 'disconnected'),
            'account_id'=>$data['shop_id'] ?? '', 'account_name'=>$data['shop_name'] ?? '',
            'access_expires_at'=>(int)($data['expires_at'] ?? 0), 'refresh_expires_at'=>0,
            'updated_at'=>(int)($data['updated_at'] ?? 0), 'last_error'=>$error,
            'callback_url'=>$this->callback($cfg), 'config'=>[],
        ];
    }

    public function connectUrl(string $user): string
    {
        $cfg = $this->config();
        if (empty($cfg['keystring']) || empty($cfg['shared_secret'])) throw new RuntimeException('Kredensial Etsy belum tersedia di host Paperbell.');
        $callback = $this->callback($cfg);
        $state = bin2hex(random_bytes(32));
        $ticket = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->db->prepare('DELETE FROM etsy_oauth_states WHERE expires_at < ?')->execute([time()]);
        $payload = ['state'=>$state, 'verifier'=>$verifier, 'redirect_uri'=>$callback, 'created_by'=>$user];
        $this->db->prepare('INSERT INTO etsy_oauth_states (state_hash,ticket_hash,browser_hash,payload,expires_at) VALUES (?,?,?,?,?)')
            ->execute([hash('sha256', $state), hash('sha256', $ticket), '', $this->encode($payload), time()+600]);
        return substr($callback, 0, -strlen('etsy-callback.php')).'etsy-authorize.php?ticket='.$ticket;
    }

    /** Exchange a one-use launch ticket for a browser-bound OAuth request. */
    public function authorize(string $ticket, string $browser): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket) || $browser === '') throw new RuntimeException('Link koneksi Etsy tidak valid.');
        $stmt = $this->db->prepare('SELECT * FROM etsy_oauth_states WHERE ticket_hash=? AND expires_at>=?');
        $stmt->execute([hash('sha256', $ticket), time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Link koneksi Etsy sudah digunakan atau kedaluwarsa. Ulangi dari Paperbell.');
        $update = $this->db->prepare('UPDATE etsy_oauth_states SET ticket_hash=?, browser_hash=? WHERE state_hash=? AND ticket_hash=?');
        $update->execute(['', hash('sha256', $browser), $row['state_hash'], hash('sha256', $ticket)]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Link koneksi Etsy sudah digunakan.');
        $data = $this->decode($row['payload']);
        return 'https://www.etsy.com/oauth/connect?'.http_build_query([
            'response_type'=>'code', 'client_id'=>$this->config()['keystring'], 'redirect_uri'=>$data['redirect_uri'],
            'scope'=>self::SCOPES, 'state'=>$data['state'],
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256', $data['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method'=>'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(array $query, string $browser): array
    {
        $state = (string)($query['state'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $state) || $browser === '') throw new RuntimeException('Sesi Etsy tidak valid. Hubungkan ulang dari Paperbell di browser yang sama.');
        $stmt = $this->db->prepare('SELECT * FROM etsy_oauth_states WHERE state_hash=?');
        $stmt->execute([hash('sha256', $state)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['expires_at'] < time() || !hash_equals($row['browser_hash'], hash('sha256', $browser))) throw new RuntimeException('Sesi Etsy tidak valid atau kedaluwarsa. Hubungkan ulang dari Paperbell.');
        $delete = $this->db->prepare('DELETE FROM etsy_oauth_states WHERE state_hash=? AND browser_hash=?');
        $delete->execute([$row['state_hash'], $row['browser_hash']]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Sesi Etsy sudah digunakan.');
        if (!empty($query['error']) || empty($query['code'])) throw new RuntimeException('Otorisasi Etsy dibatalkan atau tidak menerima kode.');
        $saved = $this->decode($row['payload']);
        $cfg = $this->config();
        $token = $this->request('POST', 'https://api.etsy.com/v3/public/oauth/token', $cfg, [
            'grant_type'=>'authorization_code', 'client_id'=>$cfg['keystring'], 'redirect_uri'=>$saved['redirect_uri'],
            'code'=>(string)$query['code'], 'code_verifier'=>$saved['verifier'],
        ]);
        $data = $this->tokenData($token);
        $userId = explode('.', $data['access_token'], 2)[0];
        if (!ctype_digit($userId)) throw new RuntimeException('Identitas pengguna dari token Etsy tidak valid.');
        $shop = $this->request('GET', 'https://api.etsy.com/v3/application/users/'.$userId.'/shops', $cfg, null, $data['access_token']);
        if (isset($shop['results'])) $shop = $shop['results'][0] ?? [];
        if (empty($shop['shop_id'])) throw new RuntimeException('Akun Etsy ini tidak memiliki toko yang dapat dihubungkan.');
        $data['shop_id'] = (string)$shop['shop_id'];
        $data['shop_name'] = (string)($shop['shop_name'] ?? 'Etsy Shop');
        $this->saveConnection($data);
        return ['shop_id'=>$data['shop_id'], 'shop_name'=>$data['shop_name']];
    }

    public function disconnect(): void
    {
        $this->db->exec('DELETE FROM etsy_oauth_connection');
        $this->db->exec('DELETE FROM etsy_oauth_states');
    }

    /** Server-side only. Tokens must never be returned to a browser/API response. */
    public function credentials(): array
    {
        $this->db->beginTransaction();
        try {
            $data = $this->connection(true);
            if (empty($data['refresh_token'])) throw new RuntimeException('Etsy belum terhubung.');
            if ((int)$data['expires_at'] <= time()+120) {
                $cfg = $this->config();
                $token = $this->request('POST', 'https://api.etsy.com/v3/public/oauth/token', $cfg, [
                    'grant_type'=>'refresh_token', 'client_id'=>$cfg['keystring'], 'refresh_token'=>$data['refresh_token'],
                ]);
                $data = array_merge($data, $this->tokenData($token));
                $this->saveConnection($data);
            }
            $this->db->commit();
            return $data;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function tokenData(array $token): array
    {
        if (empty($token['access_token']) || empty($token['refresh_token']) || (int)($token['expires_in'] ?? 0) <= 0) throw new RuntimeException('Respons token Etsy tidak lengkap.');
        return ['access_token'=>(string)$token['access_token'], 'refresh_token'=>(string)$token['refresh_token'], 'expires_at'=>time()+(int)$token['expires_in'], 'updated_at'=>time()];
    }

    private function connection(bool $lock = false): array
    {
        $suffix = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $payload = $this->db->query('SELECT payload FROM etsy_oauth_connection WHERE id=1'.$suffix)->fetchColumn();
        return $payload === false ? [] : $this->decode((string)$payload);
    }

    private function saveConnection(array $data): void
    {
        $sql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO etsy_oauth_connection (id,payload) VALUES (1,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)'
            : 'INSERT INTO etsy_oauth_connection (id,payload) VALUES (1,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload';
        $this->db->prepare($sql)->execute([$this->encode($data)]);
    }

    private function encode(array $data): string { return $this->vault->encrypt(json_encode($data, JSON_THROW_ON_ERROR)); }
    private function decode(string $payload): array { return json_decode($this->vault->decrypt($payload), true, 512, JSON_THROW_ON_ERROR); }

    private function request(string $method, string $url, array $cfg, ?array $form = null, string $token = ''): array
    {
        if ($this->transport) return ($this->transport)($method, $url, $form, $token);
        $headers = ['Accept: application/json', 'x-api-key: '.$cfg['keystring'].':'.$cfg['shared_secret']];
        if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
        if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers]);
        if ($form !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        // Never include remote response bodies, URLs or cURL errors containing credentials in user-facing errors.
        if ($raw === false) throw new RuntimeException('Tidak dapat menghubungi Etsy. Periksa koneksi dan sertifikat CA PHP.');
        if ($status < 200 || $status >= 300) throw new RuntimeException('Etsy menolak permintaan (HTTP '.$status.'). Periksa akses aplikasi atau hubungkan ulang.');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('Respons Etsy tidak valid.');
        return $data;
    }
}
