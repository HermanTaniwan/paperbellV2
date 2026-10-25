<?php
declare(strict_types=1);

/** Replies only to written four/five-star reviews, once per buyer/order. */
final class ShopeeReviewService
{
    public function __construct(private MarketplaceOAuthService $oauth) {}

    public static function group(array $review): string
    {
        $buyer = mb_strtolower(trim((string)($review['buyer_username'] ?? '')));
        $order = trim((string)($review['order_sn'] ?? ''));
        return hash('sha256', $buyer.'|'.($order !== '' ? $order : date('Y-m-d', (int)($review['create_time'] ?? 0))).($buyer === '' && $order === '' ? '|'.$review['comment_id'] : ''));
    }

    public static function eligible(array $review, int $since): bool
    {
        return (int)($review['create_time'] ?? 0) >= $since
            && in_array((int)($review['rating_star'] ?? 0), [4,5], true)
            && trim((string)($review['comment'] ?? '')) !== ''
            && empty($review['comment_reply']['reply'])
            && empty($review['hidden']);
    }

    public static function draft(array $review): string
    {
        $text = mb_strtolower((string)$review['comment']);
        if ((int)$review['rating_star'] === 4 || preg_match('/(?:tapi|tetapi|sayang|kurang|ringkih|rusak|sobek|salah|kecewa|kecil|tipis|lama|hilang|tidak|nggak|gak|ga |belum)/u', $text)) {
            return 'Terima kasih sudah berbelanja dan berbagi ulasan kak 😊 Masukan kakak kami terima. Kalau ada kendala pada produknya, boleh chat kami dengan detailnya supaya bisa kami bantu cek ya.';
        }
        if (str_contains($text, 'bonus')) return 'Makasih banyak kak! Senang produknya disukai 🥰 Semoga bonusnya juga bermanfaat ya. Terima kasih sudah belanja di Paperbell! ✨';
        if (str_contains($text, 'lucu') || str_contains($text, 'desain')) return 'Makasih banyak kak! Senang desainnya disukai 🥰 Semoga produknya bermanfaat dan bikin kegiatan kakak makin menyenangkan. Terima kasih sudah memilih Paperbell! 💕';
        return 'Makasih banyak kak sudah belanja dan berbagi ulasan 😊 Semoga produk Paperbell-nya bermanfaat dan nyaman dipakai. Ditunggu kunjungan berikutnya ya! ✨';
    }

    public function comments(array $query): array
    {
        return $this->request('GET','/api/v2/product/get_comment', $query + ['cursor'=>'','page_size'=>100], null)['response'] ?? [];
    }

    public function find(string $id): array
    {
        foreach ($this->comments(['comment_id'=>$id])['item_comment_list'] ?? [] as $review) {
            if ((string)$review['comment_id'] === $id) return $review;
        }
        throw new RuntimeException('Review tidak ditemukan: '.$id);
    }

    public function reply(string $id, string $text): array
    {
        $json = $this->request('POST','/api/v2/product/reply_comment', [], ['comment_list'=>[['comment_id'=>(int)$id,'comment'=>$text]]]);
        foreach ($json['response']['result_list'] ?? [] as $result) {
            if ((string)($result['comment_id'] ?? '') !== $id) continue;
            if (!empty($result['error']) || !empty($result['fail_error'])) throw new RuntimeException('Balasan ditolak: '.json_encode($result));
            return $result;
        }
        throw new RuntimeException('Shopee tidak mengonfirmasi balasan: '.$id);
    }

    private function request(string $method, string $path, array $extra, ?array $body): array
    {
        $auth = $this->oauth->credentials('shopee');
        $cfg = $auth['config']; $partner = (string)$cfg['partner_id']; $token = (string)$auth['access_token']; $shop = (string)$auth['account_id']; $ts = time();
        $query = ['partner_id'=>$partner,'timestamp'=>$ts,'access_token'=>$token,'shop_id'=>$shop,'sign'=>hash_hmac('sha256',$partner.$path.$ts.$token.$shop,(string)$cfg['partner_key'])] + $extra;
        $ch = curl_init(rtrim((string)($cfg['api_host'] ?? 'https://partner.shopeemobile.com'),'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986));
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method]);
        if ($body !== null) curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $raw = curl_exec($ch); $http = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($raw === false) throw new RuntimeException('Koneksi Shopee gagal.');
        $json = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if ($http < 200 || $http >= 300 || !in_array((string)($json['error'] ?? ''),['','0'],true)) throw new RuntimeException('Shopee HTTP '.$http.': '.(string)($json['message'] ?? $json['error'] ?? 'rejected'));
        return $json;
    }
}
