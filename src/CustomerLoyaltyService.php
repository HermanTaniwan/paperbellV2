<?php
declare(strict_types=1);

final class CustomerLoyaltyService
{
    private const INVALID_STATUSES = [
        'UNPAID', 'UN_PAID', 'PENDING_PAYMENT', 'PAYMENT_PENDING',
        'FAILED', 'EXPIRED', 'REFUNDED', 'RETURNED',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return array<string,array<string,mixed>> */
    public function forBuyers(array $buyers, ?int $now = null): array
    {
        $buyers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $buyer): string => trim((string)$buyer),
            $buyers
        ))));
        if (!$buyers) return [];

        $ordersByBuyer = array_fill_keys($buyers, []);
        foreach (array_chunk($buyers, 200) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->db->prepare(
                "SELECT order_sn,status,create_time,buyer_username,raw_json
                 FROM orders
                 WHERE buyer_username IN ({$marks})
                   AND order_sn NOT LIKE 'MANUAL-%'
                   AND order_sn NOT LIKE 'RANDOM-%'"
            );
            $stmt->execute($batch);
            foreach ($stmt->fetchAll() as $order) {
                $buyer = (string)$order['buyer_username'];
                if (self::isValidOrder($order)) $ordersByBuyer[$buyer][] = $order;
            }
        }

        $result = [];
        foreach ($ordersByBuyer as $buyer => $orders) {
            $summary = self::summarize($orders, $now ?? time());
            if ($summary !== null) $result[$buyer] = $summary;
        }
        return $result;
    }

    public function forBuyer(string $buyer, ?int $now = null): ?array
    {
        return $this->forBuyers([$buyer], $now)[trim($buyer)] ?? null;
    }

    public static function isValidOrder(array $order): bool
    {
        $status = strtoupper(trim((string)($order['status'] ?? '')));
        if ($status === '' || in_array($status, self::INVALID_STATUSES, true)) return false;
        foreach (['CANCEL', 'REFUND', 'RETURN'] as $invalidFragment) {
            if (str_contains($status, $invalidFragment)) return false;
        }
        return (int)($order['create_time'] ?? 0) > 0;
    }

    /**
     * Calculate badges from already validated orders. Each order needs
     * create_time and either amount or the marketplace raw_json payload.
     */
    public static function summarize(array $orders, int $now): ?array
    {
        if (!$orders) return null;

        $orderCount = count($orders);
        $lifetimeSpend = 0.0;
        $latestOrder = 0;
        $recentOrders = 0;
        foreach ($orders as $order) {
            $createdAt = (int)($order['create_time'] ?? 0);
            $latestOrder = max($latestOrder, $createdAt);
            if ($createdAt >= $now - 90 * 86400) $recentOrders++;
            $lifetimeSpend += array_key_exists('amount', $order)
                ? max(0.0, (float)$order['amount'])
                : self::orderAmount($order);
        }
        $lifetimeSpend = round($lifetimeSpend, 2);

        $daysSinceLastOrder = max(0, (int)floor(($now - $latestOrder) / 86400));
        $frequencyScore = match (true) {
            $orderCount >= 13 => 50,
            $orderCount >= 8 => 40,
            $orderCount >= 5 => 30,
            $orderCount >= 3 => 20,
            $orderCount === 2 => 10,
            default => 0,
        };
        $monetaryScore = match (true) {
            $lifetimeSpend >= 1000000 => 30,
            $lifetimeSpend >= 500000 => 22,
            $lifetimeSpend >= 250000 => 15,
            $lifetimeSpend >= 100000 => 10,
            default => 5,
        };
        $recencyScore = match (true) {
            $daysSinceLastOrder <= 30 => 20,
            $daysSinceLastOrder <= 60 => 15,
            $daysSinceLastOrder <= 90 => 10,
            $daysSinceLastOrder <= 180 => 5,
            default => 0,
        };
        $score = $frequencyScore + $monetaryScore + $recencyScore;

        $primary = self::primaryBadge($orderCount, $score);
        $secondary = self::secondaryBadges(
            $orderCount,
            $recentOrders,
            $lifetimeSpend,
            $daysSinceLastOrder
        );

        if ($primary !== null) {
            $primary['tooltipDetail'] = sprintf(
                '%d %s · %s lifetime spend · %s · score %d/100',
                $orderCount,
                $orderCount === 1 ? 'order' : 'orders',
                self::rupiah($lifetimeSpend),
                self::lastOrderText($daysSinceLastOrder),
                $score
            );
        }

        return [
            'orderCount' => $orderCount,
            'lifetimeSpend' => round($lifetimeSpend, 2),
            'latestOrder' => $latestOrder,
            'daysSinceLastOrder' => $daysSinceLastOrder,
            'recentOrderCount' => $recentOrders,
            'score' => $score,
            'scoreParts' => [
                'frequency' => $frequencyScore,
                'monetary' => $monetaryScore,
                'recency' => $recencyScore,
            ],
            'primary' => $primary,
            'secondary' => array_slice($secondary, 0, 2),
        ];
    }

    private static function primaryBadge(int $orders, int $score): ?array
    {
        if ($orders >= 13 && $score >= 80) return self::badge('bestie', '👑', 'Bestie', 'Paperbell Bestie', 'Very loyal customer with frequent repeat purchases.');
        if ($orders >= 8 && $score >= 65) return self::badge('regular', '⭐', 'Regular', 'Paperbell Regular', 'A consistent customer with strong repeat activity.');
        if ($orders >= 4 && $score >= 50) return self::badge('loyal', '💜', 'Loyal', 'Paperbell Loyal', 'A loyal customer with meaningful repeat purchases.');
        if ($orders >= 2 && $orders <= 3) return self::badge('returning', '↩️', 'Returning', 'Returning Customer', 'A customer who has returned to order again.');
        if ($orders === 1) return self::badge('new', '✨', 'New', 'New Customer', 'First-time customer.');
        return null;
    }

    private static function secondaryBadges(int $orders, int $recentOrders, float $spend, int $days): array
    {
        $badges = [];
        if ($orders >= 4 && $days >= 91 && $days <= 180) {
            $badge = self::badge('at-risk', '⚠️', 'At Risk', 'At Risk', 'Previously active customer.');
            $badge['tooltipDetail'] = 'Last order '.$days.' days ago';
            $badges[] = $badge;
        } elseif ($orders >= 2 && $days > 180) {
            $badge = self::badge('dormant', '💤', 'Dormant', 'Dormant Customer', 'Inactive customer.');
            $badge['tooltipDetail'] = 'Last order '.$days.' days ago';
            $badges[] = $badge;
        }
        if ($recentOrders >= 3) {
            $badge = self::badge('repeat', '🔁', 'Repeat', 'Repeat Buyer', 'Frequent recent purchases.');
            $badge['tooltipDetail'] = $recentOrders.' orders in the last 90 days';
            $badges[] = $badge;
        }
        if ($spend >= 750000) {
            $badge = self::badge('high-value', '💎', 'High Value', 'High Value Customer', 'High lifetime customer value.');
            $badge['tooltipDetail'] = self::rupiah($spend).' lifetime spend';
            $badges[] = $badge;
        }
        return $badges;
    }

    private static function badge(string $key, string $icon, string $label, string $title, string $description): array
    {
        return compact('key', 'icon', 'label', 'title', 'description');
    }

    private static function orderAmount(array $order): float
    {
        $raw = json_decode((string)($order['raw_json'] ?? ''), true);
        if (!is_array($raw)) return 0.0;
        $isTikTok = str_starts_with((string)($order['order_sn'] ?? ''), 'TIKTOK:');
        $amount = $isTikTok
            ? (float)($raw['payment']['total_amount'] ?? 0)
            : (float)($raw['total_amount'] ?? 0);
        if ($amount > 0) return $amount;

        if ($isTikTok) {
            $amount = (float)($raw['payment']['sub_total'] ?? 0);
            if ($amount <= 0) foreach (($raw['line_items'] ?? []) as $line) {
                $amount += (float)($line['sale_price'] ?? 0) * max(1, (int)($line['quantity'] ?? 1));
            }
        } else {
            foreach (($raw['item_list'] ?? []) as $line) {
                $amount += (float)($line['model_discounted_price'] ?? $line['model_original_price'] ?? 0)
                    * max(1, (int)($line['model_quantity_purchased'] ?? 1));
            }
        }
        return max(0.0, $amount);
    }

    private static function rupiah(float $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    private static function lastOrderText(int $days): string
    {
        return $days === 0 ? 'last order today' : 'last order '.$days.' days ago';
    }
}
