<?php
declare(strict_types=1);

final class OrderSearch
{
    /** Match all words on one item, allowing words to span its name, variant and SKU. */
    public static function condition(string $query): array
    {
        $query = trim($query);
        if ($query === '') return ['sql' => '1=1', 'params' => []];

        $like = static fn(string $value): string => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
        $params = array_fill(0, 3, $like($query));
        $words = array_values(array_unique(preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [$query]));
        $parts = [];
        foreach ($words as $word) {
            $parts[] = "(op.item_name LIKE ? ESCAPE '!' OR op.model_name LIKE ? ESCAPE '!' OR op.model_sku LIKE ? ESCAPE '!' OR op.item_sku LIKE ? ESCAPE '!')";
            array_push($params, ...array_fill(0, 4, $like($word)));
        }
        $sql = "(o.order_sn LIKE ? ESCAPE '!' OR o.buyer_username LIKE ? ESCAPE '!' OR r.tracking_number LIKE ? ESCAPE '!' OR EXISTS(SELECT 1 FROM order_process op WHERE op.order_sn=o.order_sn AND " . implode(' AND ', $parts) . '))';
        return ['sql' => $sql, 'params' => $params];
    }
}
