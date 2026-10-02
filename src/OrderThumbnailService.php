<?php
declare(strict_types=1);

final class OrderThumbnailService
{
    public function __construct(private PDO $db, private Closure $catalog, private string $directory) {}

    public static function selectImage(array $catalog, string $modelId): array
    {
        $main = (string)($catalog['item']['image']['image_url_list'][0] ?? '');
        if ($modelId === '0' || $modelId === '') return ['url'=>$main, 'kind'=>'main'];
        foreach ($catalog['models']['model'] ?? [] as $model) {
            if ((string)($model['model_id'] ?? '') !== $modelId) continue;
            foreach ($model['tier_index'] ?? [] as $tier=>$index) {
                $url = (string)($catalog['models']['tier_variation'][$tier]['option_list'][$index]['image']['image_url'] ?? '');
                if ($url !== '') return ['url'=>$url, 'kind'=>'variant'];
            }
        }
        return ['url'=>$main, 'kind'=>'main'];
    }

    public static function allowedUrl(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && preg_match('/(?:^|\.)(?:susercontent\.com|shopee\.co\.id|shopee\.com|shopeemobile\.com)$/D', $host) === 1
            && !parse_url($url, PHP_URL_USER) && !parse_url($url, PHP_URL_PORT);
    }

    public function forLine(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT p.order_item_id,p.model_name,o.raw_json FROM order_process p JOIN orders o ON o.order_sn=p.order_sn WHERE p.id=?');
        $stmt->execute([$id]);
        $line = $stmt->fetch();
        if (!$line) return null;
        $raw = json_decode((string)$line['raw_json'], true);
        $selected = null;
        foreach ($raw['item_list'] ?? [] as $position=>$item) {
            $model = (string)($item['model_id'] ?? '0');
            $key = $model !== '' && $model !== '0' ? $item['item_id'].':'.$model : (string)($item['order_item_id'] ?? $item['item_id'].':'.$position);
            if ($key === (string)$line['order_item_id']) { $selected=$item; break; }
        }
        if (!$selected || (int)($selected['item_id'] ?? 0) < 1) return null;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) throw new RuntimeException('Cache gambar tidak dapat dibuat.');
        $itemId=(int)$selected['item_id']; $modelId=(string)($selected['model_id'] ?? '0');
        $key=hash('sha256', $itemId.':'.$modelId);
        $path=$this->directory.'/'.$key.'.image'; $metaPath=$this->directory.'/'.$key.'.json';
        $cached=json_decode((string)@file_get_contents($metaPath),true);
        if (is_file($path) && is_array($cached) && (int)($cached['saved_at'] ?? 0)>time()-604800) return ['path'=>$path]+$cached;
        if (is_array($cached) && (int)($cached['retry_at'] ?? 0)>time()) return null;
        $lock=fopen($this->directory.'/'.$itemId.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) return null;
        try {
            $catalogPath=$this->directory.'/'.$itemId.'.catalog.json';
            $catalog=json_decode((string)@file_get_contents($catalogPath),true);
            if (!is_array($catalog) || (int)($catalog['saved_at'] ?? 0)<time()-86400) {
                $catalog=($this->catalog)($itemId); $catalog['saved_at']=time();
                file_put_contents($catalogPath,json_encode($catalog,JSON_THROW_ON_ERROR),LOCK_EX);
            }
            $image=self::selectImage($catalog,$modelId);
            if (!self::allowedUrl($image['url'])) return null;
            $ch=curl_init($image['url']); $bytes='';
            curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$bytes): int { if(strlen($bytes)+strlen($chunk)>5242880)return 0; $bytes.=$chunk;return strlen($chunk); }]);
            $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
            $info=@getimagesizefromstring($bytes);
            if ($ok===false || $status!==200 || !is_array($info) || !in_array($info['mime'],['image/jpeg','image/png','image/webp','image/gif'],true)) throw new RuntimeException('Gambar Shopee belum tersedia.');
            $meta=['mime'=>$info['mime'],'kind'=>$image['kind'],'saved_at'=>time()];
            if(file_put_contents($path,$bytes,LOCK_EX)===false)throw new RuntimeException('Cache gambar tidak dapat disimpan.');
            file_put_contents($metaPath,json_encode($meta,JSON_THROW_ON_ERROR),LOCK_EX);
            return ['path'=>$path]+$meta;
        } catch (Throwable $e) {
            file_put_contents($metaPath,json_encode(['retry_at'=>time()+300]),LOCK_EX);
            error_log('Order thumbnail: '.$e->getMessage());
            return is_file($path) && isset($cached['mime']) ? ['path'=>$path]+$cached : null;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
}
