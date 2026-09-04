<?php
declare(strict_types=1);

final class DashboardAnalyticsService
{
    private const COMPARISON_METRICS = [
        'shopee', 'tiktok', 'total', 'items', 'itemsPerOrder',
        'ordersPerDay', 'itemsPerDay', 'revenue', 'pricedOrders',
        'shopeePayout', 'shopeeFees', 'escrowOrders',
    ];

    public static function previousPeriod(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rangeDays = (int) $from->diff($to)->format('%a') + 1;
        $previousTo = $from->modify('-1 day');
        return [$previousTo->modify('-'.($rangeDays - 1).' days'), $previousTo];
    }

    public static function previousMonthToDate(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $previousFrom = $from->modify('first day of previous month');
        $previousMonthEnd = $previousFrom->modify('last day of this month');
        $targetDay = min((int) $to->format('j'), (int) $previousMonthEnd->format('j'));
        return [$previousFrom, $previousFrom->setDate(
            (int) $previousFrom->format('Y'),
            (int) $previousFrom->format('m'),
            $targetDay
        )];
    }

    public static function summarize(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $counts,
        array $escrowCounts,
        array $holidayLookup,
        bool $includeItems = true
    ): array {
        $items = [];
        $shopee = 0;
        $tiktok = 0;
        $itemTotal = 0;
        $revenueTotal = 0.0;
        $pricedOrders = 0;
        $shopeePayout = 0.0;
        $shopeeFees = 0.0;
        $escrowOrders = 0;
        $holidayDays = 0;

        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $isHoliday = isset($holidayLookup[$key]);
            if ($isHoliday) $holidayDays++;

            $dayShopee = (int) ($counts[$key]['shopee']['orders'] ?? 0);
            $dayTiktok = (int) ($counts[$key]['tiktok']['orders'] ?? 0);
            $dayItems = (int) ($counts[$key]['shopee']['items'] ?? 0) + (int) ($counts[$key]['tiktok']['items'] ?? 0);
            $dayShopeeRevenue = (float) ($counts[$key]['shopee']['revenue'] ?? 0);
            $dayTiktokRevenue = (float) ($counts[$key]['tiktok']['revenue'] ?? 0);
            $dayRevenue = $dayShopeeRevenue + $dayTiktokRevenue;
            $dayPriced = (int) ($counts[$key]['shopee']['pricedOrders'] ?? 0) + (int) ($counts[$key]['tiktok']['pricedOrders'] ?? 0);
            $dayPayout = (float) ($escrowCounts[$key]['payout'] ?? 0);
            $dayFees = (float) ($escrowCounts[$key]['fees'] ?? 0);
            $dayEscrowOrders = (int) ($escrowCounts[$key]['orders'] ?? 0);

            $shopee += $dayShopee;
            $tiktok += $dayTiktok;
            $itemTotal += $dayItems;
            $revenueTotal += $dayRevenue;
            $pricedOrders += $dayPriced;
            $shopeePayout += $dayPayout;
            $shopeeFees += $dayFees;
            $escrowOrders += $dayEscrowOrders;

            if ($includeItems) {
                $items[] = [
                    'date' => $key,
                    'label' => $date->format('d M'),
                    'isHoliday' => $isHoliday,
                    'shopee' => $dayShopee,
                    'tiktok' => $dayTiktok,
                    'total' => $dayShopee + $dayTiktok,
                    'items' => $dayItems,
                    'revenue' => $dayRevenue,
                    'shopeeRevenue' => $dayShopeeRevenue,
                    'tiktokRevenue' => $dayTiktokRevenue,
                    'shopeePayout' => $dayPayout,
                    'shopeeFees' => $dayFees,
                    'escrowOrders' => $dayEscrowOrders,
                    'pricedOrders' => $dayPriced,
                ];
            }
        }

        $orderTotal = $shopee + $tiktok;
        $rangeDays = (int) $from->diff($to)->format('%a') + 1;
        $operatingDays = max(0, $rangeDays - $holidayDays);

        return ['items' => $items, 'summary' => [
            'shopee' => $shopee,
            'tiktok' => $tiktok,
            'total' => $orderTotal,
            'rangeDays' => $rangeDays,
            'holidayDays' => $holidayDays,
            'operatingDays' => $operatingDays,
            'ordersPerDay' => $operatingDays > 0 ? round($orderTotal / $operatingDays, 2) : 0,
            'items' => $itemTotal,
            'itemsPerDay' => $operatingDays > 0 ? round($itemTotal / $operatingDays, 2) : 0,
            'itemsPerOrder' => $orderTotal > 0 ? round($itemTotal / $orderTotal, 2) : 0,
            'revenue' => $revenueTotal,
            'pricedOrders' => $pricedOrders,
            'shopeePayout' => $shopeePayout,
            'shopeeFees' => $shopeeFees,
            'escrowOrders' => $escrowOrders,
        ]];
    }

    public static function comparison(
        array $current,
        array $previous,
        DateTimeImmutable $currentFrom,
        DateTimeImmutable $currentTo,
        DateTimeImmutable $previousFrom,
        DateTimeImmutable $previousTo,
        array $lowerIsBetter = [],
        string $mode = 'previous_period'
    ): array {
        $metrics = [];
        foreach (self::COMPARISON_METRICS as $key) {
            $currentValue = (float) ($current[$key] ?? 0);
            $previousValue = (float) ($previous[$key] ?? 0);
            $delta = $currentValue - $previousValue;
            $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
            $percentChange = $previousValue == 0.0 ? null : round(($delta / abs($previousValue)) * 100, 2);
            $lower = in_array($key, $lowerIsBetter, true);
            $tone = $direction === 'flat' ? 'neutral' : (($direction === 'down') === $lower ? 'positive' : 'negative');
            $metrics[$key] = [
                'current' => $currentValue,
                'previous' => $previousValue,
                'delta' => $delta,
                'absoluteDelta' => abs($delta),
                'percentChange' => $percentChange,
                'percentStatus' => $previousValue == 0.0 ? ($currentValue == 0.0 ? 'unchanged_zero' : 'not_comparable') : 'available',
                'direction' => $direction,
                'lowerIsBetter' => $lower,
                'tone' => $tone,
            ];
        }

        return [
            'mode' => $mode,
            'current' => self::period($currentFrom, $currentTo),
            'previous' => self::period($previousFrom, $previousTo),
            'metrics' => $metrics,
        ];
    }

    private static function period(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days' => (int) $from->diff($to)->format('%a') + 1,
            'label' => self::dateLabel($from).' – '.self::dateLabel($to),
        ];
    }

    private static function dateLabel(DateTimeImmutable $date): string
    {
        $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return $date->format('j').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }
}
