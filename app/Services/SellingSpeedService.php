<?php

namespace App\Services;

use App\Models\SalesItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SellingSpeedService
{
    public function ranking(): Collection
    {
        $rankingStart = CarbonImmutable::now()->subDays(29)->startOfDay();
        $rankingEnd = CarbonImmutable::now();
        $rankingRows = SalesItem::query()
            ->select('products.product_id', 'products.name', 'products.sku', 'products.category')
            ->selectRaw('COUNT(DISTINCT sales_items.sale_id) as purchase_frequency')
            ->selectRaw('SUM(sales_items.quantity) as units_sold')
            ->selectRaw('SUM(sales_items.line_total) as sales_total')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('products.is_active', true)
            ->where('sales_transactions.payment_status', 'paid')
            ->whereBetween('sales_transactions.sale_date', [$rankingStart, $rankingEnd])
            ->groupBy('products.product_id', 'products.name', 'products.sku', 'products.category')
            ->get();

        return $rankingRows
            ->map(function ($item) {
                $item->units_per_purchase = (int) $item->purchase_frequency > 0
                    ? (int) $item->units_sold / (int) $item->purchase_frequency
                    : 0;
                $item->units_per_day = (int) $item->units_sold / 30;

                return $item;
            })
            ->sort(function ($left, $right) {
                return ([(int) $right->units_sold, (int) $right->purchase_frequency]
                    <=> [(int) $left->units_sold, (int) $left->purchase_frequency])
                    ?: ((int) $left->product_id <=> (int) $right->product_id);
            })
            ->values();
    }

    public function classify(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 14 => 'Fast Moving',
            $daysLeft <= 60 => 'Normal',
            default => 'Slow Moving',
        };
    }
}
