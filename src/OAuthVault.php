<?php
declare(strict_types=1);

final class OAuthVault
{
    private string $key;

    public function __construct(string $keyFile)
    {
        if (!extension_loaded('openssl')) throw new RuntimeException('Ekstensi OpenSSL PHP diperlukan untuk OAuth vault.');
        $directory = dirname($keyFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder penyimpanan OAuth tidak dapat dibuat.');
        }
        if (!is_file($keyFile)) {
            $encoded = base64_encode(random_bytes(32));
            if (file_put_contents($keyFile, $encoded, LOCK_EX) === false) throw new RuntimeException('Kunci OAuth tidak dapat dibuat.');
        }
        $decoded = base64_decode(trim((string)file_get_contents($keyFile)), true);
        if ($decoded === false || strlen($decoded) !== 32) throw new RuntimeException('Kunci OAuth tidak valid.');
        $this->key = $decoded;
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Gagal mengenkripsi data OAuth.');
        return base64_encode("PB1" . $iv . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') return '';
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 31 || substr($raw, 0, 3) !== 'PB1') throw new RuntimeException('Data OAuth terenkripsi tidak valid.');
        $plain = openssl_decrypt(substr($raw, 31), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 3, 12), substr($raw, 15, 16));
        if ($plain === false) throw new RuntimeException('Data OAuth tidak dapat dibuka dengan kunci host ini.');
        return $plain;
    }
}
