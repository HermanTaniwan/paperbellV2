<?php
declare(strict_types=1);

final class ShopeeSkuIdentity
{
    /** Explicit variant identities take precedence over historical, possibly duplicated SKU text. */
    public static function itemKey(PDO $db, string $lineId, string $fallback): string
    {
        $stmt = $db->prepare('SELECT m.sku_id FROM mapping_aliases a JOIN data_mappings m ON m.id=a.mapping_id WHERE a.alias_key=?');
        $stmt->execute(['shopee:' . $lineId]);
        $sku = $stmt->fetchColumn();
        return is_string($sku) && trim($sku) !== '' ? $sku : $fallback;
    }
}
