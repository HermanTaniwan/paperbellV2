<?php
declare(strict_types=1);

/** Builds name-only SKU edits, retaining the existing TikTok attribute structure. */
final class VariationNameAlignment
{
    public static function code(string $value): string
    {
        return strtoupper(trim($value));
    }

    public static function plan(array $item, array $product, array $modelIds = []): array
    {
        $response = $item['model_response'] ?? [];
        $byId = array_column($response['model'] ?? [], null, 'model_id');
        $byCode = [];
        foreach ($response['model'] ?? [] as $model) {
            $code = self::code((string)($model['model_sku'] ?? ''));
            if ($code === '') continue;
            foreach (array_unique([$code, self::code((string)($item['item_sku'] ?? '') . $code)]) as $key) {
                $byCode[$key][(string)$model['model_id']] = $model;
            }
        }
        $inputs = []; $changes = []; $values = []; $combinations = [];
        foreach ($product['skus'] ?? [] as $sku) {
            $matches = $byCode[self::code((string)($sku['seller_sku'] ?? ''))] ?? [];
            $mapped = $modelIds[(string)$sku['id']] ?? null;
            if (count($matches) > 1 && $mapped !== null && isset($matches[$mapped])) $matches = [$mapped => $matches[$mapped]];
            if (!$matches && $mapped !== null && isset($byId[$mapped]) && trim((string)($byId[$mapped]['model_sku'] ?? '')) === '') $matches = [$mapped => $byId[$mapped]];
            if (count($matches) !== 1) throw new RuntimeException('SKU has no unique Shopee counterpart: ' . ($sku['seller_sku'] ?? ''));
            $model = reset($matches);
            $parts = [];
            foreach ($model['tier_index'] ?? [] as $tier => $option) {
                $parts[] = trim((string)($response['tier_variation'][$tier]['option_list'][$option]['option'] ?? ''));
            }
            $attributes = $sku['sales_attributes'] ?? [];
            if (count($attributes) === 1 && count($parts) > 1 && !in_array('', $parts, true)) $parts = [implode(',', $parts)];
            if (!$parts || count($parts) !== count($attributes) || in_array('', $parts, true)) throw new RuntimeException('Variation dimensions differ or Shopee tier values are missing.');
            $new = []; $old = []; $changed = false;
            foreach ($attributes as $position => $attribute) {
                $oldName = (string)($attribute['value_name'] ?? '');
                $old[] = $oldName;
                $target = $parts[$position];
                if (mb_strlen($target) > 50) throw new RuntimeException('Shopee variation value exceeds TikTok limit.');
                $key = $position . ':' . ($attribute['value_id'] ?? $oldName);
                if (isset($values[$key]) && $values[$key] !== $target) throw new RuntimeException('One TikTok option maps to multiple Shopee options.');
                $values[$key] = $target;
                $input = array_intersect_key($attribute, array_flip(['id', 'name', 'value_id', 'value_name']));
                if (!empty($attribute['sku_img']['uri'])) $input['sku_img'] = ['uri' => $attribute['sku_img']['uri']];
                if ($oldName !== $target) {
                    unset($input['value_id']);
                    $input['value_name'] = $target;
                    $changed = true;
                }
                $new[] = $input;
            }
            $combination = json_encode($parts, JSON_THROW_ON_ERROR);
            if (isset($combinations[$combination])) throw new RuntimeException('Renaming would duplicate a variation combination.');
            $combinations[$combination] = true;
            $inputs[] = ['id' => (string)$sku['id'], 'seller_sku' => (string)$sku['seller_sku'], 'sales_attributes' => $new];
            if ($changed) $changes[] = ['sku_id' => (string)$sku['id'], 'seller_sku' => (string)$sku['seller_sku'], 'model_id' => (string)$model['model_id'], 'before' => $old, 'after' => $parts];
        }
        return ['skus' => $inputs, 'changes' => $changes];
    }
}
