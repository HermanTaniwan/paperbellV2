<?php
declare(strict_types=1);

final class InventoryStock
{
    public static function unpunchedKey(string $sku): string
    {
        // Paperbell's A5H6 SKU is the six-hole version of the same A5 refill.
        return 'UNPUNCHED:'.strtoupper(preg_replace('/A5H6$/i', 'A5', trim($sku)));
    }

    public static function isUnpunched(string $key): bool
    {
        return str_starts_with($key, 'UNPUNCHED:');
    }

    public static function candidates(array $line, ?array $mapping): array
    {
        $keys = array_values(array_unique(array_filter([
            trim((string)$line['item_key']), trim((string)($mapping['sku_id'] ?? '')),
        ])));
        // Only a resolved mapping establishes which physical product is needed.
        if (!empty($mapping['sku_id'])) $keys[] = self::unpunchedKey((string)$mapping['sku_id']);
        $unique = [];
        foreach ($keys as $key) $unique[mb_strtolower(preg_replace('/\s+/', '', $key))] = $key;
        return array_values($unique);
    }

    public static function quantities(array $keys, array $stocks): array
    {
        $ready = 0; $unpunched = 0;
        foreach ($keys as $key) {
            $qty = max(0, (int)($stocks[mb_strtolower(preg_replace('/\s+/', '', $key))] ?? 0));
            if (self::isUnpunched($key)) $unpunched += $qty;
            else $ready += $qty;
        }
        return ['ready'=>$ready, 'unpunched'=>$unpunched, 'total'=>$ready+$unpunched];
    }

    /** Caller owns the transaction, including the order's printed status. */
    public static function consume(PDO $db, array $keys, int $need, string $orderSn, string $user, string $variant): array
    {
        if (!$db->inTransaction()) throw new LogicException('Inventory consumption requires a transaction.');
        $locked = $keys;
        sort($locked, SORT_STRING);
        $stocks = [];
        $get = $db->prepare('SELECT item_key,qty FROM product_inventory WHERE item_key=? FOR UPDATE');
        foreach ($locked as $key) {
            $get->execute([$key]);
            if ($row = $get->fetch()) $stocks[$key] = max(0, (int)$row['qty']);
        }
        $available = array_sum($stocks);
        if ($available < $need) throw new InvalidArgumentException("Stok inventory tidak cukup. Dibutuhkan {$need}, tersedia {$available}.");
        $remaining = $need; $unpunchedUsed = 0; $now = time();
        $update = $db->prepare('UPDATE product_inventory SET qty=?,updated_at=? WHERE item_key=?');
        $log = $db->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,order_sn,note,created_by,created_at) VALUES(?,'consume',?,?,?,?,?,?)");
        foreach ($keys as $key) {
            $take = min($remaining, $stocks[$key] ?? 0);
            if ($take < 1) continue;
            $after = $stocks[$key] - $take;
            $raw = self::isUnpunched($key);
            if ($raw) $unpunchedUsed += $take;
            $update->execute([$after, $now, $key]);
            $log->execute([$key, -$take, $after, $orderSn, $raw ? 'Stok belum dilubangi dipakai untuk varian: '.$variant : 'Dipakai untuk memenuhi item order', $user, $now]);
            $remaining -= $take;
            if ($remaining === 0) break;
        }
        return ['remaining'=>$available-$need, 'unpunchedUsed'=>$unpunchedUsed];
    }

    public static function markUnpunched(PDO $db, string $key, int $qty, string $user): void
    {
        if (!$db->inTransaction()) throw new LogicException('Inventory transfer requires a transaction.');
        if ($qty < 1 || self::isUnpunched($key)) throw new InvalidArgumentException('Pilih jumlah stok sesuai varian yang belum dilubangi.');
        $get = $db->prepare('SELECT * FROM product_inventory WHERE item_key=? FOR UPDATE');
        $get->execute([$key]); $stock = $get->fetch();
        if (!$stock || $qty > (int)$stock['qty']) throw new InvalidArgumentException('Jumlah melebihi stok tersedia.');
        $find = $db->prepare('SELECT sku_id FROM data_mappings WHERE sku_id IN (?,?,?) ORDER BY FIELD(sku_id,?,?,?) LIMIT 1');
        $find->execute([$key,$stock['no_ref'],$stock['model_sku'],$key,$stock['no_ref'],$stock['model_sku']]);
        $sku = $find->fetchColumn();
        if (!$sku) throw new InvalidArgumentException('Mapping SKU belum ditemukan. Hubungkan SKU sebelum memisahkan stok.');
        $target = self::unpunchedKey((string)$sku); $now = time(); $after = (int)$stock['qty']-$qty;
        $update = $db->prepare('UPDATE product_inventory SET qty=?,updated_at=? WHERE item_key=?');
        $update->execute([$after,$now,$key]);
        $upsert = $db->prepare('INSERT INTO product_inventory(item_key,model_sku,item_sku,item_name,model_name,no_ref,sku_induk,qty,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty),updated_at=VALUES(updated_at)');
        $upsert->execute([$target,$sku,$stock['item_sku'],$stock['item_name'],'Belum dilubangi',$sku,$stock['sku_induk'],$qty,$now]);
        $get->execute([$target]); $targetStock = $get->fetch();
        $log = $db->prepare("INSERT INTO inventory_movements(item_key,movement_type,qty_delta,qty_after,note,created_by,created_at) VALUES(?,'reclassify',?,?,?,?,?)");
        $log->execute([$key,-$qty,$after,'Pindah ke stok belum dilubangi: '.$target,$user,$now]);
        $log->execute([$target,$qty,(int)$targetStock['qty'],'Dipisahkan dari stok lama: '.$key,$user,$now]);
    }
}
