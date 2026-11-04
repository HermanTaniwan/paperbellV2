<?php
declare(strict_types=1);

final class ShopeeProductClicksService
{
    public function __construct(private PDO $db) {}

    public function daily(string $from = '', string $to = ''): array
    {
        $bounds = $this->db->query('SELECT MIN(stat_date) min_date, MAX(stat_date) max_date FROM shopee_product_clicks_daily')->fetch();
        $min = (string) ($bounds['min_date'] ?? '');
        $max = (string) ($bounds['max_date'] ?? '');
        if ($min === '' || $max === '') {
            return ['from'=>'','to'=>'','minDate'=>'','maxDate'=>'','items'=>[], 'summary'=>['clicks'=>0,'days'=>0,'missingDays'=>0,'average'=>0,'peak'=>null]];
        }
        $defaultFrom = (new DateTimeImmutable($max))->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
        $from = $from === '' ? $defaultFrom : $from;
        $to = $to === '' ? $max : $to;
        foreach ([$from, $to] as $date) {
            if (!self::validDate($date)) throw new InvalidArgumentException('Tanggal grafik klik tidak valid.');
        }
        if ($from > $to) throw new InvalidArgumentException('Tanggal mulai tidak boleh melewati tanggal akhir.');
        if ((int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a') > 3660) {
            throw new InvalidArgumentException('Rentang grafik klik maksimal 10 tahun.');
        }
        $stmt = $this->db->prepare('SELECT stat_date, clicks FROM shopee_product_clicks_daily WHERE stat_date BETWEEN ? AND ? ORDER BY stat_date');
        $stmt->execute([$from, $to]);
        return self::series($from, $to, $stmt->fetchAll()) + [
            'minDate'=>min($min, $defaultFrom),'maxDate'=>$max,'dataMinDate'=>$min,
            'defaultFrom'=>$defaultFrom,'defaultTo'=>$max,
        ];
    }

    public static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function series(string $from, string $to, array $rows): array
    {
        $lookup = [];
        foreach ($rows as $row) $lookup[(string) $row['stat_date']] = (int) $row['clicks'];
        $items = []; $total = 0; $days = 0; $missing = 0; $peak = null;
        for ($date = new DateTimeImmutable($from); $date <= new DateTimeImmutable($to); $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $clicks = $lookup[$key] ?? null;
            $item = ['date'=>$key,'label'=>$date->format('d M'),'fullLabel'=>$date->format('d-m-Y'),'clicks'=>$clicks];
            $items[] = $item;
            if ($clicks === null) { $missing++; continue; }
            $total += $clicks; $days++;
            if ($peak === null || $clicks > $peak['clicks']) $peak = $item;
        }
        return ['from'=>$from,'to'=>$to,'items'=>$items,'summary'=>[
            'clicks'=>$total,'days'=>$days,'missingDays'=>$missing,'average'=>$days ? $total/$days : 0,'peak'=>$peak,
        ]];
    }
}
